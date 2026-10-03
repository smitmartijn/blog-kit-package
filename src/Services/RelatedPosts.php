<?php

namespace Lostdomain\BlogKit\Services;

use Illuminate\Database\Eloquent\Collection;
use Lostdomain\BlogKit\Models\Post;

/** Posts sharing the most tags first, then the most recent. */
class RelatedPosts
{
    /** @return Collection<int, Post> */
    public function for(Post $post): Collection
    {
        $limit = (int) config('blog-kit.related_count', 3);

        if ($limit < 1) {
            return new Collection;
        }

        $tagIds = $post->tags->pluck('id')->all();
        $related = new Collection;

        if ($tagIds !== []) {
            $related = Post::live()
                ->whereKeyNot($post->id)
                ->whereHas('tags', fn ($query) => $query->whereIn('blog_kit_tags.id', $tagIds))
                ->withCount(['tags as shared_tags_count' => fn ($query) => $query->whereIn('blog_kit_tags.id', $tagIds)])
                ->orderByDesc('shared_tags_count')
                ->orderByDesc('published_at')
                ->limit($limit)
                ->get();
        }

        if ($related->count() < $limit) {
            $related = $related->concat(Post::live()
                ->whereKeyNot([$post->id, ...$related->modelKeys()])
                ->orderByDesc('published_at')
                ->limit($limit - $related->count())
                ->get());
        }

        return $related->load('tags');
    }
}
