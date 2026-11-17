<?php

namespace Lostdomain\BlogKit\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Plain text of a post body, split into sections at each heading. Used to
 * build the search index (search_text) and the excerpts around matches.
 */
final class PostText
{
    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const INLINE = ['a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var'];

    private const SKIP = ['script', 'style', 'template', 'iframe', 'svg'];

    /**
     * @return list<array{heading: string|null, level: int|null, id: string|null, text: string}>
     */
    public static function sections(string $html): array
    {
        $sections = [['heading' => null, 'level' => null, 'id' => null, 'text' => '']];

        if (trim($html) !== '') {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $body = $document->getElementsByTagName('body')->item(0);

            if ($body) {
                self::walk($body, $sections);
            }
        }

        return array_values(array_filter(
            array_map(fn (array $section) => [...$section, 'text' => self::squish($section['text'])], $sections),
            fn (array $section) => $section['text'] !== '' || $section['heading'] !== null,
        ));
    }

    /**
     * Lowercased, whitespace-normalised text the search matches against:
     * title, excerpt, every heading and paragraph, and the FAQ.
     *
     * @param  list<array{question?: mixed, answer?: mixed}>|null  $faq
     */
    public static function searchText(?string $title, ?string $excerpt, ?string $html, ?array $faq = null): string
    {
        $parts = [(string) $title, (string) $excerpt];

        foreach (self::sections((string) $html) as $section) {
            $parts[] = (string) $section['heading'];
            $parts[] = $section['text'];
        }

        foreach ((array) $faq as $item) {
            if (is_array($item)) {
                $parts[] = (string) ($item['question'] ?? '');
                $parts[] = (string) ($item['answer'] ?? '');
            }
        }

        return mb_strtolower(self::squish(implode(' ', $parts)));
    }

    public static function squish(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @param  list<array{heading: string|null, level: int|null, id: string|null, text: string}>  $sections */
    private static function walk(DOMNode $node, array &$sections): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $sections[array_key_last($sections)]['text'] .= $child->textContent;

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::SKIP, true)) {
                continue;
            }

            if (in_array($tag, self::HEADINGS, true)) {
                $sections[] = [
                    'heading' => self::squish($child->textContent),
                    'level' => (int) substr($tag, 1),
                    'id' => $child->getAttribute('id') ?: null,
                    'text' => '',
                ];

                continue;
            }

            if ($tag === 'img') {
                $sections[array_key_last($sections)]['text'] .= ' '.$child->getAttribute('alt').' ';

                continue;
            }

            // Block boundaries become spaces, so words from adjacent paragraphs don't merge.
            $gap = in_array($tag, self::INLINE, true) ? '' : ' ';
            $sections[array_key_last($sections)]['text'] .= $gap;
            self::walk($child, $sections);
            $sections[array_key_last($sections)]['text'] .= $gap;
        }
    }
}
