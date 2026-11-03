<?php

namespace Lostdomain\BlogKit\Import;

use Illuminate\Support\Str;

/**
 * Turns Docusaurus (MDX) and VuePress Markdown into plain Markdown the blog
 * renders: no imports or components, admonitions as quotes, no excerpt
 * markers, and no leading "# Title" (the page shows the title). Code blocks
 * are left untouched.
 */
class MarkdownCleaner
{
    private const TRUNCATE_MARKERS = ['<!-- truncate -->', '{/* truncate */}', '<!-- more -->', '<!--more-->', '<!--truncate-->'];

    /**
     * @return array{markdown: string, title: ?string, excerpt: ?string, warnings: list<string>}
     */
    public function clean(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        $out = [];
        $warnings = [];
        $components = [];
        $title = null;
        $excerpt = null;
        $fence = null;
        $admonition = null;
        $seenContent = false;

        foreach ($lines as $line) {
            // Code fences: copy verbatim until the matching fence closes.
            if ($fence !== null) {
                $out[] = $admonition !== null ? $this->quoted($line) : $line;

                if (preg_match('/^\s*'.preg_quote($fence, '/').'\s*$/', $line)) {
                    $fence = null;
                }

                continue;
            }

            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $match)) {
                $fence = $match[1];
                $out[] = $admonition !== null ? $this->quoted($line) : $line;
                $seenContent = true;

                continue;
            }

            $trimmed = trim($line);

            if (in_array($trimmed, self::TRUNCATE_MARKERS, true)) {
                $excerpt ??= $this->plainText(implode("\n", $out));

                continue;
            }

            // MDX module syntax.
            if (preg_match('/^(import\s.+\sfrom\s+[\'"].+[\'"]|import\s+[\'"].+[\'"]|export\s+(const|default|function)\b.*);?$/', $trimmed)) {
                continue;
            }

            // The title is rendered by the page; drop a leading "# Title".
            if (! $seenContent && $trimmed !== '' && preg_match('/^#\s+(.+?)\s*#*$/', $trimmed, $match)) {
                $title = trim($match[1]);
                $seenContent = true;

                continue;
            }

            // Admonitions / custom containers: ":::tip Title" … ":::".
            if (preg_match('/^:::\s*([A-Za-z]+)(?:\[(.*)\]|\s+(.*))?$/', $trimmed, $match)) {
                $label = trim(($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
                $admonition = $label !== '' ? $label : Str::ucfirst(strtolower($match[1]));
                $out[] = '> **'.$admonition.'**';
                $out[] = '>';
                $seenContent = true;

                continue;
            }

            if ($admonition !== null && $trimmed === ':::') {
                $admonition = null;
                $out[] = '';

                continue;
            }

            $original = $line;

            // MDX comments.
            $line = (string) preg_replace('#\{/\*.*?\*/\}#', '', $line);

            // Components (<Tabs>, <CTAButton />): drop the tags, keep any text inside.
            $line = (string) preg_replace_callback('#</?([A-Z][A-Za-z0-9.]*)\b[^>]*?/?>#', function (array $match) use (&$components) {
                $components[$match[1]] = true;

                return '';
            }, $line);

            if (trim($line) === '' && trim($original) !== '') {
                continue; // the line held only a component or comment
            }

            if ($trimmed !== '') {
                $seenContent = true;
            }

            $out[] = $admonition !== null ? $this->quoted($line) : $line;
        }

        if ($components !== []) {
            $warnings[] = 'Removed components: '.implode(', ', array_map(fn ($name) => "<{$name}>", array_keys($components))).'. Check the post.';
        }

        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));

        return ['markdown' => $markdown, 'title' => $title, 'excerpt' => $excerpt !== '' ? $excerpt : null, 'warnings' => $warnings];
    }

    private function quoted(string $line): string
    {
        return rtrim('> '.$line);
    }

    private function plainText(string $markdown): string
    {
        $text = strip_tags((string) preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]*)\]\([^)]*\)/', '/[*_`>#]+/'], ['', '$1', ''], $markdown));

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $text)), 300);
    }
}
