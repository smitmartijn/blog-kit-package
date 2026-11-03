<?php

namespace Lostdomain\BlogKit\Import;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads the posts of a Docusaurus or VuePress (1/2) blog from disk, given
 * the site's root (or the blog folder itself), and works out the URL each
 * post was published at so it can be kept or redirected.
 */
class StaticSiteReader
{
    private const IGNORED_DIRECTORIES = ['node_modules', '.git', '.docusaurus', '.vuepress', 'build', 'dist', 'vendor', '.cache'];

    public function __construct(private MarkdownCleaner $cleaner) {}

    public function read(string $path): StaticSite
    {
        $root = realpath($path);

        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException("{$path} is not a directory.");
        }

        $site = $this->detect($root);
        $tagLabels = $this->docusaurusTagLabels($site);

        foreach ($this->markdownFiles($site->postsDirectory) as $file) {
            try {
                $post = $this->readPost($site, $file, $tagLabels);
            } catch (Throwable $exception) {
                $site->skipped[] = ['file' => $this->relative($site, $file), 'reason' => $exception->getMessage()];

                continue;
            }

            if (is_string($post)) {
                $site->skipped[] = ['file' => $this->relative($site, $file), 'reason' => $post];

                continue;
            }

            $site->posts[] = $post;
        }

        usort($site->posts, fn (SourcePost $a, SourcePost $b) => $a->date <=> $b->date);

