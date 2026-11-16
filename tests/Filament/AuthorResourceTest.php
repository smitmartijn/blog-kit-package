<?php

namespace Lostdomain\BlogKit\Tests\Filament;

use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Lostdomain\BlogKit\Filament\Resources\Authors\AuthorResource;
use Lostdomain\BlogKit\Filament\Resources\Authors\Pages\CreateAuthor;
use Lostdomain\BlogKit\Filament\Resources\Authors\Pages\EditAuthor;
use Lostdomain\BlogKit\Filament\Resources\Authors\Pages\ListAuthors;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\CreatePost;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\EditPost;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Author;
use Lostdomain\BlogKit\Models\Post;
use PHPUnit\Framework\Attributes\Test;

class AuthorResourceTest extends FilamentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
        $this->allowBlogKit();
    }

    #[Test]
    public function the_list_shows_authors_and_the_migrated_default(): void
    {
        $this->get(AuthorResource::getUrl('index'))->assertOk()->assertSee('Martijn Smit');

        Livewire::test(ListAuthors::class)->assertCanSeeTableRecords(Author::all());
    }

    #[Test]
    public function the_section_follows_the_gate(): void
    {
        $this->allowBlogKit(false);

        $this->get(AuthorResource::getUrl('index'))->assertForbidden();
    }

    #[Test]
    public function an_author_can_be_created_with_a_bio_and_social_links(): void
    {
        Livewire::test(CreateAuthor::class)
            ->fillForm([
                'name' => 'Ada Lovelace',
                'slug' => 'ada-lovelace',
                'job_title' => 'Analyst',
                'bio' => 'Wrote the first program.',
                'website_url' => 'https://ada.example',
                'social_links' => [
                    ['platform' => 'x', 'url' => 'https://x.com/ada'],
                    ['platform' => 'linkedin', 'url' => 'https://www.linkedin.com/in/ada'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $author = Author::where('slug', 'ada-lovelace')->sole();
        $this->assertSame('Analyst', $author->job_title);
        $this->assertSame([
            ['platform' => 'x', 'url' => 'https://x.com/ada'],
            ['platform' => 'linkedin', 'url' => 'https://www.linkedin.com/in/ada'],
        ], $author->social_links);
        $this->assertFalse($author->is_default);
    }

    #[Test]
    public function slugs_must_be_unique_and_valid(): void
    {
        Livewire::test(CreateAuthor::class)
            ->fillForm(['name' => 'X', 'slug' => 'martijn-smit'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateAuthor::class)
            ->fillForm(['name' => 'X', 'slug' => 'Not Valid'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    #[Test]
    public function making_an_author_default_unsets_the_previous_default(): void
    {
        $previous = Author::sole();
        $author = Author::factory()->create();

        Livewire::test(EditAuthor::class, ['record' => $author->getRouteKey()])
            ->fillForm(['is_default' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($author->fresh()->is_default);
        $this->assertFalse($previous->fresh()->is_default);
        $this->assertTrue(Author::default()->is($author));
    }

    #[Test]
    public function editing_an_author_with_posts_purges_the_blog_including_the_old_author_page(): void
    {
        $author = Author::sole();
        Post::factory()->create(['slug' => 'by-martijn', 'author_id' => $author->id]);

        Livewire::test(EditAuthor::class, ['record' => $author->getRouteKey()])
            ->fillForm(['slug' => 'martijn'])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(PurgeBlogCache::class, fn (PurgeBlogCache $job) => in_array('https://example.test/blog/author/martijn-smit', $job->urls, true)
            && in_array('https://example.test/blog/author/martijn', $job->urls, true)
            && in_array('https://example.test/blog/by-martijn', $job->urls, true));
        $this->get('/blog/author/martijn')->assertOk();
    }

    #[Test]
    public function deleting_an_author_moves_their_posts_to_the_chosen_author(): void
    {
        $staying = Author::sole();
        $leaving = Author::factory()->create();
        $post = Post::factory()->create(['author_id' => $leaving->id]);

        Livewire::test(EditAuthor::class, ['record' => $leaving->getRouteKey()])
            ->callAction(DeleteAction::class, ['reassign_to' => $staying->id]);

        $this->assertModelMissing($leaving);
        $this->assertSame($staying->id, $post->fresh()->author_id);
    }

    #[Test]
    public function a_new_post_defaults_to_the_default_author_and_can_pick_another(): void
    {
        $other = Author::factory()->create(['name' => 'Ada']);

        Livewire::test(CreatePost::class)
            ->assertSchemaStateSet(['author_id' => Author::default()->id])
            ->fillForm([
                'title' => 'By Ada',
                'slug' => 'by-ada',
                'author_id' => $other->id,
                'body_markdown' => 'Hello.',
                'published_at' => now()->subHour()->toDateTimeString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($other->id, Post::sole()->author_id);
    }

    #[Test]
    public function a_post_can_be_moved_to_another_author_or_to_none(): void
    {
        $post = Post::factory()->local()->create(['author_id' => Author::sole()->id]);
        $other = Author::factory()->create();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertSchemaStateSet(['author_id' => $post->author_id])
            ->fillForm(['author_id' => $other->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($other->id, $post->fresh()->author_id);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['author_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($post->fresh()->author_id);
    }
}
