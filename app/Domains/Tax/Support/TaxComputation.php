<?php

namespace App\Domains\Tax\Support;

/**
 * The result of a tax calculation, ready to be frozen onto an invoice.
 *
 * A value object rather than an array because this is the thing that gets
 * copied onto a document that can never be changed afterwards — every field
 * here becomes a permanent record, and a typo'd array key that silently
 * returned null would become a permanently wrong invoice.
 */
final class TaxComputation
{
    /**
     * @param  array<int, array{name: string, code: ?string, rate_percent: float, taxable_amount: float, tax_amount: float, jurisdiction: ?string}>  $components
     */
    public function __construct(
        public readonly float $net,
        public readonly float $taxTotal,
        public readonly float $gross,
        public readonly array $components = [],
        public readonly string $pricingMode = 'exclusive',
        public readonly bool $isExport = false,
        public readonly ?string $placeOfSupply = null,
        public readonly ?string $jurisdiction = null,
        /** Why no tax was charged, when none was. Shown on the invoice. */
        public readonly ?string $note = null,
    ) {}

    /** No tax, for a stated reason. Never a silent zero. */
    public static function none(float $amount, string $note, string $pricingMode = 'exclusive'): self
    {
        return new self(
            net: round($amount, 6),
            taxTotal: 0.0,
            gross: round($amount, 6),
            components: [],
            pricingMode: $pricingMode,
            note: $note,
        );
    }

    public function hasTax(): bool
    {
        return $this->components !== [];
    }

    public function toArray(): array
    {
        return [
            'net' => $this->net,
            'tax_total' => $this->taxTotal,
            'gross' => $this->gross,
            'components' => $this->components,
            'pricing_mode' => $this->pricingMode,
            'is_export' => $this->isExport,
            'place_of_supply' => $this->placeOfSupply,
            'jurisdiction' => $this->jurisdiction,
            'note' => $this->note,
        ];
    }
}
