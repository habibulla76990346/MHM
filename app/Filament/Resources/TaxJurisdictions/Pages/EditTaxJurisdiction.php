<?php

namespace App\Filament\Resources\TaxJurisdictions\Pages;

use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\TaxJurisdictions\TaxJurisdictionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaxJurisdiction extends EditRecord
{
    protected static string $resource = TaxJurisdictionResource::class;

    private array $before = [];

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function beforeSave(): void
    {
        $this->before = $this->record->getOriginal();
    }

    /**
     * Audited with before and after — a tax change is exactly the kind of
     * write somebody will need to account for later.
     */
    protected function afterSave(): void
    {
        app(ActivityLogger::class)->log('tax.jurisdiction_updated', $this->record, $this->before, $this->record->getChanges());
    }
}
