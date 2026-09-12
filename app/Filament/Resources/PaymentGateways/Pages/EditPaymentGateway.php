<?php

namespace App\Filament\Resources\PaymentGateways\Pages;

use App\Domains\Payments\Services\PaymentGatewayRegistry;
use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\PaymentGateways\PaymentGatewayResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentGateway extends EditRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    private array $before = [];

    protected function beforeSave(): void
    {
        $this->before = $this->record->getOriginal();
    }

    /**
     * Audited with before and after — and never with a credential in either.
     * `getChanges()` on the gateway row cannot contain one: secrets live on a
     * separate model that is $hidden and is not part of this diff.
     */
    protected function afterSave(): void
    {
        app(ActivityLogger::class)->log(
            'payments.gateway_updated',
            $this->record,
            $this->before,
            $this->record->getChanges(),
        );

        app(PaymentGatewayRegistry::class)->flush();
    }
}
