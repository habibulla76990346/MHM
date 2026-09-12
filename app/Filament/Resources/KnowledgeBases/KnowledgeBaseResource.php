<?php

namespace App\Filament\Resources\KnowledgeBases;

use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Filament\Resources\KnowledgeBases\Pages\CreateKnowledgeBase;
use App\Filament\Resources\KnowledgeBases\Pages\EditKnowledgeBase;
use App\Filament\Resources\KnowledgeBases\Pages\ListKnowledgeBases;
use App\Filament\Resources\KnowledgeBases\RelationManagers\GrantsRelationManager;
use App\Filament\Resources\KnowledgeBases\Schemas\KnowledgeBaseForm;
use App\Filament\Resources\KnowledgeBases\Tables\KnowledgeBasesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADMIN → Knowledge → Collections (§17).
 *
 * SHARED COLLECTIONS ONLY. A customer's personal documents are not
 * administered from here and are not listed here — §17 requires that file
 * access "follow role-based permissions and privacy rules", and an
 * administrator browsing customers' uploaded documents through an admin screen
 * is precisely what that forbids. What an administrator curates is the
 * collections THEY created and chose to share.
 */
class KnowledgeBaseResource extends Resource
{
    protected static ?string $model = KnowledgeBase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Collections';

    protected static string|\UnitEnum|null $navigationGroup = 'Knowledge';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /** The query is the privacy boundary, not a filter somebody can clear. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('scope', KnowledgeBase::SCOPE_SHARED);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('knowledge.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('knowledge.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('knowledge.manage') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('knowledge.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return KnowledgeBaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeBasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [GrantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeBases::route('/'),
            'create' => CreateKnowledgeBase::route('/create'),
            'edit' => EditKnowledgeBase::route('/{record}/edit'),
        ];
    }
}
