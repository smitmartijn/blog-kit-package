<?php

use Illuminate\Routing\Middleware\SubstituteBindings;

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Public pages live under this prefix (/blog by default). The Marketing OS
    | receiver lives under {prefix}/api/marketing-os/v1/posts.
    |
    | Public pages deliberately run without the "web" group: no session and
    | no cookies, so every visitor gets the same page and Cloudflare can
    | cache it. Your layout therefore always renders the guest state.
    |
    */

    'route_prefix' => env('BLOG_KIT_PREFIX', 'blog'),

    'domain' => env('BLOG_KIT_DOMAIN'),

    'middleware' => [
        SubstituteBindings::class,
    ],

    'api' => [
        'token' => env('BLOG_KIT_API_TOKEN'),
        'signing_secret' => env('BLOG_KIT_SIGNING_SECRET'),

        // Maximum age (and future skew) of X-Marketing-OS-Timestamp, in seconds.
        'tolerance' => (int) env('BLOG_KIT_SIGNATURE_TOLERANCE', 300),

        // When set, payloads for any other Marketing OS product are rejected (422).
        'product_id' => env('BLOG_KIT_PRODUCT_ID'),

        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    |
    | The package views @extends() this layout, fill these sections and push
    | meta tags, JSON-LD and the default theme variables onto the head stack.
    |
    */

    'layout' => env('BLOG_KIT_LAYOUT', 'layouts.app'),

    'sections' => [
        'content' => 'content',
        'title' => 'title',
    ],

    'head_stack' => 'head',

    /*
    |--------------------------------------------------------------------------
    | Site, author and publisher (SEO + JSON-LD)
    |--------------------------------------------------------------------------
    */

    'site_name' => env('BLOG_KIT_SITE_NAME', env('APP_NAME')),

    'blog_title' => env('BLOG_KIT_TITLE', 'Blog'),

    'blog_description' => env('BLOG_KIT_DESCRIPTION', ''),

    // <title> for every blog page; :title is the page title, :site the site name.
    'title_format' => env('BLOG_KIT_TITLE_FORMAT', ':title | :site'),

    'locale' => env('BLOG_KIT_LOCALE', 'en_US'),

    'twitter_site' => env('BLOG_KIT_TWITTER_SITE'),

    'author' => [
        'name' => env('BLOG_KIT_AUTHOR_NAME'),
        'url' => env('BLOG_KIT_AUTHOR_URL'),
    ],

    'publisher' => [
        'name' => env('BLOG_KIT_PUBLISHER_NAME', env('APP_NAME')),
        'url' => env('BLOG_KIT_PUBLISHER_URL', env('APP_URL')),
        'logo' => env('BLOG_KIT_PUBLISHER_LOGO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    'per_page' => 12,

    'related_count' => 3,

    'reading_words_per_minute' => 220,

    'feed' => [
        'items' => 20,
        'language' => 'en-US',
    ],

    /*
    |--------------------------------------------------------------------------
    | Background work
    |--------------------------------------------------------------------------
    |
    | Image downloads and cache purges are slow, so they never run while
    | Marketing OS (or an editor) waits for the response.
    |
    | "after_response" (default) runs them in the same PHP process right after
    | the response is sent: no queue worker needed. Failures retry inline
    | (attempts, with backoff in seconds), then the post records the error.
    |
    | "queue" dispatches them to images.connection / images.queue for sites
    | with a worker (Horizon); retries then follow images.tries/backoff.
    |
    */

    'processing' => env('BLOG_KIT_PROCESSING', 'after_response'),

    'after_response' => [
        'attempts' => 3,
        'backoff' => [2, 5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    |
    | Remote images (featured, images[] and inline in the body) are downloaded
    | onto this disk in the background, and the stored post is rewritten to
    | the local URLs. The disk must be public: Storage::disk()->url() is served.
    | connection, queue, tries and backoff apply in "queue" processing mode.
    |
    */

    'images' => [
        'disk' => env('BLOG_KIT_IMAGE_DISK', 'public'),
        'directory' => env('BLOG_KIT_IMAGE_DIRECTORY', 'blog'),
        'connection' => env('BLOG_KIT_QUEUE_CONNECTION'),
        'queue' => env('BLOG_KIT_QUEUE'),
        'tries' => 5,
        'backoff' => [30, 120, 600, 1800],
        'http_timeout' => 30,
        'max_bytes' => 15 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Public pages go through spatie/laravel-responsecache (its own config
    | decides the store) and get this Cache-Control header for Cloudflare.
    | Changed URLs are forgotten locally and purged from Cloudflare.
    |
    */

    'cache' => [
        'enabled' => env('BLOG_KIT_CACHE', true),
        'lifetime' => (int) env('BLOG_KIT_CACHE_LIFETIME', 60 * 60 * 24 * 7),
        'cache_control' => env('BLOG_KIT_CACHE_CONTROL', 'public, max-age=300, s-maxage=86400, stale-while-revalidate=60'),
    ],

    'cloudflare' => [
        'zone_id' => env('BLOG_KIT_CLOUDFLARE_ZONE_ID'),
        'api_token' => env('BLOG_KIT_CLOUDFLARE_API_TOKEN'),
        'base_url' => 'https://api.cloudflare.com/client/v4',
        // Cloudflare accepts 30 URLs per purge request on most plans.
        'chunk_size' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament
    |--------------------------------------------------------------------------
    |
    | Access to the Blog section of your panel requires this gate. Define it
    | in your app (Gate::define('viewBlogKit', fn ($user) => ...)). An
    | undefined gate denies access and logs an error.
    |
    */

    'filament' => [
        'gate' => 'viewBlogKit',
    ],

];
