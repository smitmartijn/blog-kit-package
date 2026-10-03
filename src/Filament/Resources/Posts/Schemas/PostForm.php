<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts\Schemas;

use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Models\Post;

class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make('Managed by Marketing OS')
                ->description(fn (?Post $record) => $record?->isLocked()
                    ? 'Locked by '.($record->locked_by ?: 'an editor').'. Marketing OS updates are rejected until the lock is released.'
                    : 'Saving here locks the post: Marketing OS updates are rejected (409) until you release the lock.')
                ->warning()
                ->visible(fn (?Post $record) => $record?->isFromMarketingOs() ?? false)
                ->columnSpanFull(),

            Grid::make(3)->columnSpanFull()->schema([
                Group::make()->columnSpan(['lg' => 2])->schema([
                    Section::make('Content')->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set, ?Post $record) {
                                if (! $record && blank($get('slug'))) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(191)
                            ->regex('/^'.BlogKit::SLUG_PATTERN.'$/')
                            ->notIn(BlogKit::RESERVED_SLUGS)
                            ->unique(Post::class, 'slug', ignoreRecord: true)
                            ->prefix('/'.trim((string) config('blog-kit.route_prefix'), '/').'/')
                            ->helperText('Changing the slug of a published post keeps a 301 redirect from the old URL.'),
                        Textarea::make('excerpt')->rows(3)->maxLength(5000),
                        CodeEditor::make('body_html')
                            ->label('Body (HTML)')
                            ->language(Language::Html)
                            ->required()
                            ->helperText('Sanitized when rendered. Remote image URLs are downloaded to the image disk after saving.'),
                    ]),
                    Section::make('FAQ')->collapsible()->schema([
                        Repeater::make('faq')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('question')->required(),
                                Textarea::make('answer')->required()->rows(3),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Add question')
                            ->collapsible()
                            ->itemLabel(fn (array $state) => $state['question'] ?? null),
                    ]),
                    Section::make('Call to action')->collapsible()->schema([
                        Textarea::make('cta')->hiddenLabel()->rows(3),
                    ]),
                ]),
                Group::make()->columnSpan(['lg' => 1])->schema([
                    Section::make('Publishing')->schema([
                        DateTimePicker::make('published_at')
                            ->label('Published at')
                            ->required()
                            ->default(now())
                            ->helperText('Shown as the post date. Posts are live as soon as they are saved.'),
                        TagsInput::make('tags')->placeholder('Add a tag'),
                    ]),
                    Section::make('Featured image')->schema([
                        FileUpload::make('featured_upload')
                            ->label('Upload')
                            ->image()
                            ->disk(fn () => config('blog-kit.images.disk'))
                            ->directory(fn () => trim((string) config('blog-kit.images.directory'), '/').'/uploads')
                            ->helperText('Replaces the URL below.'),
                        TextInput::make('featured_image.url')->label('URL')->url()->maxLength(2048),
                        TextInput::make('featured_image.alt_text')->label('Alt text')->maxLength(1000),
                        Grid::make(2)->schema([
                            TextInput::make('featured_image.width')->label('Width')->numeric()->minValue(1),
                            TextInput::make('featured_image.height')->label('Height')->numeric()->minValue(1),
                        ]),
                    ]),
                    Section::make('SEO')->collapsible()->schema([
                        TextInput::make('meta_title')->maxLength(255)->helperText('Defaults to the title.'),
                        Textarea::make('meta_description')->rows(3)->maxLength(1000),
                        TextInput::make('target_keyword')->maxLength(255),
                    ]),
                ]),
            ]),
        ]);
    }
}
