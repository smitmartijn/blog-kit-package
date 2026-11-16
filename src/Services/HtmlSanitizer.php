<?php

namespace Lostdomain\BlogKit\Services;

use Lostdomain\BlogKit\Support\EmbedSourceSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Allowlist sanitizer for post bodies: the W3C "safe" elements and
 * attributes plus class, http(s)/mailto links and http(s) media. Scripts,
 * styles and event handlers are removed. Iframes stay only when their src
 * is https on a host in blog-kit.embeds.hosts (YouTube and Vimeo by
 * default); every other iframe is removed. Rebind this class in the
 * container to allow more.
 */
class HtmlSanitizer
{
    private const IFRAME_ATTRIBUTES = ['src', 'title', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy'];

    private SymfonySanitizer $sanitizer;

    public function __construct(?HtmlSanitizerConfig $config = null)
    {
        $this->sanitizer = new SymfonySanitizer($config ?? self::defaultConfig());
    }

    public static function defaultConfig(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('class', '*')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(5_000_000);

        $embedHosts = array_values(array_filter(array_map(
            fn ($host) => strtolower(trim((string) $host)),
            (array) config('blog-kit.embeds.hosts', []),
        )));

        if ($embedHosts === []) {
            return $config;
        }

        return $config
            ->allowElement('iframe', self::IFRAME_ATTRIBUTES)
            ->withAttributeSanitizer(new EmbedSourceSanitizer($embedHosts));
    }

    public function sanitize(string $html): string
    {
        $html = $this->sanitizer->sanitize($html);

        // An iframe whose src was rejected would render as an empty box.
        return (string) preg_replace('#<iframe\b(?![^>]*\ssrc=)[^>]*>.*?</iframe>#is', '', $html);
    }
}
