<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Illuminate\Contracts\View\View;
use Lostdomain\BlogKit\Models\Tag;
use Lostdomain\BlogKit\Services\SeoBuilder;

class TagController
{
    public function __invoke(string $tag, SeoBuilder $seo): View
    {
        $tag = Tag::where('slug', $tag)->firstOrFail();

        $posts = $tag->posts()
            ->live()
            ->with('tags')
            ->orderByDesc('published_at')
            ->orderByDesc('blog_kit_posts.id')
            ->paginate((int) config('blog-kit.per_page', 12));

        abort_if($posts->isEmpty(), 404);

        return view('blog-kit::tag', [
            'tag' => $tag,
            'posts' => $posts,
            'seo' => $seo->forTag($tag, $posts),
        ]);
    }
}
