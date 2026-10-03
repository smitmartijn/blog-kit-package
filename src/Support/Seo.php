<?php

namespace Lostdomain\BlogKit\Support;

use Carbon\CarbonInterface;

/** Everything the head partial renders for one page. */
final class Seo
{
    /**
     * @param  list<array<string, mixed>>  $jsonLd
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $type = 'website',
        public ?string $image = null,
        public ?string $imageAlt = null,
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
        public ?CarbonInterface $publishedTime = null,
        public ?CarbonInterface $modifiedTime = null,
        public array $tags = [],
        public array $jsonLd = [],
        public ?string $prev = null,
        public ?string $next = null,
    ) {}

    /** JSON-LD script tags, escaped so content can't close the script element. */
    public function jsonLdScripts(): string
    {
        return collect($this->jsonLd)
            ->map(fn (array $data) => '<script type="application/ld+json">'
                .json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR)
                .'</script>')
            ->implode("\n");
    }
}
