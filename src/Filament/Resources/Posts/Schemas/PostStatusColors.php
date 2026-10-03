<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts\Schemas;

class PostStatusColors
{
    public static function status(string $status): string
    {
        return match ($status) {
            'live' => 'success',
            'hidden' => 'warning',
            'unpublished' => 'danger',
            default => 'gray',
        };
    }

    public static function images(string $status): string
    {
        return match ($status) {
            'done' => 'success',
            'pending' => 'info',
            'failed' => 'danger',
            default => 'gray',
        };
    }
}
