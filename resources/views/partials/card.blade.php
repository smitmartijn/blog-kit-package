@php /** @var \Lostdomain\BlogKit\Models\Post $post */ @endphp
<article class="group flex flex-col overflow-hidden rounded-(--blog-kit-radius) border border-(--blog-kit-border) bg-(--blog-kit-surface)">
    @if ($post->featuredImageUrl())
        <a href="{{ $post->url() }}" class="block aspect-video overflow-hidden" tabindex="-1" aria-hidden="true">
            <img src="{{ $post->featuredImageUrl() }}" alt="{{ $post->featured_image['alt_text'] ?? '' }}" loading="lazy" decoding="async"
                @if (! empty($post->featured_image['width'])) width="{{ $post->featured_image['width'] }}" height="{{ $post->featured_image['height'] }}" @endif
                class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]">
        </a>
    @endif
    <div class="flex flex-1 flex-col gap-3 p-5">
        @if ($post->tags->isNotEmpty())
            <div class="flex flex-wrap gap-2 text-xs font-medium uppercase tracking-wide">
                @foreach ($post->tags as $tag)
                    <a href="{{ $tag->url() }}" class="text-(--blog-kit-accent) hover:underline">{{ $tag->name }}</a>
                @endforeach
            </div>
        @endif
        <h2 class="font-(family-name:--blog-kit-font-heading) text-xl font-semibold leading-snug text-(--blog-kit-heading)">
            <a href="{{ $post->url() }}" class="hover:underline">{{ $post->title }}</a>
        </h2>
        @if ($post->excerpt)
            <p class="line-clamp-3 text-sm leading-relaxed text-(--blog-kit-muted)">{{ $post->excerpt }}</p>
        @endif
        <p class="mt-auto pt-2 text-xs text-(--blog-kit-muted)">
            <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->format('F j, Y') }}</time>
            <span aria-hidden="true">·</span>
            {{ $post->readingTime() }} min read
        </p>
    </div>
</article>
