<?php

namespace Lostdomain\BlogKit\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Allowlist sanitizer for post bodies: the W3C "safe" elements and
 * attributes plus class, http(s)/mailto links and http(s) media. Scripts,
 * styles, iframes and event handlers are removed. Rebind this class in
 * the container to allow more (e.g. video embeds).
 */
class HtmlSanitizer
{
    private SymfonySanitizer $sanitizer;

    public function __construct(?HtmlSanitizerConfig $config = null)
    {
        $this->sanitizer = new SymfonySanitizer($config ?? self::defaultConfig());
    }

    public static function defaultConfig(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('class', '*')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(5_000_000);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
