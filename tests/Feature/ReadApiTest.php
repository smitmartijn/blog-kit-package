<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lostdomain\BlogKit\Models\Author;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Models\Tag;
use Lostdomain\BlogKit\Services\PostPublisher;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ReadApiTest extends TestCase
{
    private const READ_TOKEN = 'read-token';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['blog-kit.read_api.token' => self::READ_TOKEN]);
    }

    // Access

    #[Test]
    public function the_api_is_off_without_a_read_token(): void
    {
        config(['blog-kit.read_api.token' => null]);

        $this->read('/blog/api/v1/posts')->assertUnauthorized()->assertJsonPath('reason', 'not_configured');
    }

    #[Test]
    public function a_wrong_or_missing_token_is_rejected_and_the_marketing_os_token_does_not_work(): void
    {
        $this->getJson('/blog/api/v1/posts')->assertUnauthorized()->assertJsonPath('reason', 'invalid_token');
        $this->read('/blog/api/v1/posts', 'nope')->assertUnauthorized();
        $this->read('/blog/api/v1/posts', self::TOKEN)->assertUnauthorized();
    }

    #[Test]
    public function answers_are_not_cached_publicly_and_set_no_cookies(): void
    {
        $response = $this->read('/blog/api/v1/posts')->assertOk();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertEmpty($response->headers->getCookies());
    }

    // Search

    #[Test]
    public function search_finds_words_that_only_appear_deep_in_the_body(): void
    {
        $this->guide();
        Post::factory()->create(['title' => 'Unrelated', 'body_html' => '<p>Nothing to see.</p>']);

        $this->read('/blog/api/v1/search?q=hotkey')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mute Zoom from anywhere')
            ->assertJsonPath('data.0.url', 'https://example.test/blog/mute-zoom')
            ->assertJsonPath('data.0.matched_in', ['body'])
            ->assertJsonPath('data.0.excerpts.0.heading', 'Set up the shortcut')
            ->assertJsonPath('data.0.excerpts.0.anchor', 'https://example.test/blog/mute-zoom#set-up-the-shortcut')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.total_published', 2);

        $excerpt = $this->read('/blog/api/v1/search?q=hotkey')->json('data.0.excerpts.0.text');
        $this->assertStringContainsString('global hotkey', $excerpt);
    }

    #[Test]
    public function search_matches_headings_titles_and_faq_case_insensitively(): void
    {
        $this->guide();

        $this->read('/blog/api/v1/search?q=TROUBLESHOOTING')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.excerpts.0.heading', 'Troubleshooting');
        $this->read('/blog/api/v1/search?q=anywhere')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.matched_in', ['title']);
        $this->read('/blog/api/v1/search?q=webex')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.excerpts.0.heading', 'FAQ: Does it work with Webex?');
    }

    #[Test]
    public function every_term_must_match_and_quotes_keep_a_phrase_together(): void
    {
        $this->guide();

        $this->read('/blog/api/v1/search?q=hotkey+teams')->assertJsonCount(1, 'data');
        $this->read('/blog/api/v1/search?q=hotkey+linux')->assertJsonCount(0, 'data');
        $this->read('/blog/api/v1/search?q='.urlencode('"global hotkey"'))->assertJsonCount(1, 'data')
            ->assertJsonPath('query.terms', ['global hotkey']);
        $this->read('/blog/api/v1/search?q='.urlencode('"hotkey global"'))->assertJsonCount(0, 'data');
    }

    #[Test]
    public function markup_is_not_searchable_but_entities_are_read_as_text(): void
    {
        Post::factory()->create(['body_html' => '<p class="callout">Tips &amp; tricks for 100% focus</p>']);

        $this->read('/blog/api/v1/search?q=callout')->assertJsonCount(0, 'data');
        $this->read('/blog/api/v1/search?q='.urlencode('"tips & tricks"'))->assertJsonCount(1, 'data');
        $this->read('/blog/api/v1/search?q=100%25')->assertJsonCount(1, 'data');
        // % and _ are literal characters, not wildcards.
        $this->read('/blog/api/v1/search?q=1_0')->assertJsonCount(0, 'data');
        $this->read('/blog/api/v1/search?q=%25')->assertJsonCount(1, 'data');
    }

    #[Test]
    public function search_only_returns_published_posts(): void
    {
        Post::factory()->create(['title' => 'Live keyboard tips']);
        Post::factory()->hidden()->create(['title' => 'Hidden keyboard tips']);
        Post::factory()->unpublished()->create(['title' => 'Unpublished keyboard tips']);
        Post::factory()->create(['title' => 'Draft keyboard tips', 'published_at' => null]);

        $this->read('/blog/api/v1/search?q=keyboard')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Live keyboard tips')
            ->assertJsonPath('meta.total_published', 1);
    }

    #[Test]
    public function title_matches_rank_first(): void
    {
        Post::factory()->create(['title' => 'A body match', 'body_html' => '<p>About microphones.</p>', 'published_at' => now()->subDay()]);
        Post::factory()->create(['title' => 'Microphones explained', 'published_at' => now()->subYear()]);

        $this->read('/blog/api/v1/search?q=microphones')
            ->assertJsonPath('data.0.title', 'Microphones explained')
            ->assertJsonPath('data.1.title', 'A body match');
    }

    #[Test]
    public function search_paginates_and_distinguishes_no_match_from_an_empty_blog(): void
    {
        Post::factory()->count(3)->create(['body_html' => '<p>Webcam lighting.</p>']);

        $this->read('/blog/api/v1/search?q=webcam&per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('links.next', 'https://example.test/blog/api/v1/search?q=webcam&per_page=2&page=2');

        $this->read('/blog/api/v1/search?q=unicorn')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.total_published', 3);
    }

    #[Test]
    public function search_needs_a_query(): void
    {
        $this->read('/blog/api/v1/search')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->read('/blog/api/v1/search?q=%22%22')->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    #[Test]
    public function the_index_follows_edits(): void
    {
        $post = $this->guide();

        app(PostPublisher::class)->save($post, ['body_html' => '<p>Now about push-to-talk.</p>']);

        $this->read('/blog/api/v1/search?q=push-to-talk')->assertJsonCount(1, 'data');
        $this->read('/blog/api/v1/search?q=hotkey')->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_migration_indexes_existing_posts(): void
    {
        $this->guide();
        DB::table('blog_kit_posts')->update(['search_text' => null]);
        $migration = require __DIR__.'/../../database/migrations/2026_11_17_000000_add_search_text_to_blog_kit_posts.php';
        $migration->down();
        $migration->up();

        $this->read('/blog/api/v1/search?q=hotkey')->assertJsonCount(1, 'data');
    }

    // Retrieve

    #[Test]
    public function a_post_comes_back_whole_as_markdown_with_headings_and_links(): void
    {
        $post = $this->guide();

        $response = $this->read('/blog/api/v1/posts/mute-zoom')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://example.test/blog/mute-zoom')
            ->assertJsonPath('data.title', 'Mute Zoom from anywhere')
            ->assertJsonPath('data.version', $post->revision)
            ->assertJsonPath('data.updated_at', '2026-10-05T09:00:00+00:00')
            ->assertJsonPath('data.content.format', 'markdown')
            ->assertJsonPath('data.outline.0', ['level' => 2, 'text' => 'Set up the shortcut', 'anchor' => 'https://example.test/blog/mute-zoom#set-up-the-shortcut'])
            ->assertJsonPath('data.faq.0.question', 'Does it work with Webex?')
            ->assertJsonPath('data.author.name', 'Martijn Smit');

        $body = $response->json('data.content.body');
        $this->assertStringContainsString('## Set up the shortcut', $body);
        $this->assertStringContainsString('## Troubleshooting', $body);
        $this->assertStringContainsString('[the settings guide](https://example.test/blog/settings)', $body);
        $this->assertStringContainsString('[Zoom docs](https://zoom.example/docs)', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('steal()', $body);

        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    #[Test]
    public function html_is_available_on_request(): void
    {
        $this->guide();

        $html = $this->read('/blog/api/v1/posts/mute-zoom?format=html')->assertOk()->json('data.content.body');

        $this->assertStringContainsString('<h2 id="set-up-the-shortcut">Set up the shortcut</h2>', $html);
        $this->assertStringContainsString('href="https://example.test/blog/settings"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    #[Test]
    public function an_unchanged_post_answers_304_to_its_etag(): void
    {
        $post = $this->guide();
        $etag = $this->read('/blog/api/v1/posts/mute-zoom')->headers->get('ETag');

        $this->read('/blog/api/v1/posts/mute-zoom', headers: ['If-None-Match' => $etag])->assertStatus(304);

        app(PostPublisher::class)->save($post, ['title' => 'Changed']);

        $this->read('/blog/api/v1/posts/mute-zoom', headers: ['If-None-Match' => $etag])->assertOk()
            ->assertJsonPath('data.version', $post->fresh()->revision);
    }

    #[Test]
    public function posts_are_found_by_id_and_by_old_slug(): void
    {
        $post = $this->guide();
        Redirect::create(['from_slug' => 'old-mute-zoom', 'post_id' => $post->id]);

        $this->read('/blog/api/v1/posts/'.$post->id)->assertOk()->assertJsonPath('data.slug', 'mute-zoom');
        $this->read('/blog/api/v1/posts/old-mute-zoom')->assertOk()->assertJsonPath('data.url', 'https://example.test/blog/mute-zoom');
    }

    #[Test]
    public function unpublished_posts_are_gone_and_hidden_or_unknown_ones_not_found(): void
    {
        Post::factory()->unpublished()->create(['slug' => 'retired']);
        Post::factory()->hidden()->create(['slug' => 'paused']);

        $this->read('/blog/api/v1/posts/retired')->assertStatus(410)->assertJsonPath('error', 'gone');
        $this->read('/blog/api/v1/posts/paused')->assertNotFound();
        $this->read('/blog/api/v1/posts/nothing-here')->assertNotFound()->assertJsonPath('error', 'not_found');
    }

    // List

    #[Test]
    public function the_inventory_lists_every_published_post_without_bodies(): void
    {
        $this->guide();
        Post::factory()->count(2)->create();
        Post::factory()->hidden()->create();

        $response = $this->read('/blog/api/v1/posts?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.total_published', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data' => [['id', 'slug', 'url', 'title', 'excerpt', 'author', 'tags', 'published_at', 'updated_at', 'version']], 'meta', 'links']);

        $this->assertArrayNotHasKey('content', $response->json('data.0'));
        $this->read('/blog/api/v1/posts?page=2&per_page=2')->assertJsonCount(1, 'data')->assertJsonPath('links.next', null);
    }

    #[Test]
    public function the_inventory_filters_by_tag_author_and_date(): void
    {
        $post = $this->guide();
        $tag = Tag::create(['name' => 'Zoom', 'slug' => 'zoom']);
        $post->tags()->attach($tag);
        $other = Author::factory()->create(['slug' => 'ada']);
        Post::factory()->create(['author_id' => $other->id, 'published_at' => now()->subYears(2)]);

        $this->read('/blog/api/v1/posts?tag=zoom')->assertJsonCount(1, 'data')->assertJsonPath('data.0.tags', ['Zoom']);
        $this->read('/blog/api/v1/posts?author=ada')->assertJsonCount(1, 'data')->assertJsonPath('data.0.author.name', $other->name);
        $this->read('/blog/api/v1/posts?published_before='.now()->subYear()->toDateString())->assertJsonCount(1, 'data');
        $this->read('/blog/api/v1/posts?order=oldest')->assertJsonPath('data.0.author.name', $other->name);
        $this->read('/blog/api/v1/search?q=hotkey&tag=zoom')->assertJsonCount(1, 'data');
        $this->read('/blog/api/v1/search?q=hotkey&author=ada')->assertJsonCount(0, 'data');
    }

    #[Test]
    public function bad_parameters_are_a_422(): void
    {
        $this->read('/blog/api/v1/posts?per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->read('/blog/api/v1/posts?q=x')->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    private function guide(): Post
    {
        return Post::factory()->create([
            'slug' => 'mute-zoom',
            'title' => 'Mute Zoom from anywhere',
            'excerpt' => 'One shortcut for every meeting.',
            'author_id' => Author::default()->id,
            'body_html' => '<p>Meetings pile up.</p>'
                .'<h2 id="set-up-the-shortcut">Set up the shortcut</h2>'
                .'<p>Pick a <strong>global hotkey</strong> that works in Teams and Meet. See <a href="/blog/settings">the settings guide</a>.</p>'
                .'<script>steal()</script>'
                .'<h2 id="troubleshooting">Troubleshooting</h2>'
                .'<p>Check the <a href="https://zoom.example/docs">Zoom docs</a>.</p>',
            'faq' => [['question' => 'Does it work with Webex?', 'answer' => 'Yes, since version 2.']],
            'published_at' => '2026-10-01 09:00:00',
            'source_updated_at' => '2026-10-05 09:00:00',
        ]);
    }

    /** @param  array<string, string>  $headers */
    private function read(string $uri, ?string $token = self::READ_TOKEN, array $headers = []): TestResponse
    {
        return $this->getJson($uri, [...$headers, 'Authorization' => 'Bearer '.$token]);
    }
}
