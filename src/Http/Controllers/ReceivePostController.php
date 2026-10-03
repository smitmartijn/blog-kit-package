<?php

namespace Lostdomain\BlogKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Actions\ReceiveMarketingOsPost;
use Lostdomain\BlogKit\Exceptions\PostLocked;
use Lostdomain\BlogKit\Exceptions\SlugUnavailable;
use Lostdomain\BlogKit\Exceptions\UnknownPost;
use Lostdomain\BlogKit\Http\Requests\ReceivePostRequest;

class ReceivePostController
{
    public function __invoke(ReceivePostRequest $request, ReceiveMarketingOsPost $receiver): JsonResponse
    {
        $payload = $request->validated();
        $isUnpublish = $payload['event'] === 'unpublish';

        if ($request->isTest()) {
            Log::info('blog-kit.api.test_received', ['event' => $payload['event'], 'delivery_id' => $payload['delivery_id']]);

            return response()->json([
                'id' => null,
                'url' => $isUnpublish ? null : route('blog-kit.show', $payload['slug']),
                'test' => true,
            ]);
        }

        try {
            $post = $receiver->handle($payload);
        } catch (SlugUnavailable $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['slug' => [$exception->getMessage()]]], 422);
        } catch (PostLocked $exception) {
            return response()->json(['error' => 'post_locked', 'message' => $exception->getMessage()], 409);
        } catch (UnknownPost $exception) {
            return response()->json(['error' => 'not_found', 'message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'id' => $post->id,
            'url' => $isUnpublish ? null : $post->url(),
        ]);
    }
}
