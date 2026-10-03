@extends(config('blog-kit.layout'))

@section(config('blog-kit.sections.title'), $seo->title)

@push(config('blog-kit.head_stack'))
    @include('blog-kit::partials.head', ['seo' => $seo])
@endpush

@section(config('blog-kit.sections.content'))
    <div class="blog-kit mx-auto w-full max-w-(--blog-kit-max-width) px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <header class="mb-10 max-w-(--blog-kit-prose-width)">
            <h1 class="font-(family-name:--blog-kit-font-heading) text-3xl font-bold tracking-tight text-(--blog-kit-heading) sm:text-4xl">{{ config('blog-kit.blog_title') }}</h1>
            @if (config('blog-kit.blog_description'))
                <p class="mt-3 text-lg text-(--blog-kit-muted)">{{ config('blog-kit.blog_description') }}</p>
            @endif
        </header>

        @include('blog-kit::partials.listing', ['posts' => $posts])
    </div>
@endsection
