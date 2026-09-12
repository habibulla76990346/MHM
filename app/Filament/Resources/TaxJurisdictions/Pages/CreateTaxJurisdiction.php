<?php

namespace App\Filament\Resources\TaxJurisdictions\Pages;

use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\TaxJurisdictions\TaxJurisdictionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxJurisdiction extends CreateRecord
{
    protected static string $resource = TaxJurisdictionResource::class;

    protected function afterCreate(): void
    {
        app(ActivityLogger::class)->log('tax.jurisdiction_created', $this->record, null, $this->record->only([
            'name', 'country', 'state', 'is_active',
        ]));
    }
}
