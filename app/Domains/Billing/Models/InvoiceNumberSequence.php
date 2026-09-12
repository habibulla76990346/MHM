<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Gap-free invoice numbering (Addendum F §3).
 *
 * Gaps and duplicates are a compliance problem in most jurisdictions, so the
 * next number is allocated under a row lock inside the issuing transaction —
 * two simultaneous checkouts cannot receive the same one.
 *
 * A number is taken only when an invoice is ACTUALLY ISSUED, never on a
 * payment attempt. Allocating on attempt would burn a number every time a card
 * was declined, and the resulting gaps are exactly what an auditor asks about.
 *
 * The financial-year reset exists because India's year starts in April. It is
 * a setting rather than an assumption.
 */
class InvoiceNumberSequence extends Model
{
    public const RESET_NEVER = 'never';

    public const RESET_YEARLY = 'yearly';

    public const RESET_MONTHLY = 'monthly';

    public const RESET_FINANCIAL_YEAR = 'financial_year';

    /** @return array<string, string> */
    public static function resetPolicies(): array
    {
        return [
            self::RESET_NEVER => 'Never — keep counting',
            self::RESET_YEARLY => 'Every calendar year',
            self::RESET_MONTHLY => 'Every month',
            self::RESET_FINANCIAL_YEAR => 'Every financial year',
        ];
    }

    protected $fillable = [
        'key', 'prefix', 'suffix', 'current_value', 'padding', 'reset_policy',
        'fy_start_month', 'format_template', 'last_period_key',
    ];

    /** The period this date falls in, under the configured reset policy. */
    public function periodKeyFor(\DateTimeInterface $date): string
    {
        $date = Carbon::instance(
            $date instanceof \DateTimeImmutable ? \DateTime::createFromImmutable($date) : $date,
        );

        return match ($this->reset_policy) {
            self::RESET_YEARLY => $date->format('Y'),
            self::RESET_MONTHLY => $date->format('Y-m'),
            self::RESET_FINANCIAL_YEAR => $this->financialYearKey($date),
            default => 'all',
        };
    }

    /** e.g. 2026-27 for a year starting in April. */
    public function financialYearKey(Carbon $date): string
    {
        $start = max(1, min(12, (int) $this->fy_start_month));
        $startYear = $date->month >= $start ? $date->year : $date->year - 1;

        return $startYear.'-'.substr((string) ($startYear + 1), -2);
    }

    /** Render a number from the template, without touching the counter. */
    public function render(int $value, \DateTimeInterface $date): string
    {
        $carbon = Carbon::parse($date);

        return str_replace(
            ['{prefix}', '{suffix}', '{number}', '{fy}', '{year}', '{month}'],
            [
                $this->prefix,
                $this->suffix,
                str_pad((string) $value, max(1, (int) $this->padding), '0', STR_PAD_LEFT),
                $this->reset_policy === self::RESET_FINANCIAL_YEAR ? $this->financialYearKey($carbon).'/' : '',
                $carbon->format('Y'),
                $carbon->format('m'),
            ],
            $this->format_template ?: '{prefix}{number}{suffix}',
        );
    }
}
