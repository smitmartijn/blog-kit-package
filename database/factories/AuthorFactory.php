<?php

namespace Lostdomain\BlogKit\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lostdomain\BlogKit\Models\Author;

/** @extends Factory<Author> */
class AuthorFactory extends Factory
{
    protected $model = Author::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->name();

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'job_title' => $this->faker->jobTitle(),
            'bio' => $this->faker->sentence(16),
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
