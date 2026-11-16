<?php

namespace Lostdomain\BlogKit\Filament\Resources\Authors\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Lostdomain\BlogKit\Filament\Resources\Authors\AuthorResource;
use Lostdomain\BlogKit\Models\Author;

/** @property Author $record */
class EditAuthor extends EditRecord
{
    protected static string $resource = AuthorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('page')
                ->label('View page')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => $this->record->url(), true),
            AuthorResource::deleteAction(),
        ];
    }

    /** @param  Author  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return AuthorResource::save($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
