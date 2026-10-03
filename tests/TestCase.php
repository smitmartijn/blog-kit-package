<?php

namespace Lostdomain\BlogKit\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lostdomain\BlogKit\BlogKitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Feed\FeedServiceProvider;
use Spatie\ResponseCache\ResponseCacheServiceProvider;
use Spatie\Sitemap\SitemapServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token';

    protected const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Requests and generated URLs both use the canonical test host.
        $this->withServerVariables(['HTTP_HOST' => 'example.test']);
        url()->forceRootUrl('https://example.test');
        url()->forceScheme('https');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ResponseCacheServiceProvider::class,
            FeedServiceProvider::class,
            SitemapServiceProvider::class,
            BlogKitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views', ...$app['config']->get('view.paths', [])]);
        $app['config']->set('responsecache.cache.store', 'array');
        $app['config']->set('responsecache.enabled', true);
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/blog-kit-public',
            'url' => 'https://example.test/storage',
            'visibility' => 'public',
        ]);

        $app['config']->set('blog-kit.api.token', self::TOKEN);
        $app['config']->set('blog-kit.api.signing_secret', self::SECRET);
        $app['config']->set('blog-kit.site_name', 'Example');
        $app['config']->set('blog-kit.blog_title', 'Blog');
        $app['config']->set('blog-kit.author.name', 'Martijn Smit');
        $app['config']->set('blog-kit.author.url', 'https://example.test/about');
        $app['config']->set('blog-kit.publisher.name', 'Example Inc');
        $app['config']->set('blog-kit.publisher.logo', 'https://example.test/logo.png');
        $app['config']->set('blog-kit.cloudflare.zone_id', null);
        $app['config']->set('blog-kit.cloudflare.api_token', null);
    }

    /**
     * POSTs a payload exactly the way Marketing OS does: raw JSON body, bearer
     * token and an HMAC over "{timestamp}.{body}".
     *
     * @param  array<string, mixed>  $payload
     * @param  array{token?: string|null, timestamp?: string|null, signature?: string|null, secret?: string, body?: string}  $overrides
     */
    protected function postSigned(array $payload, array $overrides = []): TestResponse
    {
        $body = $overrides['body'] ?? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = array_key_exists('timestamp', $overrides) ? $overrides['timestamp'] : (string) now()->getTimestamp();
        $signature = array_key_exists('signature', $overrides)
            ? $overrides['signature']
            : hash_hmac('sha256', $timestamp.'.'.$body, $overrides['secret'] ?? self::SECRET);
        $token = array_key_exists('token', $overrides) ? $overrides['token'] : self::TOKEN;

        $server = array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token === null ? null : 'Bearer '.$token,
            'HTTP_X_MARKETING_OS_TIMESTAMP' => $timestamp,
            'HTTP_X_MARKETING_OS_SIGNATURE' => $signature,
        ], fn ($value) => $value !== null);

        return $this->call('POST', '/blog/api/marketing-os/v1/posts', [], [], [], $server, $body);
    }

    /**
     * A full contract-v1 payload as Marketing OS sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => 1,
            'event' => 'publish',
            'delivery_id' => (string) str()->uuid(),
            'sent_at' => now()->toIso8601String(),
            'product_id' => 7,
            'content_item_id' => 1001,
            'title' => 'How to mute Zoom from anywhere',
            'slug' => 'mute-zoom-from-anywhere',
            'meta_title' => 'Mute Zoom from anywhere',
            'meta_description' => 'A global shortcut that mutes every meeting app.',
            'excerpt' => 'One shortcut, every meeting app.',
            'featured_image' => null,
            'body_html' => '<h2>Why it matters</h2><p>Muting should be instant. Here is how.</p>',
            'body_markdown' => "## Why it matters\n\nMuting should be instant. Here is how.",
            'faq' => [
                ['question' => 'Does it work with Teams?', 'answer' => 'Yes, and with Meet.'],
            ],
            'cta' => 'Try MuteDeck free for 14 days.',
            'internal_links' => [],
            'external_sources' => [],
            'schema_suggestions' => [],
            'images' => [],
            'target_keyword' => 'mute zoom',
            'secondary_keywords' => ['zoom shortcut'],
            'post_type' => 'how_to',
            'tags' => ['Zoom', 'Productivity'],
            'published_at' => now()->subHour()->toIso8601String(),
            'updated_at' => now()->subMinute()->toIso8601String(),
            'scheduled_publish_date' => null,
            'status' => 'webhook_queued',
        ], $overrides);
    }
}
