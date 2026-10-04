<?php

namespace Lostdomain\BlogKit;

use Lostdomain\BlogKit\Http\Middleware\PublicBlogPage;
use Lostdomain\BlogKit\Models\Post;
use Spatie\ResponseCache\Middlewares\CacheResponse;
use Spatie\Sitemap\Tags\Sitemap as SitemapTag;
use Spatie\Sitemap\Tags\Url;

class BlogKit
{
    /** Lowercase words joined by single hyphens. */
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** Path segments the package routes use; posts can't take these slugs. */
    public const RESERVED_SLUGS = ['api', 'tag', 'page', 'feed', 'rss', 'atom', 'sitemap'];

    /** @return list<string|class-string> */
    public static function publicMiddleware(): array
    {
        $middleware = [...(array) config('blog-kit.middleware', []), PublicBlogPage::class];

        if (config('blog-kit.cache.enabled')) {
            $lifetime = (int) config('blog-kit.cache.lifetime');

            // responsecache 8 encodes options via for(); 7 takes the lifetime as an argument.
            $middleware[] = method_exists(CacheResponse::class, 'for')
                ? CacheResponse::for($lifetime)
                : CacheResponse::class.':'.$lifetime;
        }

        return $middleware;
    }

    public static function sitemapUrl(): string
    {
        return route('blog-kit.sitemap');
    }

    /** An entry for the host's own SitemapIndex, e.g. SitemapIndex::create()->add(BlogKit::sitemapIndexEntry()). */
    public static function sitemapIndexEntry(): SitemapTag
    {
        $entry = SitemapTag::create(self::sitemapUrl());
        $latest = Post::live()->max('updated_at');

        if ($latest) {
            $entry->setLastModificationDate(now()->parse($latest));
        }

        return $entry;
    }

    /**
     * The blog's sitemap URLs, for hosts that merge them into a single sitemap.
     *
     * @return list<Url>
     */
    public static function sitemapTags(): array
    {
        return app(Services\SitemapBuilder::class)->tags();
    }
}
