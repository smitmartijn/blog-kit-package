<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Services\HtmlSanitizer;
use Lostdomain\BlogKit\Services\RelatedPosts;
use Lostdomain\BlogKit\Services\SeoBuilder;

class PostController
{
    public function __invoke(string $slug, SeoBuilder $seo, HtmlSanitizer $sanitizer, RelatedPosts $related): Response|RedirectResponse
    {
        $post = Post::with('tags')->where('slug', $slug)->first();

        if (! $post) {
            $post = Redirect::with('post.tags')->where('from_slug', $slug)->first()?->post;
            abort_if(! $post, 404);

            if ($post->isLive()) {
                return redirect()->to($post->url(), 301);
            }
        }

        if ($post->isUnpublished()) {
            return response()->view('blog-kit::gone', ['post' => $post], 410);
        }

        abort_unless($post->isLive(), 404);

        return response()->view('blog-kit::show', [
            'post' => $post,
            'body' => $sanitizer->sanitize($post->body_html),
            'related' => $related->for($post),
            'seo' => $seo->forPost($post),
        ]);
    }
}
