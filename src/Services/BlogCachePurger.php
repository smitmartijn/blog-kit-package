<?php

namespace Lostdomain\BlogKit\Services;

use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Http\Middleware\PublicBlogPage;
use Spatie\ResponseCache\ResponseCache;

/** Forgets URLs from the local full-page cache, then from Cloudflare. */
class BlogCachePurger
{
    public function __construct(
        private ResponseCache $responseCache,
        private CloudflarePurger $cloudflare,
    ) {}

    /** @param  list<string>  $urls */
    public function purge(array $urls): void
    {
        $urls = array_values(array_unique($urls));

        if ($urls === []) {
            return;
        }

        $this->responseCache->selectCachedItems()
            ->usingSuffix(PublicBlogPage::CACHE_SUFFIX)
            ->forUrls($urls)
            ->forget();

        Log::info('blog-kit.cache.local_purged', ['url_count' => count($urls)]);

        $this->cloudflare->purge($urls);
    }
}
