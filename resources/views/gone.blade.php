@extends(config('blog-kit.layout'))

@section(config('blog-kit.sections.title'), 'Article removed | '.config('blog-kit.site_name'))

@push(config('blog-kit.head_stack'))
    <meta name="robots" content="noindex">
    @include('blog-kit::partials.styles')
@endpush

@section(config('blog-kit.sections.content'))
    <div class="blog-kit mx-auto w-full max-w-(--blog-kit-prose-width) px-4 py-24 text-center sm:px-6">
        <p class="text-sm font-semibold uppercase tracking-wide text-(--blog-kit-muted)">410</p>
        <h1 class="mt-3 font-(family-name:--blog-kit-font-heading) text-3xl font-bold text-(--blog-kit-heading)">This article has been removed</h1>
        <p class="mt-4 text-(--blog-kit-muted)">It is no longer available. The rest of the blog is still here.</p>
        <a href="{{ route('blog-kit.index') }}" class="mt-8 inline-flex rounded-(--blog-kit-radius) bg-(--blog-kit-accent) px-5 py-2.5 text-sm font-semibold text-(--blog-kit-accent-contrast) hover:opacity-90">Browse the blog</a>
    </div>
@endsection
