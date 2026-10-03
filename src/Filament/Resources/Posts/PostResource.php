<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Lostdomain\BlogKit\Filament\Concerns\AuthorizesWithBlogKitGate;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\CreatePost;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\EditPost;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\ListPosts;
use Lostdomain\BlogKit\Filament\Resources\Posts\Pages\ViewPost;
use Lostdomain\BlogKit\Filament\Resources\Posts\Schemas\PostForm;
use Lostdomain\BlogKit\Filament\Resources\Posts\Schemas\PostInfolist;
use Lostdomain\BlogKit\Filament\Resources\Posts\Tables\PostsTable;
use Lostdomain\BlogKit\Models\Post;

class PostResource extends Resource
{
    use AuthorizesWithBlogKitGate;

    protected static ?string $model = Post::class;

    protected static ?string $slug = 'blog-kit/posts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $navigationLabel = 'Posts';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return PostForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PostInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'view' => ViewPost::route('/{record}'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
