{{--
    Theme defaults. :where() keeps specificity at zero, so any rule in the
    host CSS such as `.blog-kit { --blog-kit-accent: var(--color-amber); }`
    wins without !important.
--}}
<style>
    :where(.blog-kit) {
        --blog-kit-text: inherit;
        --blog-kit-heading: inherit;
        --blog-kit-muted: color-mix(in srgb, currentColor 65%, transparent);
        --blog-kit-accent: #2563eb;
        --blog-kit-accent-contrast: #ffffff;
        --blog-kit-border: color-mix(in srgb, currentColor 14%, transparent);
        --blog-kit-surface: color-mix(in srgb, currentColor 4%, transparent);
        --blog-kit-radius: 0.75rem;
        --blog-kit-max-width: 72rem;
        --blog-kit-prose-width: 44rem;
        --blog-kit-font-heading: inherit;
        --blog-kit-font-mono: ui-monospace, SFMono-Regular, Menlo, monospace;
        color: var(--blog-kit-text);
    }

    :where(.blog-kit-prose) { line-height: 1.75; font-size: 1.0625rem; overflow-wrap: break-word; }
    :where(.blog-kit-prose) > :where(* + *) { margin-top: 1.25em; }
    :where(.blog-kit-prose) :where(h2, h3, h4) { font-family: var(--blog-kit-font-heading); color: var(--blog-kit-heading); font-weight: 700; line-height: 1.3; margin-top: 2em; scroll-margin-top: 6rem; }
    :where(.blog-kit-prose) :where(h2) { font-size: 1.6em; }
    :where(.blog-kit-prose) :where(h3) { font-size: 1.3em; }
    :where(.blog-kit-prose) :where(h4) { font-size: 1.1em; }
    :where(.blog-kit-prose) :where(a) { color: var(--blog-kit-accent); text-decoration: underline; text-underline-offset: 0.2em; }
    :where(.blog-kit-prose) :where(ul, ol) { padding-left: 1.5em; }
    :where(.blog-kit-prose) :where(ul) { list-style: disc; }
    :where(.blog-kit-prose) :where(ol) { list-style: decimal; }
    :where(.blog-kit-prose) :where(li + li) { margin-top: 0.4em; }
    :where(.blog-kit-prose) :where(img, video, picture) { max-width: 100%; height: auto; border-radius: var(--blog-kit-radius); }
    :where(.blog-kit-prose) :where(figure) { margin-block: 2em; }
    :where(.blog-kit-prose) :where(figcaption) { margin-top: 0.6em; font-size: 0.875em; color: var(--blog-kit-muted); text-align: center; }
    :where(.blog-kit-prose) :where(blockquote) { border-left: 3px solid var(--blog-kit-accent); padding-left: 1em; color: var(--blog-kit-muted); font-style: italic; }
    :where(.blog-kit-prose) :where(code) { font-family: var(--blog-kit-font-mono); font-size: 0.9em; background: var(--blog-kit-surface); padding: 0.15em 0.35em; border-radius: 0.3em; }
    :where(.blog-kit-prose) :where(pre) { font-family: var(--blog-kit-font-mono); background: var(--blog-kit-surface); border: 1px solid var(--blog-kit-border); border-radius: var(--blog-kit-radius); padding: 1em; overflow-x: auto; font-size: 0.875em; line-height: 1.6; }
    :where(.blog-kit-prose) :where(pre code) { background: none; padding: 0; }
    :where(.blog-kit-prose) :where(table) { width: 100%; border-collapse: collapse; font-size: 0.9375em; display: block; overflow-x: auto; }
    :where(.blog-kit-prose) :where(th, td) { border: 1px solid var(--blog-kit-border); padding: 0.5em 0.75em; text-align: left; vertical-align: top; }
    :where(.blog-kit-prose) :where(th) { background: var(--blog-kit-surface); font-weight: 600; }
    :where(.blog-kit-prose) :where(hr) { border: 0; border-top: 1px solid var(--blog-kit-border); margin-block: 2.5em; }
</style>
