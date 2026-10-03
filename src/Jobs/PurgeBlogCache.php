<?php

namespace Lostdomain\BlogKit\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Services\BlogCachePurger;
use Throwable;

class PurgeBlogCache implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param  list<string>  $urls */
    public function __construct(public array $urls)
    {
        $this->onConnection(config('blog-kit.images.connection'));
        $this->onQueue(config('blog-kit.images.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(BlogCachePurger $purger): void
    {
        $purger->purge($this->urls);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('blog-kit.cache.purge_failed', [
            'url_count' => count($this->urls),
            'urls' => $this->urls,
            'error' => $exception->getMessage(),
        ]);
    }
}
