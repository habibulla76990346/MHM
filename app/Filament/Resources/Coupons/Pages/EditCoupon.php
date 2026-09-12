<?php

namespace App\Filament\Resources\Coupons\Pages;

use App\Domains\Security\Services\ActivityLogger;
use App\Filament\Resources\Coupons\CouponResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCoupon extends EditRecord
{
    protected static string $resource = CouponResource::class;

    private array $before = [];

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function beforeSave(): void
    {
        $this->before = $this->record->getOriginal();
    }

    protected function afterSave(): void
    {
        app(ActivityLogger::class)->log('coupon.updated', $this->record, $this->before, $this->record->getChanges());
    }
}
