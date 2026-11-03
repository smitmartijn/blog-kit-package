<?php

namespace Lostdomain\BlogKit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Import\ImageImporter;
use Lostdomain\BlogKit\Import\SourcePost;
use Lostdomain\BlogKit\Import\StaticSite;
use Lostdomain\BlogKit\Import\StaticSiteReader;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Services\BlogCachePurger;
use Lostdomain\BlogKit\Services\MarkdownRenderer;
use Lostdomain\BlogKit\Services\PostPublisher;
use Lostdomain\BlogKit\Support\Background;
use Throwable;

/**
 * One-time import of a Docusaurus or VuePress blog. Posts become local
 * Markdown posts, local images are copied to the image disk, and every old
 * URL keeps working: as the slug itself, or as a 301 redirect.
 */
class ImportCommand extends Command
{
    protected $signature = 'blog-kit:import
        {path : The Docusaurus or VuePress site (or its blog folder)}
        {--dry-run : Read and check everything, write nothing}
        {--update : Overwrite posts that were imported before (same slug)}
        {--clean-slugs : Drop the date from slugs (2024-06-07-name → name); the old URLs redirect}';

    protected $description = 'Import a Docusaurus or VuePress blog into Blog Kit';

    /** Stored in last_delivery_id, so --update can tell imported posts apart. */
    private const MARKER = 'import:';

    public function handle(StaticSiteReader $reader, MarkdownRenderer $renderer, PostPublisher $publisher, Background $background, BlogCachePurger $purger): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $site = $reader->read((string) $this->argument('path'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s blog at %s: %d posts, %d other files skipped.%s',
            ucfirst($site->format), $site->postsDirectory, count($site->posts), count($site->skipped), $dryRun ? ' Dry run: nothing is written.' : '',
        ));

        $newBase = '/'.trim((string) config('blog-kit.route_prefix'), '/').'/';

        if ($site->urlBase !== str_replace('//', '/', $newBase)) {
            $this->components->warn("The old blog was served under {$site->urlBase}, this one under {$newBase}. Old URLs outside {$newBase} need a redirect in your web server.");
        }

        $images = new ImageImporter($site, $dryRun);
        $rows = [];
        $takenSlugs = [];

        $work = function () use ($site, $images, $renderer, $publisher, $dryRun, &$rows, &$takenSlugs) {
            foreach ($site->posts as $source) {
                try {
                    $rows[] = $this->importPost($site, $source, $images, $renderer, $publisher, $dryRun, $takenSlugs);
                } catch (Throwable $exception) {
                    $rows[] = ['status' => 'failed', 'slug' => '', 'old' => $source->oldPath, 'notes' => [$exception->getMessage()]];
                }
            }
        };

        $purgeError = null;

        // One cache purge for the whole import instead of one per post. Run
        // here rather than as background work, so a failure shows.
        $dryRun ? $work() : $background->collectPurges($work, function (array $urls) use ($purger, &$purgeError) {
            $this->components->info(sprintf('Purging %d URLs under %s', count($urls), route('blog-kit.index')));

            try {
                $purger->purge($urls);
            } catch (Throwable $exception) {
                $purgeError = $exception->getMessage();
            }
        });

        $this->report($site, $rows, $dryRun);

        if ($purgeError !== null) {
            $this->components->error("The posts were saved, but the cache purge failed: {$purgeError}");
            $this->line('  Pages may show old content. Fix this, then run: php artisan blog-kit:purge');

            return self::FAILURE;
        }

        return collect($rows)->contains('status', 'failed') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, true>  $takenSlugs
     * @return array{status: string, slug: string, old: string, notes: list<string>}
     */
    private function importPost(StaticSite $site, SourcePost $source, ImageImporter $images, MarkdownRenderer $renderer, PostPublisher $publisher, bool $dryRun, array &$takenSlugs): array
    {
        $notes = $source->warnings;
        $slug = $this->slugFor($source, $takenSlugs);
        $takenSlugs[$slug] = true;
        $existing = Post::where('slug', $slug)->first();

        if ($existing && ! $this->option('update')) {
            return ['status' => 'exists', 'slug' => $slug, 'old' => $source->oldPath, 'notes' => ['Already here; --update overwrites it.']];
        }

        // --update only ever touches posts this importer created.
        if ($existing && ! str_starts_with((string) $existing->last_delivery_id, self::MARKER)) {
            return ['status' => 'skipped', 'slug' => $slug, 'old' => $source->oldPath, 'notes' => ['Another post (not imported) already uses this slug.']];
        }

        $rewritten = $images->rewriteMarkdown($source, $source->markdown);
        $featured = $images->featured($source);

        foreach ($rewritten['missing'] as $missing) {
            $notes[] = "Image not found: {$missing}";
        }

        if ($source->image !== null && $featured === null) {
            $notes[] = "Featured image not found: {$source->image}";
        }

        $html = $renderer->toHtml($rewritten['markdown']);

        if (! $dryRun) {
            $post = $publisher->save($existing ?? new Post, [
                'source' => Post::SOURCE_LOCAL,
                'content_item_id' => null,
                'product_id' => null,
                'slug' => $slug,
                'title' => Str::limit($source->title, 250, ''),
                'meta_description' => $source->description,
                'excerpt' => $source->description ?? $source->excerpt ?? $this->firstParagraph($html),
                'featured_image' => $featured ? [...$featured, 'alt_text' => $source->title] : null,
                'body_markdown' => $rewritten['markdown'],
                'body_format' => Post::FORMAT_MARKDOWN,
                'body_html' => $html,
                'secondary_keywords' => $source->keywords ?: null,
                'published_at' => $source->date,
                'source_updated_at' => $source->date,
                'unpublished_at' => null,
                'last_delivery_id' => Str::limit(self::MARKER.$source->oldPath, 190, ''),
            ], $source->tags);

            if ($source->oldPath !== $slug && $source->oldPath !== '') {
                Redirect::updateOrCreate(['from_slug' => $source->oldPath], ['post_id' => $post->id]);
            }
        }

        if ($source->oldPath !== $slug) {
            $notes[] = 'Old URL redirects (301).';
        }

        return ['status' => $existing ? 'updated' : 'imported', 'slug' => $slug, 'old' => $source->oldPath, 'notes' => $notes];
    }

