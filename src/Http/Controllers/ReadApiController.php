<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Services\HtmlSanitizer;
use Lostdomain\BlogKit\Services\HtmlToMarkdown;
use Lostdomain\BlogKit\Services\PostSearch;
use Lostdomain\BlogKit\Support\PostText;

/**
 * Read-only API over published posts, for agents: an inventory, full-text
 * search and full posts. Hidden, unpublished and draft posts don't exist here.
 */
class ReadApiController
{
    /** The inventory leaves out bodies, so it stays light. */
    private const LIST_COLUMNS = ['id', 'slug', 'title', 'excerpt', 'author_id', 'published_at', 'source_updated_at', 'updated_at', 'revision'];

    public function __construct(private PostSearch $search) {}

    /** GET posts: every published post, newest first, without bodies. */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $posts = $this->published($filters)
            ->orderBy('published_at', $filters['order'] === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', $filters['order'] === 'oldest' ? 'asc' : 'desc')
            ->paginate($filters['per_page'], self::LIST_COLUMNS)
            ->withQueryString();

        return response()->json([
            'data' => $posts->getCollection()->map(fn (Post $post) => $this->summary($post))->all(),
            ...$this->paging($posts),
        ]);
    }

    /** GET search?q=: published posts containing every term, with excerpts. */
    public function search(Request $request): JsonResponse
    {
        $filters = $this->filters($request, search: true);
        $terms = $this->search->terms($filters['q']);

        if ($terms === []) {
            return response()->json(['message' => 'The q field must contain a search term.', 'errors' => ['q' => ['The q field must contain a search term.']]], 422);
        }

        $query = $this->published($filters);
        $this->search->apply($query, $terms);

        $posts = $query->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'], [...self::LIST_COLUMNS, 'body_html', 'faq'])
            ->withQueryString();

        return response()->json([
            'query' => ['q' => $filters['q'], 'terms' => $terms, 'match' => 'all terms, case-insensitive, anywhere in title, excerpt, headings, body or FAQ'],
            'data' => $posts->getCollection()->map(fn (Post $post) => [
                ...$this->summary($post),
                ...$this->search->matches($post, $terms),
            ])->all(),
            ...$this->paging($posts),
        ]);
    }

    /** GET posts/{slug or id}: one published post with its full content. */
    public function show(Request $request, string $post): JsonResponse|Response
    {
        $request->validate(['format' => ['nullable', 'in:markdown,html']]);

        $found = $this->find($post);

        if ($found === null) {
            return response()->json(['error' => 'not_found', 'message' => 'No published post has this slug or id.'], 404);
        }

        if ($found->isUnpublished()) {
            return response()->json(['error' => 'gone', 'message' => 'This post was unpublished.', 'slug' => $found->slug], 410);
        }

        $etag = '"'.sha1($found->id.':'.$found->revision.':'.$found->updated_at?->getTimestamp()).'"';

        if (in_array($etag, $request->getETags(), true)) {
            return response()->noContent(304)->setEtag(trim($etag, '"'));
        }

        $format = $request->query('format') === 'html' ? 'html' : 'markdown';
        $html = $this->absoluteLinks(app(HtmlSanitizer::class)->sanitize($found->body_html));

        return response()->json(['data' => [
            ...$this->summary($found),
            'meta_description' => $found->meta_description,
            'outline' => $this->outline($found, $html),
            'content' => [
                'format' => $format,
                'body' => $format === 'html' ? $html : app(HtmlToMarkdown::class)->convert($html),
            ],
            'faq' => $found->faqItems(),
            'cta' => $found->ctaData(),
            'featured_image' => $found->featured_image,
        ]])->setEtag(trim($etag, '"'))->setLastModified($found->updated_at);
    }

