<?php

namespace Lostdomain\BlogKit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $from_slug
 * @property int $post_id
 */
class Redirect extends Model
{
    protected $table = 'blog_kit_redirects';

    protected $guarded = ['id'];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
