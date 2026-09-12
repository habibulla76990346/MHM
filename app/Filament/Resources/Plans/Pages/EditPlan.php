<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\Plans\PlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    /** @var array<string, mixed> */
    private array $before = [];

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function beforeSave(): void
    {
        $this->before = $this->record->getOriginal();
    }

    protected function afterSave(): void
    {
        app(ActivityLogger::class)->log('plan.updated', $this->record, $this->before, $this->record->getChanges());
    }
}
