<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ImportCommandTest extends TestCase
{
    private const DOCUSAURUS = __DIR__.'/../Fixtures/import/docusaurus';

    private const VUEPRESS = __DIR__.'/../Fixtures/import/vuepress';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('public', ['url' => 'https://example.test/storage']);
    }

    #[Test]
    public function it_imports_a_docusaurus_blog(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::DOCUSAURUS])
            ->expectsOutputToContain('Docusaurus blog')
            ->assertSuccessful();

        $this->assertSame(['dated-post', 'custom-slug'], Post::orderBy('published_at')->pluck('slug')->all());

        $dated = Post::where('slug', 'dated-post')->sole();
        $this->assertSame('A dated post', $dated->title);
        $this->assertSame(Post::SOURCE_LOCAL, $dated->source);
        $this->assertSame(Post::FORMAT_MARKDOWN, $dated->body_format);
        $this->assertSame('2024-01-15', $dated->published_at->toDateString());
        $this->assertSame(['Release notes', 'zoom'], $dated->tags->pluck('name')->sort()->values()->all());
        // Text before <!-- truncate --> becomes the excerpt when there's no description.
        $this->assertStringStartsWith('Intro paragraph with an emoji 📅', $dated->excerpt);
        $this->assertStringContainsString("> **Pro tip**\n>\n> Use **shortcuts**.", $dated->body_markdown);
        $this->assertStringContainsString("// <Tabs> inside code stays as is\nimport x from 'y';", $dated->body_markdown);
        $this->assertStringNotContainsString('truncate', $dated->body_markdown);
        $this->assertStringContainsString('<h2 id="details">Details</h2>', $dated->body_html);

        // Featured image from static/: copied, with its size.
        $this->assertStringStartsWith('https://example.test/storage/blog/imported/', $dated->featured_image['url']);
        $this->assertSame([2, 1], [$dated->featured_image['width'], $dated->featured_image['height']]);
        $this->assertStringContainsString('![Hero again]('.$dated->featured_image['url'].')', $dated->body_markdown);

        $folder = Post::where('slug', 'custom-slug')->sole();
        $this->assertSame('2024-02-03', $folder->published_at->toDateString());
        $this->assertSame('Written in MDX.', $folder->meta_description);
        $this->assertSame(['How-to'], $folder->tags->pluck('name')->all());
        $this->assertStringNotContainsString('import Tabs', $folder->body_markdown);
        $this->assertStringNotContainsString('<Tab', $folder->body_markdown);
        $this->assertStringNotContainsString('MDX comment', $folder->body_markdown);
        $this->assertStringNotContainsString('# Folder post', $folder->body_markdown);
        $this->assertStringContainsString('Press **Cmd**.', $folder->body_markdown);
        $this->assertStringContainsString('<figcaption>The diagram</figcaption>', $folder->body_html);
        $this->assertMatchesRegularExpression('#<img src="https://example.test/storage/blog/imported/[^"]+\.png" alt="Inline" />#', $folder->body_markdown);
        $this->assertStringContainsString('![Missing](./missing.png)', $folder->body_markdown);

        // Files are stored by content: the fixture's two identical PNGs become one file.
        $this->assertCount(1, Storage::disk('public')->allFiles('blog/imported'));
    }

    #[Test]
    public function docusaurus_dated_urls_keep_working_through_redirects(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::DOCUSAURUS])->assertSuccessful();

        $this->assertSame('2024/01/15/dated-post', Redirect::sole()->from_slug);
        $this->get('/blog/2024/01/15/dated-post')->assertStatus(301)->assertRedirect('https://example.test/blog/dated-post');
        $this->get('/blog/2024/01/15/dated-post/')->assertStatus(301);
        $this->get('/blog/custom-slug')->assertOk()->assertSee('Press <strong>Cmd</strong>.', false);
        $this->get('/blog/tags/zoom')->assertStatus(301)->assertRedirect('https://example.test/blog/tag/zoom');
        $this->get('/blog/page/2')->assertStatus(301)->assertRedirect('https://example.test/blog?page=2');
        $this->get('/blog/2024/01/15/unknown')->assertNotFound();
    }

    #[Test]
    public function drafts_partials_and_problems_are_reported(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::DOCUSAURUS, '-v' => true])
            ->expectsOutputToContain('Draft')
            ->expectsOutputToContain('_snippet.md')
            ->expectsOutputToContain('Image not found: ./missing.png')
            ->expectsOutputToContain('Removed components: <Tabs>, <TabItem>')
            ->assertSuccessful();

        $this->assertNull(Post::where('title', 'Not yet')->first());
    }

    #[Test]
    public function it_imports_a_vuepress_blog_keeping_its_folder_urls(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::VUEPRESS])
            ->expectsOutputToContain('Vuepress blog')
            ->assertSuccessful();

        // VuePress serves posts at their folder name; its "slug" front matter is ignored.
        $post = Post::where('slug', '2024-06-07-mics')->sole();
        $this->assertSame('The best microphones', $post->title);
        $this->assertSame('Pick a microphone for your home office.', $post->excerpt);
        $this->assertSame(['audio', 'news'], $post->tags->pluck('name')->sort()->values()->all());
        $this->assertStringStartsWith('Clear audio matters.', $post->body_markdown);
        $this->assertStringNotContainsString('CTAButton', $post->body_markdown);
        $this->assertStringContainsString('> **Note**', $post->body_markdown);
        // /blog/images/… (with the VuePress base) and /images/… both resolve to .vuepress/public.
        $this->assertMatchesRegularExpression('#<img src="https://example.test/storage/blog/imported/[^"]+\.png" alt="Yeti"#', $post->body_markdown);
        $this->assertStringStartsWith('https://example.test/storage/blog/imported/', $post->featured_image['url']);

        $this->get('/blog/2024-06-07-mics/')->assertOk()->assertSee('Clear audio matters.');

        // node_modules and dateless pages are not posts.
        $this->assertSame(2, Post::count());
    }

    #[Test]
    public function a_vuepress_url_that_isnt_a_valid_slug_redirects(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::VUEPRESS])->assertSuccessful();

        $this->assertTrue(Post::where('slug', '2024-10-03-loupedeck-6-0-issue')->exists());
        $this->get('/blog/2024-10-03-loupedeck-6.0-issue/')
            ->assertStatus(301)
            ->assertRedirect('https://example.test/blog/2024-10-03-loupedeck-6-0-issue');
    }

    #[Test]
    public function clean_slugs_drops_the_date_and_redirects_the_old_url(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::VUEPRESS, '--clean-slugs' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['mics', 'loupedeck-6-0-issue'], Post::pluck('slug')->all());
        $this->get('/blog/2024-06-07-mics/')->assertStatus(301)->assertRedirect('https://example.test/blog/mics');
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::DOCUSAURUS, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing is written.')
            ->expectsOutputToContain('Image not found: ./missing.png')
            ->assertSuccessful();

        $this->assertSame(0, Post::count());
        $this->assertSame(0, Redirect::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function running_it_again_skips_posts_unless_update_is_given(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::VUEPRESS])->assertSuccessful();
        Post::where('slug', '2024-06-07-mics')->update(['title' => 'Edited after import']);

        $this->artisan('blog-kit:import', ['path' => self::VUEPRESS])->assertSuccessful();
        $this->assertSame('Edited after import', Post::where('slug', '2024-06-07-mics')->value('title'));

        $this->artisan('blog-kit:import', ['path' => self::VUEPRESS, '--update' => true])->assertSuccessful();
        $this->assertSame('The best microphones', Post::where('slug', '2024-06-07-mics')->value('title'));
        $this->assertSame(2, Post::count());
    }

    #[Test]
    public function update_never_overwrites_a_post_it_did_not_import(): void
    {
        $handWritten = Post::factory()->local()->create(['slug' => '2024-06-07-mics', 'title' => 'Hand written']);

        $this->artisan('blog-kit:import', ['path' => self::VUEPRESS, '--update' => true])
            ->expectsOutputToContain('Another post (not imported) already uses this slug.')
            ->assertSuccessful();

        $this->assertSame('Hand written', $handWritten->refresh()->title);
    }

    #[Test]
    public function the_whole_import_ends_with_one_cache_purge(): void
    {
        $this->artisan('blog-kit:import', ['path' => self::DOCUSAURUS])->assertSuccessful();

        Queue::assertPushed(PurgeBlogCache::class, 1);
        Queue::assertPushed(PurgeBlogCache::class, fn (PurgeBlogCache $job) => in_array('https://example.test/blog/dated-post', $job->urls, true)
            && in_array('https://example.test/blog/custom-slug', $job->urls, true));
    }

    #[Test]
    public function a_path_that_does_not_exist_fails(): void
    {
        $this->artisan('blog-kit:import', ['path' => '/nope/not/here'])
            ->expectsOutputToContain('is not a directory')
            ->assertFailed();
    }

    #[Test]
    public function pointing_at_the_blog_folder_itself_works_too(): void
    {
        Artisan::call('blog-kit:import', ['path' => self::DOCUSAURUS.'/blog']);

        $this->assertSame(['custom-slug', 'dated-post'], Post::orderBy('slug')->pluck('slug')->all());
        $this->assertNotNull(Post::where('slug', 'dated-post')->value('featured_image'), 'static/ next to the blog folder is used for /img paths');
    }
}
