<?php

namespace Lostdomain\BlogKit\Import;

use Carbon\CarbonImmutable;

/** One post read from a static blog, before it is written. */
final class SourcePost
{
    /**
     * @param  string  $oldPath  where the post lived, relative to the old blog root (no slashes around it)
     * @param  list<string>  $tags
     * @param  list<string>  $keywords
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $file,
        public string $oldPath,
        public string $title,
        public CarbonImmutable $date,
        public ?string $description,
        public ?string $image,
        public array $tags,
        public array $keywords,
        public string $markdown,
        public ?string $excerpt,
        public array $warnings = [],
    ) {}

    public function directory(): string
    {
        return dirname($this->file);
    }
}
