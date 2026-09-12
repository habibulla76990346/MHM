<?php

namespace App\Filament\Resources\Coupons\Pages;

use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\Coupons\CouponResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    protected function afterCreate(): void
    {
        app(ActivityLogger::class)->log('coupon.created', $this->record, null, $this->record->only([
            'code', 'type', 'value', 'max_redemptions', 'is_active',
        ]));
    }
}
