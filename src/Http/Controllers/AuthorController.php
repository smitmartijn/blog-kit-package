<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Illuminate\Contracts\View\View;
use Lostdomain\BlogKit\Models\Author;
use Lostdomain\BlogKit\Services\SeoBuilder;

class AuthorController
{
    public function __invoke(string $author, SeoBuilder $seo): View
    {
        $author = Author::where('slug', $author)->firstOrFail();

        $posts = $author->posts()
            ->live()
            ->with(['tags', 'author'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) config('blog-kit.per_page', 12));

        // An author without posts still has a page; past the last page there is none.
        abort_if($posts->currentPage() > 1 && $posts->isEmpty(), 404);

        return view('blog-kit::author', [
            'author' => $author,
            'posts' => $posts,
            'seo' => $seo->forAuthor($author, $posts),
        ]);
    }
}
