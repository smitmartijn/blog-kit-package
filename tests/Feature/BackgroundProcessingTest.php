<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\PostPublisher;
use Lostdomain\BlogKit\Support\Background;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** The default mode: work runs after the response, no queue worker involved. */
class BackgroundProcessingTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\nfake-png-bytes";

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blog-kit.processing' => 'after_response',
            'blog-kit.after_response.backoff' => [0],
            'blog-kit.cloudflare.zone_id' => 'zone123',
            'blog-kit.cloudflare.api_token' => 'cf-token',
        ]);
        Queue::fake();
        Storage::fake('public', ['url' => 'https://example.test/storage']);
    }

    #[Test]
    public function a_delivery_is_answered_and_its_images_processed_without_a_queue(): void
    {
        Http::fake([
            'cdn.test/*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
            'api.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->postSigned($this->payload([
            'featured_image' => ['url' => 'https://cdn.test/hero.png', 'alt_text' => 'Hero'],
            'body_html' => '<p>Intro</p><figure><img src="https://cdn.test/step.png" alt="Step"></figure>',
        ]))->assertOk();

        $post = Post::sole();
        $this->assertSame(Post::IMAGES_DONE, $post->images_status);
        $this->assertStringStartsWith('https://example.test/storage/', $post->featured_image['url']);
        $this->assertStringNotContainsString('cdn.test', $post->body_html);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'purge_cache')
            && in_array('https://example.test/blog/mute-zoom-from-anywhere', $request['files'], true));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_work_waits_until_the_response_has_been_sent(): void
    {
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $post = app(PostPublisher::class)->save(new Post, Post::factory()->raw([
            'featured_image' => ['url' => 'https://cdn.test/hero.png'],
        ]));

        // Nothing has run yet: Laravel runs it when the request terminates.
        $this->assertSame(Post::IMAGES_PENDING, $post->refresh()->images_status);
        Http::assertNothingSent();

        $this->app->terminate();

        $this->assertSame(Post::IMAGES_DONE, $post->refresh()->images_status);
    }

    #[Test]
    public function failed_downloads_retry_inline_then_mark_the_post_failed_without_failing_the_request(): void
    {
        config(['blog-kit.after_response.attempts' => 2]);
        Http::fake([
            'cdn.test/missing.png' => Http::response('nope', 404),
            '*' => Http::response(['success' => true]),
        ]);
        Log::spy();

        $this->postSigned($this->payload(['featured_image' => ['url' => 'https://cdn.test/missing.png']]))->assertOk();

        $post = Post::sole();
        $this->assertSame(Post::IMAGES_FAILED, $post->images_status);
        $this->assertStringContainsString('https://cdn.test/missing.png (HTTP 404)', $post->image_error);
        $this->assertSame('https://cdn.test/missing.png', $post->featured_image['url']);
        Http::assertSentCount(3); // two download attempts + the Cloudflare purge
        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => $message === 'blog-kit.background.retry');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'blog-kit.images.failed');
    }

    #[Test]
    public function an_update_is_visible_straight_away(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);

        $this->postSigned($this->payload(['title' => 'First title']));
        $this->get('/blog/mute-zoom-from-anywhere')->assertSee('First title');

        $this->postSigned($this->payload(['title' => 'Second title']))->assertOk();

        $this->get('/blog/mute-zoom-from-anywhere')->assertSee('Second title');
    }

    #[Test]
    public function work_inside_a_rolled_back_transaction_never_runs(): void
    {
        Http::fake();

        try {
            DB::transaction(function () {
                app(Background::class)->run(new PurgeBlogCache(['https://example.test/blog']));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->app->terminate();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_failing_purge_is_logged_not_thrown(): void
    {
        config(['blog-kit.after_response.attempts' => 1]);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 500)]);
        Log::spy();

        app(Background::class)->run(new PurgeBlogCache(['https://example.test/blog']));
        $this->app->terminate();

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'blog-kit.cache.purge_failed');
    }

    #[Test]
    public function each_piece_of_work_runs_once_even_when_the_app_terminates_again(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);

        app(Background::class)->run(new PurgeBlogCache(['https://example.test/blog']));
        $this->app->terminate();
        $this->app->terminate();

        Http::assertSentCount(1);
    }

    #[Test]
    public function queue_mode_dispatches_to_the_queue_instead(): void
    {
        config(['blog-kit.processing' => 'queue']);

        app(Background::class)->run(new PurgeBlogCache(['https://example.test/blog']));

        Queue::assertPushed(PurgeBlogCache::class);
    }

    #[Test]
    public function an_unknown_processing_mode_fails_loudly(): void
    {
        config(['blog-kit.processing' => 'later']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('blog-kit.processing must be "after_response" or "queue"');

        app(Background::class)->run(new PurgeBlogCache([]));
    }
}
