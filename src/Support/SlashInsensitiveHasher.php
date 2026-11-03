<?php

namespace Lostdomain\BlogKit\Support;

use Illuminate\Http\Request;
use Lostdomain\BlogKit\Http\Middleware\PublicBlogPage;
use Spatie\ResponseCache\Hasher\RequestHasher;

/**
 * /blog/x and /blog/x/ show the same page: give them one cache entry, so a
 * purge finds both. Other pages of the host keep the host's own hashing.
 */
class SlashInsensitiveHasher implements RequestHasher
{
    public function __construct(private RequestHasher $hasher) {}

    public function getHashFor(Request $request): string
    {
        $isBlogPage = $request->attributes->get('responsecache.cacheNameSuffix') === PublicBlogPage::CACHE_SUFFIX;

        if (! $isBlogPage || $request->getPathInfo() === '/' || ! str_ends_with($request->getPathInfo(), '/')) {
            return $this->hasher->getHashFor($request);
        }

        $copy = $request->duplicate();
        $copy->server->set('REQUEST_URI', preg_replace('#/+(\?.*)?$#', '$1', (string) $request->server->get('REQUEST_URI')));

        return $this->hasher->getHashFor($copy);
    }
}
