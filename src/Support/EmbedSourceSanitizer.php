<?php

namespace Lostdomain\BlogKit\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps an iframe's src only when it is https on an allowed embed host.
 */
final class EmbedSourceSanitizer implements AttributeSanitizerInterface
{
    /** @param  list<string>  $hosts  lowercase host names, matched exactly */
    public function __construct(private array $hosts) {}

    public function getSupportedElements(): ?array
    {
        return ['iframe'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $url = parse_url(trim($value));

        if ($url === false || strtolower($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['port'])) {
            return null;
        }

        return in_array(strtolower($url['host'] ?? ''), $this->hosts, true) ? $value : null;
    }
}
