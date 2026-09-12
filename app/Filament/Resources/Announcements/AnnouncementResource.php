<?php

namespace App\Filament\Resources\Announcements;

use App\Domains\Notifications\Models\Announcement;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Announcements\Schemas\AnnouncementForm;
use App\Filament\Resources\Announcements\Tables\AnnouncementsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Notifications → Announcements (§22).
 *
 * NOT THE SAME THING AS Content → Announcements, which is the banner strip on
 * a page. This one is a MESSAGE: it goes to an audience defined by their
 * subscription, in the app and optionally by email, and it leaves a delivery
 * record. The banner is placement; this is people.
 *
 * A SENT ANNOUNCEMENT CANNOT BE EDITED OR DELETED. It is in inboxes; changing
 * the copy here would only make the record disagree with what was sent.
 */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSpeakerWave;

    protected static ?string $navigationLabel = 'Announcements';

    protected static string|\UnitEnum|null $navigationGroup = 'Notifications';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('notifications.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('announcements.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return ! $record->isSent() && (auth()->user()?->can('announcements.manage') ?? false);
    }

    public static function canDelete($record): bool
    {
        return ! $record->isSent() && (auth()->user()?->can('announcements.manage') ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return AnnouncementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
