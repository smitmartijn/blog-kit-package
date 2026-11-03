<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostActions;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostResource;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PostActions::purgeAllCache(),
            CreateAction::make()->label('New post'),
        ];
    }
}
