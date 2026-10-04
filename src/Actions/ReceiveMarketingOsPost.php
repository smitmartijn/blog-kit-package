<?php

namespace Lostdomain\BlogKit\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Exceptions\PostLocked;
use Lostdomain\BlogKit\Exceptions\UnknownPost;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\PostPublisher;

/**
 * Applies a validated Marketing OS delivery. Idempotent: the post is keyed
 * by content_item_id, and a redelivery or an older update changes nothing.
 */
class ReceiveMarketingOsPost
{
    public function __construct(private PostPublisher $publisher) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(array $payload): Post
    {
        Log::info('blog-kit.api.received', Arr::only($payload, ['event', 'delivery_id', 'content_item_id', 'slug']));

        $post = Post::where('content_item_id', (string) $payload['content_item_id'])->first();

        if ($payload['event'] === 'unpublish') {
            if (! $post) {
                throw new UnknownPost("No post with content_item_id {$payload['content_item_id']} exists here.");
            }

            $post->forceFill(['last_delivery_id' => $payload['delivery_id']]);

            return $this->publisher->unpublish($post);
        }

        $post ??= new Post(['source' => Post::SOURCE_MARKETING_OS]);

        if ($post->isLocked()) {
            Log::warning('blog-kit.api.post_locked', [
                'post_id' => $post->id,
                'content_item_id' => $post->content_item_id,
                'delivery_id' => $payload['delivery_id'],
                'locked_by' => $post->locked_by,
            ]);

            throw new PostLocked("Post {$post->id} was edited on this site and is locked against Marketing OS updates. Release the lock in the admin to accept updates.");
        }

        $sourceUpdatedAt = Carbon::parse($payload['updated_at'] ?? $payload['sent_at'] ?? now());

        if ($post->exists && $post->source_updated_at && $sourceUpdatedAt->lt($post->source_updated_at)) {
            Log::notice('blog-kit.api.stale_update_ignored', [
                'post_id' => $post->id,
                'delivery_id' => $payload['delivery_id'],
                'received_updated_at' => $sourceUpdatedAt->toIso8601String(),
                'stored_updated_at' => $post->source_updated_at->toIso8601String(),
            ]);

            return $post;
        }

        return $this->publisher->save($post, [
            'source' => Post::SOURCE_MARKETING_OS,
            'content_item_id' => (string) $payload['content_item_id'],
            'product_id' => (string) $payload['product_id'],
            'slug' => $payload['slug'],
            'title' => $payload['title'],
            'meta_title' => $payload['meta_title'] ?? null,
            'meta_description' => $payload['meta_description'] ?? null,
            'excerpt' => $payload['excerpt'] ?? null,
            'featured_image' => $this->featuredImage($payload['featured_image'] ?? null),
            // Deliveries are served exactly as Marketing OS rendered them.
            'body_format' => Post::FORMAT_HTML,
            'body_html' => $payload['body_html'],
            'body_markdown' => $payload['body_markdown'] ?? null,
            'faq' => $payload['faq'] ?? null,
            'cta' => $payload['cta'] ?? null,
            'internal_links' => $payload['internal_links'] ?? null,
            'external_sources' => $payload['external_sources'] ?? null,
            'schema_suggestions' => $payload['schema_suggestions'] ?? null,
            'images' => $payload['images'] ?? null,
            'target_keyword' => $payload['target_keyword'] ?? null,
            'secondary_keywords' => $payload['secondary_keywords'] ?? null,
            'post_type' => $payload['post_type'] ?? null,
            'published_at' => isset($payload['published_at']) ? Carbon::parse($payload['published_at']) : ($post->published_at ?? now()),
            'source_updated_at' => $sourceUpdatedAt,
            'unpublished_at' => null,
            'last_delivery_id' => $payload['delivery_id'],
        ], array_values($payload['tags'] ?? []));
    }

    /** @return array{url: string, alt_text: string|null, width: int|null, height: int|null}|null */
    private function featuredImage(?array $image): ?array
    {
        if (blank($image['url'] ?? null)) {
            return null;
        }

        return [
            'url' => (string) $image['url'],
            'alt_text' => $image['alt_text'] ?? null,
            'width' => isset($image['width']) ? (int) $image['width'] : null,
            'height' => isset($image['height']) ? (int) $image['height'] : null,
        ];
    }
}
