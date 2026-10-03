<?php

namespace Lostdomain\BlogKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes public blog pages shareable caches: one cache entry per URL for
 * every visitor, Cache-Control for the edge, and no cookies.
 */
class PublicBlogPage
{
    public const CACHE_SUFFIX = 'blog-kit';

    private const CACHEABLE_STATUSES = [200, 301, 410];

    public function handle(Request $request, Closure $next): Response
    {
        // Pages run without a session; layouts that read $errors still work.
        if (! View::shared('errors')) {
            View::share('errors', new ViewErrorBag);
        }

        $request->attributes->set('responsecache.cacheNameSuffix', self::CACHE_SUFFIX);

        $cacheable = $this->hasOnlyKnownQuery($request);

        if (! $cacheable) {
            // Arbitrary query strings would create cache entries no purge can find.
            $request->attributes->set('responsecache.doNotCache', true);
        }

        $response = $next($request);

        if ($cacheable && $request->isMethodCacheable() && in_array($response->getStatusCode(), self::CACHEABLE_STATUSES, true)) {
            $response->headers->set('Cache-Control', (string) config('blog-kit.cache.cache_control'));
            $response->headers->remove('Set-Cookie');
        }

        return $response;
    }

    private function hasOnlyKnownQuery(Request $request): bool
    {
        $ignored = (array) config('responsecache.ignored_query_parameters', []);

        foreach ($request->query() as $key => $value) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            if ($key === 'page' && is_string($value) && preg_match('/^[1-9]\d{0,5}$/', $value) === 1) {
                continue;
            }

            return false;
        }

        return true;
    }
}
