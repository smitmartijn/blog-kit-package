<?php

namespace Lostdomain\BlogKit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'blog-kit:install {--force : Overwrite an existing config/blog-kit.php}';

    protected $description = 'Publish the Blog Kit config and print the remaining setup steps';

    public function handle(): int
    {
        $this->call('vendor:publish', array_filter([
            '--tag' => 'blog-kit-config',
            '--force' => $this->option('force'),
        ]));

        // The Atom feed view references /vendor/feed/atom.xsl for browser display.
        $this->call('vendor:publish', ['--tag' => 'feed-assets']);

        $prefix = trim((string) config('blog-kit.route_prefix', 'blog'), '/');
        $endpoint = rtrim((string) config('app.url'), '/').'/'.($prefix === '' ? '' : $prefix.'/').'api/marketing-os/v1/posts';

        $this->newLine();
        $this->components->info('Blog Kit config published. Remaining steps:');

        $this->line('<options=bold>1. Environment</> (add to .env, then to your deploy secrets):');
        $this->line('   BLOG_KIT_API_TOKEN='.Str::random(48));
        $this->line('   BLOG_KIT_SIGNING_SECRET='.Str::random(64));
        $this->line('   BLOG_KIT_PRODUCT_ID=            # Marketing OS product id, optional guard');
        $this->line('   BLOG_KIT_LAYOUT=layouts.app     # the layout the blog views @extends');
        $this->line('   BLOG_KIT_AUTHOR_NAME=  BLOG_KIT_PUBLISHER_LOGO=');
        $this->line('   BLOG_KIT_CLOUDFLARE_ZONE_ID=  BLOG_KIT_CLOUDFLARE_API_TOKEN=   # token needs Zone > Cache Purge');
        $this->line('   RESPONSE_CACHE_DRIVER=redis     # store for the full-page cache');
        $this->newLine();

        $this->line('<options=bold>2. Database</>: php artisan migrate');
        $this->line('<options=bold>3. Images</>: php artisan storage:link (public disk), and a queue worker (Horizon).');
        $this->newLine();

        $this->line('<options=bold>4. Layout</>: your layout needs @yield(\''.config('blog-kit.sections.content').'\'), @yield(\''.config('blog-kit.sections.title').'\') in <title>, and @stack(\''.config('blog-kit.head_stack').'\') in <head>.');
        $this->newLine();

        $this->line('<options=bold>5. Tailwind v4</>: add to resources/css/app.css:');
        $this->line('   @source "../../vendor/lostdomain/blog-kit/resources/views";');
        $this->newLine();

        $this->line('<options=bold>6. Filament</> (optional): ->plugin(\Lostdomain\BlogKit\Filament\BlogKitPlugin::make()) in your panel provider,');
        $this->line('   and Gate::define(\''.config('blog-kit.filament.gate').'\', fn ($user) => ...) in a service provider.');
        $this->newLine();

        $this->line('<options=bold>7. Marketing OS</>: add a Blog Kit destination with');
        $this->line("   endpoint {$endpoint}");
        $this->line('   and the same token and signing secret.');

        return self::SUCCESS;
    }
}
