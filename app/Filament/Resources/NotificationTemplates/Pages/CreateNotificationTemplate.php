<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Domains\Notifications\Support\NotificationEvent;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationTemplate extends CreateRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // A copy of what this event provides, so the row explains itself
        // without loading code — and so an export of the table is readable.
        $data['variables'] = array_keys(NotificationEvent::variables((string) $data['event_key']));

        return $data;
    }
}
