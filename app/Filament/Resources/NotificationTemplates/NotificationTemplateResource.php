<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Domains\Notifications\Models\NotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplates\Schemas\NotificationTemplateForm;
use App\Filament\Resources\NotificationTemplates\Tables\NotificationTemplatesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Notifications → Templates (§22).
 *
 * A ROW HERE IS AN OVERRIDE. Every event ships with wording, so an empty table
 * still sends complete email — and deleting a row restores the shipped words
 * rather than silencing the event. Switching one off is a separate, deliberate
 * act, which is what an owner means by "stop sending this".
 */
class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Templates';

    protected static string|\UnitEnum|null $navigationGroup = 'Notifications';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('notifications.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('notifications.templates.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('notifications.templates.manage') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('notifications.templates.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return NotificationTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'create' => CreateNotificationTemplate::route('/create'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
