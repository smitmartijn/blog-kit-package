<?php

namespace Lostdomain\BlogKit\Filament\Resources\Authors;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Filament\Concerns\AuthorizesWithBlogKitGate;
use Lostdomain\BlogKit\Filament\Resources\Authors\Pages\CreateAuthor;
use Lostdomain\BlogKit\Filament\Resources\Authors\Pages\EditAuthor;
use Lostdomain\BlogKit\Filament\Resources\Authors\Pages\ListAuthors;
use Lostdomain\BlogKit\Jobs\PurgeBlogCache;
use Lostdomain\BlogKit\Models\Author;
use Lostdomain\BlogKit\Services\CacheUrls;
use Lostdomain\BlogKit\Support\Background;

class AuthorResource extends Resource
{
    use AuthorizesWithBlogKitGate;

    protected static ?string $model = Author::class;

    protected static ?string $slug = 'blog-kit/authors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $navigationLabel = 'Authors';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Section::make('Profile')->columnSpan(['lg' => 2])->columns(2)->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set, ?Author $record) {
                            if (! $record && blank($get('slug'))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(191)
                        ->regex('/^'.BlogKit::SLUG_PATTERN.'$/')
                        ->unique(Author::class, 'slug', ignoreRecord: true)
                        ->prefix('/'.ltrim(trim((string) config('blog-kit.route_prefix'), '/').'/author/', '/')),
                    TextInput::make('job_title')
                        ->label('Role')
                        ->placeholder('Founder, Acme')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Textarea::make('bio')
                        ->rows(4)
                        ->maxLength(2000)
                        ->helperText('A few sentences. Shown on the author page and below each of their posts.')
                        ->columnSpanFull(),
                    Toggle::make('is_default')
                        ->label('Default author')
                        ->helperText('Posts from Marketing OS, imports and new posts get this author. Without a default, the first author is used.')
                        ->columnSpanFull(),
                ]),
                Section::make('Photo')->columnSpan(['lg' => 1])->schema([
                    FileUpload::make('avatar_upload')
                        ->label('Upload')
                        ->image()
                        ->avatar()
                        ->disk(fn () => config('blog-kit.images.disk'))
                        ->directory(fn () => trim((string) config('blog-kit.images.directory'), '/').'/uploads/authors')
                        ->helperText('Replaces the URL below.'),
                    TextInput::make('avatar_url')->label('URL')->url()->maxLength(2048),
                ]),
            ]),
            Section::make('Links')->columnSpanFull()->schema([
                TextInput::make('website_url')->label('Website')->url()->maxLength(2048),
                Repeater::make('social_links')
                    ->label('Social profiles')
                    ->schema([
                        Select::make('platform')->options(Author::PLATFORMS)->required()->native(false),
                        TextInput::make('url')->label('Profile URL')->url()->required()->maxLength(2048),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add profile'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('avatar_url')->label('')->circular()->imageSize(36),
                TextColumn::make('name')->searchable()->sortable()->description(fn (Author $record) => $record->job_title),
                TextColumn::make('slug')->fontFamily('mono')->toggleable(),
                TextColumn::make('posts_count')->label('Posts')->counts('posts')->sortable(),
                IconColumn::make('is_default')->label('Default')->boolean()->trueIcon('heroicon-o-star')->falseIcon('heroicon-o-minus'),
            ])
            ->recordActions([
                Action::make('page')
                    ->label('Page')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Author $record) => $record->url(), true),
                EditAction::make(),
                static::deleteAction(),
            ]);
    }

    /** Deleting an author with posts moves them to another author first. */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(fn (Author $record) => $record->posts()->exists()
                ? 'Their posts move to the author you pick.'
                : 'This author has no posts.')
            ->schema(fn (Author $record) => $record->posts()->exists() ? [
                Select::make('reassign_to')
                    ->label('Move their posts to')
                    ->options(fn () => Author::whereKeyNot($record->id)->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => Author::whereKeyNot($record->id)->orderByDesc('is_default')->orderBy('id')->value('id'))
                    ->placeholder('No author')
                    ->helperText('Posts without an author show no byline.'),
            ] : [])
            ->before(function (Author $record, array $data) {
                $record->posts()->update(['author_id' => filled($data['reassign_to'] ?? null) ? (int) $data['reassign_to'] : null]);
            })
            ->after(function (Author $record) {
                Log::info('blog-kit.author.deleted', ['author_id' => $record->id, 'slug' => $record->slug]);
                static::purge($record, $record->slug, hadPosts: true);
            });
    }

    /**
     * Applies the admin form state to an author and saves it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(Author $author, array $data): Author
    {
        $upload = $data['avatar_upload'] ?? null;

        if (is_array($upload)) {
            $upload = array_values($upload)[0] ?? null;
        }

        if (filled($upload)) {
            $data['avatar_url'] = Storage::disk(config('blog-kit.images.disk'))->url($upload);
        }

        $data['social_links'] = array_values(array_filter(
            (array) ($data['social_links'] ?? []),
            fn ($link) => is_array($link) && filled($link['url'] ?? null),
        )) ?: null;

        $makeDefault = (bool) ($data['is_default'] ?? false);
        unset($data['avatar_upload'], $data['is_default']);

        $oldSlug = $author->exists ? (string) $author->getOriginal('slug') : null;
        $author->fill($data)->save();

        if ($makeDefault) {
            $author->makeDefault();
        } elseif ($author->is_default) {
            $author->forceFill(['is_default' => false])->save();
        }

        // A new author has no cached pages yet.
        if ($oldSlug !== null) {
            static::purge($author, $oldSlug);
        }

        return $author;
    }

    /**
     * The author's pages, and, when they have posts, the whole blog: their
     * name and links appear on every page that lists one of their posts.
     */
    public static function purge(Author $author, string $oldSlug, bool $hadPosts = false): void
    {
        $urls = app(CacheUrls::class);
        $pages = [...$urls->authorUrls($oldSlug), ...$urls->authorUrls($author->slug)];

        if ($hadPosts || $author->posts()->exists()) {
            $pages = [...$pages, ...$urls->all()];
        }

        app(Background::class)->run(new PurgeBlogCache(array_values(array_unique($pages))));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuthors::route('/'),
            'create' => CreateAuthor::route('/create'),
            'edit' => EditAuthor::route('/{record}/edit'),
        ];
    }
}
