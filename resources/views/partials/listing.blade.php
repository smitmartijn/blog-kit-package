@php /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $posts */ @endphp
@if ($posts->isEmpty())
    <p class="rounded-(--blog-kit-radius) border border-dashed border-(--blog-kit-border) p-10 text-center text-(--blog-kit-muted)">No posts yet.</p>
@else
    <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($posts as $post)
            @include('blog-kit::partials.card', ['post' => $post])
        @endforeach
    </div>
    @include('blog-kit::partials.pagination', ['paginator' => $posts])
@endif
