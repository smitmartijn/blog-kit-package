<?php

namespace Lostdomain\BlogKit\Filament\Resources\Redirects\Pages;

use Filament\Resources\Pages\ManageRecords;
use Lostdomain\BlogKit\Filament\Resources\Redirects\RedirectResource;

class ManageRedirects extends ManageRecords
{
    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [RedirectResource::createAction()];
    }
}
