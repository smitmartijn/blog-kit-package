<?php

namespace Lostdomain\BlogKit\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Runs the package's slow work (image downloads, cache purges).
 *
 * "after_response" (default): in the same PHP process, after the response
 * has been sent, so no queue worker is needed and Marketing OS still gets
 * its answer straight away. Failures retry a few times inline, then go to
 * the job's failed() handler, as a queue would.
 *
 * "queue": dispatched to the configured queue connection.
 */
class Background
{
    public const AFTER_RESPONSE = 'after_response';

    public const QUEUE = 'queue';

    private bool $running = false;

    public function __construct(private Application $app) {}

    public function mode(): string
    {
        $mode = (string) config('blog-kit.processing', self::AFTER_RESPONSE);

        if (! in_array($mode, [self::AFTER_RESPONSE, self::QUEUE], true)) {
            throw new InvalidArgumentException("blog-kit.processing must be \"after_response\" or \"queue\", \"{$mode}\" given.");
        }

        return $mode;
    }

    public function run(ShouldQueue $job): void
    {
        if ($this->mode() === self::QUEUE) {
            dispatch($job)->afterCommit();

            return;
        }

        // Never act on data a surrounding transaction may still roll back.
        DB::afterCommit(function () use ($job) {
            // Already doing background work (one job starting another), or on
            // the command line where there is no response to wait for: now.
            if ($this->running || ($this->app->runningInConsole() && ! $this->app->runningUnitTests())) {
                $this->runNow($job);

                return;
            }

            // Laravel never clears terminating callbacks; run this one once.
            $done = false;
            $this->app->terminating(function () use ($job, &$done) {
                if (! $done) {
                    $done = true;
                    $this->runNow($job);
                }
            });
        });
    }

    /** Runs the job with inline retries; after the last attempt, its failed() handler. */
    public function runNow(ShouldQueue $job): void
    {
        $wasRunning = $this->running;
        $this->running = true;
        $attempts = max(1, (int) config('blog-kit.after_response.attempts', 3));
        $backoff = array_values(array_map('intval', (array) config('blog-kit.after_response.backoff', [2, 5])));

        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->app->call([$job, 'handle']);

                    return;
                } catch (Throwable $exception) {
                    if ($attempt >= $attempts) {
                        method_exists($job, 'failed') ? $job->failed($exception) : report($exception);

                        return;
                    }

                    $wait = $backoff[$attempt - 1] ?? ($backoff === [] ? 0 : end($backoff));

                    Log::info('blog-kit.background.retry', [
                        'job' => class_basename($job),
                        'attempt' => $attempt,
                        'next_in_seconds' => $wait,
                        'error' => $exception->getMessage(),
                    ]);

                    if ($wait > 0) {
                        sleep($wait);
                    }
                }
            }
        } finally {
            $this->running = $wasRunning;
        }
    }
}
