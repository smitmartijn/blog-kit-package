<?php

namespace Lostdomain\BlogKit\Services;

use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Models\Tag;

/**
 * Every public URL whose response may change when a post changes: the
 * article (current and redirected slugs), all index and tag pages, the
 * feeds and the sitemap.
 */
class CacheUrls
{
    /**
     * Every public URL of the blog.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->forSlugs(
            [...Post::pluck('slug')->all(), ...Redirect::pluck('from_slug')->all()],
            Tag::pluck('slug')->all(),
        );
    }

    /**
     * @param  list<string>  $extraSlugs  slugs the post no longer answers to (e.g. before a rename)
     * @param  list<string>  $extraTagSlugs  tags the post was removed from
     * @return list<string>
     */
    public function forPost(Post $post, array $extraSlugs = [], array $extraTagSlugs = []): array
    {
        $slugs = [$post->slug, ...$post->redirects()->pluck('from_slug')->all(), ...$extraSlugs];
        $tagSlugs = [...$post->tags()->pluck('slug')->all(), ...$extraTagSlugs];

        return $this->forSlugs($slugs, $tagSlugs);
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $tagSlugs
     * @return list<string>
     */
    public function forSlugs(array $slugs, array $tagSlugs): array
    {
        $urls = [];

        // Appended rather than route()d: imported legacy paths contain / and . .
        $base = route('blog-kit.index');

        foreach (array_unique($slugs) as $slug) {
            $urls[] = $base.'/'.$slug;
        }

        $urls = [...$urls, ...$this->indexUrls()];

        foreach (array_unique($tagSlugs) as $tagSlug) {
            $urls = [...$urls, ...$this->tagUrls($tagSlug)];
        }

        return array_values(array_unique([...$urls, ...$this->globalUrls()]));
    }

    /** @return list<string> */
    public function indexUrls(): array
    {
        return $this->paginatedUrls(route('blog-kit.index'), Post::live()->count());
    }

    /** @return list<string> */
    public function tagUrls(string $tagSlug): array
    {
        $tag = Tag::where('slug', $tagSlug)->first();
        $count = $tag ? $tag->posts()->live()->count() : 0;

        return $this->paginatedUrls(route('blog-kit.tag', $tagSlug), $count);
    }

    /** @return list<string> */
    public function globalUrls(): array
    {
        return [
            route('blog-kit.feed.rss'),
            route('blog-kit.feed.atom'),
            route('blog-kit.sitemap'),
        ];
    }

    /**
     * Page 1 plus ?page=1..n+1: one page beyond the current last page covers
     * a page that just emptied because a post went away.
     *
     * @return list<string>
     */
    private function paginatedUrls(string $base, int $count): array
    {
        $pages = (int) ceil($count / max(1, (int) config('blog-kit.per_page', 12))) + 1;
        $urls = [$base];

        for ($page = 1; $page <= $pages; $page++) {
            $urls[] = $base.'?page='.$page;
        }

        return $urls;
    }
}
