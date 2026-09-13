<?php

namespace App\Filament\Resources\ImageGenerations;

use App\Domains\Images\Models\ImageGeneration;
use App\Filament\Resources\ImageGenerations\Pages\ListImageGenerations;
use App\Filament\Resources\ImageGenerations\Tables\ImageGenerationsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Media → Image generations (§16).
 *
 * OPERATIONS, NOT A VIEWER. It answers the questions an owner actually has —
 * how many images are being made, what they cost, which ones failed and why,
 * which model served them — and it deliberately does NOT show the pictures.
 *
 * A customer's generated images are their content, the same way a personal
 * knowledge base is: `media.view` is the authority to run the feature, not the
 * authority to look at what people made with it. The one case that genuinely
 * needs the picture — a report about a specific image — is a deletion under
 * `media.delete_any`, which is a deliberate act with an audit record behind
 * it, rather than a gallery anybody with admin access can browse.
 *
 * Nothing here can be created or edited: an administrator inventing a
 * generation that never happened would be inventing a charge.
 */
class ImageGenerationResource extends Resource
{
    protected static ?string $model = ImageGeneration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Image generations';

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static ?int $navigationSort = 10;

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
        return auth()->user()?->can('media.delete_any') ?? false;
    }

    public static function table(Table $table): Table
    {
        return ImageGenerationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListImageGenerations::route('/')];
    }
}
