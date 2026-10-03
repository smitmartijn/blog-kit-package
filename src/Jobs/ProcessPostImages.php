<?php

namespace Lostdomain\BlogKit\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Exceptions\ImageDownloadFailed;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\CacheUrls;
use Lostdomain\BlogKit\Services\ImageLocalizer;
use Throwable;

/**
 * Downloads a post's remote images and rewrites the stored post to the
 * local URLs. Bound to one revision: if the post changed meanwhile, a
 * newer job owns the work and this one stops.
 */
class ProcessPostImages implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public int $postId, public int $revision)
    {
        $this->tries = max(1, (int) config('blog-kit.images.tries', 5));
        $this->onConnection(config('blog-kit.images.connection'));
        $this->onQueue(config('blog-kit.images.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map('intval', (array) config('blog-kit.images.backoff', [30, 120, 600, 1800]));
    }

    public function handle(ImageLocalizer $localizer, CacheUrls $urls): void
    {
        $post = $this->currentPost();

        if (! $post) {
            return;
        }

        $result = $localizer->localize($post);

        if ($result['failures'] !== []) {
            $message = $this->describe($result['failures']);

            Post::whereKey($post->id)->where('revision', $this->revision)->update(['image_error' => $message]);

            throw new ImageDownloadFailed("Post {$post->id}: {$message}");
        }

        $this->finish($localizer, $urls, $post, $result['map'], Post::IMAGES_DONE, null);
    }

    /**
     * Retries are exhausted. Keep whatever was stored, leave the rest on
     * their original URLs, and record the error on the post.
     */
    public function failed(Throwable $exception): void
    {
        $post = Post::find($this->postId);

        Log::error('blog-kit.images.failed', [
            'post_id' => $this->postId,
            'revision' => $this->revision,
            'slug' => $post?->slug,
            'error' => $exception->getMessage(),
        ]);

        if (! $post || $post->revision !== $this->revision) {
            return;
        }

        $localizer = app(ImageLocalizer::class);

        $this->finish($localizer, app(CacheUrls::class), $post, $localizer->storedMap($post), Post::IMAGES_FAILED, $exception->getMessage());
    }

    private function currentPost(): ?Post
    {
        $post = Post::find($this->postId);

        if (! $post) {
            Log::info('blog-kit.images.skipped', ['post_id' => $this->postId, 'reason' => 'post deleted']);

            return null;
        }

        if ($post->revision !== $this->revision) {
            Log::info('blog-kit.images.skipped', [
                'post_id' => $post->id,
                'reason' => 'superseded',
                'job_revision' => $this->revision,
                'current_revision' => $post->revision,
            ]);

            return null;
        }

        return $post;
    }

    /** @param  array<string, string>  $map */
    private function finish(ImageLocalizer $localizer, CacheUrls $urls, Post $post, array $map, string $status, ?string $error): void
    {
        $attributes = $localizer->rewrite($post, $map);
        $post->forceFill([
            ...$attributes,
            'images_status' => $status,
            'image_error' => $error,
            'images_processed_at' => now(),
        ]);

        // Conditional on the revision, so a concurrent update is never overwritten.
        $updated = Post::whereKey($post->id)
            ->where('revision', $this->revision)
            ->update($post->getDirty());

        if ($updated === 0) {
            Log::info('blog-kit.images.skipped', ['post_id' => $post->id, 'reason' => 'superseded while processing']);

            return;
        }

        Log::info('blog-kit.images.processed', [
            'post_id' => $post->id,
            'status' => $status,
            'stored' => count($map),
        ]);

        PurgeBlogCache::dispatch($urls->forPost($post));
    }

    /** @param  array<string, string>  $failures */
    private function describe(array $failures): string
    {
        return count($failures).' image(s) failed: '.collect($failures)
            ->map(fn (string $error, string $url) => "{$url} ({$error})")
            ->implode('; ');
    }
}