    /**
     * The old URL when it is a valid slug (so it keeps working as is), else a
     * cleaned-up version of it.
     *
     * @param  array<string, true>  $takenSlugs
     */
    private function slugFor(SourcePost $source, array $takenSlugs): string
    {
        // Dated Docusaurus routes (2024/06/07/name) can't be a slug: use the name.
        $candidate = (string) preg_replace('#^\d{4}/\d{1,2}/\d{1,2}/#', '', $source->oldPath);
        $candidate = (string) preg_replace('#(/index)?\.html$#', '', $candidate);

        if ($this->option('clean-slugs')) {
            $candidate = (string) preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $candidate);
        }

        if (! $this->validSlug($candidate)) {
            $candidate = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($candidate)), '-');
        }

        if (! $this->validSlug($candidate)) {
            $candidate = Str::slug($source->title) ?: 'post';
        }

        $slug = $candidate;

        for ($i = 2; isset($takenSlugs[$slug]); $i++) {
            $slug = $candidate.'-'.$i;
        }

        return $slug;
    }

    private function validSlug(string $slug): bool
    {
        return preg_match('/^'.BlogKit::SLUG_PATTERN.'$/', $slug) === 1 && ! in_array($slug, BlogKit::RESERVED_SLUGS, true);
    }

    private function firstParagraph(string $html): ?string
    {
        return preg_match('#<p>(.*?)</p>#s', $html, $match) ? Str::limit(trim(strip_tags($match[1])), 300) : null;
    }

    /** @param  list<array{status: string, slug: string, old: string, notes: list<string>}>  $rows */
    private function report(StaticSite $site, array $rows, bool $dryRun): void
    {
        $counts = collect($rows)->countBy('status');
        $verb = $dryRun ? 'Would import' : 'Imported';

        $this->newLine();
        $this->components->twoColumnDetail("<options=bold>{$verb}</>", (string) ($counts['imported'] ?? 0));
        $this->components->twoColumnDetail($dryRun ? 'Would update' : 'Updated', (string) ($counts['updated'] ?? 0));
        $this->components->twoColumnDetail('Already imported (skipped)', (string) ($counts['exists'] ?? 0));
        $this->components->twoColumnDetail('Skipped (slug used by another post)', (string) ($counts['skipped'] ?? 0));
        $this->components->twoColumnDetail('<fg=red>Failed</>', (string) ($counts['failed'] ?? 0));
        $this->components->twoColumnDetail('Redirects for changed URLs', (string) collect($rows)->filter(fn ($row) => $row['slug'] !== '' && $row['old'] !== $row['slug'] && in_array($row['status'], ['imported', 'updated'], true))->count());

        $flagged = collect($rows)->filter(fn ($row) => $row['notes'] !== [] && $row['notes'] !== ['Old URL redirects (301).']);
        $shown = $this->output->isVerbose() ? collect($rows) : $flagged;

        if ($shown->isNotEmpty()) {
            $this->newLine();
            $this->table(['Status', 'Slug', 'Old URL', 'Notes'], $shown->map(fn ($row) => [
                $row['status'], $row['slug'], $site->urlBase.$row['old'], implode("\n", $row['notes']),
            ])->all());
        }

        if ($site->skipped !== [] && $this->output->isVerbose()) {
            $this->newLine();
            $this->table(['Skipped file', 'Reason'], array_map(fn ($skip) => [$skip['file'], $skip['reason']], $site->skipped));
        } elseif ($site->skipped !== []) {
            $this->line('  '.count($site->skipped).' files were not posts (run with -v to list them).');
        }

        if (! $this->output->isVerbose() && $flagged->count() < count($rows)) {
            $this->line('  Run with -v to list every post.');
        }
    }
}
