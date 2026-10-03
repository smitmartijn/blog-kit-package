<?php

namespace Lostdomain\BlogKit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remote image referenced by a post, and where it was stored locally.
 *
 * @property int $id
 * @property int $post_id
 * @property string $source_url
 * @property string $status
 * @property string|null $url
 * @property string|null $error
 */
class Image extends Model
{
    public const STORED = 'stored';

    public const FAILED = 'failed';

    protected $table = 'blog_kit_images';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public static function hashFor(string $url): string
    {
        return hash('sha256', $url);
    }
}
