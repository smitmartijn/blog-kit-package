<?php

namespace Lostdomain\BlogKit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class Tag extends Model
{
    protected $table = 'blog_kit_tags';

    protected $guarded = ['id'];

    /** @return BelongsToMany<Post, $this> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'blog_kit_post_tag', 'tag_id', 'post_id');
    }

    public function url(): string
    {
        return route('blog-kit.tag', $this->slug);
    }
}
