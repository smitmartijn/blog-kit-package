<?php

namespace Lostdomain\BlogKit\Import;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copies a post's local images onto the blog's image disk and rewrites the
 * Markdown to point at them. Remote (http) images are left alone: the
 * normal image job downloads those after the post is saved.
 */
class ImageImporter
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    /** @var array<string, string> local file → stored URL, so shared images are copied once */
    private array $stored = [];

    public function __construct(private StaticSite $site, private bool $dryRun = false) {}

    /**
     * @return array{markdown: string, missing: list<string>}
     */
    public function rewriteMarkdown(SourcePost $post, string $markdown): array
    {
        $missing = [];
        $replace = function (string $reference) use ($post, &$missing): string {
            $url = $this->importReference($reference, $post);

            if ($url === null && $this->isLocal($reference)) {
                $missing[] = $reference;
            }

            return $url ?? $reference;
        };

        // ![alt](src "title")
        $markdown = (string) preg_replace_callback(
            '/(!\[[^\]]*\]\(\s*<?)([^)\s>]+)/',
            fn (array $match) => $match[1].$replace($match[2]),
            $markdown,
        );

        // <img src="…">, <source srcset="…"> and MDX src={require('./x.png').default}
        $markdown = (string) preg_replace_callback(
            '/(<(?:img|source)\b[^>]*?\s(src|srcset)\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|\{require\([\'"]([^\'"]+)[\'"]\)(?:\.default)?\})/i',
            function (array $match) use ($replace) {
                $value = collect([$match[3] ?? '', $match[4] ?? '', $match[5] ?? ''])->first(fn ($group) => $group !== '') ?? '';

                if (strtolower($match[2]) === 'srcset') {
                    // "a.png 1x, b.png 2x": each candidate separately.
                    $value = collect(explode(',', $value))
                        ->map(function (string $candidate) use ($replace) {
                            $parts = preg_split('/\s+/', trim($candidate), 2);

                            return trim($replace($parts[0]).' '.($parts[1] ?? ''));
                        })
                        ->implode(', ');
                } else {
                    $value = $replace($value);
                }

                return $match[1].'"'.$value.'"';
            },
            $markdown,
        );

        return ['markdown' => $markdown, 'missing' => array_values(array_unique($missing))];
    }

    /**
     * The featured image as stored on the disk (or left remote), with its size.
     *
     * @return array{url: string, width: ?int, height: ?int}|null
     */
    public function featured(SourcePost $post): ?array
    {
        if ($post->image === null) {
            return null;
        }

        $file = $this->resolve($post->image, $post);
        $url = $this->importReference($post->image, $post);

        if ($url === null) {
            return null;
        }

        $size = $file !== null ? @getimagesize($file) : false;

        return ['url' => $url, 'width' => $size[0] ?? null, 'height' => $size[1] ?? null];
    }

    public function isLocal(string $reference): bool
    {
        return ! preg_match('#^([a-z][a-z0-9+.-]*:|//|\#)#i', $reference);
    }

    /** Stored URL for a local image, the reference itself for a remote one, null when the file is missing. */
    private function importReference(string $reference, SourcePost $post): ?string
    {
        if (! $this->isLocal($reference)) {
            return $reference;
        }

        $file = $this->resolve($reference, $post);

        if ($file === null) {
            return null;
        }

        return $this->stored[$file] ??= $this->store($file);
    }

    /** Finds the file a reference points to, the way the old site resolved it. */
    public function resolve(string $reference, SourcePost $post): ?string
    {
        $path = rawurldecode((string) preg_replace('/[?#].*$/', '', $reference));
        $candidates = [];

        if (str_starts_with($path, '/')) {
            $withoutBase = '/'.ltrim(substr($path, strlen(rtrim($this->site->urlBase, '/'))), '/');

            foreach ($this->site->staticRoots as $root) {
                $candidates[] = $root.$path;

                if ($this->site->urlBase !== '/' && str_starts_with($path, rtrim($this->site->urlBase, '/').'/')) {
                    $candidates[] = $root.$withoutBase;
                }
            }
        } else {
            $candidates[] = $post->directory().'/'.$path;
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);

            // Only image files inside the site being imported or its static folders.
            if ($real !== false && is_file($real) && $this->insideSite($real)
                && in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                return $real;
            }
        }

        return null;
    }

    private function insideSite(string $file): bool
    {
        foreach ([$this->site->root, ...$this->site->staticRoots] as $allowed) {
            $allowed = realpath($allowed);

            if ($allowed !== false && str_starts_with($file, $allowed.'/')) {
                return true;
            }
        }

        return false;
    }

    private function store(string $file): string
    {
        $contents = (string) file_get_contents($file);
        $hash = hash('sha256', $contents);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $path = trim((string) config('blog-kit.images.directory'), '/').'/imported/'.substr($hash, 0, 2).'/'.$hash.'.'.$extension;

        if ($this->dryRun) {
            return 'dry-run://'.$path;
        }

        $disk = $this->disk();

        if (! $disk->exists($path) && $disk->put($path, $contents) === false) {
            throw new RuntimeException("Could not write {$path} to disk \"".config('blog-kit.images.disk').'"');
        }

        return $disk->url($path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('blog-kit.images.disk'));
    }
}
