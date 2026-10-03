<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Illuminate\Contracts\View\View;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\SeoBuilder;

class IndexController
{
    public function __invoke(SeoBuilder $seo): View
    {
        $posts = Post::live()
            ->with('tags')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) config('blog-kit.per_page', 12));

        abort_if($posts->currentPage() > 1 && $posts->isEmpty(), 404);

        return view('blog-kit::index', [
            'posts' => $posts,
            'seo' => $seo->forIndex($posts),
        ]);
    }
}
