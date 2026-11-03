# Changelog

All notable changes to `lostdomain/blog-kit` are documented here. The package follows semantic versioning.

## Unreleased

- Fix: switching a post from HTML to Markdown put stray backslashes in image captions containing a double quote. Requires league/html-to-markdown 5.2.3 or newer, which escapes image titles itself and includes its security fixes.

- `blog-kit:import`: one-time import of a Docusaurus or VuePress blog: posts as local Markdown posts, local images copied to the image disk, old URLs kept as slugs or redirected (301). Supports `--dry-run`, `--update` and `--clean-slugs`.
- Old URLs that aren't valid slugs (dated Docusaurus paths, dots, `.html`) now resolve through the redirect table; `/blog/tags/x` and `/blog/page/n` redirect to their Blog Kit equivalents.

- **No queue worker needed.** Image downloads and cache purges now run right after the response is sent (`BLOG_KIT_PROCESSING=after_response`, the new default), with inline retries. `BLOG_KIT_PROCESSING=queue` keeps the previous queued behaviour for sites with a worker. Upgrading a site that relies on its queue: set `BLOG_KIT_PROCESSING=queue` to keep it.

- Admin form: Content and Body are full width, with Publishing, Featured image and SEO side by side below them.
- Switching a post between HTML and Markdown converts its content instead of showing an empty editor (league/html-to-markdown; figures round-trip with their captions).
- Article styles cover every element Markdown produces (h1, h5, h6, nested and task lists, kbd, mark, details). Previously a Markdown `# Heading` rendered as plain text.

- Fix: cache purges could leave paginated index and tag pages stale with spatie/laravel-responsecache 8.4.0, whose multi-URL `forget()` stops at the first URL that isn't cached. URLs are now forgotten one at a time.
- Laravel 13 requires PHP 8.4 (via responsecache 8); documented and removed from the CI matrix for PHP 8.3.

- Admin editor writes posts in Markdown with a live preview and image uploads; HTML is rendered on save (GFM, heading ids, figures with captions). Marketing OS posts open in the Markdown they were delivered with. Raw HTML mode stays available per post.
- New `body_format` column: run `php artisan migrate`.

## 1.0.0 - Unreleased

- Marketing OS receiver (contract v1): bearer token, timestamped HMAC signature, idempotent upsert by `content_item_id`, slug-change redirects, unpublish (410), `_test` deliveries, out-of-order protection.
- Queued image localisation to any public disk, with retries, per-image errors and revision-safe rewrites.
- Public pages: index, article, tag, RSS, Atom and sitemap, with Open Graph, Twitter cards and JSON-LD (BlogPosting, FAQPage, BreadcrumbList).
- Full-page cache (spatie/laravel-responsecache), Cache-Control for the edge, and targeted local plus Cloudflare purges.
- Filament 4/5 plugin: posts (create locally, edit with lock against Marketing OS updates, hide/restore, retry images, purge, delete) and redirects.
- `blog-kit:install` command.
- Supports PHP 8.2+, Laravel 12 and 13, Filament 4.12.6+ and 5.7.6+ (the oldest releases without security advisories). CI tests every combination at lowest and latest dependencies.
