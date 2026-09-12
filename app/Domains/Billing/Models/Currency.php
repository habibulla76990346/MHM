<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A currency the platform can price in (Addendum F).
 *
 * `decimal_places` is the point of this table. Money formatted at a fixed two
 * decimals is wrong for JPY (zero) and KWD (three), and rounding at two would
 * quietly lose or invent money in those markets.
 */
class Currency extends Model
{
    protected $fillable = [
        'code', 'name', 'symbol', 'decimal_places', 'display_format',
        'is_active', 'is_base', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_base' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public static function find3(?string $code): ?self
    {
        return $code ? static::where('code', strtoupper($code))->first() : null;
    }

    /** Round to what this currency can actually express. */
    public function round(float $amount): float
    {
        return round($amount, $this->decimal_places);
    }

    public function format(float $amount): string
    {
        $number = number_format($this->round($amount), $this->decimal_places);

        return str_replace(
            ['{symbol}', '{amount}', '{code}'],
            [$this->symbol, $number, $this->code],
            $this->display_format ?: '{symbol}{amount}',
        );
    }
}
