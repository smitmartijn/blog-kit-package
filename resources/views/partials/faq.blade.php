@php /** @var list<array{question: string, answer: string}> $items */ @endphp
<section class="mt-14" aria-labelledby="blog-kit-faq">
    <h2 id="blog-kit-faq" class="font-(family-name:--blog-kit-font-heading) text-2xl font-bold text-(--blog-kit-heading)">Frequently asked questions</h2>
    <div class="mt-6 divide-y divide-(--blog-kit-border) rounded-(--blog-kit-radius) border border-(--blog-kit-border)">
        @foreach ($items as $item)
            <details class="group p-5" @if ($loop->first) open @endif>
                <summary class="flex cursor-pointer list-none items-start justify-between gap-4 font-semibold text-(--blog-kit-heading)">
                    <span>{{ $item['question'] }}</span>
                    <span class="mt-0.5 shrink-0 text-(--blog-kit-muted) transition group-open:rotate-45" aria-hidden="true">+</span>
                </summary>
                <div class="mt-3 leading-relaxed text-(--blog-kit-muted)">{!! nl2br(e($item['answer'])) !!}</div>
            </details>
        @endforeach
    </div>
</section>
