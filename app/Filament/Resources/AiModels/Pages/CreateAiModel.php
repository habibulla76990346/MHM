<?php

namespace App\Filament\Resources\AiModels\Pages;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\AiModels\AiModelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiModel extends CreateRecord
{
    protected static string $resource = AiModelResource::class;

    /** @var array<int, string> */
    private array $capabilityKeys = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->capabilityKeys = $data['capability_keys'] ?? [];
        unset($data['capability_keys']);

        // Typed in by hand, so a catalog refresh must never overwrite it.
        $data['source'] = AiModel::SOURCE_MANUAL;

        return $data;
    }

    protected function afterCreate(): void
    {
        AiModelCapability::syncForModel($this->record, $this->capabilityKeys);

        app(ActivityLogger::class)->log('model.created', $this->record, null, [
            'model_identifier' => $this->record->model_identifier,
            'provider_id' => $this->record->provider_id,
        ]);
    }
}
