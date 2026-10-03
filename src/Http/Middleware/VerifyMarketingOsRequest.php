<?php

namespace Lostdomain\BlogKit\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the bearer token, the timestamp window and the HMAC signature
 * over "{timestamp}.{raw body}". Anything off is a 401 with a reason.
 */
class VerifyMarketingOsRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        // Every answer from this API is JSON, validation errors included.
        $request->headers->set('Accept', 'application/json');

        $token = (string) config('blog-kit.api.token');
        $secret = (string) config('blog-kit.api.signing_secret');

        if ($token === '' || $secret === '') {
            Log::error('blog-kit.api.not_configured', [
                'missing' => array_keys(array_filter(['BLOG_KIT_API_TOKEN' => $token === '', 'BLOG_KIT_SIGNING_SECRET' => $secret === ''])),
            ]);

            return $this->reject($request, 'not_configured', 'The Blog Kit API token or signing secret is not configured on this site.');
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($token, $given)) {
            return $this->reject($request, 'invalid_token', 'The bearer token is missing or wrong.');
        }

        $timestamp = (string) $request->header('X-Marketing-OS-Timestamp');

        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return $this->reject($request, 'missing_timestamp', 'X-Marketing-OS-Timestamp is missing or not a unix timestamp.');
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('blog-kit.api.tolerance', 300)) {
            return $this->reject($request, 'stale_timestamp', 'X-Marketing-OS-Timestamp is outside the allowed window.');
        }

        $signature = strtolower(trim((string) $request->header('X-Marketing-OS-Signature')));
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return $this->reject($request, 'invalid_signature', 'X-Marketing-OS-Signature does not match the request body.');
        }

        return $next($request);
    }

    private function reject(Request $request, string $reason, string $message): JsonResponse
    {
        Log::warning('blog-kit.api.rejected', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return response()->json(['error' => 'unauthenticated', 'reason' => $reason, 'message' => $message], 401);
    }
}
