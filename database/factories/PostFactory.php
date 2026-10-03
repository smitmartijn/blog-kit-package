<?php

namespace Lostdomain\BlogKit\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lostdomain\BlogKit\Models\Post;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        $title = rtrim($this->faker->unique()->sentence(5), '.');

        return [
            'source' => Post::SOURCE_MARKETING_OS,
            'content_item_id' => (string) $this->faker->unique()->numberBetween(1, 1_000_000),
            'product_id' => '1',
            'slug' => str($title)->slug()->toString(),
            'title' => $title,
            'meta_description' => $this->faker->sentence(12),
            'excerpt' => $this->faker->sentence(20),
            'body_html' => '<p>'.$this->faker->paragraph(8).'</p>',
            'published_at' => now()->subDay(),
            'source_updated_at' => now()->subDay(),
        ];
    }

    public function local(): static
    {
        return $this->state(['source' => Post::SOURCE_LOCAL, 'content_item_id' => null, 'product_id' => null]);
    }

    public function unpublished(): static
    {
        return $this->state(['unpublished_at' => now()]);
    }

    public function hidden(): static
    {
        return $this->state(['hidden_at' => now()]);
    }

    public function locked(): static
    {
        return $this->state(['locked_at' => now(), 'locked_by' => 'editor@example.test']);
    }
}
