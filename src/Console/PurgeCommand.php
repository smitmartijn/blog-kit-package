<?php

namespace Lostdomain\BlogKit\Console;

use Illuminate\Console\Command;
use Lostdomain\BlogKit\Services\BlogCachePurger;
use Lostdomain\BlogKit\Services\CacheUrls;
use Throwable;

/** Runs here and now, whatever BLOG_KIT_PROCESSING says, so a failure shows. */
class PurgeCommand extends Command
{
    protected $signature = 'blog-kit:purge';

    protected $description = 'Purge every blog page from the local page cache and Cloudflare';

    public function handle(CacheUrls $urls, BlogCachePurger $purger): int
    {
        $all = $urls->all();

        $this->components->info(sprintf('Purging %d URLs under %s', count($all), route('blog-kit.index')));

        try {
            $purger->purge($all);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Done.');

        return self::SUCCESS;
    }
}
