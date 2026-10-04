<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Models\Tag;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PublicPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function the_index_lists_live_posts_newest_first(): void
    {
        Post::factory()->create(['title' => 'Older post', 'published_at' => now()->subDays(3)]);
        Post::factory()->create(['title' => 'Newer post', 'published_at' => now()->subDay()]);
        Post::factory()->hidden()->create(['title' => 'Hidden post']);
        Post::factory()->unpublished()->create(['title' => 'Unpublished post']);

        $this->get('/blog')
            ->assertOk()
            ->assertSeeInOrder(['Newer post', 'Older post'])
            ->assertDontSee('Hidden post')
            ->assertDontSee('Unpublished post');
    }

    #[Test]
    public function the_index_paginates(): void
    {
        config(['blog-kit.per_page' => 2]);
        foreach (range(1, 5) as $day) {
            Post::factory()->create(['title' => "Post {$day}", 'published_at' => now()->subDays($day)]);
        }

        $this->get('/blog')->assertOk()->assertSee('Post 1')->assertDontSee('Post 3')->assertSee('Page 1 of 3');
        $this->get('/blog?page=2')->assertOk()->assertSee('Post 3')->assertSee('<link rel="prev" href="https://example.test/blog">', false);
        $this->get('/blog?page=9')->assertNotFound();
    }

    #[Test]
    public function the_index_shows_an_empty_state(): void
    {
        $this->get('/blog')->assertOk()->assertSee('No posts yet.');
    }

    #[Test]
    public function the_article_page_renders_the_full_post(): void
    {
        $post = $this->article();

        $this->get('/blog/mute-zoom')
            ->assertOk()
            ->assertSee('<title>Mute Zoom from anywhere | Example</title>', false)
            ->assertSee('How to mute Zoom')
            ->assertSee('<h2>Why it matters</h2>', false)
            ->assertSee('https://example.test/storage/blog/hero.png', false)
            ->assertSee('alt="A hero image"', false)
            ->assertSee('Frequently asked questions')
            ->assertSee('Does it work with Teams?')
            ->assertSee('Yes, and with Meet.')
            ->assertSee('Try MuteDeck free.')
            ->assertSee('September 1, 2026')
            ->assertSee('Updated')
            ->assertSee('2 min read')
            ->assertSee('Martijn Smit')
            ->assertSee('href="https://example.test/blog/tag/zoom"', false);

        $this->assertSame(2, $post->readingTime());
    }

    #[Test]
    public function article_styles_cover_every_heading_level(): void
    {
        Post::factory()->create(['slug' => 'styled', 'body_format' => Post::FORMAT_MARKDOWN, 'body_html' => '<h1 id="hi">hi</h1><p>bai</p>']);

        $this->get('/blog/styled')
            ->assertOk()
            ->assertSee('<h1 id="hi">hi</h1>', false)
            ->assertSee(':where(.blog-kit-prose) :where(h1, h2, h3, h4, h5, h6)', false)
            ->assertSee(':where(.blog-kit-prose) :where(h1) { font-size', false);
    }

    #[Test]
    public function the_body_is_sanitized(): void
    {
        Post::factory()->create([
            'slug' => 'unsafe',
            'body_html' => '<p onclick="steal()">Safe text</p><script>alert(1)</script><a href="javascript:alert(1)">bad</a>'
                .'<iframe src="https://evil.test"></iframe><img src="https://example.test/a.png" onerror="x()" alt="A" class="wide">'
                .'<style>body{display:none}</style>',
        ]);

        $html = $this->get('/blog/unsafe')->assertOk()->getContent();
        $body = str($html)->after('blog-kit-prose')->before('</article>')->toString();

        $this->assertStringContainsString('Safe text', $body);
        $this->assertStringContainsString('<img src="https://example.test/a.png" alt="A" class="wide" />', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $this->assertStringNotContainsString('display:none', $body);
    }

    #[Test]
    public function the_cta_object_form_renders_a_button(): void
    {
        Post::factory()->create(['slug' => 'cta', 'cta' => ['text' => 'Ready?', 'url' => 'https://example.test/pricing', 'label' => 'See pricing']]);

        $this->get('/blog/cta')->assertOk()->assertSee('Ready?')->assertSee('href="https://example.test/pricing"', false)->assertSee('See pricing');
    }

    #[Test]
    public function related_posts_prefer_shared_tags_then_recency(): void
    {
        $post = $this->article();
        $zoom = Tag::where('slug', 'zoom')->sole();
        $teams = Tag::firstOrCreate(['slug' => 'teams'], ['name' => 'Teams']);

        $sharedTag = Post::factory()->create(['title' => 'Shares a tag', 'published_at' => now()->subYear()]);
        $sharedTag->tags()->attach($zoom);
        Post::factory()->create(['title' => 'Recent untagged', 'published_at' => now()->subHour()]);
        $unrelated = Post::factory()->create(['title' => 'Other tag', 'published_at' => now()->subMonths(2)]);
        $unrelated->tags()->attach($teams);
        Post::factory()->unpublished()->create(['title' => 'Unpublished related'])->tags()->attach($zoom);

        $this->get($post->url())
            ->assertOk()
            ->assertSeeInOrder(['Related articles', 'Shares a tag', 'Recent untagged', 'Other tag'])
            ->assertDontSee('Unpublished related');
    }

    #[Test]
    public function tag_pages_list_live_posts_with_that_tag(): void
    {
        $post = $this->article();
        Post::factory()->create(['title' => 'Untagged post']);

        $this->get('/blog/tag/zoom')->assertOk()->assertSee('Zoom')->assertSee($post->title)->assertDontSee('Untagged post');
        $this->get('/blog/tag/unknown')->assertNotFound();
    }

    #[Test]
    public function a_tag_without_live_posts_is_a_404(): void
    {
        $post = $this->article();
        $post->forceFill(['unpublished_at' => now()])->save();

        $this->get('/blog/tag/zoom')->assertNotFound();
    }

    #[Test]
    public function unpublished_posts_return_410_with_noindex(): void
    {
        Post::factory()->unpublished()->create(['slug' => 'gone']);

        $this->get('/blog/gone')
            ->assertStatus(410)
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertHeader('Cache-Control', 'max-age=300, public, s-maxage=86400, stale-while-revalidate=60');
    }

    #[Test]
    public function hidden_posts_return_404(): void
    {
        Post::factory()->hidden()->create(['slug' => 'hidden']);

        $this->get('/blog/hidden')->assertNotFound();
    }

    #[Test]
    public function an_old_slug_redirects_permanently_to_the_live_post(): void
    {
        $post = Post::factory()->create(['slug' => 'new-slug']);
        Redirect::create(['from_slug' => 'old-slug', 'post_id' => $post->id]);

        $this->get('/blog/old-slug')->assertStatus(301)->assertRedirect('https://example.test/blog/new-slug');
    }

    #[Test]
    public function an_old_slug_of_an_unpublished_post_returns_410(): void
    {
        $post = Post::factory()->unpublished()->create(['slug' => 'new-slug']);
        Redirect::create(['from_slug' => 'old-slug', 'post_id' => $post->id]);

        $this->get('/blog/old-slug')->assertStatus(410);
    }

    #[Test]
    public function unknown_slugs_are_404(): void
    {
        $this->get('/blog/does-not-exist')->assertNotFound();
        $this->get('/blog/Not_A_Slug')->assertNotFound();
    }

    #[Test]
    public function the_article_has_seo_meta_open_graph_and_twitter_cards(): void
    {
        $this->article();

        $this->get('/blog/mute-zoom')
            ->assertOk()
            ->assertSee('<meta name="description" content="A global shortcut for every meeting app.">', false)
            ->assertSee('<link rel="canonical" href="https://example.test/blog/mute-zoom">', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:title" content="Mute Zoom from anywhere | Example">', false)
            ->assertSee('<meta property="og:url" content="https://example.test/blog/mute-zoom">', false)
            ->assertSee('<meta property="og:image" content="https://example.test/storage/blog/hero.png">', false)
            ->assertSee('<meta property="og:image:width" content="1600">', false)
            ->assertSee('<meta property="article:published_time" content="2026-09-01T10:00:00+00:00">', false)
            ->assertSee('<meta property="article:tag" content="Zoom">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<link rel="alternate" type="application/rss+xml"', false);
    }

    #[Test]
    public function the_article_has_blog_posting_faq_and_breadcrumb_json_ld(): void
    {
        $this->article();

        $graphs = $this->jsonLd($this->get('/blog/mute-zoom')->getContent());
        $types = array_column($graphs, '@type');

        $this->assertSame(['BlogPosting', 'FAQPage', 'BreadcrumbList'], $types);

        $posting = $graphs[0];
        $this->assertSame('How to mute Zoom', $posting['headline']);
        $this->assertSame('https://example.test/blog/mute-zoom', $posting['url']);
        $this->assertSame('https://example.test/blog/mute-zoom', $posting['mainEntityOfPage']['@id']);
        $this->assertSame(['@type' => 'Person', 'name' => 'Martijn Smit', 'url' => 'https://example.test/about'], $posting['author']);
        $this->assertSame('Example Inc', $posting['publisher']['name']);
        $this->assertSame('https://example.test/logo.png', $posting['publisher']['logo']['url']);
        $this->assertSame(['https://example.test/storage/blog/hero.png'], $posting['image']);
        $this->assertSame('2026-09-01T10:00:00+00:00', $posting['datePublished']);

        $faq = $graphs[1];
        $this->assertSame('Does it work with Teams?', $faq['mainEntity'][0]['name']);
        $this->assertSame('Yes, and with Meet.', $faq['mainEntity'][0]['acceptedAnswer']['text']);

        $this->assertSame(
            ['Example', 'Blog', 'How to mute Zoom'],
            array_column($graphs[2]['itemListElement'], 'name'),
        );
    }

    #[Test]
    public function there_is_no_faq_page_json_ld_without_faq(): void
    {
        Post::factory()->create(['slug' => 'no-faq', 'faq' => []]);

        $types = array_column($this->jsonLd($this->get('/blog/no-faq')->getContent()), '@type');

        $this->assertSame(['BlogPosting', 'BreadcrumbList'], $types);
    }

    #[Test]
    public function json_ld_cannot_break_out_of_its_script_tag(): void
    {
        Post::factory()->create(['slug' => 'xss', 'title' => 'Title </script><script>alert(1)</script>']);

        $html = $this->get('/blog/xss')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    #[Test]
    public function public_pages_are_cacheable_at_the_edge_without_cookies(): void
    {
        $this->article();

        $response = $this->get('/blog/mute-zoom');

        $response->assertHeader('Cache-Control', 'max-age=300, public, s-maxage=86400, stale-while-revalidate=60');
        $this->assertEmpty($response->headers->getCookies());
    }

    #[Test]
    public function pages_render_inside_the_host_layout(): void
    {
        config(['blog-kit.sections.content' => 'content']);

        $this->get('/blog')->assertOk()->assertSee('<main>', false)->assertSee('<style>', false);
    }

    private function article(): Post
    {
        $post = Post::factory()->create([
            'slug' => 'mute-zoom',
            'title' => 'How to mute Zoom',
            'meta_title' => 'Mute Zoom from anywhere',
            'meta_description' => 'A global shortcut for every meeting app.',
            'excerpt' => 'One shortcut, every app.',
            'featured_image' => ['url' => 'https://example.test/storage/blog/hero.png', 'alt_text' => 'A hero image', 'width' => 1600, 'height' => 900],
            'body_html' => '<h2>Why it matters</h2><p>'.str_repeat('word ', 300).'</p>',
            'faq' => [['question' => 'Does it work with Teams?', 'answer' => 'Yes, and with Meet.']],
            'cta' => 'Try MuteDeck free.',
            'published_at' => '2026-09-01 10:00:00',
            'source_updated_at' => '2026-09-20 10:00:00',
        ]);
        $post->tags()->attach(Tag::firstOrCreate(['slug' => 'zoom'], ['name' => 'Zoom']));

        return $post;
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        return array_map(fn (string $json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    }
}
