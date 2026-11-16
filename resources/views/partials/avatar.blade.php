@php /** @var \Lostdomain\BlogKit\Models\Author $author */ $size ??= 'size-10'; @endphp
@if ($author->avatar_url)
    <img src="{{ $author->avatar_url }}" alt="" loading="lazy" decoding="async" class="{{ $size }} shrink-0 rounded-full object-cover">
@else
    <span class="{{ $size }} inline-flex shrink-0 items-center justify-center rounded-full bg-(--blog-kit-surface) font-semibold text-(--blog-kit-muted) ring-1 ring-(--blog-kit-border)" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($author->name, 0, 1)) }}</span>
@endif
