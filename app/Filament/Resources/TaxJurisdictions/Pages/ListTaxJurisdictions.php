<?php

namespace App\Filament\Resources\TaxJurisdictions\Pages;

use App\Filament\Resources\TaxJurisdictions\TaxJurisdictionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxJurisdictions extends ListRecords
{
    protected static string $resource = TaxJurisdictionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
