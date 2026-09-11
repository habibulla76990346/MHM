<?php

namespace App\Filament\Resources\Personas;

use App\Domains\Chat\Models\Persona;
use App\Filament\Resources\Personas\Pages\CreatePersona;
use App\Filament\Resources\Personas\Pages\EditPersona;
use App\Filament\Resources\Personas\Pages\ListPersonas;
use App\Filament\Resources\Personas\Schemas\PersonaForm;
use App\Filament\Resources\Personas\Tables\PersonasTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → AI → Personas (blueprint §15).
 *
 * The system prompt that shapes how Aziv AI answers. Written here and nowhere
 * else: a customer-supplied system prompt is how a product's guardrails get
 * talked away, and the point of a persona is that the OWNER decides how their
 * product behaves.
 */
class PersonaResource extends Resource
{
    protected static ?string $model = Persona::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Personas';

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'name';

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

    /** The default persona is what a new chat gets; deleting it leaves none. */
    public static function canDelete($record): bool
    {
        return ($auth = auth()->user())
            && $auth->can('content.manage')
            && $record instanceof Persona
            && ! $record->is_default;
    }

    public static function form(Schema $schema): Schema
    {
        return PersonaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PersonasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPersonas::route('/'),
            'create' => CreatePersona::route('/create'),
            'edit' => EditPersona::route('/{record}/edit'),
        ];
    }
}
