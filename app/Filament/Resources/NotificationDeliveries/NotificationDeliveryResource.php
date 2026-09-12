<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Domains\Notifications\Models\NotificationDelivery;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Resources\NotificationDeliveries\Tables\NotificationDeliveriesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Notifications → Delivery log (§22).
 *
 * READ ONLY, AND DELIBERATELY THIN. It answers "did the renewal notice for
 * invoice 42 go out, and did it fail?" — which is what support needs — and it
 * cannot answer "what did it say", because it does not hold the message. An
 * audit table is read by more people than an inbox is.
 *
 * Nothing here can be created, edited or deleted: a record of what happened
 * that somebody can edit is not a record.
 */
class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Delivery log';

    protected static string|\UnitEnum|null $navigationGroup = 'Notifications';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('notifications.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return NotificationDeliveriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationDeliveries::route('/')];
    }
}
