<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usage per key, per window (Rule 7).
 *
 * This exists so a provider's rate limits can be RESPECTED. It is deliberately
 * not wired to any automatic key-cycling on quota errors: rotating to a second
 * key when the first is exhausted has no purpose other than evading the limit
 * the provider set, and Rule 7 forbids exactly that.
 */
class CredentialUsageCounter extends Model
{
    protected $fillable = ['credential_id', 'window_start', 'request_count', 'token_count'];

    protected function casts(): array
    {
        return ['window_start' => 'datetime'];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiProviderCredential::class, 'credential_id');
    }

    /** Record one call against the hour it happened in. */
    public static function record(int $credentialId, int $tokens = 0): void
    {
        $window = now()->startOfHour();

        static::query()->upsert(
            [[
                'credential_id' => $credentialId,
                'window_start' => $window,
                'request_count' => 1,
                'token_count' => $tokens,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['credential_id', 'window_start'],
            [],
        );

        static::where('credential_id', $credentialId)
            ->where('window_start', $window)
            ->increment('request_count');

        if ($tokens > 0) {
            static::where('credential_id', $credentialId)
                ->where('window_start', $window)
                ->increment('token_count', $tokens);
        }
    }
}
