<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Lostdomain\BlogKit\Filament\Resources\Posts\PostActions;
use Lostdomain\BlogKit\Filament\Resources\Posts\Schemas\PostStatusColors;
use Lostdomain\BlogKit\Models\Post;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable(['title', 'slug'])
                    ->description(fn (Post $record) => '/'.$record->slug)
                    ->limit(70)
                    ->wrap(),
                TextColumn::make('status')
                    ->state(fn (Post $record) => $record->status())
                    ->badge()
                    ->color(fn (string $state) => PostStatusColors::status($state)),
                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => $state === Post::SOURCE_LOCAL ? 'Local' : 'Marketing OS'),
                TextColumn::make('images_status')
                    ->label('Images')
                    ->badge()
                    ->color(fn (string $state) => PostStatusColors::images($state))
                    ->tooltip(fn (Post $record) => $record->image_error),
                IconColumn::make('locked_at')
                    ->label('Locked')
                    ->state(fn (Post $record) => $record->isLocked())
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')
                    ->falseColor('gray'),
                TextColumn::make('published_at')->label('Published')->date()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['live' => 'Live', 'hidden' => 'Hidden', 'unpublished' => 'Unpublished'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'live' => $query->live(),
                        'hidden' => $query->whereNotNull('hidden_at'),
                        'unpublished' => $query->whereNotNull('unpublished_at'),
                        default => $query,
                    }),
                SelectFilter::make('images_status')
                    ->label('Images')
                    ->options(['none' => 'None', 'pending' => 'Pending', 'done' => 'Done', 'failed' => 'Failed']),
                SelectFilter::make('source')
                    ->options([Post::SOURCE_MARKETING_OS => 'Marketing OS', Post::SOURCE_LOCAL => 'Local']),
                TernaryFilter::make('locked')
                    ->nullable()
                    ->attribute('locked_at'),
                SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ...PostActions::all(),
                ]),
            ]);
    }
}
