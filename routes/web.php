<?php

use Illuminate\Support\Facades\Route;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Http\Controllers\FeedController;
use Lostdomain\BlogKit\Http\Controllers\IndexController;
use Lostdomain\BlogKit\Http\Controllers\PostController;
use Lostdomain\BlogKit\Http\Controllers\ReceivePostController;
use Lostdomain\BlogKit\Http\Controllers\SitemapController;
use Lostdomain\BlogKit\Http\Controllers\TagController;
use Lostdomain\BlogKit\Http\Middleware\VerifyMarketingOsRequest;

Route::group([
    'prefix' => config('blog-kit.route_prefix'),
    'domain' => config('blog-kit.domain'),
    'as' => 'blog-kit.',
], function () {
    Route::post('api/marketing-os/v1/posts', ReceivePostController::class)
        ->middleware([...(array) config('blog-kit.api.middleware', []), VerifyMarketingOsRequest::class])
        ->name('api.posts');

    Route::middleware(BlogKit::publicMiddleware())->group(function () {
        Route::get('/', IndexController::class)->name('index');
        Route::get('rss.xml', [FeedController::class, 'rss'])->name('feed.rss');
        Route::get('atom.xml', [FeedController::class, 'atom'])->name('feed.atom');
        Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
        Route::get('tag/{tag}', TagController::class)->name('tag')->where('tag', BlogKit::SLUG_PATTERN);
        Route::get('{slug}', PostController::class)->name('show')->where('slug', BlogKit::SLUG_PATTERN);
    });
});
