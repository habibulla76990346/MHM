<?php

namespace App\Domains\Identity\Services;

use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Optional multi-factor authentication for privileged administrators (§23).
 *
 * TOTP, and nothing that needs a third party. Six digits from an app the
 * administrator already has, checked with arithmetic on this server: no SMS
 * account, no per-message cost, and no channel that can be taken over by
 * asking a phone company nicely. An emailed code would protect an account
 * whose password reset goes to the same inbox, which protects nothing.
 *
 * IT IS OPTIONAL BY DEFAULT AND ENFORCEABLE BY SETTING, which is the blueprint's
 * word. Forcing it on a single-owner platform on day one is how somebody locks
 * themselves out of their own product before they have a recovery code
 * anywhere — so the owner turns it on when they are ready, and the enforcement
 * covers everyone who can reach the Admin Panel rather than everyone at all.
 *
 * THE WINDOW IS ONE STEP EITHER SIDE. Clocks drift; a phone thirty seconds
 * behind is ordinary, and a customer who cannot sign in because of it will
 * turn the feature off. One step is ±30 seconds, which costs an attacker
 * nothing they did not already have.
 */
class MfaService
{
    /** ±1 period. Wider is a guessing window; narrower is a support ticket. */
    private const WINDOW = 1;

    private const RECOVERY_CODES = 8;

    public function __construct(
        private readonly Google2FA $totp,
        private readonly ActivityLogger $log,
    ) {}

    /** Is MFA required of anybody who can reach the Admin Panel? */
    public function isEnforced(): bool
    {
        return (bool) settings('auth.mfa_required_for_admins');
    }

    /**
     * Must THIS person have it?
     *
     * Scoped to people with administrative access rather than to everybody: a
     * customer forced through TOTP to read their own invoices is a customer
     * who leaves, and their account cannot change a price or read a
     * credential.
     */
    public function isRequiredFor(User $user): bool
    {
        return $this->isEnforced() && $user->hasAnyRole(PermissionRegistry::adminRoles());
    }

    public function isEnabledFor(User $user): bool
    {
        return $user->mfa_confirmed_at !== null && (string) $user->mfa_secret !== '';
    }

    /**
     * Start enrolment: a secret, not yet confirmed.
     *
     * UNCONFIRMED ON PURPOSE. Somebody who scans the code and closes the tab
     * has a secret and no working app, and treating that as enabled would lock
     * them out of their own platform over an unfinished form.
     */
    public function beginEnrolment(User $user): string
    {
        $secret = $this->totp->generateSecretKey();

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /** What the authenticator app scans. */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->totp->getQRCodeUrl(
            // The issuer and the label are what the administrator sees in a
            // list of accounts, so they carry the platform's own name.
            (string) settings('branding.app_name'),
            $user->email,
            $secret,
        );
    }

    /**
     * Finish enrolment, and hand back the recovery codes ONCE.
     *
     * Returned rather than stored in readable form: what goes into the
     * database is a hash of each, because nothing ever needs to read one back
     * — only to check whether one somebody typed matches.
     *
     * @return array<int, string>|null null when the code was wrong
     */
    public function confirmEnrolment(User $user, string $code): ?array
    {
        $secret = (string) $user->mfa_secret;

        if ($secret === '' || ! $this->verifyTotp($secret, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => $this->hashAll($codes),
        ])->save();

        $this->log->log('security.mfa.enabled', $user);

        return $codes;
    }

    /**
     * Check a code at sign-in.
     *
     * A RECOVERY CODE IS CONSUMED, not marked used. Leaving a used code in the
     * list with a flag beside it is one refactor away from being accepted
     * again; deleting it cannot be.
     */
    public function verify(User $user, string $code): bool
    {
        $code = trim($code);

        if ($code === '' || ! $this->isEnabledFor($user)) {
            return false;
        }

        if ($this->verifyTotp((string) $user->mfa_secret, $code)) {
            $user->forceFill(['mfa_last_used_at' => now()])->save();

            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * Turn it off.
     *
     * Audited, because switching off the second factor on an account that can
     * read every credential in the platform is a security event whoever
     * reviews the log needs to see.
     */
    public function disable(User $user, ?User $actor = null): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        $this->log->log('security.mfa.disabled', $user, null, [
            'by' => $actor?->email ?? 'self',
        ]);
    }

    /** How many recovery codes are left, without revealing any of them. */
    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->storedCodes($user));
    }

    /** @return array<int, string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill(['mfa_recovery_codes' => $this->hashAll($codes)])->save();

        $this->log->log('security.mfa.recovery_codes_regenerated', $user);

        return $codes;
    }

    // -- internals ---------------------------------------------------------------

    private function verifyTotp(string $secret, string $code): bool
    {
        // Digits only. The library throws on anything else, and an exception
        // at a login form is a 500 where a "that code is wrong" belongs.
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        try {
            return (bool) $this->totp->verifyKey($secret, $code, self::WINDOW);
        } catch (\Throwable) {
            return false;
        }
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $stored = $this->storedCodes($user);
        $normalised = strtolower(str_replace(' ', '', $code));

        foreach ($stored as $index => $hash) {
            if (Hash::check($normalised, $hash)) {
                unset($stored[$index]);

                $user->forceFill([
                    'mfa_recovery_codes' => implode("\n", $stored),
                    'mfa_last_used_at' => now(),
                ])->save();

                $this->log->log('security.mfa.recovery_code_used', $user, null, [
                    'remaining' => count($stored),
                ]);

                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function storedCodes(User $user): array
    {
        $raw = (string) $user->mfa_recovery_codes;

        return $raw === '' ? [] : array_values(array_filter(explode("\n", $raw)));
    }

    /** @return array<int, string> */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /** @param array<int, string> $codes */
    private function hashAll(array $codes): string
    {
        return implode("\n", array_map(
            fn (string $code) => Hash::make(strtolower(str_replace(' ', '', $code))),
            $codes,
        ));
    }
}
