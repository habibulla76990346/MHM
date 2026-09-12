<?php

namespace App\Filament\Resources\PaymentGateways\Pages;

use App\Filament\Resources\PaymentGateways\PaymentGatewayResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentGateways extends ListRecords
{
    protected static string $resource = PaymentGatewayResource::class;
}
