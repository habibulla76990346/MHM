<?php

namespace App\Filament\Resources\KnowledgeBases\RelationManagers;

use App\Domains\Billing\Models\Plan;
use App\Domains\Knowledge\Models\KnowledgeBaseGrant;
use App\Domains\Security\Services\ActivityLogger;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who may search this collection (§17).
 *
 * A CLOSED SET: one named customer, or one whole plan. There is no "everyone"
 * option, because the commonest way sensitive documents leak is a broad
 * default nobody revisited.
 *
 * Every grant and every removal is audited. "Who gave this customer access to
 * the internal handbook?" is a question asked after the fact, and it needs an
 * answer.
 */
class GrantsRelationManager extends RelationManager
{
    protected static string $relationship = 'grants';

    protected static ?string $title = 'Who can search it';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('knowledge.view') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('A named customer')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => User::query()
                    ->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->limit(20)
                    ->pluck('email', 'id')
                    ->all())
                ->getOptionLabelUsing(fn ($value) => User::find($value)?->email)
                ->helperText('Leave empty to grant a whole plan instead.'),

            Select::make('plan_id')
                ->label('Everyone on a plan')
                ->options(fn () => Plan::orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Access follows the plan: it starts when they subscribe and stops when they leave it.'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('Customer')->placeholder('—')->searchable(),
                TextColumn::make('plan.name')->label('Plan')->placeholder('—'),
                TextColumn::make('created_at')->label('Granted')->dateTime('j M Y')->visibleFrom('sm'),
            ])
            ->emptyStateHeading('Nobody can search this yet')
            ->emptyStateDescription('A collection with no grant reaches nobody. Add a customer or a plan.')
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('knowledge.grant') ?? false)
                    ->mutateDataUsing(function (array $data): array {
                        $data['granted_by'] = auth()->id();

                        return $data;
                    })
                    ->before(function (array $data) {
                        // One or the other, never neither: a grant naming
                        // nobody would sit in the list looking like access.
                        if (blank($data['user_id'] ?? null) && blank($data['plan_id'] ?? null)) {
                            throw new \RuntimeException('Choose a customer or a plan.');
                        }
                    })
                    ->after(fn (KnowledgeBaseGrant $record) => app(ActivityLogger::class)->log(
                        'knowledge.granted',
                        $record->knowledgeBase,
                        null,
                        ['user_id' => $record->user_id, 'plan_id' => $record->plan_id],
                    )),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('knowledge.grant') ?? false)
                    ->before(fn (KnowledgeBaseGrant $record) => app(ActivityLogger::class)->log(
                        'knowledge.grant_removed',
                        $record->knowledgeBase,
                        ['user_id' => $record->user_id, 'plan_id' => $record->plan_id],
                        null,
                    )),
            ]);
    }
}
