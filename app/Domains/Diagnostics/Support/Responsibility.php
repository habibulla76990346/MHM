<?php

namespace App\Domains\Diagnostics\Support;

/** Whose problem is this? The single most useful field on a finding. */
enum Responsibility: string
{
    case Hosting = 'hosting';
    case Application = 'application';
    case Configuration = 'configuration';
    case Provider = 'api_provider';
    case Database = 'database';
    case Payment = 'payment';
    case Security = 'security';
    case Network = 'network';

    public function label(): string
    {
        return match ($this) {
            self::Provider => 'API / provider',
            default => ucfirst($this->value),
        };
    }
}
