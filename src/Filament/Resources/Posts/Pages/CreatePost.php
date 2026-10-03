<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Lostdomain\BlogKit\Exceptions\SlugUnavailable;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostFormData;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostResource;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\PostPublisher;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        [$attributes, $tags] = PostFormData::toAttributes($data);

        try {
            return app(PostPublisher::class)->save(new Post, [
                ...$attributes,
                'source' => Post::SOURCE_LOCAL,
                'content_item_id' => null,
                'product_id' => null,
                'source_updated_at' => now(),
            ], $tags);
        } catch (SlugUnavailable $exception) {
            throw ValidationException::withMessages(['data.slug' => $exception->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