        return $site;
    }

    private function detect(string $root): StaticSite
    {
        $docusaurusConfig = $this->firstExisting($root, ['docusaurus.config.js', 'docusaurus.config.ts', 'docusaurus.config.mjs', 'docusaurus.config.cjs']);

        if ($docusaurusConfig !== null) {
            $config = (string) file_get_contents($docusaurusConfig);
            $baseUrl = $this->configString($config, 'baseUrl') ?? '/';
            // Only inside "blog: { … }": the docs plugin has its own path/routeBasePath.
            $blogConfig = preg_match('/\bblog\s*:\s*\{([^}]*)/', $config, $match) ? $match[1] : '';
            $blogPath = $this->configString($blogConfig, 'path') ?? 'blog';
            $routeBasePath = trim($this->configString($blogConfig, 'routeBasePath') ?? 'blog', '/');

            return new StaticSite(
                format: 'docusaurus',
                root: $root,
                postsDirectory: $this->join($root, $blogPath),
                urlBase: '/'.trim(trim($baseUrl, '/').'/'.$routeBasePath, '/').'/',
                staticRoots: array_values(array_filter([$this->join($root, 'static')], 'is_dir')),
            );
        }

        $vuepressConfig = $this->firstExisting($root, ['.vuepress/config.js', '.vuepress/config.ts', '.vuepress/config.mjs']);

        if ($vuepressConfig !== null) {
            $base = $this->configString((string) file_get_contents($vuepressConfig), 'base') ?? '/';

            return new StaticSite(
                format: 'vuepress',
                root: $root,
                postsDirectory: $root,
                urlBase: '/'.trim($base, '/').($base === '/' ? '' : '/'),
                staticRoots: array_values(array_filter([$this->join($root, '.vuepress/public'), $root], 'is_dir')),
            );
        }

        // Pointed straight at a folder of posts (e.g. a Docusaurus blog/ folder).
        return new StaticSite(
            format: 'folder',
            root: $root,
            postsDirectory: $root,
            urlBase: '/blog/',
            staticRoots: array_values(array_filter([$this->join(dirname($root), 'static'), $root], 'is_dir')),
        );
    }

    /** @param  array<string, string>  $tagLabels */
    private function readPost(StaticSite $site, string $file, array $tagLabels): SourcePost|string
    {
        $relative = $this->relative($site, $file);

        if (preg_match('#(^|/)_#', $relative)) {
            return 'Partial (name starts with _)';
        }

        [$frontMatter, $body] = $this->splitFrontMatter((string) file_get_contents($file));

        if (($frontMatter['draft'] ?? false) === true) {
            return 'Draft';
        }

        $dated = $this->dateFromPath($relative);
        $date = $this->date($frontMatter['date'] ?? null) ?? $dated['date'];

        if ($date === null) {
            // VuePress folders hold plain pages too (index, archive): only dated files are posts.
            if ($site->format === 'vuepress') {
                return 'No date in front matter or file name (a page, not a post?)';
            }

            $date = CarbonImmutable::createFromTimestamp((int) filemtime($file));
            $undated = true;
        }

        $cleaned = $this->cleaner->clean($body);
        $title = $this->string($frontMatter['title'] ?? null) ?? $cleaned['title'] ?? Str::headline($dated['name'] ?? pathinfo($file, PATHINFO_FILENAME));
        $warnings = $cleaned['warnings'];

        if (isset($undated)) {
            $warnings[] = 'No date found; used the file modification time.';
        }

        if (($frontMatter['unlisted'] ?? false) === true) {
            $warnings[] = 'Was unlisted; imported as a normal (listed) post.';
        }

        if ($cleaned['markdown'] === '') {
            return 'Empty body';
        }

        return new SourcePost(
            file: $file,
            oldPath: $this->oldPath($site, $relative, $frontMatter, $dated),
            title: $title,
            date: $date,
            description: $this->string($frontMatter['description'] ?? null),
            image: $this->string($frontMatter['image'] ?? $frontMatter['cover'] ?? null),
            tags: $this->tags($frontMatter, $tagLabels),
            keywords: $this->list($frontMatter['keywords'] ?? []),
            markdown: $cleaned['markdown'],
            excerpt: $cleaned['excerpt'],
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $frontMatter
     * @param  array{date: ?CarbonImmutable, name: ?string, path: ?string}  $dated
     */
    private function oldPath(StaticSite $site, string $relative, array $frontMatter, array $dated): string
    {
        if ($site->format === 'vuepress') {
            if ($permalink = $this->string($frontMatter['permalink'] ?? null)) {
                return trim(Str::after('/'.ltrim($permalink, '/'), rtrim($site->urlBase, '/')), '/');
            }

            // a/index.md and a/README.md → a ; a/b.md → a/b.html
            if (preg_match('#^(.*?)/?(?:index|readme)\.md$#i', $relative, $match)) {
                return trim($match[1], '/');
            }

            return (string) preg_replace('/\.md$/i', '.html', $relative);
        }

        // Docusaurus: front matter slug, else the dated route, else the file name.
        if ($slug = $this->string($frontMatter['slug'] ?? null)) {
            return trim($slug, '/');
        }

        if ($dated['date'] !== null && $dated['name'] !== null) {
            return $dated['path'].'/'.$dated['name'];
        }

        return (string) preg_replace('#(/index)?\.mdx?$#i', '', $relative);
    }

    /** @return array{date: ?CarbonImmutable, name: ?string, path: ?string} */
    private function dateFromPath(string $relative): array
    {
        // 2024-06-07-name.md, 2024-06-07-name/index.md, 2024/06/07/name.md
        if (preg_match('#(?:^|/)(\d{4})[-/](\d{1,2})[-/](\d{1,2})[-/](.+?)(?:/index)?\.mdx?$#i', $relative, $match)) {
            try {
                $date = CarbonImmutable::create((int) $match[1], (int) $match[2], (int) $match[3]);
            } catch (Throwable) {
                $date = null;
            }

            if ($date !== null) {
                return ['date' => $date, 'name' => $match[4], 'path' => $match[1].'/'.$match[2].'/'.$match[3]];
            }
        }

        return ['date' => null, 'name' => null, 'path' => null];
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function splitFrontMatter(string $contents): array
    {
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (! preg_match('/^---\r?\n(.*?)\r?\n---(?:\r?\n|$)(.*)$/s', $contents, $match)) {
            return [[], $contents];
        }

        try {
            $data = Yaml::parse($match[1], Yaml::PARSE_DATETIME);
        } catch (ParseException $exception) {
            throw new RuntimeException('Invalid front matter: '.$exception->getMessage());
        }

        return [is_array($data) ? $data : [], $match[2]];
    }

    /** @param  array<string, mixed>  $frontMatter */
    private function tags(array $frontMatter, array $tagLabels): array
    {
        $tags = [];

        foreach ([$frontMatter['tags'] ?? [], $frontMatter['category'] ?? [], $frontMatter['categories'] ?? []] as $value) {
            foreach (is_array($value) ? $value : explode(',', (string) $value) as $tag) {
                $label = is_array($tag) ? ($tag['label'] ?? null) : $tag;
                $label = trim((string) $label);

                if ($label !== '') {
                    $tags[] = $tagLabels[$label] ?? $label;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /** Docusaurus tags.yml: key → label. @return array<string, string> */
    private function docusaurusTagLabels(StaticSite $site): array
    {
        $file = $this->join($site->postsDirectory, 'tags.yml');

        if (! is_file($file)) {
            return [];
        }

        $labels = [];

        foreach ((array) Yaml::parseFile($file) as $key => $tag) {
            $labels[(string) $key] = is_array($tag) && filled($tag['label'] ?? null) ? (string) $tag['label'] : (string) $key;
        }

        return $labels;
    }

    /** @return list<string> */
    private function markdownFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("No blog folder at {$directory}.");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                fn (SplFileInfo $file) => ! ($file->isDir() && in_array($file->getFilename(), self::IGNORED_DIRECTORIES, true)),
            ),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.mdx?$/i', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_int($value) => CarbonImmutable::createFromTimestamp($value),
            is_string($value) && trim($value) !== '' => CarbonImmutable::parse($value),
            default => null,
        };
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $items)));
    }

    /** A string option from a JS/TS config, e.g. base: '/blog/'. The first match wins. */
    private function configString(string $config, string $key, ?string $default = null): ?string
    {
        return preg_match('/\b'.preg_quote($key, '/').'\s*:\s*(?:process\.env\.\w+\s*(?:\|\||\?\?)\s*)?[\'"`]([^\'"`]*)[\'"`]/', $config, $match)
            ? $match[1]
            : $default;
    }

    /** @param  list<string>  $names */
    private function firstExisting(string $root, array $names): ?string
    {
        foreach ($names as $name) {
            if (is_file($this->join($root, $name))) {
                return $this->join($root, $name);
            }
        }

        return null;
    }

    private function relative(StaticSite $site, string $file): string
    {
        return ltrim(Str::after($file, $site->postsDirectory), '/');
    }

    private function join(string $base, string $path): string
    {
        return rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
