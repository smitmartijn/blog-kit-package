<?php

namespace Lostdomain\BlogKit\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lostdomain\BlogKit\Database\Factories\AuthorFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $job_title
 * @property string|null $bio
 * @property string|null $avatar_url
 * @property string|null $website_url
 * @property list<array{platform: string, url: string}>|null $social_links
 * @property bool $is_default
 */
class Author extends Model
{
    /** @use HasFactory<AuthorFactory> */
    use HasFactory;

    /** Platforms offered in the admin, keyed by the value stored in social_links. */
    public const PLATFORMS = [
        'x' => 'X (Twitter)',
        'linkedin' => 'LinkedIn',
        'github' => 'GitHub',
        'bluesky' => 'Bluesky',
        'mastodon' => 'Mastodon',
        'threads' => 'Threads',
        'youtube' => 'YouTube',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'tiktok' => 'TikTok',
        'other' => 'Other',
    ];

    protected $table = 'blog_kit_authors';

    protected $guarded = ['id'];

    protected $attributes = [
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'is_default' => 'boolean',
        ];
    }

    protected static function newFactory(): AuthorFactory
    {
        return AuthorFactory::new();
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * The author new posts get when nobody picks one (Marketing OS deliveries,
     * imports): the author marked default, else the first author created.
     */
    public static function default(): ?self
    {
        return static::query()->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** Makes this the only default author. */
    public function makeDefault(): void
    {
        static::query()->whereKeyNot($this->id)->where('is_default', true)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }

    public function url(): string
    {
        return route('blog-kit.author', $this->slug);
    }

    /**
     * Social profiles with a usable URL, labelled for display.
     *
     * @return list<array{platform: string, label: string, url: string}>
     */
    public function socialProfiles(): array
    {
        return collect($this->social_links ?? [])
            ->filter(fn ($link) => is_array($link) && filled($link['url'] ?? null) && preg_match('#^https?://#i', (string) $link['url']) === 1)
            ->map(fn (array $link) => [
                'platform' => (string) ($link['platform'] ?? 'other'),
                'label' => self::PLATFORMS[$link['platform'] ?? 'other'] ?? 'Link',
                'url' => (string) $link['url'],
            ])
            ->values()
            ->all();
    }

    /**
     * Profiles that identify this person elsewhere, for JSON-LD sameAs.
     *
     * @return list<string>
     */
    public function sameAs(): array
    {
        return collect($this->socialProfiles())
            ->pluck('url')
            ->prepend($this->website_url)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** "@handle" from an X/Twitter profile URL, for twitter:creator. */
    public function twitterHandle(): ?string
    {
        foreach ($this->socialProfiles() as $profile) {
            if ($profile['platform'] === 'x' && preg_match('#^https?://(?:www\.)?(?:x|twitter)\.com/@?([A-Za-z0-9_]{1,15})/?(?:[?\#].*)?$#i', $profile['url'], $match) === 1) {
                return '@'.$match[1];
            }
        }

        return null;
    }
}
