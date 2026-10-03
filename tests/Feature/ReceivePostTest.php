<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Lostdomain\BlogKit\Jobs\ProcessPostImages;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ReceivePostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function publish_stores_the_post_and_returns_its_id_and_canonical_url(): void
    {
        $response = $this->postSigned($this->payload())->assertOk();

        $post = Post::sole();
        $response->assertExactJson([
            'id' => $post->id,
            'url' => 'https://example.test/blog/mute-zoom-from-anywhere',
        ]);

        $this->assertSame('1001', $post->content_item_id);
        $this->assertSame('7', $post->product_id);
        $this->assertSame(Post::SOURCE_MARKETING_OS, $post->source);
        $this->assertSame('How to mute Zoom from anywhere', $post->title);
        $this->assertSame('Try MuteDeck free for 14 days.', $post->cta);
        $this->assertSame([['question' => 'Does it work with Teams?', 'answer' => 'Yes, and with Meet.']], $post->faq);
        $this->assertSame(['Productivity', 'Zoom'], $post->tags->pluck('name')->all());
        $this->assertTrue($post->isLive());
        $this->assertNotNull($post->last_delivery_id);
    }

    #[Test]
    public function the_same_delivery_twice_leaves_one_post(): void
    {
        $payload = $this->payload();

        $first = $this->postSigned($payload)->assertOk()->json('id');
        $second = $this->postSigned($payload)->assertOk()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Post::count());
    }

    #[Test]
    public function publish_for_an_existing_content_item_updates_it(): void
    {
        $this->postSigned($this->payload());
        $this->postSigned($this->payload(['event' => 'update', 'title' => 'Updated title', 'tags' => ['Zoom']]))->assertOk();

        $post = Post::sole();
        $this->assertSame('Updated title', $post->title);
        $this->assertSame(['Zoom'], $post->tags->pluck('name')->all());
        $this->assertSame(2, $post->revision);
    }

    #[Test]
    public function update_for_an_unknown_content_item_creates_it(): void
    {
        $this->postSigned($this->payload(['event' => 'update']))->assertOk();

        $this->assertSame(1, Post::count());
    }

    #[Test]
    public function a_slug_change_keeps_a_301_from_the_old_slug(): void
    {
        $this->postSigned($this->payload());
        $this->postSigned($this->payload(['event' => 'update', 'slug' => 'mute-zoom-anywhere']))
            ->assertOk()
            ->assertJsonPath('url', 'https://example.test/blog/mute-zoom-anywhere');

        $post = Post::sole();
        $this->assertSame('mute-zoom-anywhere', $post->slug);
        $this->assertSame(['mute-zoom-from-anywhere'], $post->redirects()->pluck('from_slug')->all());

        $this->get('/blog/mute-zoom-from-anywhere')->assertStatus(301)->assertRedirect('https://example.test/blog/mute-zoom-anywhere');
    }

    #[Test]
    public function changing_the_slug_back_removes_the_redirect_that_would_shadow_it(): void
    {
        $this->postSigned($this->payload());
        $this->postSigned($this->payload(['slug' => 'renamed']));
        $this->postSigned($this->payload(['slug' => 'mute-zoom-from-anywhere']));

        $this->assertSame(['renamed'], Redirect::pluck('from_slug')->all());
        $this->get('/blog/mute-zoom-from-anywhere')->assertOk();
        $this->get('/blog/renamed')->assertRedirect('https://example.test/blog/mute-zoom-from-anywhere');
    }

    #[Test]
    public function a_slug_owned_by_another_post_is_a_422(): void
    {
        Post::factory()->local()->create(['slug' => 'mute-zoom-from-anywhere']);

        $this->postSigned($this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->assertSame(1, Post::count());
    }

    #[Test]
    public function unpublish_hides_the_post_returns_a_null_url_and_the_page_returns_410(): void
    {
        $id = $this->postSigned($this->payload())->json('id');

        $this->postSigned($this->unpublishPayload())
            ->assertOk()
            ->assertExactJson(['id' => $id, 'url' => null]);

        $this->assertTrue(Post::sole()->isUnpublished());
        $this->get('/blog/mute-zoom-from-anywhere')->assertStatus(410)->assertSee('This article has been removed');
    }

    #[Test]
    public function unpublish_is_idempotent(): void
    {
        $this->postSigned($this->payload());
        $this->postSigned($this->unpublishPayload())->assertOk();
        $unpublishedAt = Post::sole()->unpublished_at;

        $this->travel(5)->minutes();
        $this->postSigned($this->unpublishPayload())->assertOk();

        $this->assertEquals($unpublishedAt, Post::sole()->unpublished_at);
    }

    #[Test]
    public function unpublish_for_an_unknown_post_is_a_404(): void
    {
        $this->postSigned($this->unpublishPayload())
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    #[Test]
    public function publishing_again_after_unpublish_makes_the_post_live(): void
    {
        $this->postSigned($this->payload());
        $this->postSigned($this->unpublishPayload());
        $this->postSigned($this->payload())->assertOk();

        $this->assertTrue(Post::sole()->isLive());
    }

    #[Test]
    public function the_minimal_unpublish_payload_marketing_os_sends_is_valid(): void
    {
        $this->postSigned($this->payload());

        // Exactly the keys WebhookPublisherService sends for unpublish.
        $this->postSigned([
            'schema_version' => 1,
            'event' => 'unpublish',
            'delivery_id' => 'd-1',
            'product_id' => 7,
            'content_item_id' => 1001,
            'slug' => 'mute-zoom-from-anywhere',
            'sent_at' => now()->toIso8601String(),
        ])->assertOk();
    }

    #[Test]
    public function test_payloads_are_validated_and_answered_but_never_stored(): void
    {
        $this->postSigned($this->payload(['_test' => true, '_test_sent_at' => now()->toIso8601String()]))
            ->assertOk()
            ->assertExactJson(['id' => null, 'url' => 'https://example.test/blog/mute-zoom-from-anywhere', 'test' => true]);

        $this->assertSame(0, Post::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function invalid_test_payloads_still_get_422(): void
    {
        $this->postSigned($this->payload(['_test' => true, 'title' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    #[Test]
    public function test_unpublish_payloads_are_not_applied(): void
    {
        $this->postSigned($this->payload());
        $this->postSigned($this->unpublishPayload(['_test' => true]))->assertOk()->assertJsonPath('url', null);

        $this->assertTrue(Post::sole()->isLive());
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_payloads_get_a_422_json_response(array $overrides, string $field): void
    {
        $this->postSigned($this->payload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, Post::count());
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing title' => [['title' => null], 'title'],
            'missing body' => [['body_html' => ''], 'body_html'],
            'unknown schema version' => [['schema_version' => 2], 'schema_version'],
            'unknown event' => [['event' => 'delete'], 'event'],
            'missing delivery id' => [['delivery_id' => null], 'delivery_id'],
            'missing content item' => [['content_item_id' => null], 'content_item_id'],
            'uppercase slug' => [['slug' => 'Mute-Zoom'], 'slug'],
            'slug with slash' => [['slug' => 'a/b'], 'slug'],
            'reserved slug' => [['slug' => 'tag'], 'slug'],
            'featured image without url' => [['featured_image' => ['alt_text' => 'x']], 'featured_image.url'],
            'featured image bad url' => [['featured_image' => ['url' => 'javascript:alert(1)']], 'featured_image.url'],
            'image without url' => [['images' => [['slot' => 'hero']]], 'images.0.url'],
            'faq without answer' => [['faq' => [['question' => 'Q?']]], 'faq.0.answer'],
            'tags not a list' => [['tags' => 'zoom'], 'tags'],
            'bad published_at' => [['published_at' => 'yesterday-ish'], 'published_at'],
        ];
    }

    #[Test]
    public function a_body_that_is_not_json_is_a_422(): void
    {
        $this->postSigned([], ['body' => 'not json'])->assertUnprocessable();
    }

    #[Test]
    public function unknown_fields_are_ignored(): void
    {
        $this->postSigned($this->payload([
            'some_future_field' => ['nested' => true],
            'refresh_context' => 'x',
        ]))->assertOk();

        $this->assertSame(1, Post::count());
    }

    #[Test]
    public function the_marketing_os_status_field_is_ignored(): void
    {
        $this->postSigned($this->payload(['status' => 'draft', 'scheduled_publish_date' => now()->addWeek()->toIso8601String()]))->assertOk();

        $this->assertTrue(Post::sole()->isLive());
    }

    #[Test]
    public function an_empty_tag_list_is_allowed(): void
    {
        $this->postSigned($this->payload(['tags' => []]))->assertOk();

        $this->assertCount(0, Post::sole()->tags);
    }

    #[Test]
    public function a_configured_product_id_rejects_other_products(): void
    {
        config(['blog-kit.api.product_id' => '9']);

        $this->postSigned($this->payload(['product_id' => 7]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');

        $this->postSigned($this->payload(['product_id' => 9]))->assertOk();
    }

    #[Test]
    public function an_update_older_than_the_stored_version_is_ignored(): void
    {
        $this->postSigned($this->payload(['title' => 'Newest', 'updated_at' => now()->toIso8601String()]));
        $this->postSigned($this->payload(['title' => 'Older', 'updated_at' => now()->subHour()->toIso8601String()]))->assertOk();

        $this->assertSame('Newest', Post::sole()->title);
    }

    #[Test]
    public function a_locked_post_rejects_updates_with_409(): void
    {
        $this->postSigned($this->payload());
        Post::sole()->forceFill(['locked_at' => now(), 'locked_by' => 'editor@example.test'])->save();

        $this->postSigned($this->payload(['title' => 'From Marketing OS']))
            ->assertStatus(409)
            ->assertJsonPath('error', 'post_locked');

        $this->assertSame('How to mute Zoom from anywhere', Post::sole()->title);
    }

    #[Test]
    public function a_locked_post_still_accepts_unpublish(): void
    {
        $this->postSigned($this->payload());
        Post::sole()->forceFill(['locked_at' => now()])->save();

        $this->postSigned($this->unpublishPayload())->assertOk();

        $this->assertTrue(Post::sole()->isUnpublished());
    }

    #[Test]
    public function a_post_with_remote_images_queues_the_image_job_for_its_revision(): void
    {
        $this->postSigned($this->payload([
            'featured_image' => ['url' => 'https://cdn.marketing-os.test/hero.png', 'alt_text' => 'Hero', 'width' => 1600, 'height' => 900],
        ]));

        $post = Post::sole();
        $this->assertSame(Post::IMAGES_PENDING, $post->images_status);
        Queue::assertPushed(ProcessPostImages::class, fn (ProcessPostImages $job) => $job->postId === $post->id && $job->revision === $post->revision);
        // The article itself is purged after the images are local.
        Queue::assertNotPushed(PurgeBlogCache::class);
    }

    #[Test]
    public function a_post_without_images_is_purged_straight_away(): void
    {
        $this->postSigned($this->payload());

        Queue::assertNotPushed(ProcessPostImages::class);
        Queue::assertPushed(PurgeBlogCache::class, fn (PurgeBlogCache $job) => in_array('https://example.test/blog/mute-zoom-from-anywhere', $job->urls, true)
            && in_array('https://example.test/blog', $job->urls, true)
            && in_array('https://example.test/blog/tag/zoom', $job->urls, true)
            && in_array('https://example.test/blog/rss.xml', $job->urls, true)
            && in_array('https://example.test/blog/sitemap.xml', $job->urls, true));
    }

    #[Test]
    public function unpublish_purges_the_article_and_listings(): void
    {
        $this->postSigned($this->payload());
        Queue::fake();

        $this->postSigned($this->unpublishPayload());

        Queue::assertPushed(PurgeBlogCache::class, fn (PurgeBlogCache $job) => in_array('https://example.test/blog/mute-zoom-from-anywhere', $job->urls, true)
            && in_array('https://example.test/blog/tag/productivity', $job->urls, true));
    }

    #[Test]
    public function the_original_image_urls_are_served_until_the_job_runs(): void
    {
        $this->postSigned($this->payload([
            'body_html' => '<p><img src="https://cdn.marketing-os.test/inline.png" alt="Inline"></p>',
        ]));

        $this->get('/blog/mute-zoom-from-anywhere')->assertOk()->assertSee('https://cdn.marketing-os.test/inline.png', false);
    }

    /** @return array<string, mixed> */
    private function unpublishPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => 1,
            'event' => 'unpublish',
            'delivery_id' => (string) str()->uuid(),
            'sent_at' => now()->toIso8601String(),
            'product_id' => 7,
            'content_item_id' => 1001,
            'slug' => 'mute-zoom-from-anywhere',
        ], $overrides);
    }
}
