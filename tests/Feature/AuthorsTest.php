<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Author;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\CacheUrls;
use Lostdomain\BlogKit\Services\PostPublisher;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AuthorsTest extends TestCase
{
    private const MIGRATION = __DIR__.'/../../database/migrations/2026_11_16_000000_create_blog_kit_authors_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function the_migration_turns_the_configured_author_into_the_default_author_of_every_existing_post(): void
    {
        $migration = require self::MIGRATION;
        $migration->down();

        // Posts as they exist on a site before upgrading: no author column.
        $now = now();
        DB::table('blog_kit_posts')->insert([
            ['slug' => 'first', 'title' => 'First', 'body_html' => '<p>1</p>', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'second', 'title' => 'Second', 'body_html' => '<p>2</p>', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $migration->up();

        $author = Author::sole();
        $this->assertSame('Martijn Smit', $author->name);
        $this->assertSame('martijn-smit', $author->slug);
        $this->assertSame('https://example.test/about', $author->website_url);
        $this->assertTrue($author->is_default);
        $this->assertSame([$author->id, $author->id], Post::pluck('author_id')->all());
    }

    #[Test]
    public function without_a_configured_author_the_migration_creates_none(): void
    {
        config(['blog-kit.author.name' => null]);
        $migration = require self::MIGRATION;
        $migration->down();
        $migration->up();

        $this->assertSame(0, Author::count());
    }

    #[Test]
    public function a_new_marketing_os_post_gets_the_default_author(): void
    {
        Author::query()->delete();
        Author::factory()->create(['name' => 'First']);
        $default = Author::factory()->default()->create(['name' => 'Default']);

        $this->postSigned($this->payload())->assertOk();

        $this->assertSame($default->id, Post::sole()->author_id);
    }

    #[Test]
    public function without_a_default_author_the_first_author_is_used(): void
    {
        Author::query()->delete();
        $first = Author::factory()->create();
        Author::factory()->create();

        $this->postSigned($this->payload())->assertOk();

        $this->assertSame($first->id, Post::sole()->author_id);
    }

    #[Test]
    public function without_any_author_marketing_os_posts_have_none(): void
    {
        Author::query()->delete();

        $this->postSigned($this->payload())->assertOk();

        $this->assertNull(Post::sole()->author_id);
    }

    #[Test]
    public function an_update_keeps_the_author_the_post_has(): void
    {
        $chosen = Author::factory()->create();
        $this->postSigned($this->payload())->assertOk();
        Post::sole()->forceFill(['author_id' => $chosen->id])->save();

        $this->postSigned($this->payload(['event' => 'update', 'title' => 'Updated', 'updated_at' => now()->toIso8601String()]))->assertOk();

        $this->assertSame('Updated', Post::sole()->title);
        $this->assertSame($chosen->id, Post::sole()->author_id);
    }

    #[Test]
    public function the_author_page_shows_the_profile_and_their_live_posts(): void
    {
        $author = Author::factory()->create([
            'name' => 'Ada Lovelace',
            'slug' => 'ada',
            'job_title' => 'Analyst',
            'bio' => 'Wrote the first program.',
            'avatar_url' => 'https://example.test/ada.jpg',
            'website_url' => 'https://ada.example',
            'social_links' => [
                ['platform' => 'x', 'url' => 'https://x.com/ada_l'],
                ['platform' => 'github', 'url' => 'https://github.com/ada'],
                ['platform' => 'other', 'url' => 'javascript:alert(1)'],
            ],
        ]);
        Post::factory()->create(['title' => 'Notes on the engine', 'author_id' => $author->id]);
        Post::factory()->hidden()->create(['title' => 'Hidden one', 'author_id' => $author->id]);
        Post::factory()->create(['title' => 'Someone else']);

        $html = $this->get('/blog/author/ada')
            ->assertOk()
            ->assertSee('<title>Ada Lovelace – Blog | Example</title>', false)
            ->assertSee('Analyst')
            ->assertSee('Wrote the first program.')
            ->assertSee('href="https://github.com/ada"', false)
            ->assertSee('href="https://ada.example"', false)
            ->assertDontSee('javascript:alert(1)', false)
            ->assertSee('<meta name="twitter:creator" content="@ada_l">', false)
            ->assertSee('Notes on the engine')
            ->assertDontSee('Hidden one')
            ->assertDontSee('Someone else')
            ->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);
        $profile = json_decode($match[1], true);

        $this->assertSame('ProfilePage', $profile['@type']);
        $this->assertSame('Ada Lovelace', $profile['mainEntity']['name']);
        $this->assertSame('https://example.test/blog/author/ada', $profile['mainEntity']['url']);
        $this->assertSame(['https://ada.example', 'https://x.com/ada_l', 'https://github.com/ada'], $profile['mainEntity']['sameAs']);
    }

    #[Test]
    public function an_author_without_posts_has_a_page_and_unknown_authors_404(): void
    {
        Author::factory()->create(['slug' => 'new-writer', 'name' => 'New Writer']);

        $this->get('/blog/author/new-writer')->assertOk()->assertSee('0 articles');
        $this->get('/blog/author/new-writer?page=2')->assertNotFound();
        $this->get('/blog/author/nobody')->assertNotFound();
    }

    #[Test]
    public function the_article_links_its_author_and_shows_their_bio(): void
    {
        $author = Author::factory()->create(['name' => 'Ada Lovelace', 'slug' => 'ada', 'bio' => 'Wrote the first program.']);
        Post::factory()->create(['slug' => 'engine', 'author_id' => $author->id]);

        $this->get('/blog/engine')
            ->assertOk()
            ->assertSee('href="https://example.test/blog/author/ada"', false)
            ->assertSee('About the author', false)
            ->assertSee('Wrote the first program.');

        $this->get('/blog')->assertOk()->assertSee('Ada Lovelace');
    }

    #[Test]
    public function a_post_without_an_author_has_no_byline(): void
    {
        Post::factory()->create(['slug' => 'anonymous', 'author_id' => null]);

        $this->get('/blog/anonymous')->assertOk()->assertDontSee('rel="author"', false);
    }

    #[Test]
    public function the_sitemap_lists_authors_with_live_posts(): void
    {
        $active = Author::factory()->create(['slug' => 'active']);
        Author::factory()->create(['slug' => 'idle']);
        Post::factory()->create(['author_id' => $active->id]);

        $this->get('/blog/sitemap.xml')
            ->assertOk()
            ->assertSee('https://example.test/blog/author/active')
            ->assertDontSee('https://example.test/blog/author/idle');
    }

    #[Test]
    public function moving_a_post_to_another_author_purges_both_author_pages(): void
    {
        $from = Author::factory()->create(['slug' => 'from']);
        $to = Author::factory()->create(['slug' => 'to']);
        $post = Post::factory()->create(['author_id' => $from->id]);

        app(PostPublisher::class)->save($post, ['author_id' => $to->id]);

        Queue::assertPushed(PurgeBlogCache::class, fn (PurgeBlogCache $job) => in_array('https://example.test/blog/author/from', $job->urls, true)
            && in_array('https://example.test/blog/author/to', $job->urls, true));
    }

    #[Test]
    public function the_whole_blog_includes_author_pages(): void
    {
        $this->assertContains('https://example.test/blog/author/martijn-smit', app(CacheUrls::class)->all());
    }

    #[Test]
    public function author_is_a_reserved_post_slug(): void
    {
        $this->postSigned($this->payload(['slug' => 'author']))->assertUnprocessable()->assertJsonValidationErrors('slug');
    }
}
