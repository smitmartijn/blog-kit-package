@php /** @var \Lostdomain\BlogKit\Models\Post $post */ @endphp
@extends(config('blog-kit.layout'))

@section(config('blog-kit.sections.title'), $seo->title)

@push(config('blog-kit.head_stack'))
    @include('blog-kit::partials.head', ['seo' => $seo])
@endpush

@section(config('blog-kit.sections.content'))
    <div class="blog-kit mx-auto w-full max-w-(--blog-kit-max-width) px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <article class="mx-auto max-w-(--blog-kit-prose-width)">
            <nav class="text-sm text-(--blog-kit-muted)" aria-label="Breadcrumb">
                <a href="{{ route('blog-kit.index') }}" class="font-medium text-(--blog-kit-accent) hover:underline">{{ config('blog-kit.blog_title') }}</a>
                @if ($post->tags->isNotEmpty())
                    <span aria-hidden="true">/</span>
                    <a href="{{ $post->tags->first()->url() }}" class="hover:underline">{{ $post->tags->first()->name }}</a>
                @endif
            </nav>

            <header class="mt-4">
                <h1 class="font-(family-name:--blog-kit-font-heading) text-3xl font-bold leading-tight tracking-tight text-(--blog-kit-heading) sm:text-4xl">{{ $post->title }}</h1>
                @if ($post->excerpt)
                    <p class="mt-4 text-lg leading-relaxed text-(--blog-kit-muted)">{{ $post->excerpt }}</p>
                @endif
                <p class="mt-5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-(--blog-kit-muted)">
                    @if ($post->author)
                        <a href="{{ $post->author->url() }}" class="inline-flex items-center gap-2 font-medium text-(--blog-kit-heading) hover:underline" rel="author">
                            @include('blog-kit::partials.avatar', ['author' => $post->author, 'size' => 'size-7 text-xs'])
                            {{ $post->author->name }}
                        </a>
                        <span aria-hidden="true">·</span>
                    @endif
                    <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->format('F j, Y') }}</time>
                    @if ($post->modifiedAt() && $post->modifiedAt()->isAfter($post->published_at->copy()->endOfDay()))
                        <span aria-hidden="true">·</span>
                        <span>Updated <time datetime="{{ $post->modifiedAt()->toIso8601String() }}">{{ $post->modifiedAt()->format('F j, Y') }}</time></span>
                    @endif
                    <span aria-hidden="true">·</span>
                    <span>{{ $post->readingTime() }} min read</span>
                </p>
            </header>

            @if ($post->featuredImageUrl())
                <figure class="mt-8">
                    <img src="{{ $post->featuredImageUrl() }}" alt="{{ $post->featured_image['alt_text'] ?? '' }}" fetchpriority="high" decoding="async"
                        @if (! empty($post->featured_image['width'])) width="{{ $post->featured_image['width'] }}" height="{{ $post->featured_image['height'] }}" @endif
                        class="aspect-video w-full rounded-(--blog-kit-radius) object-cover">
                </figure>
            @endif

            <div class="blog-kit-prose mt-10">
                {!! $body !!}
            </div>

            @if ($post->faqItems() !== [])
                @include('blog-kit::partials.faq', ['items' => $post->faqItems()])
            @endif

            @if ($post->ctaData())
                @include('blog-kit::partials.cta', ['cta' => $post->ctaData()])
            @endif

            @if ($post->tags->isNotEmpty())
                <div class="mt-10 flex flex-wrap gap-2">
                    @foreach ($post->tags as $tag)
                        <a href="{{ $tag->url() }}" class="rounded-full border border-(--blog-kit-border) px-3 py-1 text-sm hover:border-(--blog-kit-accent) hover:text-(--blog-kit-accent)">{{ $tag->name }}</a>
                    @endforeach
                </div>
            @endif

            @if ($post->author && ($post->author->bio || $post->author->job_title))
                <aside class="mt-12 flex gap-4 rounded-(--blog-kit-radius) border border-(--blog-kit-border) bg-(--blog-kit-surface) p-5" aria-label="About the author">
                    @include('blog-kit::partials.avatar', ['author' => $post->author, 'size' => 'size-14 text-xl'])
                    <div class="min-w-0">
                        <p class="font-semibold text-(--blog-kit-heading)"><a href="{{ $post->author->url() }}" class="hover:underline" rel="author">{{ $post->author->name }}</a></p>
                        @if ($post->author->job_title)
                            <p class="text-sm text-(--blog-kit-muted)">{{ $post->author->job_title }}</p>
                        @endif
                        @if ($post->author->bio)
                            <p class="mt-2 whitespace-pre-line text-sm leading-relaxed">{{ $post->author->bio }}</p>
                        @endif
                        <div class="mt-3">
                            @include('blog-kit::partials.author-links', ['author' => $post->author])
                        </div>
                    </div>
                </aside>
            @endif
        </article>

        @if ($related->isNotEmpty())
            <section class="mt-20 border-t border-(--blog-kit-border) pt-12" aria-labelledby="blog-kit-related">
                <h2 id="blog-kit-related" class="font-(family-name:--blog-kit-font-heading) text-2xl font-bold text-(--blog-kit-heading)">Related articles</h2>
                <div class="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($related as $relatedPost)
                        @include('blog-kit::partials.card', ['post' => $relatedPost])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
