<?php

namespace Lostdomain\BlogKit\Filament\Resources\Authors\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Lostdomain\BlogKit\Filament\Resources\Authors\AuthorResource;

class ListAuthors extends ListRecords
{
    protected static string $resource = AuthorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New author')];
    }
}
