# Blog Kit

`lostdomain/blog-kit` adds a complete blog to a Laravel app. Posts are written and approved in Marketing OS and pushed here over a signed API. The package handles everything after that: receiving, storage, image localisation, public pages, feeds, sitemap, SEO, full-page caching and Cloudflare purges. An optional Filament plugin lets you manage posts inside the app, including writing posts locally.

It is deliberately only a blog: no page builder, no generic CMS.

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Layout integration](#layout-integration)
- [Theming and overriding views](#theming-and-overriding-views)
- [Filament admin](#filament-admin)
- [Marketing OS API (contract v1)](#marketing-os-api-contract-v1)
- [Images](#images)
- [Caching and Cloudflare](#caching-and-cloudflare)
- [Feeds and sitemap](#feeds-and-sitemap)
- [Migrating from Docusaurus](#migrating-from-docusaurus)
- [Upgrading](#upgrading)
- [Development](#development)

## Requirements

- PHP 8.4, Laravel 13
- A queue worker (Horizon / Redis): image downloads and cache purges are queued
- A public disk for images (`public` or an S3/R2 disk with a public URL)
- Optional: Filament 5 for the admin section; a Cloudflare zone for edge purges

## Installation

Add the private repository and require the package:

```json
// composer.json
"repositories": [
    { "type": "vcs", "url": "git@github.com:lostdomain/blog-kit.git" }
]
```

```bash
composer require lostdomain/blog-kit:^1.0
php artisan blog-kit:install
php artisan migrate
php artisan storage:link   # when images go to the public disk
```

`blog-kit:install` publishes `config/blog-kit.php` and spatie/laravel-feed's assets, then prints the remaining steps with a freshly generated token and signing secret. Those steps are covered below.

Minimum `.env`:

```dotenv
BLOG_KIT_API_TOKEN=...            # shared with Marketing OS
BLOG_KIT_SIGNING_SECRET=...       # shared with Marketing OS
BLOG_KIT_PRODUCT_ID=12            # optional: reject payloads for other products
BLOG_KIT_LAYOUT=layouts.app
BLOG_KIT_AUTHOR_NAME="Martijn Smit"
BLOG_KIT_PUBLISHER_LOGO=https://example.com/logo.png
BLOG_KIT_CLOUDFLARE_ZONE_ID=...
BLOG_KIT_CLOUDFLARE_API_TOKEN=... # Zone > Cache Purge permission only
RESPONSE_CACHE_DRIVER=redis       # store for the full-page cache
```

Then, in Marketing OS, add a publishing destination with the **Blog Kit** payload format, endpoint `https://your-app.com/blog/api/marketing-os/v1/posts`, bearer auth with the token, and the signing secret.

## Configuration

Everything lives in `config/blog-kit.php`. The most important keys:

| Key | Default | Purpose |
| --- | --- | --- |
| `route_prefix` | `blog` | Public pages and the API live under this prefix |
| `domain` | `null` | Restrict routes to one domain |
| `middleware` | `[SubstituteBindings]` | Public page middleware. Deliberately **not** `web` (see below) |
| `api.token`, `api.signing_secret` | — | Required. Without both, every API call is a 401 and an error is logged |
| `api.tolerance` | `300` | Max age (and future skew) of the request timestamp, seconds |
| `api.product_id` | `null` | When set, other products' payloads are a 422 |
| `layout` | `layouts.app` | Layout the views `@extends` |
| `sections.content`, `sections.title` | `content`, `title` | Section names the views fill |
| `head_stack` | `head` | Stack the views push meta tags, JSON-LD and theme CSS onto |
| `site_name`, `blog_title`, `blog_description`, `title_format` | | Titles and descriptions; `title_format` is `:title \| :site` |
| `author.*`, `publisher.*` | | Used in JSON-LD, Open Graph and the byline |
| `per_page`, `related_count`, `reading_words_per_minute` | `12`, `3`, `220` | Listing |
| `images.*` | `public` disk, `blog/` | Disk, directory, queue, retries, max size |
| `cache.enabled`, `cache.lifetime`, `cache.cache_control` | `true`, 7 days, `public, max-age=300, s-maxage=86400, stale-while-revalidate=60` | Full-page cache and edge headers |
| `cloudflare.zone_id`, `cloudflare.api_token` | — | Edge purge; skipped with a warning when missing |
| `filament.gate` | `viewBlogKit` | Gate for the admin section |

## Layout integration

The views extend your layout:

```blade
{{-- resources/views/layouts/app.blade.php --}}
<head>
    <title>@yield('title', config('app.name'))</title>
    @stack('head')
</head>
<body>
    @yield('content')
</body>
```

Section and stack names are configurable if your layout uses different ones. The views push the meta description, canonical, Open Graph, Twitter, JSON-LD, feed links and default theme CSS onto the head stack. **If your layout prints its own `<meta name="description">` or canonical, skip them on blog pages**, e.g. `@unless(request()->routeIs('blog-kit.*')) ... @endunless`.

**Public pages run without the `web` middleware group: no session and no cookies.** That is what makes them safe to cache for everyone, locally and at Cloudflare (Cloudflare does not cache responses that set cookies). Consequences for the layout on blog pages:

- `@auth` is always false, so the guest navigation renders. That is the correct output for a shared cached page.
- `$errors` is an empty bag. `csrf_token()` returns a token that is not tied to a session, so forms that POST to your app from the blog layout (logout, newsletter) will fail CSRF. Use links or a separate page for those.

If you really need sessions on blog pages, set `middleware` to `['web']`, but expect personalised pages to be cached for every visitor. Don't do this.

## Theming and overriding views

The views use plain Tailwind utilities plus CSS variables. Tailwind v4 has to scan the package views, so add this to your main CSS file (path relative to the CSS file):

```css
@import "tailwindcss";
@source "../../vendor/lostdomain/blog-kit/resources/views";
```

Theme with variables on `.blog-kit`. The defaults are declared with zero specificity, so a plain rule wins:

```css
.blog-kit {
    --blog-kit-accent: var(--color-amber-500);
    --blog-kit-accent-contrast: #111;
    --blog-kit-heading: var(--color-cream);
    --blog-kit-muted: color-mix(in srgb, var(--color-cream) 60%, transparent);
    --blog-kit-border: var(--color-line);
    --blog-kit-surface: transparent;
    --blog-kit-radius: 0;
    --blog-kit-font-heading: var(--font-display);
    --blog-kit-max-width: 72rem;
    --blog-kit-prose-width: 44rem;
}
```

Article bodies are styled through `.blog-kit-prose` (headings, lists, figures, tables, code), using the same variables.

To change markup, publish the views and edit only the files you need:

```bash
php artisan vendor:publish --tag=blog-kit-views
# then delete every file in resources/views/vendor/blog-kit you did not change
```

> **An overridden view stops receiving updates.** Laravel uses your copy instead of the package's, so fixes and new features in that view will not reach you. Keep overrides few and small (a partial such as `partials/card.blade.php` rather than `show.blade.php`), and diff them against the package after each upgrade.

## Filament admin

Requires Filament 5. Register the plugin and define the gate:

```php
// app/Providers/Filament/AdminPanelProvider.php
->plugin(\Lostdomain\BlogKit\Filament\BlogKitPlugin::make()->navigationGroup('Blog'))

// app/Providers/AppServiceProvider.php
Gate::define('viewBlogKit', fn (User $user) => $user->is_admin);
```

If the gate isn't defined, the section is forbidden and `blog-kit.filament.gate_missing` is logged.

The section has **Posts** and **Redirects**:

- **List and view posts**: status (live / hidden / unpublished), source, image status with per-image errors, lock state and redirects.
- **Create posts locally.** Local posts are not tied to Marketing OS, and the API never touches them.
- **Edit any post.** Slug changes keep a 301. Remote image URLs in the body are downloaded after saving.
- **Actions**: view on site, retry images, purge cache, hide/restore, release lock, delete.
- **Redirects**: add old slugs (useful when migrating) and remove them.

**Locking.** Editing a post that came from Marketing OS locks it. From then on Marketing OS `publish`/`update` deliveries for that post get **409** (`post_locked`), so your local edits are never silently overwritten and the conflict shows as a failed delivery in Marketing OS. `unpublish` still goes through. **Release lock** accepts updates again; the next update overwrites the local edits.

**Hide vs unpublish.** *Hide* (admin) returns 404, a temporary takedown. *Unpublish* (Marketing OS) returns 410 Gone, which tells search engines the page is gone for good.

## Marketing OS API (contract v1)

```
POST {prefix}/api/marketing-os/v1/posts
Content-Type: application/json
Authorization: Bearer {BLOG_KIT_API_TOKEN}
X-Marketing-OS-Timestamp: {unix seconds}
X-Marketing-OS-Signature: hex(hmac_sha256("{timestamp}.{raw request body}", BLOG_KIT_SIGNING_SECRET))
```

### Authentication

Checks run in this order on every request, all with `hash_equals`:

1. Token and secret are configured. If not: 401 `not_configured`, error logged.
2. The bearer token matches. If not: 401 `invalid_token`.
3. The timestamp is a unix timestamp within `api.tolerance` seconds (past or future). If not: 401 `missing_timestamp` / `stale_timestamp`.
4. The signature matches the HMAC over the **exact raw body bytes**. If not: 401 `invalid_signature`.

401 body: `{"error": "unauthenticated", "reason": "invalid_signature", "message": "..."}`. Rejections are logged (`blog-kit.api.rejected`) with reason, IP and path, never with secrets.

Signing example (what Marketing OS does):

```php
$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
```

### Body

| Field | Notes |
| --- | --- |
| `schema_version` | `1` |
| `event` | `publish`, `update` or `unpublish` |
| `delivery_id` | Stored as `last_delivery_id` |
| `sent_at` | Optional |
| `product_id`, `content_item_id` | `content_item_id` is the idempotency key |
| `slug` | `^[a-z0-9]+(-[a-z0-9]+)*$`, not `api`, `tag`, `page`, `feed`, `rss`, `atom`, `sitemap` |
| `title`, `body_html` | Required for publish/update |
| `meta_title`, `meta_description`, `excerpt`, `body_markdown` | Optional |
| `featured_image` | `{url, alt_text, width, height}` or `null` |
| `faq` | `[{question, answer}]`; rendered and emitted as FAQPage JSON-LD |
| `cta` | String (or `{text, url, label}`) |
| `images` | `[{slot, placement, kind, url, alt_text, caption, section_heading, aspect_ratio}]` |
| `tags` | List of strings, may be empty |
| `internal_links`, `external_sources`, `schema_suggestions`, `target_keyword`, `secondary_keywords`, `post_type` | Stored |
| `published_at` | Shown as the post date (defaults to now) |
| `updated_at` | Used as "Updated" date and for ordering of deliveries |
| `status`, `scheduled_publish_date` | Marketing OS internals, **ignored**. A received publish/update is live. |
| `_test` | `true`: validated and answered, never stored |

For `unpublish`, only `schema_version`, `event`, `delivery_id`, `product_id`, `content_item_id` and `slug` are required. Unknown fields are ignored, so Marketing OS can add fields without breaking older sites.

### Behaviour

- **Idempotent upsert by `content_item_id`.** `publish` and `update` both create or update. Repeating a delivery changes nothing.
- **Out-of-order deliveries.** An update whose `updated_at` is older than the stored one is ignored (200, logged as `blog-kit.api.stale_update_ignored`).
- **Slug change.** The old slug answers with a 301 to the new one. A slug owned by another post (including a local post) is a 422.
- **Unpublish.** The post is hidden and its URL, including old slugs, returns 410. A later `publish` makes it live again.
- **Locked posts.** Locked posts get 409 for publish/update (see [Filament admin](#filament-admin)).

### Responses

| Status | Body |
| --- | --- |
| 200 | `{"id": 12, "url": "https://example.com/blog/my-post"}` (`url` is `null` for unpublish) |
| 200 (`_test`) | `{"id": null, "url": "https://example.com/blog/my-post", "test": true}` |
| 401 | `{"error": "unauthenticated", "reason": "...", "message": "..."}` |
| 404 | `{"error": "not_found", ...}`: unpublish for an unknown `content_item_id` |
| 409 | `{"error": "post_locked", ...}`: the post was edited locally |
| 422 | `{"message": "...", "errors": {"field": ["..."]}}` |

The API stores the post and queues the slow work (image downloads, purges), so it answers well within Marketing OS's 15 s timeout.

## Images

After a post is saved, `ProcessPostImages` (queued) downloads:

- the featured image
- every `images[].url`
- every `<img src>`, `<img srcset>` and `<source srcset>` in the body
- every `![](...)` image in the Markdown

Files go to `images.disk` under `images.directory/{hash-prefix}/{sha256}.{ext}`. The stored post is then rewritten to the local URLs. Until the job finishes, the original Marketing OS URLs are served.

- Accepted types are JPEG, PNG, GIF, WebP and AVIF. SVG is refused because it can carry scripts. Responses over `images.max_bytes` fail.
- A failure keeps the image on its original URL. The job records `image_error` on the post, logs `blog-kit.images.download_failed`, and throws so the queue retries with `images.backoff`. A retry only fetches what is still missing.
- When retries run out, the post gets `images_status = failed` and the error, the images that did download are rewritten, and `blog-kit.images.failed` is logged at error level. The admin shows the error and offers **Retry images**.
- Each job is tied to the post's revision. If the post changed meanwhile, the newer job takes over.
- Images already stored for a post are reused on later updates without downloading again.
- Deleting a post does not delete its files, because content-addressed files can be shared.

Cloud disks (R2/S3) need a `url` in the disk config so `Storage::disk()->url()` returns the public URL.

## Caching and Cloudflare

Public pages (index, tags, articles, feeds, sitemap) are cached with spatie/laravel-responsecache. Its own config picks the store, so set `RESPONSE_CACHE_DRIVER=redis`. They also get the `cache.cache_control` header. Requests with query parameters other than `page` (and the UTM-style parameters responsecache ignores) are not cached, because no purge could find them.

After a publish, update or unpublish (and after images are processed), `PurgeBlogCache` forgets these URLs from the local cache and purges them from Cloudflare (in chunks of 30):

- the article, at its current slug and every old slug
- every index page, plus one page beyond the current last page
- every tag page of the old and new tags
- both feeds and the sitemap

Admin actions purge the same way.

- When Cloudflare isn't configured, the purge is skipped and `blog-kit.cloudflare.purge_skipped` is logged as a warning.
- Every request and response is logged (`blog-kit.cloudflare.purge_request` / `_response`) without the token.
- Errors throw, so the job retries. Final failures are logged as `blog-kit.cache.purge_failed`.

**Cloudflare does not cache HTML by default.** Add a Cache Rule for `/blog*` with *Eligible for cache* and *Edge TTL: use cache-control header if present*. Create the API token with only the **Zone → Cache Purge** permission.

## Feeds and sitemap

| URL | Content |
| --- | --- |
| `/blog/rss.xml` | RSS 2.0, latest `feed.items` posts |
| `/blog/atom.xml` | Atom |
| `/blog/sitemap.xml` | Index, live posts (with lastmod and featured image) and tags that have live posts |

To include the blog in your own sitemap:

```php
use Lostdomain\BlogKit\BlogKit;

SitemapIndex::create()->add('/sitemap-pages.xml')->add(BlogKit::sitemapIndexEntry());
// or merge the URLs into one sitemap
Sitemap::create()->add($pages)->add(BlogKit::sitemapTags());
```

## Migrating from Docusaurus

MuteDeck and WhatPulse keep their URLs:

- **Articles** stay at `/blog/{slug}`. Imported posts must keep their Docusaurus slugs (Marketing OS sends the slug).
- **Feeds** keep the Docusaurus paths `rss.xml` and `atom.xml`, so existing subscribers keep working.
- **Changed URLs**, such as dated paths or `/blog/tags/x`, can be mapped in the **Redirects** admin, or with host routes for paths that aren't a single slug.

## Upgrading

The package follows semver: patch and minor releases are safe to `composer update`, and major releases list breaking changes in `CHANGELOG.md`.

After each upgrade:

1. `php artisan migrate`. The package's migrations load automatically.
2. Compare your `config/blog-kit.php` with the package's for new keys. New top-level keys fall back to package defaults; keys added inside an existing array (e.g. `images`) do not, so copy those in.
3. If you override views, diff them against `vendor/lostdomain/blog-kit/resources/views`.
4. Rebuild your CSS, because new views can use new Tailwind classes.

## Development

```bash
composer install
vendor/bin/phpunit   # offline: Http::fake, Storage::fake, Queue::fake
vendor/bin/pint
```
