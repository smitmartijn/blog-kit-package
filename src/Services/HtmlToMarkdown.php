<?php

namespace Lostdomain\BlogKit\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * HTML → Markdown for switching a post's editor from HTML to Markdown.
 * Image figures become ![alt](src "caption"), the reverse of
 * MarkdownRenderer. Markup Markdown can't express (details, embeds) is
 * kept as inline HTML, which MarkdownRenderer passes through.
 */
class HtmlToMarkdown
{
    private HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => false,
            'remove_nodes' => 'script style',
            'list_item_style' => '-',
            'hard_break' => false,
            'strip_placeholder_links' => true,
        ]);

        $this->converter->getEnvironment()->addConverter(new TableConverter);
    }

    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Head-only elements first in the input make the parser emit </head><body>.
        $html = (string) preg_replace(['#<(script|style)\b[^>]*>.*?</\1>#is', '#<(?:meta|link)\b[^>]*>#i'], '', $html);

        if (trim($html) === '') {
            return '';
        }

        $markdown = $this->converter->convert($this->flattenFigures($html));

        // Raw HTML blocks (details, embeds) need a blank line after them, or
        // CommonMark swallows the next block into the HTML.
        $markdown = (string) preg_replace('#(</(?:details|div|section|aside|iframe|video|figure)>)[ \t]*\n?(?=\S)#i', "$1\n\n", $markdown);

        return trim($markdown);
    }

    /** <figure><img><figcaption>c</figcaption></figure> → <p><img title="c"></p> */
    private function flattenFigures(string $html): string
    {
        if (stripos($html, '<figure') === false) {
            return $html;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($document->getElementsByTagName('figure')) as $figure) {
            /** @var DOMElement $figure */
            $images = (new DOMXPath($document))->query('.//img', $figure);
            $image = $images?->item(0);

            if (! $image instanceof DOMElement) {
                // Not an image figure: keep its content, drop the wrapper.
                while ($figure->firstChild) {
                    $figure->parentNode?->insertBefore($figure->firstChild, $figure);
                }
                $figure->parentNode?->removeChild($figure);

                continue;
            }

            $caption = $figure->getElementsByTagName('figcaption')->item(0);
            $title = $caption ? trim($caption->textContent) : '';

            $replacement = $document->createElement('img');
            $replacement->setAttribute('src', $image->getAttribute('src'));
            $replacement->setAttribute('alt', $image->getAttribute('alt'));

            if ($title !== '' || $image->getAttribute('title') !== '') {
                $replacement->setAttribute('title', $title !== '' ? $title : $image->getAttribute('title'));
            }

            $paragraph = $document->createElement('p');
            $paragraph->appendChild($replacement);
            $figure->parentNode?->replaceChild($paragraph, $figure);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $out = '';

        foreach ($body?->childNodes ?? [] as $node) {
            $out .= $document->saveHTML($node);
        }

        return $out;
    }
}
