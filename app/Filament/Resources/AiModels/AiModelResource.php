<?php

namespace App\Filament\Resources\AiModels;

use App\Domains\AI\Models\AiModel;
use App\Filament\Resources\AiModels\Pages\CreateAiModel;
use App\Filament\Resources\AiModels\Pages\EditAiModel;
use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Filament\Resources\AiModels\Schemas\AiModelForm;
use App\Filament\Resources\AiModels\Tables\AiModelsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → AI → Models (blueprint §11).
 *
 * Rule 5 made visible: this table is where the application learns what models
 * exist. Nothing in code names one.
 */
class AiModelResource extends Resource
{
    protected static ?string $model = AiModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Models';

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('models.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('models.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('models.manage') ?? false;
    }

    /**
     * A SYNCED model is never deletable: usage records and invoices refer to
     * it, and the next sync would bring it straight back. Deprecating it is
     * the correct action, and the catalog keeps the history intact.
     */
    public static function canDelete($record): bool
    {
        return ($auth = auth()->user())
            && $auth->can('models.manage')
            && $record instanceof AiModel
            && $record->source === AiModel::SOURCE_MANUAL;
    }

    public static function form(Schema $schema): Schema
    {
        return AiModelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiModelsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiModels::route('/'),
            'create' => CreateAiModel::route('/create'),
            'edit' => EditAiModel::route('/{record}/edit'),
        ];
    }
}
