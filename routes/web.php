<?php

use Illuminate\Support\Facades\Route;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Http\Controllers\AuthorController;
use Lostdomain\BlogKit\Http\Controllers\FeedController;
use Lostdomain\BlogKit\Http\Controllers\IndexController;
use Lostdomain\BlogKit\Http\Controllers\LegacyUrlController;
use Lostdomain\BlogKit\Http\Controllers\PostController;
use Lostdomain\BlogKit\Http\Controllers\ReadApiController;
use Lostdomain\BlogKit\Http\Controllers\ReceivePostController;
use Lostdomain\BlogKit\Http\Controllers\SitemapController;
use Lostdomain\BlogKit\Http\Controllers\TagController;
use Lostdomain\BlogKit\Http\Middleware\VerifyMarketingOsRequest;
use Lostdomain\BlogKit\Http\Middleware\VerifyReadApiToken;

Route::group([
    'prefix' => config('blog-kit.route_prefix'),
    'domain' => config('blog-kit.domain'),
    'as' => 'blog-kit.',
], function () {
    Route::post('api/marketing-os/v1/posts', ReceivePostController::class)
        ->middleware([...(array) config('blog-kit.api.middleware', []), VerifyMarketingOsRequest::class])
        ->name('api.posts');

    // Read-only API for agents (BLOG_KIT_READ_TOKEN). Never cached: outside the public group.
    Route::prefix('api/v1')
        ->middleware([...(array) config('blog-kit.read_api.middleware', []), VerifyReadApiToken::class])
        ->name('read-api.')
        ->group(function () {
            Route::get('posts', [ReadApiController::class, 'index'])->name('posts.index');
            Route::get('posts/{post}', [ReadApiController::class, 'show'])->name('posts.show')->where('post', '[A-Za-z0-9-]+');
            Route::get('search', [ReadApiController::class, 'search'])->name('search');
        });

    Route::middleware(BlogKit::publicMiddleware())->group(function () {
        Route::get('/', IndexController::class)->name('index');
        Route::get('rss.xml', [FeedController::class, 'rss'])->name('feed.rss');
        Route::get('atom.xml', [FeedController::class, 'atom'])->name('feed.atom');
        Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
        Route::get('tag/{tag}', TagController::class)->name('tag')->where('tag', BlogKit::SLUG_PATTERN);
        Route::get('author/{author}', AuthorController::class)->name('author')->where('author', BlogKit::SLUG_PATTERN);
        Route::get('{slug}', PostController::class)->name('show')->where('slug', BlogKit::SLUG_PATTERN);
        // Last: old URLs from an imported Docusaurus/VuePress blog.
        Route::get('{path}', LegacyUrlController::class)->name('legacy')->where('path', '.+');
    });
});
