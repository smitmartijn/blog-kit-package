<?php

namespace Lostdomain\BlogKit\Services;

use Illuminate\Database\Eloquent\Builder;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Support\PostText;

/**
 * Full-text search over published posts for the read API. Every term
 * (a word, or a "quoted phrase") must appear somewhere in the title,
 * excerpt, headings, body or FAQ. Case-insensitive; no stemming.
 */
class PostSearch
{
    public const MAX_TERMS = 10;

    private const EXCERPT_RADIUS = 110;

    /** @return list<string> lowercased terms, quoted phrases kept whole */
    public function terms(string $query): array
    {
        preg_match_all('/"([^"]+)"|(\S+)/u', $query, $matches, PREG_SET_ORDER);

        $terms = [];

        foreach ($matches as $match) {
            $term = mb_strtolower(PostText::squish($match[1] !== '' ? $match[1] : ($match[2] ?? '')));
            $term = trim($term, " \t\n\r\0\x0B\"");

            if ($term !== '') {
                $terms[] = $term;
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, self::MAX_TERMS);
    }

    /**
     * Narrows the query to posts containing every term; title matches first.
     *
     * @param  Builder<Post>  $query
     * @param  list<string>  $terms
     */
    public function apply(Builder $query, array $terms): void
    {
        foreach ($terms as $term) {
            $query->whereRaw("blog_kit_posts.search_text LIKE ? ESCAPE '!'", ['%'.$this->escape($term).'%']);
        }

        $titleMatch = implode(' AND ', array_fill(0, count($terms), "LOWER(blog_kit_posts.title) LIKE ? ESCAPE '!'"));
        $query->orderByRaw(
            "CASE WHEN {$titleMatch} THEN 0 ELSE 1 END",
            array_map(fn (string $term) => '%'.$this->escape($term).'%', $terms),
        );
    }

    /**
     * Where the terms occur: "title" and/or "body", plus up to $limit
     * excerpts from the body, each with the heading of its section.
     *
     * @param  list<string>  $terms
     * @return array{matched_in: list<string>, excerpts: list<array{heading: string|null, anchor: string|null, text: string}>}
     */
    public function matches(Post $post, array $terms, int $limit = 3): array
    {
        $matchedIn = [];

        if ($this->containsAny((string) $post->title, $terms)) {
            $matchedIn[] = 'title';
        }

        if ($this->containsAny((string) $post->excerpt, $terms)) {
            $matchedIn[] = 'excerpt';
        }

        $candidates = [];

        foreach (PostText::sections($post->body_html) as $index => $section) {
            $found = array_values(array_filter($terms, fn (string $term) => mb_stripos($section['text'], $term) !== false));
            $inHeading = $section['heading'] !== null && $this->containsAny($section['heading'], $terms);

            if ($found === [] && ! $inHeading) {
                continue;
            }

            $candidates[] = [
                'score' => count($found) * 2 + ($inHeading ? 1 : 0),
                'index' => $index,
                'heading' => $section['heading'],
                'anchor' => $section['id'] !== null ? $post->url().'#'.$section['id'] : null,
                'text' => $this->excerpt($section['text'], $found),
            ];
        }

        foreach ($post->faqItems() as $item) {
            $text = $item['question'].' '.$item['answer'];
            $found = array_values(array_filter($terms, fn (string $term) => mb_stripos($text, $term) !== false));

            if ($found !== []) {
                $candidates[] = ['score' => count($found) * 2, 'index' => PHP_INT_MAX, 'heading' => 'FAQ: '.$item['question'], 'anchor' => null, 'text' => $this->excerpt($item['answer'], $found)];
            }
        }

        if ($candidates !== []) {
            $matchedIn[] = 'body';
        }

        // Best sections first; ties keep the article's order.
        usort($candidates, fn (array $a, array $b) => [$b['score'], $a['index']] <=> [$a['score'], $b['index']]);

        return [
            'matched_in' => $matchedIn,
            'excerpts' => array_map(
                fn (array $candidate) => ['heading' => $candidate['heading'], 'anchor' => $candidate['anchor'], 'text' => $candidate['text']],
                array_slice($candidates, 0, $limit),
            ),
        ];
    }

    /** @param  list<string>  $terms  terms found in $text; empty takes the start */
    private function excerpt(string $text, array $terms): string
    {
        $positions = array_filter(array_map(fn (string $term) => mb_stripos($text, $term), $terms), fn ($position) => $position !== false);
        $at = $positions === [] ? 0 : min($positions);
        $length = mb_strlen($text);

        $start = max(0, $at - self::EXCERPT_RADIUS);
        $end = min($length, $at + self::EXCERPT_RADIUS * 2);

        // Widen to whole words.
        while ($start > 0 && ! preg_match('/\s/u', mb_substr($text, $start - 1, 1))) {
            $start--;
        }

        while ($end < $length && ! preg_match('/\s/u', mb_substr($text, $end, 1))) {
            $end++;
        }

        return ($start > 0 ? '…' : '').trim(mb_substr($text, $start, $end - $start)).($end < $length ? '…' : '');
    }

    /** @param  list<string>  $terms */
    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (mb_stripos($text, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    private function escape(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
