<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Models\Redirect;

/**
 * Old blog URLs that aren't a valid slug, for sites moved from Docusaurus or
 * VuePress: dated paths (2024/06/07/name), dots (name-6.0-issue), .html,
 * and Docusaurus' tags/{tag} and page/{n}. Posts are looked up in the
 * redirect table; anything unknown is a 404.
 */
class LegacyUrlController
{
    public function __invoke(string $path): Response|RedirectResponse
    {
        $path = trim($path, '/');

        if (preg_match('#^tags/('.BlogKit::SLUG_PATTERN.')$#', $path, $match)) {
            return redirect()->route('blog-kit.tag', $match[1], 301);
        }

        if (preg_match('#^page/([1-9]\d*)$#', $path, $match)) {
            return redirect()->to(route('blog-kit.index').($match[1] === '1' ? '' : '?page='.$match[1]), 301);
        }

        if (in_array($path, ['archive', 'tags'], true)) {
            return redirect()->route('blog-kit.index', [], 301);
        }

        $candidates = array_unique([$path, strtolower($path), preg_replace('#(/index)?\.html$#', '', $path)]);
        $post = Redirect::with('post')->whereIn('from_slug', $candidates)->first()?->post;

        abort_if(! $post, 404);

        if ($post->isUnpublished()) {
            return response()->view('blog-kit::gone', ['post' => $post], 410);
        }

        abort_unless($post->isLive(), 404);

        return redirect()->to($post->url(), 301);
    }
}
