<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The circuit breaker (§14).
 *
 * A provider that is failing should be taken out of rotation quickly and
 * probed occasionally, rather than every customer request waiting for the same
 * timeout. `forced_open` is the emergency control from §24: an owner can pull
 * a provider out manually at 2am without disabling it permanently.
 */
class ProviderCircuitState extends Model
{
    protected $table = 'provider_circuit_state';

    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half_open';

    protected $fillable = [
        'provider_id', 'state', 'failure_count', 'opened_at', 'next_probe_at', 'forced_open',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'next_probe_at' => 'datetime',
            'forced_open' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function isOpen(): bool
    {
        if ($this->forced_open) {
            return true;
        }

        if ($this->state !== self::OPEN) {
            return false;
        }

        // An open circuit that has reached its probe time is not open any
        // more — it is ready to be tried once. Treating it as open forever
        // would mean a recovered provider never came back on its own.
        return $this->next_probe_at === null || $this->next_probe_at->isFuture();
    }

    public function stateLabel(): string
    {
        return match (true) {
            $this->forced_open => 'Forced open by an administrator',
            $this->state === self::OPEN => 'Open — routing around this provider',
            $this->state === self::HALF_OPEN => 'Testing whether it has recovered',
            default => 'Closed — normal',
        };
    }
}
