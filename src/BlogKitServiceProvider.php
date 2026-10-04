<?php

namespace Lostdomain\BlogKit;

use Illuminate\Support\ServiceProvider;
use Lostdomain\BlogKit\Console\InstallCommand;
use Lostdomain\BlogKit\Services\HtmlSanitizer;
use Lostdomain\BlogKit\Services\MarkdownRenderer;
use Lostdomain\BlogKit\Support\Background;

class BlogKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blog-kit.php', 'blog-kit');

        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(MarkdownRenderer::class);
        $this->app->singleton(Background::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'blog-kit');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/blog-kit.php' => config_path('blog-kit.php'),
            ], 'blog-kit-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/blog-kit'),
            ], 'blog-kit-views');

            $this->commands([InstallCommand::class]);
        }
    }
}
