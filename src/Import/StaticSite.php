<?php

namespace Lostdomain\BlogKit\Import;

/** A Docusaurus or VuePress blog on disk, as read by StaticSiteReader. */
final class StaticSite
{
    /**
     * @param  'docusaurus'|'vuepress'|'folder'  $format
     * @param  string  $urlBase  where the blog was served, e.g. "/blog/"
     * @param  list<string>  $staticRoots  directories root-relative assets ("/img/x.png") resolve against
     * @param  list<SourcePost>  $posts
     * @param  list<array{file: string, reason: string}>  $skipped
     * @param  string  $assetBase  where the old site served its static files, e.g. "/" (Docusaurus baseUrl)
     */
    public function __construct(
        public string $format,
        public string $root,
        public string $postsDirectory,
        public string $urlBase,
        public array $staticRoots,
        public array $posts = [],
        public array $skipped = [],
        public string $assetBase = '/',
    ) {}
}
