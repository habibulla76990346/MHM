<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use App\Domains\Tax\Models\TaxJurisdiction;
use App\Domains\Tax\Models\TaxRule;
use App\Domains\Tax\Models\TaxSettings;

/**
 * Whether the tax configuration is complete enough to issue an invoice
 * (Owner Addendum F and G).
 *
 * The failure this exists to catch is the quiet one: tax switched ON with a
 * jurisdiction that has no rule, or a rule whose rates have all expired. Every
 * invoice then goes out with no tax and a note nobody reads, and the shortfall
 * is discovered at filing time — when it is the business, not the customer,
 * that owes the money.
 *
 * It never says what a rate SHOULD be. That is the owner's decision with their
 * accountant; this only reports whether what they configured can actually be
 * applied.
 */
class TaxConfigurationCheck extends BaseCheck
{
    public function key(): string
    {
        return 'payments.tax';
    }

    public function title(): string
    {
        return 'Tax configuration';
    }

    public function category(): Category
    {
        return Category::Payments;
    }

    public function isApplicable(): bool
    {
        try {
            return TaxSettings::query()->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function run(): CheckResult
    {
        $settings = TaxSettings::current();

        if (! $settings->tax_enabled) {
            return $this->result(
                Status::Grey,
                Severity::Informational,
                'Tax is switched off, so invoices are issued without it.',
                'Nothing is being charged as tax. If that is not right for your business, it needs configuring.',
                'Open Admin → Tax and compliance, fill in your business details, add your rates, then switch tax on.',
            );
        }

        $missing = $settings->missingFields();

        if ($missing !== []) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                'Tax is on but these are missing: '.implode(', ', $missing).'.',
                'Invoices are going out with no tax and a note saying the details are incomplete.',
                'Open Admin → Tax and compliance and complete the business details.',
            );
        }

        $active = TaxJurisdiction::where('is_active', true)->with(['rates', 'rules'])->get();

        if ($active->isEmpty()) {
            return $this->result(
                Status::Red,
                Severity::High,
                'Tax is on but no jurisdiction is active.',
                'No tax can be calculated, so every invoice records zero.',
                'Open Admin → Tax rules, create a jurisdiction with your rates and switch it on.',
            );
        }

        $problems = [];

        foreach ($active as $jurisdiction) {
            $rules = $jurisdiction->rules->where('is_active', true);

            if ($rules->isEmpty()) {
                $problems[] = $jurisdiction->name.': active but has no rule, so nothing will ever match it';

                continue;
            }

            $inForce = $jurisdiction->rates()->effectiveOn(now())->pluck('id')->all();

            foreach ($rules as $rule) {
                $named = array_map('intval', (array) $rule->rate_ids);

                if (array_intersect($named, $inForce) === []) {
                    $problems[] = $jurisdiction->name.': the "'
                        .(TaxRule::conditions()[$rule->condition_type] ?? $rule->condition_type)
                        .'" rule names no rate that is in force today';
                }
            }
        }

        if ($problems !== []) {
            return $this->result(
                Status::Red,
                Severity::High,
                implode(' · ', $problems),
                'Invoices matching those rules will be issued with no tax on them.',
                'Open Admin → Tax rules and check that each rule names a rate whose dates cover today.',
            );
        }

        $withoutTax = Invoice::whereNotNull('issued_at')
            ->where('issued_at', '>=', now()->subDays(7))
            ->where('tax_total', 0)
            ->count();

        if ($withoutTax > 0) {
            return $this->result(
                Status::Yellow,
                Severity::Medium,
                $withoutTax.' invoice(s) issued in the last week carry no tax.',
                'That may be correct — exports and exempt customers are legitimately zero — but it is worth confirming.',
                'Open Admin → Invoices and check a few of them. The tax note on each says why it was zero.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            'Tax is on, the business details are complete, and every active rule names a rate in force.',
            'Invoices will carry tax as configured.',
            'Nothing to do.',
        );
    }

    private function result(
        Status $status,
        Severity $severity,
        string $technicalReason,
        string $recommendedAction,
        string $adminAction,
    ): CheckResult {
        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $status,
            severity: $severity,
            responsibility: Responsibility::Configuration,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
        );
    }
}
