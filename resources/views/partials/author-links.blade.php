@php /** @var \Lostdomain\BlogKit\Models\Author $author */ @endphp
@if ($author->website_url || $author->socialProfiles() !== [])
    <ul class="flex flex-wrap gap-2 text-sm">
        @if ($author->website_url)
            <li><a href="{{ $author->website_url }}" rel="me noopener" class="inline-block rounded-full border border-(--blog-kit-border) px-3 py-1 hover:border-(--blog-kit-accent) hover:text-(--blog-kit-accent)">Website</a></li>
        @endif
        @foreach ($author->socialProfiles() as $profile)
            <li><a href="{{ $profile['url'] }}" rel="me noopener" class="inline-block rounded-full border border-(--blog-kit-border) px-3 py-1 hover:border-(--blog-kit-accent) hover:text-(--blog-kit-accent)">{{ $profile['label'] }}</a></li>
        @endforeach
    </ul>
@endif