    /**
     * @return array{q: string, tag: string|null, author: string|null, published_after: string|null, published_before: string|null, order: string, per_page: int}
     */
    private function filters(Request $request, bool $search = false): array
    {
        $data = $request->validate([
            'q' => [$search ? 'required' : 'prohibited', 'string', 'max:300'],
            'tag' => ['nullable', 'string', 'max:191'],
            'author' => ['nullable', 'string', 'max:191'],
            'published_after' => ['nullable', 'date'],
            'published_before' => ['nullable', 'date'],
            'order' => ['nullable', 'in:newest,oldest'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'q' => (string) ($data['q'] ?? ''),
            'tag' => $data['tag'] ?? null,
            'author' => $data['author'] ?? null,
            'published_after' => $data['published_after'] ?? null,
            'published_before' => $data['published_before'] ?? null,
            'order' => $data['order'] ?? 'newest',
            'per_page' => (int) ($data['per_page'] ?? ($search ? 20 : 50)),
        ];
    }

    /**
     * @param  array{tag: string|null, author: string|null, published_after: string|null, published_before: string|null}  $filters
     * @return Builder<Post>
     */
    private function published(array $filters): Builder
    {
        return Post::live()
            ->with(['tags:id,name,slug', 'author:id,name,slug'])
            ->when($filters['tag'], fn (Builder $query, string $tag) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('slug', $tag)))
            ->when($filters['author'], fn (Builder $query, string $author) => $query->whereHas('author', fn (Builder $authors) => $authors->where('slug', $author)))
            ->when($filters['published_after'], fn (Builder $query, string $date) => $query->where('published_at', '>=', now()->parse($date)))
            ->when($filters['published_before'], fn (Builder $query, string $date) => $query->where('published_at', '<=', now()->parse($date)));
    }

    /** @return array<string, mixed> */
    private function summary(Post $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'url' => $post->url(),
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'author' => $post->author ? ['name' => $post->author->name, 'url' => $post->author->url()] : null,
            'tags' => $post->tags->pluck('name')->all(),
            'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => ($post->modifiedAt() ?? $post->published_at)?->toIso8601String(),
            'version' => $post->revision,
            ...(array_key_exists('body_html', $post->getAttributes()) ? ['reading_time_minutes' => $post->readingTime()] : []),
        ];
    }

    /** @return array{meta: array<string, int>, links: array<string, string|null>} */
    private function paging(LengthAwarePaginator $posts): array
    {
        return [
            'meta' => [
                'page' => $posts->currentPage(),
                'per_page' => $posts->perPage(),
                'last_page' => $posts->lastPage(),
                'total' => $posts->total(),
                // Every published post, ignoring q and filters: tells "no match" apart from "nothing here".
                'total_published' => Post::live()->count(),
            ],
            'links' => [
                'next' => $posts->nextPageUrl(),
                'prev' => $posts->previousPageUrl(),
            ],
        ];
    }

    /** A live post by slug, old slug (redirect) or id; unpublished posts too, for the 410. */
    private function find(string $key): ?Post
    {
        $with = ['tags:id,name,slug', 'author:id,name,slug'];
        $post = Post::with($with)->where('slug', $key)->first()
            ?? Redirect::where('from_slug', $key)->first()?->post?->load($with)
            ?? (ctype_digit($key) ? Post::with($with)->find((int) $key) : null);

        if ($post === null || $post->isHidden() || $post->published_at === null) {
            return null;
        }

        return $post;
    }

    /** @return list<array{level: int, text: string, anchor: string|null}> */
    private function outline(Post $post, string $html): array
    {
        return array_values(array_map(
            fn (array $section) => [
                'level' => (int) $section['level'],
                'text' => (string) $section['heading'],
                'anchor' => $section['id'] !== null ? $post->url().'#'.$section['id'] : null,
            ],
            array_filter(PostText::sections($html), fn (array $section) => $section['heading'] !== null),
        ));
    }

    /** Root-relative links and images become absolute, so they work outside the site. */
    private function absoluteLinks(string $html): string
    {
        $root = rtrim(url('/'), '/');

        return (string) preg_replace('#\b(href|src)="/(?!/)#i', '$1="'.$root.'/', $html);
    }
}
