<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lostdomain\BlogKit\Exceptions\ImageDownloadFailed;
use Lostdomain\BlogKit\Jobs\ProcessPostImages;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Image;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\CacheUrls;
use Lostdomain\BlogKit\Services\ImageLocalizer;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ProcessPostImagesTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\nfake-png-bytes";

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('public', ['url' => 'https://example.test/storage']);
    }

    #[Test]
    public function it_downloads_every_image_and_rewrites_the_post_to_local_urls(): void
    {
        Http::fake([
            'cdn.test/hero.png' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
            'cdn.test/inline.jpg*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'cdn.test/inline@2x.jpg' => Http::response('jpeg-2x-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'cdn.test/slot.webp' => Http::response('webp-bytes', 200, ['Content-Type' => 'image/webp; charset=binary']),
        ]);

        $post = $this->postWithImages();

        $this->runJob($post);

        $post->refresh();
        $this->assertSame(Post::IMAGES_DONE, $post->images_status);
        $this->assertNull($post->image_error);
        $this->assertNotNull($post->images_processed_at);

        $hero = Image::where('source_url', 'https://cdn.test/hero.png')->sole();
        $this->assertSame($hero->url, $post->featured_image['url']);
        $this->assertStringStartsWith('https://example.test/storage/blog/', $hero->url);
        Storage::disk('public')->assertExists($hero->path);
        $this->assertSame(self::PNG, Storage::disk('public')->get($hero->path));

        $this->assertStringNotContainsString('src="https://cdn.test', $post->body_html);
        $this->assertStringNotContainsString('https://cdn.test/inline', $post->body_html);
        // Links are not images; only <img>/<source> are localized.
        $this->assertStringContainsString('href="https://cdn.test/not-an-image.png"', $post->body_html);
        $this->assertStringNotContainsString('cdn.test', $post->body_markdown);
        $this->assertStringNotContainsString('cdn.test', json_encode($post->images));
        $this->assertSame(4, Image::where('status', Image::STORED)->count());

        // The query-string URL was matched in its HTML-escaped form too.
        $inline = Image::where('source_url', 'https://cdn.test/inline.jpg?w=800&h=600')->sole();
        $this->assertStringContainsString('src="'.$inline->url.'"', $post->body_html);
    }

    #[Test]
    public function it_purges_the_post_urls_once_the_images_are_local(): void
    {
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);
        $post = $this->postWithImages();

        $this->runJob($post);

        Queue::assertPushed(PurgeBlogCache::class, fn (PurgeBlogCache $job) => in_array($post->url(), $job->urls, true));
    }

    #[Test]
    public function a_failed_download_records_the_error_keeps_the_original_url_and_throws_for_a_retry(): void
    {
        Http::fake([
            'cdn.test/hero.png' => Http::response('missing', 404),
            '*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
        ]);
        Log::spy();
        $post = $this->postWithImages();

        try {
            $this->runJob($post);
            $this->fail('The job should throw so the queue retries it.');
        } catch (ImageDownloadFailed $exception) {
            $this->assertStringContainsString('https://cdn.test/hero.png (HTTP 404)', $exception->getMessage());
        }

        $post->refresh();
        $this->assertSame(Post::IMAGES_PENDING, $post->images_status);
        $this->assertStringContainsString('https://cdn.test/hero.png (HTTP 404)', $post->image_error);
        // Nothing is rewritten until every image is stored or retries run out.
        $this->assertSame('https://cdn.test/hero.png', $post->featured_image['url']);
        $this->assertSame(Image::FAILED, Image::where('source_url', 'https://cdn.test/hero.png')->value('status'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'blog-kit.images.download_failed'
            && $context['url'] === 'https://cdn.test/hero.png');
        Queue::assertNotPushed(PurgeBlogCache::class);
    }

    #[Test]
    public function a_retry_only_downloads_what_is_still_missing(): void
    {
        Http::fakeSequence('cdn.test/hero.png')
            ->push('missing', 503)
            ->push(self::PNG, 200, ['Content-Type' => 'image/png']);
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);
        $post = $this->postWithImages();

        try {
            $this->runJob($post);
        } catch (ImageDownloadFailed) {
        }

        $this->runJob($post);

        $this->assertSame(Post::IMAGES_DONE, $post->refresh()->images_status);
        Http::assertSentCount(5);
    }

    #[Test]
    public function when_retries_run_out_the_post_is_marked_failed_with_partial_rewrites_and_an_error_log(): void
    {
        Http::fake([
            'cdn.test/hero.png' => Http::response('missing', 404),
            '*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
        ]);
        Log::spy();
        $post = $this->postWithImages();
        $job = new ProcessPostImages($post->id, $post->revision);

        try {
            $job->handle(app(ImageLocalizer::class), app(CacheUrls::class));
        } catch (ImageDownloadFailed $exception) {
            $job->failed($exception);
        }

        $post->refresh();
        $this->assertSame(Post::IMAGES_FAILED, $post->images_status);
        $this->assertStringContainsString('HTTP 404', $post->image_error);
        // The failed image stays on its original URL, never dropped.
        $this->assertSame('https://cdn.test/hero.png', $post->featured_image['url']);
        $this->assertStringNotContainsString('https://cdn.test/slot.webp', json_encode($post->images));
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'blog-kit.images.failed'
            && $context['post_id'] === $post->id);
        Queue::assertPushed(PurgeBlogCache::class);
    }

    #[Test]
    public function non_image_responses_fail(): void
    {
        Http::fake(['*' => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html'])]);
        $post = Post::factory()->create(['featured_image' => ['url' => 'https://cdn.test/hero.png']]);

        $this->expectException(ImageDownloadFailed::class);
        $this->expectExceptionMessage('Unsupported content type "text/html"');

        $this->runJob($post);
    }

    #[Test]
    public function svg_is_refused_because_it_can_carry_scripts(): void
    {
        Http::fake(['*' => Http::response('<svg/>', 200, ['Content-Type' => 'image/svg+xml'])]);
        $post = Post::factory()->create(['featured_image' => ['url' => 'https://cdn.test/logo.svg']]);

        $this->expectException(ImageDownloadFailed::class);

        $this->runJob($post);
    }

    #[Test]
    public function images_over_the_size_limit_fail(): void
    {
        config(['blog-kit.images.max_bytes' => 4]);
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);
        $post = Post::factory()->create(['featured_image' => ['url' => 'https://cdn.test/hero.png']]);

        $this->expectException(ImageDownloadFailed::class);
        $this->expectExceptionMessage('larger than the 4 byte limit');

        $this->runJob($post);
    }

    #[Test]
    public function connection_errors_fail_visibly(): void
    {
        Http::fake(fn () => throw new RuntimeException('Could not resolve host'));
        $post = Post::factory()->create(['featured_image' => ['url' => 'https://cdn.test/hero.png']]);

        $this->expectException(ImageDownloadFailed::class);
        $this->expectExceptionMessage('Could not resolve host');

        $this->runJob($post);
    }

    #[Test]
    public function a_job_for_an_older_revision_does_nothing(): void
    {
        Http::fake();
        $post = $this->postWithImages();
        $job = new ProcessPostImages($post->id, $post->revision - 1);

        $job->handle(app(ImageLocalizer::class), app(CacheUrls::class));

        Http::assertNothingSent();
        $this->assertSame('https://cdn.test/hero.png', $post->refresh()->featured_image['url']);
    }

    #[Test]
    public function an_update_reuses_images_that_were_already_stored(): void
    {
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);
        $post = $this->postWithImages();
        $this->runJob($post);
        Http::fake();

        // Marketing OS sends its own URLs again on every update.
        $post->refresh()->forceFill([
            'featured_image' => ['url' => 'https://cdn.test/hero.png', 'alt_text' => 'Hero'],
            'revision' => $post->revision + 1,
        ])->save();
        $this->runJob($post);

        Http::assertNothingSent();
        $this->assertStringStartsWith('https://example.test/storage/', $post->refresh()->featured_image['url']);
    }

    #[Test]
    public function the_receiver_and_job_work_end_to_end(): void
    {
        Queue::fake([PurgeBlogCache::class]);
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $this->postSigned($this->payload([
            'featured_image' => ['url' => 'https://cdn.test/hero.png', 'alt_text' => 'Hero', 'width' => 1600, 'height' => 900],
            'body_html' => '<p>Intro</p><figure><img src="https://cdn.test/step-1.png" alt="Step 1"></figure>',
        ]))->assertOk();

        $post = Post::sole();
        $this->assertSame(Post::IMAGES_DONE, $post->images_status);
        $this->assertStringNotContainsString('cdn.test', $post->body_html.$post->featured_image['url']);
        $this->get($post->url())->assertOk()->assertDontSee('cdn.test');
    }

    private function postWithImages(): Post
    {
        return Post::factory()->create([
            'featured_image' => ['url' => 'https://cdn.test/hero.png', 'alt_text' => 'Hero', 'width' => 1600, 'height' => 900],
            'body_html' => '<p>Intro</p>'
                .'<figure><img src="https://cdn.test/inline.jpg?w=800&amp;h=600" srcset="https://cdn.test/inline.jpg?w=800&amp;h=600 1x, https://cdn.test/inline@2x.jpg 2x" alt="Inline"><figcaption>Step</figcaption></figure>'
                .'<p><a href="https://cdn.test/not-an-image.png">link stays</a></p>',
            'body_markdown' => "Intro\n\n![Inline](https://cdn.test/inline.jpg?w=800&h=600 \"Step\")",
            'images' => [
                ['slot' => 'hero', 'placement' => 'featured', 'kind' => 'hero', 'url' => 'https://cdn.test/hero.png', 'alt_text' => 'Hero'],
                ['slot' => 'step-1', 'placement' => 'inline', 'kind' => 'screenshot', 'url' => 'https://cdn.test/slot.webp', 'alt_text' => 'Slot'],
            ],
            'images_status' => Post::IMAGES_PENDING,
        ]);
    }

    private function runJob(Post $post): void
    {
        $post->refresh();
        (new ProcessPostImages($post->id, $post->revision))->handle(app(ImageLocalizer::class), app(CacheUrls::class));
    }
}
