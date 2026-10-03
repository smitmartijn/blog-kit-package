<?php

namespace Lostdomain\BlogKit\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ApiAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function a_correctly_signed_request_is_accepted(): void
    {
        $this->postSigned($this->payload())->assertOk();

        $this->assertSame(1, Post::count());
    }

    #[Test]
    public function a_missing_bearer_token_is_rejected(): void
    {
        $this->postSigned($this->payload(), ['token' => null])
            ->assertUnauthorized()
            ->assertJson(['error' => 'unauthenticated', 'reason' => 'invalid_token']);

        $this->assertSame(0, Post::count());
    }

    #[Test]
    public function a_wrong_bearer_token_is_rejected(): void
    {
        $this->postSigned($this->payload(), ['token' => 'nope'])
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'invalid_token');
    }

    #[Test]
    public function a_missing_timestamp_is_rejected(): void
    {
        $this->postSigned($this->payload(), ['timestamp' => null])
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'missing_timestamp');
    }

    #[Test]
    public function a_timestamp_older_than_the_tolerance_is_rejected(): void
    {
        $this->postSigned($this->payload(), ['timestamp' => (string) now()->subSeconds(301)->getTimestamp()])
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'stale_timestamp');
    }

    #[Test]
    public function a_timestamp_within_the_tolerance_is_accepted(): void
    {
        $this->postSigned($this->payload(), ['timestamp' => (string) now()->subSeconds(290)->getTimestamp()])
            ->assertOk();
    }

    #[Test]
    public function a_timestamp_far_in_the_future_is_rejected(): void
    {
        $this->postSigned($this->payload(), ['timestamp' => (string) now()->addMinutes(10)->getTimestamp()])
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'stale_timestamp');
    }

    #[Test]
    public function the_tolerance_is_configurable(): void
    {
        config(['blog-kit.api.tolerance' => 30]);

        $this->postSigned($this->payload(), ['timestamp' => (string) now()->subSeconds(60)->getTimestamp()])
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'stale_timestamp');
    }

    #[Test]
    public function a_signature_made_with_another_secret_is_rejected(): void
    {
        $this->postSigned($this->payload(), ['secret' => 'other-secret'])
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'invalid_signature');
    }

    #[Test]
    public function a_body_changed_after_signing_is_rejected(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $signed = json_encode($this->payload(['title' => 'Original']));
        $signature = hash_hmac('sha256', $timestamp.'.'.$signed, self::SECRET);

        $this->postSigned([], [
            'body' => json_encode($this->payload(['title' => 'Tampered'])),
            'timestamp' => $timestamp,
            'signature' => $signature,
        ])->assertUnauthorized()->assertJsonPath('reason', 'invalid_signature');

        $this->assertSame(0, Post::count());
    }

    #[Test]
    public function the_signature_covers_the_raw_bytes_not_a_re_encoding(): void
    {
        // Pretty-printed JSON decodes to the same data but must be verified as sent.
        $body = json_encode($this->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->postSigned([], ['body' => $body])->assertOk();
    }

    #[Test]
    public function a_missing_signature_is_rejected(): void
    {
        $this->postSigned($this->payload(), ['signature' => null])
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'invalid_signature');
    }

    #[Test]
    public function an_unconfigured_site_rejects_everything_and_logs_an_error(): void
    {
        config(['blog-kit.api.signing_secret' => null]);
        Log::spy();

        $this->postSigned($this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'not_configured');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'blog-kit.api.not_configured'
            && $context['missing'] === ['BLOG_KIT_SIGNING_SECRET']);
    }

    #[Test]
    public function rejections_are_logged_without_secrets(): void
    {
        Log::spy();

        $this->postSigned($this->payload(), ['token' => 'wrong-token']);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            $logged = json_encode($context);

            return $message === 'blog-kit.api.rejected'
                && $context['reason'] === 'invalid_token'
                && ! str_contains($logged, 'wrong-token')
                && ! str_contains($logged, self::SECRET);
        });
    }
}
