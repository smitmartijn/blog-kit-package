<?php

namespace Lostdomain\BlogKit\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Lostdomain\BlogKit\Models\Image;
use Lostdomain\BlogKit\Models\Post;
use RuntimeException;
use Throwable;

/**
 * Finds remote images referenced by a post, downloads them onto the
 * configured disk and rewrites the post to the stored URLs.
 */
class ImageLocalizer
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /**
     * Remote (http/https) image URLs in the featured image, images[] and the
     * body, excluding anything already stored for this post.
     *
     * @return list<string>
     */
    public function remoteUrls(Post $post): array
    {
        $candidates = [];

        if (filled($post->featured_image['url'] ?? null)) {
            $candidates[] = (string) $post->featured_image['url'];
        }

        foreach ($post->images ?? [] as $image) {
            if (is_array($image) && filled($image['url'] ?? null)) {
                $candidates[] = (string) $image['url'];
            }
        }

        $candidates = [
            ...$candidates,
            ...$this->urlsInHtml((string) $post->body_html),
            ...$this->urlsInMarkdown((string) $post->body_markdown),
        ];

        $local = $post->storedImages()->where('status', Image::STORED)->pluck('url')->all();

        return array_values(array_unique(array_filter(
            $candidates,
            fn (string $url) => preg_match('#^https?://#i', $url) === 1
                && ! in_array($url, $local, true)
                && ! $this->isOnDisk($url),
        )));
    }

    /**
     * Downloads every remote image that isn't stored yet. Returns the
     * source => local URL map of everything stored, and the failures.
     *
     * @return array{map: array<string, string>, failures: array<string, string>}
     */
    public function localize(Post $post): array
    {
        $failures = [];

        foreach ($this->remoteUrls($post) as $url) {
            $image = $this->download($post, $url);

            if ($image->status !== Image::STORED) {
                $failures[$url] = (string) $image->error;
            }
        }

        return ['map' => $this->storedMap($post), 'failures' => $failures];
    }

    /** @return array<string, string> source URL => local URL */
    public function storedMap(Post $post): array
    {
        return $post->storedImages()
            ->where('status', Image::STORED)
            ->pluck('url', 'source_url')
            ->all();
    }

    public function download(Post $post, string $url): Image
    {
        $image = Image::firstOrNew(['post_id' => $post->id, 'source_hash' => Image::hashFor($url)]);
        $image->source_url = $url;

        if ($image->exists && $image->status === Image::STORED) {
            return $image;
        }

        $image->attempts = ($image->attempts ?? 0) + 1;

        try {
            Log::info('blog-kit.images.download', ['post_id' => $post->id, 'url' => $url, 'attempt' => $image->attempts]);

            $response = Http::timeout((int) config('blog-kit.images.http_timeout', 30))
                ->withHeaders(['Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/*'])
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException("HTTP {$response->status()}");
            }

            $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $extension = self::EXTENSIONS[$mime] ?? null;

            if ($extension === null) {
                throw new RuntimeException("Unsupported content type \"{$mime}\"");
            }

            $body = $response->body();
            $bytes = strlen($body);
            $maxBytes = (int) config('blog-kit.images.max_bytes');

            if ($bytes === 0) {
                throw new RuntimeException('Empty response body');
            }

            if ($maxBytes > 0 && $bytes > $maxBytes) {
                throw new RuntimeException("Image is {$bytes} bytes, larger than the {$maxBytes} byte limit");
            }

            $hash = hash('sha256', $body);
            $path = trim((string) config('blog-kit.images.directory'), '/').'/'.substr($hash, 0, 2).'/'.$hash.'.'.$extension;
            $disk = $this->disk();

            if (! $disk->exists($path) && $disk->put($path, $body) === false) {
                throw new RuntimeException("Could not write {$path} to disk \"".config('blog-kit.images.disk').'"');
            }

            $image->fill([
                'status' => Image::STORED,
                'disk' => config('blog-kit.images.disk'),
                'path' => $path,
                'url' => $disk->url($path),
                'mime_type' => $mime,
                'bytes' => $bytes,
                'error' => null,
            ]);

            Log::info('blog-kit.images.stored', ['post_id' => $post->id, 'url' => $url, 'path' => $path, 'bytes' => $bytes]);
        } catch (Throwable $exception) {
            $image->fill(['status' => Image::FAILED, 'error' => $exception->getMessage()]);

            Log::warning('blog-kit.images.download_failed', [
                'post_id' => $post->id,
                'url' => $url,
                'attempt' => $image->attempts,
                'error' => $exception->getMessage(),
            ]);
        }

        $image->save();

        return $image;
    }

    /**
     * The post's image-bearing attributes with every mapped URL replaced.
     *
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    public function rewrite(Post $post, array $map): array
    {
        if ($map === []) {
            return [];
        }

        $htmlMap = $map;

        foreach ($map as $source => $local) {
            $htmlMap[htmlspecialchars($source, ENT_QUOTES)] = htmlspecialchars($local, ENT_QUOTES);
        }

        // Longest first, so a URL that prefixes another never clobbers it.
        uksort($htmlMap, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $featured = $post->featured_image;

        if (is_array($featured) && isset($featured['url'], $map[$featured['url']])) {
            $featured['url'] = $map[$featured['url']];
        }

        $images = collect($post->images ?? [])
            ->map(function ($image) use ($map) {
                if (is_array($image) && isset($image['url'], $map[$image['url']])) {
                    $image['url'] = $map[$image['url']];
                }

                return $image;
            })
            ->all();

        return [
            'body_html' => strtr((string) $post->body_html, $htmlMap),
            'body_markdown' => $post->body_markdown === null ? null : strtr($post->body_markdown, $htmlMap),
            'featured_image' => $featured,
            'images' => $images,
        ];
    }

    /** @return list<string> */
    private function urlsInHtml(string $html): array
    {
        $urls = [];

        preg_match_all('/<(?:img|source)\b[^>]*>/i', $html, $tags);

        foreach ($tags[0] as $tag) {
            if (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $match)) {
                $urls[] = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
            }

            if (preg_match('/\ssrcset\s*=\s*(["\'])(.*?)\1/i', $tag, $match)) {
                foreach (explode(',', html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5)) as $candidate) {
                    $candidate = trim(preg_split('/\s+/', trim($candidate))[0] ?? '');

                    if ($candidate !== '') {
                        $urls[] = $candidate;
                    }
                }
            }
        }

        return $urls;
    }

    /** @return list<string> */
    private function urlsInMarkdown(string $markdown): array
    {
        preg_match_all('/!\[[^\]]*\]\(\s*<?(https?:\/\/[^\s)>]+)>?(?:\s+"[^"]*")?\s*\)/i', $markdown, $matches);

        return $matches[1];
    }

    private function isOnDisk(string $url): bool
    {
        $base = $this->disk()->url(trim((string) config('blog-kit.images.directory'), '/').'/');

        return str_starts_with($url, $base);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('blog-kit.images.disk'));
    }
}
