<?php

namespace Lostdomain\BlogKit\Filament\Resources\Posts\Schemas;

use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Lostdomain\BlogKit\BlogKit;
use Lostdomain\BlogKit\Models\Post;
use Lostdomain\BlogKit\Services\HtmlSanitizer;
use Lostdomain\BlogKit\Services\HtmlToMarkdown;
use Lostdomain\BlogKit\Services\MarkdownRenderer;

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

            Section::make('Content')->columnSpanFull()->columns(2)->schema([
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
                Textarea::make('excerpt')->rows(3)->maxLength(5000)->columnSpanFull(),
            ]),

            Section::make('Body')->columnSpanFull()->schema([
                ToggleButtons::make('body_format')
                    ->hiddenLabel()
                    ->options([Post::FORMAT_MARKDOWN => 'Markdown', Post::FORMAT_HTML => 'HTML'])
                    ->icons([Post::FORMAT_MARKDOWN => 'heroicon-o-pencil-square', Post::FORMAT_HTML => 'heroicon-o-code-bracket'])
                    ->default(Post::FORMAT_MARKDOWN)
                    ->inline()
                    ->live()
                    // Carry the content across, so switching never shows an empty editor.
                    ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set) {
                        if ($state === $old) {
                            return;
                        }

                        if ($state === Post::FORMAT_MARKDOWN) {
                            $set('body_markdown', app(HtmlToMarkdown::class)->convert((string) $get('body_html')));
                        } else {
                            $set('body_html', app(MarkdownRenderer::class)->toHtml((string) $get('body_markdown')));
                        }
                    })
                    ->helperText(fn (Get $get) => $get('body_format') === Post::FORMAT_HTML
                        ? 'Raw HTML, saved as written. Switching to Markdown converts this HTML.'
                        : 'The page is rendered from this Markdown when you save. Use ## for sections (the title is the page heading). An image on its own line becomes a figure; ![alt](url "caption") adds a caption.'),
                Grid::make(['default' => 1, 'xl' => 2])
                    ->visible(fn (Get $get) => $get('body_format') !== Post::FORMAT_HTML)
                    ->schema([
                        MarkdownEditor::make('body_markdown')
                            ->label('Markdown')
                            ->required()
                            ->live(debounce: 750)
                            ->minHeight('32rem')
                            ->fileAttachmentsDisk(fn () => config('blog-kit.images.disk'))
                            ->fileAttachmentsDirectory(fn () => trim((string) config('blog-kit.images.directory'), '/').'/uploads'),
                        Html::make(fn (Get $get) => self::preview((string) $get('body_markdown'))),
                    ]),
                CodeEditor::make('body_html')
                    ->label('HTML')
                    ->language(Language::Html)
                    ->required()
                    ->visible(fn (Get $get) => $get('body_format') === Post::FORMAT_HTML)
                    ->helperText('Sanitized when rendered. Remote image URLs are downloaded to the image disk after saving.'),
            ]),

            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
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
                Section::make('SEO')->schema([
                    TextInput::make('meta_title')->maxLength(255)->helperText('Defaults to the title.'),
                    Textarea::make('meta_description')->rows(3)->maxLength(1000),
                    TextInput::make('target_keyword')->maxLength(255),
                ]),
            ]),

            Section::make('FAQ')->columnSpanFull()->collapsible()->schema([
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
            Section::make('Call to action')->columnSpanFull()->collapsible()->schema([
                Textarea::make('cta')->hiddenLabel()->rows(3),
            ]),
        ]);
    }

    /** The article body as the public page renders it, with the package's prose styles. */
    private static function preview(string $markdown): HtmlString
    {
        $body = trim($markdown) === ''
            ? '<p style="opacity:.6">The preview appears here as you write.</p>'
            : app(HtmlSanitizer::class)->sanitize(app(MarkdownRenderer::class)->toHtml($markdown));

        return new HtmlString(
            view('blog-kit::partials.styles')->render()
            .'<p style="font-size:.875rem;font-weight:500;margin-bottom:.5rem">Preview</p>'
            .'<div class="blog-kit" style="max-height:40rem;overflow-y:auto;padding:1rem;border:1px solid rgb(127 127 127 / .25);border-radius:.5rem">'
            .'<div class="blog-kit-prose">'.$body.'</div></div>'
        );
    }
}
