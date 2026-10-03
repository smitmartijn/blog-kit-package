# Changelog

All notable changes to `lostdomain/blog-kit` are documented here. The package follows semantic versioning.

## 1.0.0 - Unreleased

- Marketing OS receiver (contract v1): bearer token, timestamped HMAC signature, idempotent upsert by `content_item_id`, slug-change redirects, unpublish (410), `_test` deliveries, out-of-order protection.
- Queued image localisation to any public disk, with retries, per-image errors and revision-safe rewrites.
- Public pages: index, article, tag, RSS, Atom and sitemap, with Open Graph, Twitter cards and JSON-LD (BlogPosting, FAQPage, BreadcrumbList).
- Full-page cache (spatie/laravel-responsecache), Cache-Control for the edge, and targeted local plus Cloudflare purges.
- Filament 5 plugin: posts (create locally, edit with lock against Marketing OS updates, hide/restore, retry images, purge, delete) and redirects.
- `blog-kit:install` command.
