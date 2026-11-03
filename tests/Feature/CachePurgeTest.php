<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Exceptions\CachePurgeFailed;
use Lostdomain\BlogKit\Exceptions\CloudflarePurgeFailed;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Models\Tag;
use Lostdomain\BlogKit\Services\BlogCachePurger;
use Lostdomain\BlogKit\Services\CacheUrls;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\ResponseCache\Hasher\RequestHasher;
use Spatie\ResponseCache\ResponseCache;
use Spatie\ResponseCache\ResponseCacheRepository;

class CachePurgeTest extends TestCase
{
    private const ZONE = 'zone123';

    private const CF_TOKEN = 'cf-secret-token';

    #[Test]
    public function pages_are_served_from_the_local_cache_until_purged(): void
    {
        Http::fake();
        $post = Post::factory()->create(['slug' => 'cached', 'title' => 'First title']);

        $this->get('/blog/cached')->assertSee('First title');
        $post->forceFill(['title' => 'Second title'])->saveQuietly();
        $this->get('/blog/cached')->assertSee('First title');

        app(BlogCachePurger::class)->purge([$post->url()]);

        $this->get('/blog/cached')->assertSee('Second title');
    }

    #[Test]
    public function paginated_index_pages_are_purged(): void
    {
        Http::fake();
        config(['blog-kit.per_page' => 1]);
        Post::factory()->create(['title' => 'Alpha', 'published_at' => now()->subDays(2)]);
        $beta = Post::factory()->create(['title' => 'Beta', 'published_at' => now()->subDay()]);

        $this->get('/blog?page=2')->assertSee('Alpha');
        $beta->forceFill(['published_at' => now()->subDays(3)])->saveQuietly();

        app(BlogCachePurger::class)->purge(app(CacheUrls::class)->indexUrls());

        $this->get('/blog?page=2')->assertSee('Beta');
    }

    #[Test]
    public function requests_with_unknown_query_strings_are_not_cached(): void
    {
        $post = Post::factory()->create(['slug' => 'q', 'title' => 'First title']);

        $this->get('/blog/q?foo=bar')->assertSee('First title');
        $post->forceFill(['title' => 'Second title'])->saveQuietly();

        $this->get('/blog/q?foo=bar')->assertSee('Second title')->assertHeaderMissing('X-Cache-Status');
    }

