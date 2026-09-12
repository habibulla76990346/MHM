<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\Page;

/**
 * One payment's whole timeline (Addendum D §7).
 *
 * Creation, redirect, return, every webhook received, every reconciliation
 * attempt, the credit grant and the invoice. This is the screen that answers
 * "I paid and got nothing" — without it, the answer is a guess.
 */
class ViewPayment extends Page
{
    protected static string $resource = PaymentResource::class;

    protected string $view = 'filament.pages.payment';

    public $record;

    public function mount(int|string $record): void
    {
        $this->record = static::getResource()::resolveRecordRouteBinding($record);

        abort_unless($this->record !== null, 404);
        abort_unless(auth()->user()?->can('billing.view') ?? false, 403);

        $this->record->load(['transactions', 'refunds', 'user', 'gateway', 'plan', 'invoice']);
    }

    public function getTitle(): string
    {
        return __('Payment :ref', ['ref' => substr((string) $this->record->uuid, 0, 8)]);
    }
}
