<?php

namespace Lostdomain\BlogKit\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Exceptions\CachePurgeFailed;
use Lostdomain\BlogKit\Http\Middleware\PublicBlogPage;
use Spatie\ResponseCache\ResponseCache;

/** Forgets URLs from the local full-page cache, then from Cloudflare. */
class BlogCachePurger
{
    public function __construct(
        private ResponseCache $responseCache,
        private CloudflarePurger $cloudflare,
    ) {}

    /**
     * @param  list<string>  $urls
     *
     * @throws CachePurgeFailed when a page is still cached afterwards
     */
    public function purge(array $urls): void
    {
        $urls = array_values(array_unique($urls));

        if ($urls === []) {
            return;
        }

        // One URL per call: responsecache 8.4.0 stops a multi-URL forget()
        // at the first URL that isn't cached.
        foreach ($urls as $url) {
            $this->responseCache->selectCachedItems()
                ->usingSuffix(PublicBlogPage::CACHE_SUFFIX)
                ->forUrls([$url])
                ->forget();
        }

        $this->assertForgotten($urls);

        Log::info('blog-kit.cache.local_purged', ['url_count' => count($urls)]);

        $this->cloudflare->purge($this->withSlashVariants($urls));
    }

    /**
     * The cache doesn't report a failed forget: a file store this process may
     * not write to (a CLI user other than the web server's) silently keeps
     * the page. So check. Cloudflare can wait: it would refill from this cache.
     *
     * @param  list<string>  $urls
     */
    private function assertForgotten(array $urls): void
    {
        $stuck = array_values(array_filter($urls, function (string $url) {
            $request = Request::create($url);
            $request->attributes->set('responsecache.cacheNameSuffix', PublicBlogPage::CACHE_SUFFIX);

            return $this->responseCache->hasBeenCached($request);
        }));

        if ($stuck === []) {
            return;
        }

        Log::error('blog-kit.cache.local_purge_failed', [
            'url_count' => count($urls),
            'stuck_count' => count($stuck),
            'first_stuck' => $stuck[0],
        ]);

        throw new CachePurgeFailed(sprintf(
            '%d of %d pages are still cached after purging them, first %s. Does this user (%s) have write access to the response cache store?',
            count($stuck), count($urls), $stuck[0], get_current_user(),
        ));
    }

    /**
     * Locally /blog/x and /blog/x/ share an entry, but Cloudflare caches each
     * URL separately. Feeds and the sitemap are only linked without a slash.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function withSlashVariants(array $urls): array
    {
        $variants = [];

        foreach ($urls as $url) {
            [$path, $query] = array_pad(explode('?', $url, 2), 2, null);

            if (! str_ends_with($path, '.xml') && ! str_ends_with($path, '/')) {
                $variants[] = $path.'/'.($query === null ? '' : '?'.$query);
            }
        }

        return array_values(array_unique([...$urls, ...$variants]));
    }
}
