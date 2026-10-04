<?php

namespace Lostdomain\BlogKit\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Lostdomain\BlogKit\Database\Factories\PostFactory;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;

/**
 * @property int $id
 * @property string $source
 * @property string|null $content_item_id
 * @property string $slug
 * @property string $title
 * @property array{url?: string, alt_text?: string|null, width?: int|null, height?: int|null}|null $featured_image
 * @property string $body_html
 * @property int $revision
 * @property string $images_status
 */
class Post extends Model implements Feedable
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    public const SOURCE_MARKETING_OS = 'marketing_os';

    public const SOURCE_LOCAL = 'local';

    /** body_html is the source (Marketing OS deliveries, raw HTML edits). */
    public const FORMAT_HTML = 'html';

    /** body_markdown is the source; body_html is rendered from it on save. */
    public const FORMAT_MARKDOWN = 'markdown';

    public const IMAGES_NONE = 'none';

    public const IMAGES_PENDING = 'pending';

    public const IMAGES_DONE = 'done';

    public const IMAGES_FAILED = 'failed';

    protected $table = 'blog_kit_posts';

    protected $guarded = ['id'];

    protected $attributes = [
        'source' => self::SOURCE_MARKETING_OS,
        'body_format' => self::FORMAT_HTML,
        'images_status' => self::IMAGES_NONE,
        'revision' => 1,
    ];

    protected function casts(): array
    {
        return [
            'featured_image' => 'array',
            'faq' => 'array',
            'cta' => 'array',
            'internal_links' => 'array',
            'external_sources' => 'array',
            'schema_suggestions' => 'array',
            'images' => 'array',
            'secondary_keywords' => 'array',
            'published_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'hidden_at' => 'datetime',
            'locked_at' => 'datetime',
            'images_processed_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'blog_kit_post_tag', 'post_id', 'tag_id')->orderBy('name');
    }

    /** @return HasMany<Redirect, $this> */
    public function redirects(): HasMany
    {
        return $this->hasMany(Redirect::class);
    }

    /** @return HasMany<Image, $this> */
    public function storedImages(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    /** @param Builder<Post> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('unpublished_at')
            ->whereNull('hidden_at')
            ->whereNotNull('published_at');
    }

    public function isLive(): bool
    {
        return $this->unpublished_at === null && $this->hidden_at === null && $this->published_at !== null;
    }

    public function isUnpublished(): bool
    {
        return $this->unpublished_at !== null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isFromMarketingOs(): bool
    {
        return $this->source === self::SOURCE_MARKETING_OS;
    }

    /** One word for admin badges and logs. */
    public function status(): string
    {
        return match (true) {
            $this->isUnpublished() => 'unpublished',
            $this->isHidden() => 'hidden',
            $this->published_at === null => 'draft',
            default => 'live',
        };
    }

    public function url(): string
    {
        return route('blog-kit.show', $this->slug);
    }

    public function seoTitle(): string
    {
        return filled($this->meta_title) ? $this->meta_title : $this->title;
    }

    public function seoDescription(): string
    {
        return (string) ($this->meta_description ?: $this->excerpt ?: Str::limit(strip_tags($this->body_html), 160));
    }

    public function featuredImageUrl(): ?string
    {
        return $this->featured_image['url'] ?? null;
    }

    public function wordCount(): int
    {
        return str_word_count(strip_tags($this->body_html));
    }

    public function readingTime(): int
    {
        return max(1, (int) ceil($this->wordCount() / max(1, (int) config('blog-kit.reading_words_per_minute', 220))));
    }

    public function modifiedAt(): ?CarbonInterface
    {
        return $this->source_updated_at ?? $this->updated_at;
    }

    /** CTA as {text, url|null}; Marketing OS sends a plain string, but tolerate an object. */
    public function ctaData(): ?array
    {
        $cta = $this->cta;

        if (is_string($cta) && trim($cta) !== '') {
            return ['text' => $cta, 'url' => null, 'label' => null];
        }

        if (is_array($cta) && filled($cta['text'] ?? null)) {
            return ['text' => (string) $cta['text'], 'url' => $cta['url'] ?? null, 'label' => $cta['label'] ?? null];
        }

        return null;
    }

    /** @return list<array{question: string, answer: string}> */
    public function faqItems(): array
    {
        return collect($this->faq ?? [])
            ->filter(fn ($item) => is_array($item) && filled($item['question'] ?? null) && filled($item['answer'] ?? null))
            ->map(fn (array $item) => ['question' => (string) $item['question'], 'answer' => (string) $item['answer']])
            ->values()
            ->all();
    }

    public function toFeedItem(): FeedItem
    {
        return FeedItem::create()
            ->id($this->url())
            ->title($this->title)
            ->summary($this->excerpt ?: $this->seoDescription())
            ->updated($this->modifiedAt() ?? $this->published_at ?? now())
            ->link($this->url())
            ->authorName((string) (config('blog-kit.author.name') ?: config('blog-kit.publisher.name') ?: config('blog-kit.site_name')))
            ->category(...$this->tags->pluck('name')->all());
    }
}
