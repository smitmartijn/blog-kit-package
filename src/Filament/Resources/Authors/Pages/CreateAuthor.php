<?php

namespace Lostdomain\BlogKit\Filament\Resources\Authors\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Lostdomain\BlogKit\Filament\Resources\Authors\AuthorResource;
use Lostdomain\BlogKit\Models\Author;

class CreateAuthor extends CreateRecord
{
    protected static string $resource = AuthorResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return AuthorResource::save(new Author, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
