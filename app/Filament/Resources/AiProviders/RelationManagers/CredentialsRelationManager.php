<?php

namespace App\Filament\Resources\AiProviders\RelationManagers;

use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * API keys for one provider (§10, §25, Rule 6).
 *
 * THE DISPLAY RULE. The full secret is never shown — not on this screen, not
 * in an edit form, not anywhere. The list identifies a key by its label and
 * the last four characters, which are stored separately so listing keys never
 * decrypts anything.
 *
 * The edit form therefore shows an EMPTY key field. Pre-filling it would mean
 * decrypting the secret and sending it to a browser, which is precisely what
 * §25 forbids; leaving it blank means "keep the existing key", and typing in
 * it replaces it.
 */
class CredentialsRelationManager extends RelationManager
{
    protected static string $relationship = 'credentials';

    protected static ?string $title = 'API keys';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('credentials.view') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')
                ->required()
                ->maxLength(120)
                ->helperText('Something you will recognise later, such as "Main account" or "Standby".'),

            TextInput::make('credential')
                ->label('API key')
                ->password()
                ->revealable(false)
                ->autocomplete(false)
                // Required when creating, optional when editing: an empty field
                // on edit means "leave the key alone".
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'create'
                    ? 'Pasted once and encrypted immediately. It is never shown again — only the last four characters.'
                    : 'Leave empty to keep the current key. Type a new one to replace it.')
                ->maxLength(500),

            Select::make('status')
                ->options(AiProviderCredential::STATUSES)
                ->default(AiProviderCredential::STATUS_ACTIVE)
                ->required(),

            TextInput::make('priority')
                ->numeric()
                ->default(100)
                ->helperText('Lower is used first.'),

            Textarea::make('quota_note')
                ->label('Notes')
                ->rows(2)
                ->maxLength(255)
                ->columnSpanFull()
                // Rule 7, stated where a second key is actually added.
                ->helperText(
                    'Several keys are supported for genuine reasons — separate billing accounts, a standby for a revoked key, '
                    .'regional accounts. Aziv AI will not switch keys to get around a provider\'s rate limit or quota, because '
                    .'that breaks their terms.'
                ),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('label')->weight('medium'),

                // Read from the stored hint. Nothing is decrypted to render
                // this screen.
                TextColumn::make('hint')
                    ->label('Key')
                    ->formatStateUsing(fn (?string $state, AiProviderCredential $record) => $record->masked())
                    ->fontFamily('mono'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => AiProviderCredential::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),

                TextColumn::make('last_verified_at')
                    ->label('Last checked')
                    ->since()
                    ->placeholder('Never')
                    ->visibleFrom('md'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('credentials.manage'))
                    ->after(fn (AiProviderCredential $record) => app(ActivityLogger::class)->log(
                        'credential.added',
                        $record,
                        null,
                        // The label and the hint. Never the key, and never
                        // anything from which it could be reconstructed.
                        ['label' => $record->label, 'hint' => $record->hint],
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('credentials.manage'))
                    // Never pre-fill the key into the browser.
                    ->mutateRecordDataUsing(function (array $data): array {
                        unset($data['credential']);

                        return $data;
                    })
                    ->after(fn (AiProviderCredential $record) => app(ActivityLogger::class)->log(
                        'credential.updated',
                        $record,
                        null,
                        ['label' => $record->label, 'hint' => $record->hint, 'status' => $record->status],
                    )),

                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('credentials.manage'))
                    ->before(fn (AiProviderCredential $record) => app(ActivityLogger::class)->log(
                        'credential.deleted',
                        $record,
                        ['label' => $record->label, 'hint' => $record->hint],
                        null,
                    )),
            ]);
    }
}
