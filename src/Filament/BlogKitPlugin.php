<?php

namespace Lostdomain\BlogKit\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostResource;
use Lostdomain\BlogKit\Filament\Resources\Redirects\RedirectResource;
use UnitEnum;

/**
 * Adds the Blog section to a Filament 4 or 5 panel:
 * ->plugin(BlogKitPlugin::make()->navigationGroup('Blog'))
 */
class BlogKitPlugin implements Plugin
{
    protected string|UnitEnum|null $navigationGroup = 'Blog';

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'blog-kit';
    }

    public function navigationGroup(string|UnitEnum|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->navigationGroup;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            PostResource::class,
            RedirectResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
