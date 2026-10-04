<?php

namespace Lostdomain\BlogKit\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkProcessor;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown to post HTML, matching what Marketing OS produces: GitHub
 * flavoured Markdown (tables, strikethrough, autolinks), ids on headings,
 * and an image alone in a paragraph becomes a figure, with its title
 * ![alt](url "caption") as the caption.
 *
 * The output is stored as-is and sanitized at render time like any body.
 */
class MarkdownRenderer
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'insert' => HeadingPermalinkProcessor::INSERT_NONE,
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'min_heading_level' => 1,
                'max_heading_level' => 6,
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    public function toHtml(string $markdown): string
    {
        $html = trim($this->converter->convert($markdown)->getContent());

        return $this->figures($html);
    }

    /** <p><img …></p> → <figure><img …><figcaption>title</figcaption></figure> */
    private function figures(string $html): string
    {
        return (string) preg_replace_callback(
            '#<p>\s*(<img\b[^>]*?)\s*/?>\s*</p>#i',
            function (array $match) {
                $img = $match[1];
                $caption = null;

                if (preg_match('#\stitle="([^"]*)"#', $img, $title)) {
                    $caption = $title[1];
                    $img = str_replace($title[0], '', $img);
                }

                return '<figure class="article-figure">'.$img.' loading="lazy" />'
                    .($caption !== null && $caption !== '' ? '<figcaption>'.$caption.'</figcaption>' : '')
                    .'</figure>';
            },
            $html,
        );
    }
}
