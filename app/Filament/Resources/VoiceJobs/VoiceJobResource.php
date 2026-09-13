<?php

namespace App\Filament\Resources\VoiceJobs;

use App\Domains\Voice\Models\VoiceJob;
use App\Filament\Resources\VoiceJobs\Pages\ListVoiceJobs;
use App\Filament\Resources\VoiceJobs\Tables\VoiceJobsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Media → Voice (§18).
 *
 * OPERATIONS, AND IT DOES NOT SHOW WHAT ANYONE SAID. It answers how much audio
 * is being used, what it cost, and which jobs failed and why — and the
 * transcript column is deliberately absent, because a customer speaking to
 * Aziv AI is having a conversation, not filing something for review. The same
 * reasoning as the notification delivery log, which records that a message
 * went and never what it said.
 */
class VoiceJobResource extends Resource
{
    protected static ?string $model = VoiceJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static ?string $navigationLabel = 'Voice';

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static ?int $navigationSort = 15;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('media.view') ?? false;
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
        return VoiceJobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListVoiceJobs::route('/')];
    }
}
