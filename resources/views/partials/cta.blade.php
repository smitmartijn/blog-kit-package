@php /** @var array{text: string, url: string|null, label: string|null} $cta */ @endphp
<aside class="mt-14 rounded-(--blog-kit-radius) border border-(--blog-kit-border) bg-(--blog-kit-surface) p-6 sm:p-8">
    <p class="text-lg font-medium leading-relaxed text-(--blog-kit-heading)">{{ $cta['text'] }}</p>
    @if ($cta['url'])
        <a href="{{ $cta['url'] }}" class="mt-5 inline-flex items-center rounded-(--blog-kit-radius) bg-(--blog-kit-accent) px-5 py-2.5 text-sm font-semibold text-(--blog-kit-accent-contrast) hover:opacity-90">
            {{ $cta['label'] ?: 'Learn more' }}
        </a>
    @endif
</aside>
