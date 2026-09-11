<?php

namespace App\Filament\Resources\AiProviders;

use App\Domains\AI\Models\AiProvider;
use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Filament\Resources\AiProviders\RelationManagers\CredentialsRelationManager;
use App\Filament\Resources\AiProviders\Schemas\AiProviderForm;
use App\Filament\Resources\AiProviders\Tables\AiProvidersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → AI → Providers (blueprint §10).
 *
 * Adding a provider is a form, not a deployment. For any provider that copies
 * OpenAI's API shape — which is most of them — a name, a base URL and a key
 * are the whole integration.
 */
class AiProviderResource extends Resource
{
    protected static ?string $model = AiProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'Providers';

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('providers.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('providers.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('providers.manage') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('providers.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return AiProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiProvidersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [CredentialsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiProviders::route('/'),
            'create' => CreateAiProvider::route('/create'),
            'edit' => EditAiProvider::route('/{record}/edit'),
        ];
    }
}
