<?php

namespace Lostdomain\BlogKit\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Exceptions\SlugUnavailable;
use Lostdomain\BlogKit\Jobs\ProcessPostImages;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Models\Tag;

/**
 * The single write path for posts, used by the API and the admin. Keeps
 * redirects, tags, image processing and cache purges consistent.
 */
class PostPublisher
{
    public function __construct(
        private CacheUrls $urls,
        private ImageLocalizer $images,
    ) {}

    /**
     * Creates or updates a post with new content.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $tags  null leaves tags untouched
     */
    public function save(Post $post, array $attributes, ?array $tags = null): Post
    {
        $before = $post->exists
            ? ['slug' => $post->getOriginal('slug'), 'tags' => $post->tags()->pluck('slug')->all()]
            : null;

        DB::transaction(function () use ($post, $attributes, $tags, $before) {
            $post->fill($attributes);
            $this->assertSlugAvailable($post);

            if ($post->exists) {
                $post->revision = $post->revision + 1;
            }

            $post->save();

            if ($before !== null && $before['slug'] !== $post->slug) {
                Redirect::updateOrCreate(['from_slug' => $before['slug']], ['post_id' => $post->id]);
            }

            // A slug that used to redirect now belongs to this post.
            Redirect::where('from_slug', $post->slug)->delete();

            if ($tags !== null) {
                $this->syncTags($post, $tags);
            }
        });

        $this->afterContentChange($post, $before);

        return $post;
    }

    public function unpublish(Post $post): Post
    {
        $post->forceFill([
            'unpublished_at' => $post->unpublished_at ?? now(),
            'revision' => $post->revision + 1,
        ])->save();

        Log::info('blog-kit.post.unpublished', ['post_id' => $post->id, 'slug' => $post->slug]);
        $this->purge($post);

        return $post;
    }

    public function hide(Post $post): Post
    {
        $post->forceFill(['hidden_at' => now()])->save();
        Log::info('blog-kit.post.hidden', ['post_id' => $post->id, 'slug' => $post->slug]);
        $this->purge($post);

        return $post;
    }

    public function restore(Post $post): Post
    {
        $post->forceFill(['hidden_at' => null, 'unpublished_at' => null, 'published_at' => $post->published_at ?? now()])->save();
        Log::info('blog-kit.post.restored', ['post_id' => $post->id, 'slug' => $post->slug]);
        $this->purge($post);

        return $post;
    }

    public function delete(Post $post): void
    {
        $urls = $this->urls->forPost($post);
        $post->delete();

        Log::info('blog-kit.post.deleted', ['post_id' => $post->id, 'slug' => $post->slug]);
        PurgeBlogCache::dispatch($urls)->afterCommit();
    }

    public function lock(Post $post, ?string $by): Post
    {
        if ($post->isFromMarketingOs()) {
            $post->forceFill(['locked_at' => $post->locked_at ?? now(), 'locked_by' => $by])->save();
        }

        return $post;
    }

    public function releaseLock(Post $post): Post
    {
        $post->forceFill(['locked_at' => null, 'locked_by' => null])->save();
        Log::info('blog-kit.post.lock_released', ['post_id' => $post->id, 'slug' => $post->slug]);

        return $post;
    }

    public function retryImages(Post $post): Post
    {
        $post->forceFill(['images_status' => Post::IMAGES_PENDING])->save();
        ProcessPostImages::dispatch($post->id, $post->revision)->afterCommit();

        return $post;
    }

    public function purge(Post $post): void
    {
        PurgeBlogCache::dispatch($this->urls->forPost($post))->afterCommit();
    }

    public function assertSlugAvailable(Post $post): void
    {
        if (in_array($post->slug, BlogKit::RESERVED_SLUGS, true) || preg_match('/^'.BlogKit::SLUG_PATTERN.'$/', $post->slug) !== 1) {
            throw new SlugUnavailable("The slug \"{$post->slug}\" is not allowed.");
        }

        $taken = Post::where('slug', $post->slug)
            ->when($post->exists, fn ($query) => $query->whereKeyNot($post->id))
            ->exists();

        if ($taken) {
            throw new SlugUnavailable("The slug \"{$post->slug}\" is already used by another post.");
        }
    }

    /** @param  array{slug: string, tags: list<string>}|null  $before */
    private function afterContentChange(Post $post, ?array $before): void
    {
        $removedTags = $before ? array_values(array_diff($before['tags'], $post->tags()->pluck('slug')->all())) : [];
        $oldSlugs = $before && $before['slug'] !== $post->slug ? [$before['slug']] : [];

        if ($this->images->remoteUrls($post) !== []) {
            $post->forceFill(['images_status' => Post::IMAGES_PENDING])->save();
            ProcessPostImages::dispatch($post->id, $post->revision)->afterCommit();

            // Pages the post just left can be purged now; its own pages are
            // purged once the images are local.
            if ($oldSlugs !== [] || $removedTags !== []) {
                PurgeBlogCache::dispatch($this->urls->forSlugs($oldSlugs, $removedTags))->afterCommit();
            }

            return;
        }

        $post->forceFill([
            'images_status' => $post->storedImages()->exists() ? Post::IMAGES_DONE : Post::IMAGES_NONE,
            'image_error' => null,
        ])->save();

        PurgeBlogCache::dispatch($this->urls->forPost($post, $oldSlugs, $removedTags))->afterCommit();
    }

    /** @param  list<string>  $names */
    private function syncTags(Post $post, array $names): void
    {
        $ids = collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn (string $name) => $name !== '' && Str::slug($name) !== '')
            ->unique(fn (string $name) => Str::slug($name))
            ->map(fn (string $name) => Tag::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->id)
            ->all();

        $post->tags()->sync($ids);
    }
}
