<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A dated rate (§13).
 *
 * The newest rate at or before a date is used, so a gap in the history is
 * covered by the last known figure. A stale rate is far better than a missing
 * one: a missing rate means a margin report that silently omits a day.
 */
class ExchangeRate extends Model
{
    protected $fillable = ['base_currency', 'quote_currency', 'rate', 'effective_on', 'source'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:8', 'effective_on' => 'date'];
    }

    /**
     * The rate that applied on a given day.
     *
     * NOT named `on()`: Eloquent already defines `Model::on($connection)`, and
     * redeclaring it with a different signature is a fatal error at class load
     * — the whole application stops, not just the report.
     *
     * Returns null rather than guessing 1.0 when nothing is known: silently
     * treating one US dollar as one rupee would make a margin report
     * confidently wrong, which is worse than one that says it cannot tell.
     */
    public static function rateOn(string $from, string $to, ?\DateTimeInterface $date = null): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $date ??= now();

        $direct = static::where('base_currency', $from)
            ->where('quote_currency', $to)
            ->where('effective_on', '<=', $date)
            ->orderByDesc('effective_on')
            ->value('rate');

        if ($direct !== null) {
            return (float) $direct;
        }

        // The inverse of a stored pair is still a known fact, so one row can
        // serve both directions.
        $inverse = static::where('base_currency', $to)
            ->where('quote_currency', $from)
            ->where('effective_on', '<=', $date)
            ->orderByDesc('effective_on')
            ->value('rate');

        return $inverse !== null && (float) $inverse > 0 ? 1 / (float) $inverse : null;
    }
}
