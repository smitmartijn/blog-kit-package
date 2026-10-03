<?php

namespace Lostdomain\BlogKit\Services;

use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Tag;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class SitemapBuilder
{
    public function build(): Sitemap
    {
        return Sitemap::create()->add($this->tags());
    }

    /** @return list<Url> */
    public function tags(): array
    {
        $posts = Post::live()->orderByDesc('published_at')->get();
        $tags = [];

        $index = Url::create(route('blog-kit.index'))->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY);

        if ($latest = $posts->max(fn (Post $post) => $post->modifiedAt())) {
            $index->setLastModificationDate($latest);
        }

        $tags[] = $index;

        foreach ($posts as $post) {
            $url = Url::create($post->url())->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY);

            if ($modified = $post->modifiedAt() ?? $post->published_at) {
                $url->setLastModificationDate($modified);
            }

            if ($image = $post->featuredImageUrl()) {
                $url->addImage($image, (string) ($post->featured_image['alt_text'] ?? ''));
            }

            $tags[] = $url;
        }

        Tag::whereHas('posts', fn ($query) => $query->live())
            ->orderBy('slug')
            ->get()
            ->each(function (Tag $tag) use (&$tags) {
                $tags[] = Url::create($tag->url())->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY);
            });

        return $tags;
    }
}
