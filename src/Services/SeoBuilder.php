<?php

namespace Lostdomain\BlogKit\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Tag;
use Lostdomain\BlogKit\Support\Seo;
use Spatie\SchemaOrg\BaseType;
use Spatie\SchemaOrg\Schema;

class SeoBuilder
{
    public function forIndex(LengthAwarePaginator $posts): Seo
    {
        $title = (string) config('blog-kit.blog_title');

        if ($posts->currentPage() > 1) {
            $title .= ' – Page '.$posts->currentPage();
        }

        $canonical = $this->pageUrl(route('blog-kit.index'), $posts->currentPage());

        return new Seo(
            title: $this->title($title),
            description: (string) config('blog-kit.blog_description'),
            canonical: $canonical,
            image: config('blog-kit.publisher.logo'),
            jsonLd: [
                $this->toArray(Schema::blog()
                    ->name($title)
                    ->url($canonical)
                    ->description((string) config('blog-kit.blog_description'))
                    ->publisher($this->publisher())),
                $this->breadcrumbs([]),
            ],
            prev: $posts->currentPage() > 1 ? $this->pageUrl(route('blog-kit.index'), $posts->currentPage() - 1) : null,
            next: $posts->hasMorePages() ? $this->pageUrl(route('blog-kit.index'), $posts->currentPage() + 1) : null,
        );
    }

    public function forTag(Tag $tag, LengthAwarePaginator $posts): Seo
    {
        $title = $tag->name.' – '.config('blog-kit.blog_title');

        if ($posts->currentPage() > 1) {
            $title .= ' – Page '.$posts->currentPage();
        }

        return new Seo(
            title: $this->title($title),
            description: 'Articles about '.$tag->name.'.',
            canonical: $this->pageUrl($tag->url(), $posts->currentPage()),
            image: config('blog-kit.publisher.logo'),
            jsonLd: [$this->breadcrumbs([[$tag->name, $tag->url()]])],
            prev: $posts->currentPage() > 1 ? $this->pageUrl($tag->url(), $posts->currentPage() - 1) : null,
            next: $posts->hasMorePages() ? $this->pageUrl($tag->url(), $posts->currentPage() + 1) : null,
        );
    }

    public function forPost(Post $post): Seo
    {
        $image = $post->featured_image;

        return new Seo(
            title: $this->title($post->seoTitle()),
            description: $post->seoDescription(),
            canonical: $post->url(),
            type: 'article',
            image: $image['url'] ?? config('blog-kit.publisher.logo'),
            imageAlt: $image['alt_text'] ?? null,
            imageWidth: isset($image['width']) ? (int) $image['width'] : null,
            imageHeight: isset($image['height']) ? (int) $image['height'] : null,
            publishedTime: $post->published_at,
            modifiedTime: $post->modifiedAt(),
            tags: $post->tags->pluck('name')->all(),
            jsonLd: array_values(array_filter([
                $this->blogPosting($post),
                $this->faqPage($post),
                $this->breadcrumbs([[$post->title, $post->url()]]),
            ])),
        );
    }

    /** @return array<string, mixed> */
    public function blogPosting(Post $post): array
    {
        $images = collect([$post->featuredImageUrl()])
            ->merge(collect($post->images ?? [])->pluck('url'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $posting = Schema::blogPosting()
            ->headline($post->title)
            ->description($post->seoDescription())
            ->url($post->url())
            ->mainEntityOfPage(Schema::webPage()->identifier($post->url()))
            ->datePublished($post->published_at)
            ->dateModified($post->modifiedAt() ?? $post->published_at)
            ->wordCount($post->wordCount())
            ->inLanguage(str_replace('_', '-', (string) config('blog-kit.locale')))
            ->publisher($this->publisher());

        if ($author = $this->author()) {
            $posting->author($author);
        }

        if ($images !== []) {
            $posting->image($images);
        }

        if ($post->tags->isNotEmpty()) {
            $posting->keywords($post->tags->pluck('name')->implode(', '));
        }

        return $this->toArray($posting);
    }

    /** @return array<string, mixed>|null */
    public function faqPage(Post $post): ?array
    {
        $items = $post->faqItems();

        if ($items === []) {
            return null;
        }

        return $this->toArray(Schema::fAQPage()->mainEntity(array_map(
            fn (array $item) => Schema::question()
                ->name($item['question'])
                ->acceptedAnswer(Schema::answer()->text($item['answer'])),
            $items,
        )));
    }

    /**
     * Home > Blog > ...$trail
     *
     * @param  list<array{0: string, 1: string}>  $trail
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $trail): array
    {
        $crumbs = [
            [(string) config('blog-kit.site_name'), url('/')],
            [(string) config('blog-kit.blog_title'), route('blog-kit.index')],
            ...$trail,
        ];

        return $this->toArray(Schema::breadcrumbList()->itemListElement(array_map(
            fn (array $crumb, int $index) => Schema::listItem()->position($index + 1)->name($crumb[0])->item($crumb[1]),
            $crumbs,
            array_keys($crumbs),
        )));
    }

    private function title(string $title): string
    {
        return strtr((string) config('blog-kit.title_format', ':title | :site'), [
            ':title' => $title,
            ':site' => (string) config('blog-kit.site_name'),
        ]);
    }

    private function pageUrl(string $base, int $page): string
    {
        return $page > 1 ? $base.'?page='.$page : $base;
    }

    private function publisher(): BaseType
    {
        $publisher = Schema::organization()
            ->name((string) (config('blog-kit.publisher.name') ?: config('blog-kit.site_name')));

        if ($url = config('blog-kit.publisher.url')) {
            $publisher->url($url);
        }

        if ($logo = config('blog-kit.publisher.logo')) {
            $publisher->logo(Schema::imageObject()->url($logo));
        }

        return $publisher;
    }

    private function author(): ?BaseType
    {
        if (blank(config('blog-kit.author.name'))) {
            return null;
        }

        $author = Schema::person()->name((string) config('blog-kit.author.name'));

        if ($url = config('blog-kit.author.url')) {
            $author->url($url);
        }

        return $author;
    }

    /** @return array<string, mixed> */
    private function toArray(BaseType $type): array
    {
        return $type->toArray();
    }
}
