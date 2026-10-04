<?php

namespace Lostdomain\BlogKit\Tests\Filament;

use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\CreatePost;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\EditPost;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\ListPosts;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\ViewPost;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostResource;
use Lostdomain\BlogKit\Filament\Resources\Redirects\Pages\ManageRedirects;
use Lostdomain\BlogKit\Jobs\ProcessPostImages;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Services\PostPublisher;
use PHPUnit\Framework\Attributes\Test;

class PostResourceTest extends FilamentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
    }

    #[Test]
    public function without_the_gate_the_section_is_forbidden_and_an_error_is_logged(): void
    {
        Log::spy();

        $this->get(PostResource::getUrl('index'))->assertForbidden();

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'blog-kit.filament.gate_missing' && $context['gate'] === 'viewBlogKit');
    }

    #[Test]
    public function a_denying_gate_forbids_access(): void
    {
        $this->allowBlogKit(false);

        $this->get(PostResource::getUrl('index'))->assertForbidden();
    }

    #[Test]
    public function the_list_shows_posts_with_their_status(): void
    {
        $this->allowBlogKit();
        $live = Post::factory()->create(['title' => 'A live post']);
        $hidden = Post::factory()->hidden()->create(['title' => 'A hidden post']);

        $this->get(PostResource::getUrl('index'))->assertOk()->assertSee('A live post')->assertSee('Content');

        Livewire::test(ListPosts::class)
            ->assertCanSeeTableRecords([$live, $hidden])
            ->filterTable('status', 'hidden')
            ->assertCanSeeTableRecords([$hidden])
            ->assertCanNotSeeTableRecords([$live]);
    }

    #[Test]
    public function the_view_page_shows_image_errors(): void
    {
        $this->allowBlogKit();
        $post = Post::factory()->create(['images_status' => Post::IMAGES_FAILED, 'image_error' => '1 image(s) failed: https://cdn.test/a.png (HTTP 404)']);

        $this->get(PostResource::getUrl('view', ['record' => $post]))
            ->assertOk()
            ->assertSee('https://cdn.test/a.png (HTTP 404)');
    }

    #[Test]
    public function a_local_post_can_be_written_in_markdown(): void
    {
        $this->allowBlogKit();

        Livewire::test(CreatePost::class)
            ->assertSchemaStateSet(['body_format' => Post::FORMAT_MARKDOWN])
            ->fillForm([
                'title' => 'Written here',
                'slug' => 'written-here',
                'excerpt' => 'Local excerpt',
                'body_markdown' => "Hello from the admin.\n\n## Steps\n\n![Step 1](https://example.test/storage/blog/s1.png \"Open settings\")",
                'published_at' => now()->subHour()->toDateTimeString(),
                'tags' => ['Local'],
                'faq' => [['question' => 'Q?', 'answer' => 'A.']],
                'cta' => 'Buy it.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::sole();
        $this->assertSame(Post::FORMAT_MARKDOWN, $post->body_format);
        $this->assertStringContainsString('<h2 id="steps">Steps</h2>', $post->body_html);
        $this->assertStringContainsString('<figcaption>Open settings</figcaption>', $post->body_html);
        $this->assertSame(Post::SOURCE_LOCAL, $post->source);
        $this->assertNull($post->content_item_id);
        $this->assertSame(['Local'], $post->tags->pluck('name')->all());
        $this->assertSame([['question' => 'Q?', 'answer' => 'A.']], $post->faq);
        $this->assertSame('Buy it.', $post->cta);
        $this->assertFalse($post->isLocked());
        Queue::assertPushed(PurgeBlogCache::class);

        $this->get('/blog/written-here')
            ->assertOk()
            ->assertSee('Hello from the admin.')
            ->assertSee('<figcaption>Open settings</figcaption>', false);
    }

    #[Test]
    public function a_local_post_can_be_written_in_raw_html(): void
    {
        $this->allowBlogKit();

        Livewire::test(CreatePost::class)
            ->fillForm([
                'title' => 'Raw',
                'slug' => 'raw',
                'body_format' => Post::FORMAT_HTML,
                'body_html' => '<p class="lead">Hand-written HTML.</p>',
                'published_at' => now()->subHour()->toDateTimeString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::sole();
        $this->assertSame(Post::FORMAT_HTML, $post->body_format);
        $this->assertSame('<p class="lead">Hand-written HTML.</p>', $post->body_html);
    }

    #[Test]
    public function the_markdown_body_is_required_in_markdown_mode(): void
    {
        $this->allowBlogKit();

        Livewire::test(CreatePost::class)
            ->fillForm(['title' => 'X', 'slug' => 'x', 'body_markdown' => '', 'published_at' => now()->toDateTimeString()])
            ->call('create')
            ->assertHasFormErrors(['body_markdown' => 'required']);
    }

    #[Test]
    public function the_preview_renders_the_markdown_as_the_page_will(): void
    {
        $this->allowBlogKit();

        Livewire::test(CreatePost::class)
            ->fillForm(['body_markdown' => "## Live preview\n\n<script>alert(1)</script>Safe"])
            ->assertSeeHtml('<h2 id="live-preview">Live preview</h2>')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    #[Test]
    public function a_delivered_marketing_os_post_opens_in_markdown_and_saving_renders_from_it(): void
    {
        $this->allowBlogKit();
        $this->postSigned($this->payload([
            'body_html' => '<h2>Delivered</h2><p>As Marketing OS rendered it.</p>',
            'body_markdown' => "## Delivered\n\nAs Marketing OS rendered it.",
        ]));
        $post = Post::sole();
        $this->assertSame(Post::FORMAT_HTML, $post->body_format);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertSchemaStateSet(['body_format' => Post::FORMAT_MARKDOWN, 'body_markdown' => "## Delivered\n\nAs Marketing OS rendered it."])
            ->fillForm(['body_markdown' => "## Delivered\n\nEdited in the admin."])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame(Post::FORMAT_MARKDOWN, $post->body_format);
        $this->assertSame("<h2 id=\"delivered\">Delivered</h2>\n<p>Edited in the admin.</p>", $post->body_html);
        $this->assertTrue($post->isLocked());
    }

    #[Test]
    public function switching_an_html_post_to_markdown_converts_its_content(): void
    {
        $this->allowBlogKit();
        // An HTML-only post, like the ones delivered before Markdown editing existed.
        $post = Post::factory()->create([
            'body_html' => '<h2>Setup</h2><p>Install <strong>first</strong>.</p><figure><img src="https://example.test/storage/blog/a.png" alt="A"><figcaption>The app</figcaption></figure>',
            'body_markdown' => null,
        ]);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertSchemaStateSet(['body_format' => Post::FORMAT_HTML])
            ->fillForm(['body_format' => Post::FORMAT_MARKDOWN])
            ->assertSchemaStateSet([
                'body_markdown' => "## Setup\n\nInstall **first**.\n\n![A](https://example.test/storage/blog/a.png \"The app\")",
            ])
            ->assertSeeHtml('<h2 id="setup">Setup</h2>')
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame(Post::FORMAT_MARKDOWN, $post->body_format);
        $this->assertStringContainsString('<p>Install <strong>first</strong>.</p>', $post->body_html);
        $this->assertStringContainsString('<figcaption>The app</figcaption>', $post->body_html);
    }

    #[Test]
    public function switching_markdown_to_html_renders_the_current_markdown(): void
    {
        $this->allowBlogKit();

        Livewire::test(CreatePost::class)
            ->fillForm(['body_markdown' => "## Hello\n\nWorld"])
            ->fillForm(['body_format' => Post::FORMAT_HTML])
            ->assertSchemaStateSet(['body_html' => "<h2 id=\"hello\">Hello</h2>\n<p>World</p>"]);
    }

    #[Test]
    public function a_delivery_without_markdown_opens_in_html_mode(): void
    {
        $this->allowBlogKit();
        $this->postSigned($this->payload(['body_markdown' => null]));

        Livewire::test(EditPost::class, ['record' => Post::sole()->getRouteKey()])
            ->assertSchemaStateSet(['body_format' => Post::FORMAT_HTML]);
    }

    #[Test]
    public function a_post_saved_as_html_reopens_as_html_and_keeps_its_markdown_untouched(): void
    {
        $this->allowBlogKit();
        $this->postSigned($this->payload(['body_markdown' => 'Original markdown']));
        $post = Post::sole();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['body_format' => Post::FORMAT_HTML, 'body_html' => '<p>Hand-tuned HTML</p>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame(Post::FORMAT_HTML, $post->body_format);
        $this->assertSame('<p>Hand-tuned HTML</p>', $post->body_html);
        $this->assertSame('Original markdown', $post->body_markdown);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertSchemaStateSet(['body_format' => Post::FORMAT_HTML, 'body_html' => '<p>Hand-tuned HTML</p>']);
    }

    #[Test]
    public function a_marketing_os_update_after_releasing_the_lock_serves_its_html_again(): void
    {
        $this->allowBlogKit();
        $this->postSigned($this->payload());
        $post = Post::sole();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['body_markdown' => 'Local edit'])
            ->call('save');
        app(PostPublisher::class)->releaseLock($post->refresh());

        $this->postSigned($this->payload(['body_html' => '<p>From Marketing OS again</p>']))->assertOk();

        $post->refresh();
        $this->assertSame(Post::FORMAT_HTML, $post->body_format);
        $this->assertSame('<p>From Marketing OS again</p>', $post->body_html);
    }

    #[Test]
    public function creating_with_a_taken_or_reserved_slug_fails_validation(): void
    {
        $this->allowBlogKit();
        Post::factory()->create(['slug' => 'taken']);

        foreach (['taken', 'tag', 'Not A Slug'] as $slug) {
            Livewire::test(CreatePost::class)
                ->fillForm(['title' => 'X', 'slug' => $slug, 'body_html' => '<p>x</p>', 'published_at' => now()->toDateTimeString()])
                ->call('create')
                ->assertHasFormErrors(['slug']);
        }
    }

    #[Test]
    public function editing_a_marketing_os_post_locks_it_against_updates(): void
    {
        $this->allowBlogKit();
        $this->postSigned($this->payload());
        $post = Post::sole();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertSchemaStateSet(['title' => 'How to mute Zoom from anywhere', 'tags' => ['Productivity', 'Zoom']])
            ->fillForm(['title' => 'Edited here'])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame('Edited here', $post->title);
        $this->assertTrue($post->isLocked());
        $this->assertSame('admin@example.test', $post->locked_by);

        $this->postSigned($this->payload(['title' => 'From Marketing OS']))->assertStatus(409);
        $this->assertSame('Edited here', $post->refresh()->title);
    }

    #[Test]
    public function editing_the_slug_keeps_a_redirect(): void
    {
        $this->allowBlogKit();
        $post = Post::factory()->local()->create(['slug' => 'before']);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['slug' => 'after'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['before'], Redirect::pluck('from_slug')->all());
        $this->assertFalse($post->refresh()->isLocked(), 'Local posts have nothing to lock against.');
    }

    #[Test]
    public function releasing_the_lock_accepts_marketing_os_updates_again(): void
    {
        $this->allowBlogKit();
        $this->postSigned($this->payload());
        $post = Post::sole();
        $post->forceFill(['locked_at' => now(), 'locked_by' => 'admin@example.test'])->save();

        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])
            ->callAction('releaseLock')
            ->assertNotified();

        $this->assertFalse($post->refresh()->isLocked());
        $this->postSigned($this->payload(['title' => 'From Marketing OS']))->assertOk();
    }

    #[Test]
    public function hide_and_restore_change_public_visibility(): void
    {
        $this->allowBlogKit();
        $post = Post::factory()->create(['slug' => 'toggle']);

        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])->callAction('hide');
        $this->assertTrue($post->refresh()->isHidden());
        $this->get('/blog/toggle')->assertNotFound();

        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])->callAction('restore');
        $this->assertTrue($post->refresh()->isLive());
        Queue::assertPushed(PurgeBlogCache::class, 2);
    }

    #[Test]
    public function failed_images_can_be_retried(): void
    {
        $this->allowBlogKit();
        $post = Post::factory()->create(['images_status' => Post::IMAGES_FAILED]);

        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])->callAction('retryImages');

        Queue::assertPushed(ProcessPostImages::class, fn (ProcessPostImages $job) => $job->postId === $post->id);
        $this->assertSame(Post::IMAGES_PENDING, $post->refresh()->images_status);
    }

    #[Test]
    public function the_cache_can_be_purged_by_hand(): void
    {
        $this->allowBlogKit();
        $post = Post::factory()->create();

        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])->callAction('purgeCache');

        Queue::assertPushed(PurgeBlogCache::class, fn (PurgeBlogCache $job) => in_array($post->url(), $job->urls, true));
    }

    #[Test]
    public function deleting_a_post_purges_it(): void
    {
        $this->allowBlogKit();
        $post = Post::factory()->create(['slug' => 'doomed']);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertSame(0, Post::count());
        Queue::assertPushed(PurgeBlogCache::class, fn (PurgeBlogCache $job) => in_array('https://example.test/blog/doomed', $job->urls, true));
    }

    #[Test]
    public function redirects_can_be_added_and_removed(): void
    {
        $this->allowBlogKit();
        $post = Post::factory()->create(['slug' => 'target']);

        Livewire::test(ManageRedirects::class)
            ->callAction('create', ['from_slug' => 'legacy-docusaurus-slug', 'post_id' => $post->id])
            ->assertHasNoActionErrors();

        $this->get('/blog/legacy-docusaurus-slug')->assertRedirect('https://example.test/blog/target');

        Livewire::test(ManageRedirects::class)
            ->callAction('create', ['from_slug' => 'target', 'post_id' => $post->id])
            ->assertHasActionErrors(['from_slug']);
    }
}
