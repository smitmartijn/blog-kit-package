<?php

namespace Lostdomain\BlogKit\Tests\Unit;

use Lostdomain\BlogKit\Services\MarkdownRenderer;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MarkdownRendererTest extends TestCase
{
    private function render(string $markdown): string
    {
        return app(MarkdownRenderer::class)->toHtml($markdown);
    }

    #[Test]
    public function headings_get_ids_for_in_page_links(): void
    {
        $html = $this->render("## Why it matters\n\n### Why it matters");

        $this->assertStringContainsString('<h2 id="why-it-matters">Why it matters</h2>', $html);
        $this->assertStringContainsString('<h3 id="why-it-matters-1">Why it matters</h3>', $html);
    }

    #[Test]
    public function an_image_on_its_own_line_becomes_a_figure_with_its_title_as_caption(): void
    {
        // The shape Marketing OS writes: ![alt](url "caption").
        $html = $this->render('![Step 1](https://cdn.test/a.png "Open \"settings\" & go")');

        $this->assertSame(
            '<figure class="article-figure"><img src="https://cdn.test/a.png" alt="Step 1" loading="lazy" /><figcaption>Open &quot;settings&quot; &amp; go</figcaption></figure>',
            $html,
        );
    }

    #[Test]
    public function an_image_without_title_becomes_a_figure_without_caption(): void
    {
        $this->assertSame(
            '<figure class="article-figure"><img src="https://cdn.test/b.png" alt="" loading="lazy" /></figure>',
            $this->render('![](https://cdn.test/b.png)'),
        );
    }

    #[Test]
    public function inline_images_stay_inline(): void
    {
        $this->assertSame(
            '<p>Press <img src="https://cdn.test/key.png" alt="key" /> twice.</p>',
            $this->render('Press ![key](https://cdn.test/key.png) twice.'),
        );
    }

    #[Test]
    public function github_flavoured_tables_strikethrough_and_code_work(): void
    {
        $html = $this->render("| App | Key |\n|---|---|\n| Zoom | Cmd |\n\n~~old~~\n\n```bash\nbrew install x\n```");

        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<td>Zoom</td>', $html);
        $this->assertStringContainsString('<del>old</del>', $html);
        $this->assertStringContainsString('<pre><code class="language-bash">brew install x', $html);
    }

    #[Test]
    public function unsafe_links_are_dropped(): void
    {
        $this->assertStringNotContainsString('javascript:', $this->render('[bad](javascript:alert(1))'));
    }

    #[Test]
    public function inline_html_passes_through_for_the_render_time_sanitizer(): void
    {
        $this->assertStringContainsString('<details><summary>More</summary>', $this->render('<details><summary>More</summary>Text</details>'));
    }
}
