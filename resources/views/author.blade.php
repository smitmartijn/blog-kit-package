@php /** @var \Lostdomain\BlogKit\Models\Author $author */ @endphp
@extends(config('blog-kit.layout'))

@section(config('blog-kit.sections.title'), $seo->title)

@push(config('blog-kit.head_stack'))
    @include('blog-kit::partials.head', ['seo' => $seo])
@endpush

@section(config('blog-kit.sections.content'))
    <div class="blog-kit mx-auto w-full max-w-(--blog-kit-max-width) px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <header class="mb-12">
            <a href="{{ route('blog-kit.index') }}" class="text-sm font-medium text-(--blog-kit-accent) hover:underline">&larr; {{ config('blog-kit.blog_title') }}</a>
            <div class="mt-6 flex flex-col gap-6 sm:flex-row sm:items-start">
                @include('blog-kit::partials.avatar', ['author' => $author, 'size' => 'size-24 text-3xl'])
                <div class="max-w-(--blog-kit-prose-width)">
                    <h1 class="font-(family-name:--blog-kit-font-heading) text-3xl font-bold tracking-tight text-(--blog-kit-heading) sm:text-4xl">{{ $author->name }}</h1>
                    @if ($author->job_title)
                        <p class="mt-1 text-(--blog-kit-muted)">{{ $author->job_title }}</p>
                    @endif
                    @if ($author->bio)
                        <p class="mt-4 whitespace-pre-line leading-relaxed">{{ $author->bio }}</p>
                    @endif
                    <div class="mt-5">
                        @include('blog-kit::partials.author-links', ['author' => $author])
                    </div>
                </div>
            </div>
        </header>

        <h2 class="mb-6 font-(family-name:--blog-kit-font-heading) text-xl font-semibold text-(--blog-kit-heading)">{{ $posts->total() }} {{ \Illuminate\Support\Str::plural('article', $posts->total()) }}</h2>

        @include('blog-kit::partials.listing', ['posts' => $posts])
    </div>
@endsection
