<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Lostdomain\BlogKit\Models\Post;
use Spatie\Feed\Feed;

class FeedController
{
    public function rss(): Feed
    {
        return $this->feed('rss', route('blog-kit.feed.rss'));
    }

    public function atom(): Feed
    {
        return $this->feed('atom', route('blog-kit.feed.atom'));
    }

    private function feed(string $format, string $url): Feed
    {
        $posts = Post::live()
            ->with('tags')
            ->orderByDesc('published_at')
            ->limit((int) config('blog-kit.feed.items', 20))
            ->get();

        return new Feed(
            title: config('blog-kit.blog_title').' – '.config('blog-kit.site_name'),
            items: $posts,
            url: $url,
            view: "feed::{$format}",
            description: (string) config('blog-kit.blog_description'),
            language: (string) config('blog-kit.feed.language', 'en-US'),
            image: (string) config('blog-kit.publisher.logo'),
            format: $format,
        );
    }
}
