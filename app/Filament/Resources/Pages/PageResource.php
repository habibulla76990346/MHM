<?php

namespace App\Filament\Resources\Pages;

use App\Domains\Content\Models\Page;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Filament\Resources\Pages\Tables\PagesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Content → Pages (blueprint §7).
 *
 * Pages are assembled from a closed set of section types rather than free
 * HTML. That is what keeps page content inside the theme system and inside
 * Owner Addendum A: a section renders from design tokens and is responsive by
 * construction, where arbitrary HTML would pass the six-viewport gate only by
 * luck and could hard-code a colour the theme could never override.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Pages';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('content.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('content.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('content.manage') ?? false;
    }

    /** System pages are linked from the footer and from navigation; never deletable. */
    public static function canDelete($record): bool
    {
        return ($auth = auth()->user())
            && $auth->can('content.manage')
            && $record instanceof Page
            && $record->isDeletable();
    }

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
