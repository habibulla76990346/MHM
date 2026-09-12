<?php

namespace App\Filament\Pages;

use App\Domains\Credits\Models\CreditLedgerEntry;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Security\Services\ActivityLogger;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * ADMIN → Billing → Customer credits (§19).
 *
 * Grants, deductions, refunds and manual adjustments, each with a reason.
 *
 * THE REASON IS MANDATORY and is stored on the ledger row itself. An
 * adjustment nobody can explain six months later is indistinguishable from a
 * mistake or a theft, and the person who has to tell those apart is usually
 * not the person who made it.
 */
class CustomerCredits extends Page
{
    protected static ?string $navigationLabel = 'Customer credits';

    protected static ?string $title = 'Customer credits';

    protected static ?string $slug = 'customer-credits';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.customer-credits';

    public string $search = '';

    public ?int $selectedUserId = null;

    public ?string $amount = null;

    public string $reason = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('billing.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function canAdjust(): bool
    {
        return auth()->user()?->can('users.adjust_credits') ?? false;
    }

    /** @return Collection<int, User> */
    public function matches()
    {
        if (trim($this->search) === '') {
            return collect();
        }

        return User::query()
            ->where(fn ($q) => $q->where('email', 'like', '%'.trim($this->search).'%')
                ->orWhere('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('email')
            ->limit(10)
            ->get();
    }

    public function selected(): ?User
    {
        return $this->selectedUserId ? User::find($this->selectedUserId) : null;
    }

    public function select(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->search = '';
    }

    public function balance(): array
    {
        $user = $this->selected();

        if (! $user) {
            return ['confirmed' => 0.0, 'held' => 0.0, 'spendable' => 0.0, 'reconciles' => true];
        }

        $credits = app(CreditService::class);
        $balance = $credits->balance($user);

        return [
            'confirmed' => (float) $balance->confirmed_balance,
            'held' => (float) $balance->held_balance,
            'spendable' => $balance->spendable(),
            // Surfaced rather than assumed: if the cached balance ever stops
            // matching the ledger, every figure on this screen is suspect and
            // somebody needs to know before a customer does.
            'reconciles' => $credits->reconciles($user),
        ];
    }

    public function ledger()
    {
        $user = $this->selected();

        if (! $user) {
            return collect();
        }

        return CreditLedgerEntry::where('user_id', $user->getKey())
            ->with('actor')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function adjust(): void
    {
        $user = $this->selected();

        if (! $user || ! $this->canAdjust()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $amount = (float) $this->amount;

        if ($amount === 0.0) {
            Notification::make()->title('Enter an amount')->warning()->send();

            return;
        }

        if (trim($this->reason) === '') {
            Notification::make()
                ->title('A reason is required')
                ->body('It is stored on the ledger permanently, and it is what makes this adjustment explainable later.')
                ->warning()
                ->send();

            return;
        }

        try {
            $entry = app(CreditService::class)->adjust($user, $amount, trim($this->reason), (int) auth()->id());
        } catch (\Throwable $e) {
            Notification::make()->title('Not applied')->body($e->getMessage())->danger()->send();

            return;
        }

        app(ActivityLogger::class)->log('credits.adjusted', $user, null, [
            'amount' => $amount,
            'reason' => trim($this->reason),
            'balance_after' => (float) $entry->balance_after,
        ]);

        $this->amount = null;
        $this->reason = '';

        Notification::make()->title('Adjusted')->success()->send();
    }
}
