<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts;

use Illuminate\Support\Facades\Storage;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\MarkdownRenderer;

/** Converts between Post records and the admin form state. */
class PostFormData
{
    /** @return array<string, mixed> */
    public static function fill(Post $post, array $data): array
    {
        $data['tags'] = $post->tags->pluck('name')->all();
        $data['cta'] = $post->ctaData()['text'] ?? null;
        $data['body_format'] = self::editorFormat($post);

        return $data;
    }

    /**
     * Markdown whenever it is the source of truth. That includes untouched
     * Marketing OS deliveries, which always carry the Markdown they were
     * rendered from. Hand-edited HTML stays HTML: its Markdown is stale.
     */
    public static function editorFormat(Post $post): string
    {
        if ($post->body_format === Post::FORMAT_MARKDOWN) {
            return Post::FORMAT_MARKDOWN;
        }

        $delivered = $post->isFromMarketingOs() && ! $post->isLocked();

        return $delivered && filled($post->body_markdown) ? Post::FORMAT_MARKDOWN : Post::FORMAT_HTML;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function toAttributes(array $data): array
    {
        $tags = array_values(array_filter((array) ($data['tags'] ?? []), 'filled'));
        $upload = $data['featured_upload'] ?? null;
        $featured = (array) ($data['featured_image'] ?? []);

        if (is_array($upload)) {
            $upload = array_values($upload)[0] ?? null;
        }

        if (filled($upload)) {
            $featured['url'] = Storage::disk(config('blog-kit.images.disk'))->url($upload);
        }

        $data['featured_image'] = filled($featured['url'] ?? null) ? [
            'url' => (string) $featured['url'],
            'alt_text' => $featured['alt_text'] ?? null,
            'width' => filled($featured['width'] ?? null) ? (int) $featured['width'] : null,
            'height' => filled($featured['height'] ?? null) ? (int) $featured['height'] : null,
        ] : null;

        $data['faq'] = array_values((array) ($data['faq'] ?? [])) ?: null;
        $data['cta'] = filled($data['cta'] ?? null) ? (string) $data['cta'] : null;

        $data['body_format'] = ($data['body_format'] ?? Post::FORMAT_MARKDOWN) === Post::FORMAT_HTML
            ? Post::FORMAT_HTML
            : Post::FORMAT_MARKDOWN;

        if ($data['body_format'] === Post::FORMAT_MARKDOWN) {
            $data['body_html'] = app(MarkdownRenderer::class)->toHtml((string) ($data['body_markdown'] ?? ''));
        }

        unset($data['tags'], $data['featured_upload']);

        return [$data, $tags];
    }
}
