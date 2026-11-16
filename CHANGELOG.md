# Changelog

All notable changes to `lostdomain/blog-kit` are documented here. The package follows semantic versioning.

## Unreleased

- **Video embeds.** YouTube and Vimeo iframes in a post body are kept (`blog-kit.embeds.hosts`, https only, exact host match); every other iframe is removed. Markdown no longer escapes `<iframe>`, so embeds written in Markdown or imported from Docusaurus render. Published configs get the default host list automatically.
- `blog-kit:import` copies local videos and audio (`<video>`, `<audio>`, `<source>` and `poster`) as well as images, and rewrites every media attribute in a tag instead of the first.
- `blog-kit:import --base-url=/blog/` for Docusaurus and VuePress configs that read the base URL from an environment variable, so static files referenced with it are found.
- Fix: a Docusaurus blog at the site root (`routeBasePath: '/'`) was reported as served under `//`.
- Fix: in apps with Filament, post bodies were sanitized with Filament's `HtmlSanitizerConfig` (bound in the container) instead of Blog Kit's rules.
- Fix: Redirects in global search showed unfiltered rows; they now have `from_slug` as record title.

- **Multiple authors.** Authors have their own page (`/blog/author/{slug}`) with photo, role, bio, website and social profiles, managed under **Authors** in Filament. Posts get an author picker; bylines link to the author page, articles end with an "About the author" box, and cards show the author. JSON-LD `author` is now a full `Person` (author page, `sameAs` profiles), author pages emit `ProfilePage`, and an X profile adds `twitter:creator`. Author pages are in the sitemap and purged with their posts.
- **Default author** for Marketing OS deliveries, imports and new admin posts: the author marked default, else the first author. Updates keep the post's author.
- **Upgrade: run `php artisan migrate`.** It creates the first author from `BLOG_KIT_AUTHOR_NAME` / `BLOG_KIT_AUTHOR_URL`, makes it the default and assigns it to every existing post. Those variables aren't read after that. Without `BLOG_KIT_AUTHOR_NAME`, no author is created and posts stay without a byline. Overridden `show` or `partials/card` views won't show authors until updated.
- `author` is now a reserved post slug.

- `blog-kit:purge` and a **Purge cache** button above the admin post list purge every blog page, leaving the rest of the cache alone.
- Fix: a purge could report success while pages stayed cached, for example when the command-line user can't write to the web server's cache files. It now checks, logs `blog-kit.cache.local_purge_failed` and fails. `blog-kit:import` reports a failed purge and exits with an error.
- Fix: `/blog/x/` had its own cache entry that purges never reached. It now shares the entry of `/blog/x`, and both are purged from Cloudflare (so Cloudflare receives about twice as many URLs per purge).

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
