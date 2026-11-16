<?php

namespace Lostdomain\BlogKit\Tests\Filament;

use Lostdomain\BlogKit\Services\HtmlSanitizer;
use PHPUnit\Framework\Attributes\Test;

/**
 * Filament binds its own HtmlSanitizerConfig in the container; Blog Kit's
 * sanitizer must keep using its own rules next to it.
 */
class SanitizerWithFilamentTest extends FilamentTestCase
{
    #[Test]
    public function the_blog_sanitizer_ignores_filaments_sanitizer_config(): void
    {
        $html = app(HtmlSanitizer::class)->sanitize(
            '<iframe src="https://www.youtube.com/embed/abc" title="Demo"></iframe><iframe src="https://evil.test/x"></iframe>',
        );

        $this->assertSame('<iframe src="https://www.youtube.com/embed/abc" title="Demo"></iframe>', $html);
    }
}
