<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\PostPublisher;

/** Operational actions shared by the table, view and edit pages. */
class PostActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            static::viewOnSite(),
            static::retryImages(),
            static::purgeCache(),
            static::hide(),
            static::restore(),
            static::releaseLock(),
            static::delete(),
        ];
    }

    public static function viewOnSite(): Action
    {
        return Action::make('viewOnSite')
            ->label('View on site')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn (Post $record) => $record->url())
            ->openUrlInNewTab()
            ->visible(fn (Post $record) => $record->isLive());
    }

    public static function retryImages(): Action
    {
        return Action::make('retryImages')
            ->label('Retry images')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Post $record) => in_array($record->images_status, [Post::IMAGES_FAILED, Post::IMAGES_PENDING], true))
            ->action(function (Post $record) {
                app(PostPublisher::class)->retryImages($record);
                Notification::make()->title('Image processing queued')->success()->send();
            });
    }

    public static function purgeCache(): Action
    {
        return Action::make('purgeCache')
            ->label('Purge cache')
            ->icon(Heroicon::OutlinedBolt)
            ->action(function (Post $record) {
                app(PostPublisher::class)->purge($record);
                Notification::make()->title('Cache purge queued')->body('Local page cache and Cloudflare.')->success()->send();
            });
    }

    public static function hide(): Action
    {
        return Action::make('hide')
            ->label('Hide')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('The article returns 404 until you restore it. Marketing OS updates still apply to its content.')
            ->visible(fn (Post $record) => $record->isLive())
            ->action(function (Post $record) {
                app(PostPublisher::class)->hide($record);
                Notification::make()->title('Post hidden')->success()->send();
            });
    }

    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon(Heroicon::OutlinedEye)
            ->requiresConfirmation()
            ->modalDescription('The article becomes publicly visible again.')
            ->visible(fn (Post $record) => $record->isHidden() || $record->isUnpublished())
            ->action(function (Post $record) {
                app(PostPublisher::class)->restore($record);
                Notification::make()->title('Post restored')->success()->send();
            });
    }

    public static function releaseLock(): Action
    {
        return Action::make('releaseLock')
            ->label('Release lock')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Marketing OS updates will be accepted again. The next update overwrites the edits made here.')
            ->visible(fn (Post $record) => $record->isLocked())
            ->action(function (Post $record) {
                app(PostPublisher::class)->releaseLock($record);
                Notification::make()->title('Lock released')->success()->send();
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('The post and its redirects are deleted; its URL returns 404. A later Marketing OS publish creates it again.')
            ->using(function (Post $record) {
                app(PostPublisher::class)->delete($record);

                return true;
            });
    }
}
