<?php

namespace App\Filament\Resources\KnowledgeBases\Pages;

use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Filament\Resources\KnowledgeBases\KnowledgeBaseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeBase extends CreateRecord
{
    protected static string $resource = KnowledgeBaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Everything created here is SHARED. A personal collection belongs to
        // a customer and is made from their own Library.
        $data['scope'] = KnowledgeBase::SCOPE_SHARED;
        $data['created_by'] = auth()->id();

        return $data;
    }
}
