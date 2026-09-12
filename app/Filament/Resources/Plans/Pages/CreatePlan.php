<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    /** Every admin write is audited with before and after. */
    protected function afterCreate(): void
    {
        app(ActivityLogger::class)->log('plan.created', $this->record, null, $this->record->only([
            'name', 'billing_cycle', 'credits_per_period', 'status', 'is_default',
        ]));
    }
}
