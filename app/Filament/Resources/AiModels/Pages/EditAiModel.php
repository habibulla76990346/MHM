<?php

namespace App\Filament\Resources\AiModels\Pages;

use App\Domains\AI\Models\AiModelCapability;
use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\AiModels\AiModelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiModel extends EditRecord
{
    protected static string $resource = AiModelResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Capabilities live in their own table, so the checkbox list is filled
     * from the relationship rather than a column.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['capability_keys'] = $this->record->capabilities()
            ->where('is_supported', true)
            ->pluck('capability')
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->capabilityKeys = $data['capability_keys'] ?? [];
        unset($data['capability_keys']);

        return $data;
    }

    /** @var array<int, string> */
    private array $capabilityKeys = [];

    protected function afterSave(): void
    {
        AiModelCapability::syncForModel($this->record, $this->capabilityKeys);

        app(ActivityLogger::class)->log('model.updated', $this->record, null, [
            'display_name' => $this->record->display_name,
            'is_enabled' => $this->record->is_enabled,
            'capabilities' => $this->capabilityKeys,
        ]);
    }
}
