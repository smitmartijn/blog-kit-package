<?php

namespace Lostdomain\BlogKit\Tests\Unit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lostdomain\BlogKit\Import\ImageImporter;
use Lostdomain\BlogKit\Import\StaticSiteReader;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StaticSiteReaderTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        // A Docusaurus site that serves the blog at the root of a /blog/ base URL set from the environment.
        $this->site = sys_get_temp_dir().'/blog-kit-reader-'.Str::random(8);
        File::ensureDirectoryExists($this->site.'/blog');
        File::ensureDirectoryExists($this->site.'/static/assets');
        File::put($this->site.'/docusaurus.config.js', "module.exports = { baseUrl: process.env.BASE_URL || '/', presets: [['classic', { blog: { routeBasePath: '/' } }]] };");
        File::put($this->site.'/static/assets/clip.mp4', 'fake-mp4-bytes');
        File::put($this->site.'/blog/2024-01-02-post.md', "---\ntitle: Post\n---\n\n<video controls><source src=\"/blog/assets/clip.mp4\" /></video>\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->site);

        parent::tearDown();
    }

    #[Test]
    public function a_blog_at_the_site_root_is_served_under_a_single_slash(): void
    {
        $site = app(StaticSiteReader::class)->read($this->site);

        $this->assertSame('/', $site->urlBase);
        $this->assertSame('/', $site->assetBase);
    }

    #[Test]
    public function the_base_url_option_replaces_one_the_config_cannot_tell(): void
    {
        $site = app(StaticSiteReader::class)->read($this->site, '/blog/');

        $this->assertSame('/blog/', $site->urlBase);
        $this->assertSame('/blog/', $site->assetBase);
    }

    #[Test]
    public function static_files_referenced_with_the_base_url_resolve_once_it_is_known(): void
    {
        $reader = app(StaticSiteReader::class);

        $withoutBase = $reader->read($this->site);
        $this->assertNull((new ImageImporter($withoutBase, dryRun: true))->resolve('/blog/assets/clip.mp4', $withoutBase->posts[0]));

        $withBase = $reader->read($this->site, '/blog/');
        $this->assertSame(
            realpath($this->site.'/static/assets/clip.mp4'),
            (new ImageImporter($withBase, dryRun: true))->resolve('/blog/assets/clip.mp4', $withBase->posts[0]),
        );
    }
}
