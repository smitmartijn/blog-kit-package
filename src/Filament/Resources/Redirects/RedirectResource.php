<?php

namespace Lostdomain\BlogKit\Filament\Resources\Redirects;

use BackedEnum;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Filament\Concerns\AuthorizesWithBlogKitGate;
use Lostdomain\BlogKit\Filament\Resources\Redirects\Pages\ManageRedirects;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Models\Redirect;
use Lostdomain\BlogKit\Services\CacheUrls;
use Lostdomain\BlogKit\Support\Background;

class RedirectResource extends Resource
{
    use AuthorizesWithBlogKitGate;

    protected static ?string $model = Redirect::class;

    protected static ?string $slug = 'blog-kit/redirects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static ?string $navigationLabel = 'Redirects';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('from_slug')
                ->label('Old slug')
                ->required()
                ->maxLength(191)
                ->regex('/^'.BlogKit::SLUG_PATTERN.'$/')
                ->notIn(BlogKit::RESERVED_SLUGS)
                ->unique(Redirect::class, 'from_slug', ignoreRecord: true)
                ->rules([fn () => function (string $attribute, mixed $value, Closure $fail) {
                    if (Post::where('slug', $value)->exists()) {
                        $fail('A post already uses this slug, so a redirect would never be reached.');
                    }
                }])
                ->prefix('/'.trim((string) config('blog-kit.route_prefix'), '/').'/'),
            Select::make('post_id')
                ->label('Redirect to')
                ->relationship('post', 'title')
                ->searchable()
                ->preload()
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('from_slug')->label('Old slug')->fontFamily('mono')->searchable(),
                TextColumn::make('post.title')->label('Redirects to')->description(fn (Redirect $record) => '/'.$record->post?->slug)->searchable(),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()->after(fn (Redirect $record) => static::purge($record)),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->after(fn (Redirect $record) => static::purge($record));
    }

    public static function purge(Redirect $redirect): void
    {
        app(Background::class)->run(new PurgeBlogCache(app(CacheUrls::class)->forSlugs([$redirect->from_slug], [])));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRedirects::route('/'),
        ];
    }
}
