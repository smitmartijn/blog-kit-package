<?php

namespace Lostdomain\BlogKit\Tests\Unit;

use Lostdomain\BlogKit\Services\HtmlToMarkdown;
use Lostdomain\BlogKit\Services\MarkdownRenderer;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HtmlToMarkdownTest extends TestCase
{
    private function convert(string $html): string
    {
        return app(HtmlToMarkdown::class)->convert($html);
    }

    #[Test]
    public function common_article_html_becomes_markdown(): void
    {
        $markdown = $this->convert('<h2 id="why">Why it matters</h2><p>Muting is <strong>instant</strong>. See <a href="https://example.com">docs</a>.</p><ul><li>One</li><li>Two</li></ul><pre><code class="language-bash">brew install x</code></pre><blockquote><p>Quote</p></blockquote>');

        $this->assertSame(
            "## Why it matters\n\nMuting is **instant**. See [docs](https://example.com).\n\n- One\n- Two\n\n```bash\nbrew install x\n```\n\n> Quote",
            $markdown,
        );
    }

    #[Test]
    public function marketing_os_figures_become_images_with_their_caption_as_title(): void
    {
        $markdown = $this->convert('<figure class="article-figure" data-slot="step-1"><img src="https://cdn.test/a.png?w=1&amp;h=2" alt="Step 1" loading="lazy"><figcaption>Open "settings"</figcaption></figure>');

        $this->assertSame('![Step 1](https://cdn.test/a.png?w=1&h=2 "Open &quot;settings&quot;")', $markdown);
    }

    #[Test]
    public function special_characters_in_a_caption_survive_a_round_trip(): void
    {
        $markdown = $this->convert('<figure><img src="https://cdn.test/a.png" alt="Step 1"><figcaption>Open "settings" &amp; use C:\temp\*_x_</figcaption></figure>');

        $this->assertStringContainsString(
            '<figcaption>Open &quot;settings&quot; &amp; use C:\temp\*_x_</figcaption>',
            app(MarkdownRenderer::class)->toHtml($markdown),
        );
    }

    #[Test]
    public function figures_without_an_image_keep_their_content(): void
    {
        $this->assertSame('> Not an image', $this->convert('<figure><blockquote><p>Not an image</p></blockquote></figure>'));
    }

    #[Test]
    public function tables_become_markdown_tables(): void
    {
        $this->assertSame(
            "| App | Key |\n|---|---|\n| Zoom | Cmd |",
            $this->convert('<table><thead><tr><th>App</th><th>Key</th></tr></thead><tbody><tr><td>Zoom</td><td>Cmd</td></tr></tbody></table>'),
        );
    }

    #[Test]
    public function html_without_a_markdown_equivalent_is_kept_and_separated_from_what_follows(): void
    {
        $markdown = $this->convert('<details><summary>More</summary>Hidden</details><p>After</p>');

        $this->assertSame("<details><summary>More</summary>Hidden</details>\n\nAfter", $markdown);
    }

    #[Test]
    public function scripts_and_styles_are_dropped(): void
    {
        $this->assertSame('Safe', $this->convert('<script>alert(1)</script><style>p{}</style><p>Safe</p>'));
    }

    #[Test]
    public function marketing_os_html_survives_a_round_trip_through_markdown(): void
    {
        $html = '<h2>Steps</h2><p>Intro <em>text</em>.</p><figure class="article-figure"><img src="https://cdn.test/a.png" alt="Step 1"><figcaption>Open settings</figcaption></figure><table><thead><tr><th>App</th></tr></thead><tbody><tr><td>Zoom</td></tr></tbody></table>';

        $rendered = app(MarkdownRenderer::class)->toHtml($this->convert($html));

        $this->assertStringContainsString('<h2 id="steps">Steps</h2>', $rendered);
        $this->assertStringContainsString('<p>Intro <em>text</em>.</p>', $rendered);
        $this->assertStringContainsString('<figure class="article-figure"><img src="https://cdn.test/a.png" alt="Step 1" loading="lazy" /><figcaption>Open settings</figcaption></figure>', $rendered);
        $this->assertStringContainsString('<td>Zoom</td>', $rendered);
    }

    #[Test]
    public function empty_html_is_empty_markdown(): void
    {
        $this->assertSame('', $this->convert(''));
    }
}
