<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Support\Facades\File;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class InstallCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(config_path('blog-kit.php'));

        parent::tearDown();
    }

    #[Test]
    public function it_publishes_the_config_and_prints_the_remaining_steps(): void
    {
        File::delete(config_path('blog-kit.php'));

        $this->artisan('blog-kit:install')
            ->expectsOutputToContain('BLOG_KIT_API_TOKEN=')
            ->expectsOutputToContain('BLOG_KIT_SIGNING_SECRET=')
            ->expectsOutputToContain('@source "../../vendor/lostdomain/blog-kit/resources/views";')
            ->expectsOutputToContain('BlogKitPlugin::make()')
            ->expectsOutputToContain('https://example.test/blog/api/marketing-os/v1/posts')
            ->assertSuccessful();

        $this->assertFileExists(config_path('blog-kit.php'));
    }
}
