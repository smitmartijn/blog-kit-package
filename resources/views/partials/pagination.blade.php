@php /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator */ @endphp
@if ($paginator->hasPages())
    <nav class="mt-12 flex items-center justify-between gap-4 border-t border-(--blog-kit-border) pt-6 text-sm" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span></span>
        @else
            <a href="{{ $paginator->currentPage() === 2 ? strtok($paginator->url(1), '?') : $paginator->previousPageUrl() }}" rel="prev" class="font-medium text-(--blog-kit-accent) hover:underline">&larr; Newer posts</a>
        @endif
        <span class="text-(--blog-kit-muted)">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="font-medium text-(--blog-kit-accent) hover:underline">Older posts &rarr;</a>
        @else
            <span></span>
        @endif
    </nav>
@endif
