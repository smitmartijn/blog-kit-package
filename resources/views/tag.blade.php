@extends(config('blog-kit.layout'))

@section(config('blog-kit.sections.title'), $seo->title)

@push(config('blog-kit.head_stack'))
    @include('blog-kit::partials.head', ['seo' => $seo])
@endpush

@section(config('blog-kit.sections.content'))
    <div class="blog-kit mx-auto w-full max-w-(--blog-kit-max-width) px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <header class="mb-10">
            <a href="{{ route('blog-kit.index') }}" class="text-sm font-medium text-(--blog-kit-accent) hover:underline">&larr; {{ config('blog-kit.blog_title') }}</a>
            <h1 class="mt-3 font-(family-name:--blog-kit-font-heading) text-3xl font-bold tracking-tight text-(--blog-kit-heading) sm:text-4xl">{{ $tag->name }}</h1>
            <p class="mt-2 text-(--blog-kit-muted)">{{ $posts->total() }} {{ \Illuminate\Support\Str::plural('article', $posts->total()) }}</p>
        </header>

        @include('blog-kit::partials.listing', ['posts' => $posts])
    </div>
@endsection
