<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Tag;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FeedAndSitemapTest extends TestCase
{
    #[Test]
    public function the_rss_feed_lists_live_posts(): void
    {
        $post = Post::factory()->create(['title' => 'Live post', 'excerpt' => 'Live excerpt']);
        $post->tags()->attach(Tag::create(['name' => 'Zoom', 'slug' => 'zoom']));
        Post::factory()->unpublished()->create(['title' => 'Gone post']);
        Post::factory()->hidden()->create(['title' => 'Hidden post']);

        $response = $this->get('/blog/rss.xml')->assertOk();

        $this->assertStringContainsString('xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<rss version="2.0"', $response->getContent());
        $xml = simplexml_load_string($response->getContent());
        $this->assertSame('Blog – Example', (string) $xml->channel->title);
        $this->assertCount(1, $xml->channel->item);
        $this->assertSame('Live post', (string) $xml->channel->item[0]->title);
        $this->assertSame('https://example.test/blog/'.$post->slug, (string) $xml->channel->item[0]->link);
        $this->assertSame('Zoom', (string) $xml->channel->item[0]->category);
    }

    #[Test]
    public function the_atom_feed_lists_live_posts(): void
    {
        $post = Post::factory()->create(['title' => 'Live post']);
        Post::factory()->unpublished()->create(['title' => 'Gone post']);

        $response = $this->get('/blog/atom.xml')->assertOk();

        $this->assertStringContainsString('xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom"', $response->getContent());
        $xml = simplexml_load_string($response->getContent());
        $this->assertCount(1, $xml->entry);
        $this->assertSame('Live post', (string) $xml->entry[0]->title);
        $this->assertSame($post->url(), (string) $xml->entry[0]->link['href']);
    }

    #[Test]
    public function the_feed_is_limited_to_the_configured_number_of_items(): void
    {
        config(['blog-kit.feed.items' => 2]);
        Post::factory()->count(3)->create();

        $xml = simplexml_load_string($this->get('/blog/rss.xml')->getContent());

        $this->assertCount(2, $xml->channel->item);
    }

    #[Test]
    public function the_sitemap_lists_the_index_live_posts_and_tags(): void
    {
        $post = Post::factory()->create([
            'slug' => 'live',
            'featured_image' => ['url' => 'https://example.test/storage/blog/hero.png', 'alt_text' => 'Hero'],
            'source_updated_at' => '2026-09-20 10:00:00',
        ]);
        $post->tags()->attach(Tag::create(['name' => 'Zoom', 'slug' => 'zoom']));
        Tag::create(['name' => 'Empty', 'slug' => 'empty']);
        Post::factory()->unpublished()->create(['slug' => 'gone']);

        $response = $this->get('/blog/sitemap.xml')->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('<loc>https://example.test/blog</loc>', $content);
        $this->assertStringContainsString('<loc>https://example.test/blog/live</loc>', $content);
        $this->assertStringContainsString('<lastmod>2026-09-20T10:00:00+00:00</lastmod>', $content);
        $this->assertStringContainsString('<image:loc>https://example.test/storage/blog/hero.png</image:loc>', $content);
        $this->assertStringContainsString('<loc>https://example.test/blog/tag/zoom</loc>', $content);
        $this->assertStringNotContainsString('/blog/gone', $content);
        $this->assertStringNotContainsString('/blog/tag/empty', $content);
    }

    #[Test]
    public function hosts_can_merge_the_blog_into_their_own_sitemap_or_index(): void
    {
        Post::factory()->create(['slug' => 'live']);

        $this->assertSame('https://example.test/blog/sitemap.xml', BlogKit::sitemapIndexEntry()->url);
        $this->assertContains('https://example.test/blog/live', array_map(fn ($tag) => $tag->url, BlogKit::sitemapTags()));
    }
}
