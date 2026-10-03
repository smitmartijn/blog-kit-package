<?php

namespace Lostdomain\BlogKit\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Exceptions\CloudflarePurgeFailed;
use Throwable;

class CloudflarePurger
{
    public function isConfigured(): bool
    {
        return filled(config('blog-kit.cloudflare.zone_id')) && filled(config('blog-kit.cloudflare.api_token'));
    }

    /**
     * Purges the URLs from the Cloudflare edge cache, in chunks. Throws on any
     * failed request so the calling job retries and the failure is visible.
     *
     * @param  list<string>  $urls
     */
    public function purge(array $urls): void
    {
        if ($urls === []) {
            return;
        }

        if (! $this->isConfigured()) {
            Log::warning('blog-kit.cloudflare.purge_skipped', [
                'reason' => 'BLOG_KIT_CLOUDFLARE_ZONE_ID and BLOG_KIT_CLOUDFLARE_API_TOKEN are not both set',
                'url_count' => count($urls),
            ]);

            return;
        }

        $zoneId = (string) config('blog-kit.cloudflare.zone_id');
        $endpoint = rtrim((string) config('blog-kit.cloudflare.base_url'), '/')."/zones/{$zoneId}/purge_cache";

        foreach (array_chunk($urls, max(1, (int) config('blog-kit.cloudflare.chunk_size', 30))) as $chunk) {
            Log::info('blog-kit.cloudflare.purge_request', ['zone_id' => $zoneId, 'urls' => $chunk]);

            try {
                $response = Http::withToken((string) config('blog-kit.cloudflare.api_token'))
                    ->acceptJson()
                    ->timeout(15)
                    ->post($endpoint, ['files' => $chunk]);
            } catch (Throwable $exception) {
                Log::error('blog-kit.cloudflare.purge_failed', [
                    'zone_id' => $zoneId,
                    'url_count' => count($chunk),
                    'error' => $exception->getMessage(),
                ]);

                throw new CloudflarePurgeFailed('Cloudflare purge request failed: '.$exception->getMessage(), previous: $exception);
            }

            $context = [
                'zone_id' => $zoneId,
                'status' => $response->status(),
                'success' => $response->json('success'),
                'errors' => $response->json('errors'),
                'url_count' => count($chunk),
            ];

            if (! $response->successful() || $response->json('success') !== true) {
                Log::error('blog-kit.cloudflare.purge_failed', $context);

                throw new CloudflarePurgeFailed("Cloudflare purge returned HTTP {$response->status()}: ".json_encode($response->json('errors')));
            }

            Log::info('blog-kit.cloudflare.purge_response', $context);
        }
    }
}