    #[Test]
    public function cloudflare_is_purged_with_the_configured_zone_and_token(): void
    {
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'errors' => []])]);

        app(BlogCachePurger::class)->purge(['https://example.test/blog/a', 'https://example.test/blog']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.self::CF_TOKEN)
            && $request['files'] === ['https://example.test/blog/a', 'https://example.test/blog', 'https://example.test/blog/a/', 'https://example.test/blog/']);
    }

    #[Test]
    public function cloudflare_purges_are_chunked(): void
    {
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $urls = array_map(fn (int $i) => "https://example.test/blog/post-{$i}", range(1, 65));
        app(BlogCachePurger::class)->purge($urls);

        // 65 URLs plus their trailing-slash variants.
        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request) => count($request['files']) === 30);
        Http::assertSent(fn (Request $request) => count($request['files']) === 10);
    }

    #[Test]
    public function a_cloudflare_error_throws_so_the_job_retries_and_is_logged_without_the_token(): void
    {
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403)]);
        Log::spy();

        try {
            app(BlogCachePurger::class)->purge(['https://example.test/blog/a']);
            $this->fail('Expected CloudflarePurgeFailed.');
        } catch (CloudflarePurgeFailed $exception) {
            $this->assertStringContainsString('HTTP 403', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'blog-kit.cloudflare.purge_failed'
            && $context['status'] === 403
            && ! str_contains(json_encode($context), self::CF_TOKEN));
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($message) => $message === 'blog-kit.cloudflare.purge_response'), \Mockery::any()]);
    }

    #[Test]
    public function a_200_without_success_true_is_a_failure(): void
    {
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'nope']]])]);

        $this->expectException(CloudflarePurgeFailed::class);

        app(BlogCachePurger::class)->purge(['https://example.test/blog/a']);
    }

    #[Test]
    public function without_cloudflare_config_the_purge_is_skipped_with_a_warning(): void
    {
        Http::fake();
        Log::spy();

        app(BlogCachePurger::class)->purge(['https://example.test/blog/a']);

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'blog-kit.cloudflare.purge_skipped');
    }

    #[Test]
    public function the_purge_job_logs_an_error_when_it_finally_fails(): void
    {
        Log::spy();

        (new PurgeBlogCache(['https://example.test/blog/a']))->failed(new CloudflarePurgeFailed('boom'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'blog-kit.cache.purge_failed' && $context['error'] === 'boom');
    }

    #[Test]
    public function the_url_set_covers_article_old_slugs_listings_tags_feeds_and_sitemap(): void
    {
        config(['blog-kit.per_page' => 1]);
        $post = Post::factory()->create(['slug' => 'current']);
        Post::factory()->create();
        Redirect::create(['from_slug' => 'previous', 'post_id' => $post->id]);
        $post->tags()->attach(Tag::create(['name' => 'Zoom', 'slug' => 'zoom']));

        $urls = app(CacheUrls::class)->forPost($post, [], ['removed-tag']);

        foreach ([
            'https://example.test/blog/current',
            'https://example.test/blog/previous',
            'https://example.test/blog',
            'https://example.test/blog?page=1',
            'https://example.test/blog?page=2',
            'https://example.test/blog?page=3',
            'https://example.test/blog/tag/zoom',
            'https://example.test/blog/tag/zoom?page=1',
            'https://example.test/blog/tag/removed-tag',
            'https://example.test/blog/rss.xml',
            'https://example.test/blog/atom.xml',
            'https://example.test/blog/sitemap.xml',
        ] as $expected) {
            $this->assertContains($expected, $urls);
        }

        $this->assertSame($urls, array_values(array_unique($urls)));
    }

    #[Test]
    public function a_publish_ends_with_cloudflare_receiving_the_affected_urls(): void
    {
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->postSigned($this->payload(['tags' => ['Zoom']]))->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'purge_cache')
            && in_array('https://example.test/blog/mute-zoom-from-anywhere', $request['files'], true)
            && in_array('https://example.test/blog/tag/zoom', $request['files'], true));
    }

    #[Test]
    public function a_slug_change_purges_the_old_url(): void
    {
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->postSigned($this->payload());
        $this->postSigned($this->payload(['slug' => 'renamed']));

        Http::assertSent(fn (Request $request) => in_array('https://example.test/blog/mute-zoom-from-anywhere', $request['files'] ?? [], true)
            && in_array('https://example.test/blog/renamed', $request['files'] ?? [], true));
    }

    #[Test]
    public function a_trailing_slash_shares_the_cache_entry_and_is_purged_with_it(): void
    {
        Http::fake();
        $post = Post::factory()->create(['slug' => 'slashed', 'title' => 'First title']);

        $this->assertStringContainsString('First title', $this->visit('/blog/slashed/?page=1'));
        $post->forceFill(['title' => 'Second title'])->saveQuietly();
        $this->assertStringContainsString('First title', $this->visit('/blog/slashed?page=1'), 'served from the entry the slash URL created');

        app(BlogCachePurger::class)->purge(['https://example.test/blog/slashed?page=1']);

        $this->assertStringContainsString('Second title', $this->visit('/blog/slashed/?page=1'));
    }

    #[Test]
    public function other_pages_of_the_host_keep_separate_entries_for_a_trailing_slash(): void
    {
        $hasher = app(RequestHasher::class);

        $this->assertNotSame(
            $hasher->getHashFor(HttpRequest::create('https://example.test/pricing')),
            $hasher->getHashFor(HttpRequest::create('https://example.test/pricing/')),
        );
    }

    #[Test]
    public function cloudflare_gets_slash_variants_except_for_feeds_and_the_query_stays_last(): void
    {
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        app(BlogCachePurger::class)->purge(['https://example.test/blog?page=2', 'https://example.test/blog/rss.xml']);

        Http::assertSent(fn (Request $request) => $request['files'] === [
            'https://example.test/blog?page=2',
            'https://example.test/blog/rss.xml',
            'https://example.test/blog/?page=2',
        ]);
    }

    #[Test]
    public function a_page_that_stays_cached_fails_the_purge_and_cloudflare_waits(): void
    {
        $this->configureCloudflare();
        Http::fake();
        Log::spy();
        $this->useACacheThatCannotForget();
        Post::factory()->create(['slug' => 'stuck']);

        $this->get('/blog/stuck')->assertOk();

        try {
            app(BlogCachePurger::class)->purge(['https://example.test/blog/stuck', 'https://example.test/blog/never-cached']);
            $this->fail('Expected CachePurgeFailed.');
        } catch (CachePurgeFailed $exception) {
            $this->assertStringContainsString('1 of 2 pages are still cached', $exception->getMessage());
            $this->assertStringContainsString('https://example.test/blog/stuck', $exception->getMessage());
        }

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'blog-kit.cache.local_purge_failed');
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($message) => $message === 'blog-kit.cache.local_purged'), \Mockery::any()]);
    }

    #[Test]
    public function the_purge_command_purges_every_page(): void
    {
        Http::fake();
        config(['blog-kit.per_page' => 1]);
        $post = Post::factory()->create(['slug' => 'one', 'title' => 'First title']);
        Redirect::create(['from_slug' => '2024/01/15/one', 'post_id' => $post->id]);
        $post->tags()->attach(Tag::create(['name' => 'Zoom', 'slug' => 'zoom']));

        $this->get('/blog')->assertSee('First title');
        $this->get('/blog/one')->assertSee('First title');
        $this->get('/blog/tag/zoom')->assertSee('First title');
        $this->get('/blog/2024/01/15/one')->assertStatus(301);

        $post->forceFill(['title' => 'Second title'])->saveQuietly();
        Redirect::query()->delete();

        $this->artisan('blog-kit:purge')
            ->expectsOutputToContain('under https://example.test/blog')
            ->assertSuccessful();

        $this->get('/blog')->assertSee('Second title');
        $this->get('/blog/one')->assertSee('Second title');
        $this->get('/blog/tag/zoom')->assertSee('Second title');
    }

    #[Test]
    public function the_purge_command_runs_now_even_in_queue_mode_and_fails_visibly(): void
    {
        $this->useACacheThatCannotForget();
        Post::factory()->create(['slug' => 'stuck']);
        $this->get('/blog/stuck')->assertOk();

        $this->artisan('blog-kit:purge')
            ->expectsOutputToContain('still cached')
            ->assertFailed();
    }

    #[Test]
    public function all_urls_include_posts_redirects_and_tags(): void
    {
        $post = Post::factory()->create(['slug' => 'current']);
        Post::factory()->create(['slug' => 'gone', 'unpublished_at' => now()]);
        Redirect::create(['from_slug' => '2024-10-03-old-6.0', 'post_id' => $post->id]);
        Tag::create(['name' => 'Zoom', 'slug' => 'zoom']);

        $urls = app(CacheUrls::class)->all();

        foreach (['current', 'gone', '2024-10-03-old-6.0', 'tag/zoom', 'rss.xml'] as $path) {
            $this->assertContains("https://example.test/blog/{$path}", $urls);
        }

        $this->assertContains('https://example.test/blog', $urls);
    }

    /** A real request, with the URL as given: the test client strips trailing slashes. */
    private function visit(string $path): string
    {
        $kernel = $this->app->make(Kernel::class);
        $request = HttpRequest::create('https://example.test'.$path);
        $response = $kernel->handle($request);
        // responsecache 8 stores the page while terminating.
        $kernel->terminate($request, $response);

        return (string) $response->getContent();
    }

    /** Like a file store this process may not write to: forget() fails without an exception. */
    private function useACacheThatCannotForget(): void
    {
        Cache::extend('cannot-forget', fn () => Cache::repository(new class extends ArrayStore
        {
            public function forget($key)
            {
                return false;
            }
        }));

        config([
            'cache.stores.cannot-forget' => ['driver' => 'cannot-forget'],
            'responsecache.cache.store' => 'cannot-forget',
            'responsecache.cache_store' => 'cannot-forget', // responsecache 7
        ]);

        foreach ([ResponseCache::class, ResponseCacheRepository::class, BlogCachePurger::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    private function configureCloudflare(): void
    {
        config([
            'blog-kit.cloudflare.zone_id' => self::ZONE,
            'blog-kit.cloudflare.api_token' => self::CF_TOKEN,
        ]);
    }
}
