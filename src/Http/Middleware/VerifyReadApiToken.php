<?php

namespace Lostdomain\BlogKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token for the read API (BLOG_KIT_READ_TOKEN). Separate from the
 * Marketing OS token, which can publish: an agent only ever reads.
 */
class VerifyReadApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $token = (string) config('blog-kit.read_api.token');

        if ($token === '') {
            return $this->reject($request, 'not_configured', 'The read API is off: set BLOG_KIT_READ_TOKEN on this site.');
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($token, $given)) {
            return $this->reject($request, 'invalid_token', 'The bearer token is missing or wrong.');
        }

        $response = $next($request);

        // Answers depend on the token and change with every edit.
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }

    private function reject(Request $request, string $reason, string $message): Response
    {
        Log::warning('blog-kit.read_api.rejected', ['reason' => $reason, 'ip' => $request->ip(), 'path' => $request->path()]);

        return response()->json(['error' => 'unauthenticated', 'reason' => $reason, 'message' => $message], 401);
    }
}
