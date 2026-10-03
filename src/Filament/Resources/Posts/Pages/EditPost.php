<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts\Pages;

use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Lostdomain\BlogKit\Exceptions\SlugUnavailable;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostActions;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostFormData;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostResource;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\PostPublisher;

/** @property Post $record */
class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), ...PostActions::all()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return PostFormData::fill($this->record, $data);
    }

    /** @param  Post  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        [$attributes, $tags] = PostFormData::toAttributes($data);
        $publisher = app(PostPublisher::class);

        try {
            $publisher->save($record, [...$attributes, 'source_updated_at' => $record->isFromMarketingOs() ? $record->source_updated_at : now()], $tags);
        } catch (SlugUnavailable $exception) {
            throw ValidationException::withMessages(['data.slug' => $exception->getMessage()]);
        }

        $user = auth()->user();

        return $publisher->lock($record, $user ? (string) ($user->email ?? $user->name ?? $user->getAuthIdentifier()) : null);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
