<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Lostdomain\BlogKit\Models\Post;

class PostInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Post')->columnSpan(['lg' => 2])->columns(2)->schema([
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('slug')->fontFamily('mono')->url(fn (Post $record) => $record->isLive() ? $record->url() : null, true),
                    TextEntry::make('status')->state(fn (Post $record) => $record->status())->badge()->color(fn (string $state) => PostStatusColors::status($state)),
                    TextEntry::make('published_at')->dateTime(),
                    TextEntry::make('source_updated_at')->label('Updated in source')->dateTime()->placeholder('—'),
                    TextEntry::make('tags.name')->badge()->placeholder('No tags'),
                    TextEntry::make('reading_time')->state(fn (Post $record) => $record->readingTime().' min'),
                    TextEntry::make('excerpt')->columnSpanFull()->placeholder('—'),
                ]),
                Section::make('Source')->columnSpan(['lg' => 1])->schema([
                    TextEntry::make('source')->badge()->formatStateUsing(fn (string $state) => $state === Post::SOURCE_LOCAL ? 'Local' : 'Marketing OS'),
                    TextEntry::make('body_format')
                        ->label('Body source')
                        ->formatStateUsing(fn (string $state, Post $record) => match (true) {
                            $state === Post::FORMAT_MARKDOWN => 'Markdown',
                            $record->isFromMarketingOs() && ! $record->isLocked() => 'HTML from Marketing OS',
                            default => 'HTML',
                        }),
                    TextEntry::make('content_item_id')->label('Content item')->placeholder('—'),
                    TextEntry::make('product_id')->label('Product')->placeholder('—'),
                    TextEntry::make('locked_at')
                        ->label('Locked')
                        ->state(fn (Post $record) => $record->isLocked() ? $record->locked_at->toDayDateTimeString().' by '.($record->locked_by ?: 'unknown') : null)
                        ->placeholder('No')
                        ->color('danger'),
                    TextEntry::make('last_delivery_id')->label('Last delivery')->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('revision'),
                ]),
            ]),
            Section::make('Images')->columnSpanFull()->schema([
                TextEntry::make('images_status')->label('Status')->badge()->color(fn (string $state) => PostStatusColors::images($state)),
                TextEntry::make('image_error')->label('Error')->color('danger')->placeholder('None')->visible(fn (Post $record) => filled($record->image_error)),
                RepeatableEntry::make('storedImages')
                    ->label('Files')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('source_url')->label('Source')->limit(60)->tooltip(fn ($state) => $state)->columnSpan(2),
                        TextEntry::make('status')->badge()->color(fn (string $state) => $state === 'stored' ? 'success' : 'danger'),
                        TextEntry::make('error')->placeholder('—')->color('danger'),
                    ])
                    ->placeholder('No images downloaded'),
            ]),
            Section::make('Redirects')->columnSpanFull()->collapsible()->schema([
                RepeatableEntry::make('redirects')
                    ->hiddenLabel()
                    ->schema([TextEntry::make('from_slug')->label('Old slug')->fontFamily('mono')])
                    ->placeholder('No redirects to this post'),
            ]),
        ]);
    }
}
