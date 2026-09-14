<x-layouts.app :title="__('Two-factor authentication')">
    <div class="flex flex-col gap-5" style="max-width: 34rem;">
        <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
            {{ __('Two-factor authentication') }}
        </h1>

        @if ($required && ! $enabled)
            <x-ui.alert variant="warning">
                {{ __('Your administrator has made a second factor mandatory. Set it up here to carry on.') }}
            </x-ui.alert>
        @endif

        @if ($enabled)
            <x-ui.alert variant="success">
                {{ __('Two-factor authentication is on. You have :count recovery code(s) left.', ['count' => $remaining]) }}
            </x-ui.alert>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">
                    {{ __('Recovery codes') }}
                </h2>
                <p class="mb-4 text-sm text-text-muted">
                    {{ __('Generating a new set immediately invalidates the old one.') }}
                </p>
                <form method="POST" action="{{ route('mfa.recovery.regenerate') }}">
                    @csrf
                    <x-ui.button variant="secondary">{{ __('Generate new recovery codes') }}</x-ui.button>
                </form>
            </div>

            @unless ($required)
                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">
                        {{ __('Turn it off') }}
                    </h2>
                    <p class="mb-4 text-sm text-text-muted">
                        {{ __('Your password is required, because the risk this protects against is somebody already holding your session.') }}
                    </p>
                    <form method="POST" action="{{ route('mfa.disable') }}" class="flex flex-col gap-3">
                        @csrf
                        <x-ui.input name="password" :label="__('Your password')" type="password" required
                                    autocomplete="current-password" />
                        <div><x-ui.button variant="secondary">{{ __('Turn off two-factor authentication') }}</x-ui.button></div>
                    </form>
                </div>
            @endunless
        @else
            <div class="rounded-lg border border-border bg-surface p-5">
                <ol class="flex flex-col gap-4" style="padding-left: 1.25rem;">
                    <li>
                        <p class="text-text">{{ __('Open your authenticator app and add an account.') }}</p>
                        <p class="text-sm text-text-muted">
                            {{ __('Any TOTP app works — Google Authenticator, 1Password, Aegis, Bitwarden.') }}
                        </p>
                    </li>
                    <li>
                        <p class="mb-2 text-text">{{ __('Enter this key:') }}</p>
                        {{-- The key as TEXT rather than only a QR image: a
                             desktop password manager takes typed keys, and a
                             QR code on the same screen as the manager cannot
                             be scanned. --}}
                        <code class="block break-anywhere rounded-md border border-border p-3 text-sm"
                              style="background: var(--color-surface-alt);">{{ $secret }}</code>
                    </li>
                    <li>
                        <p class="mb-2 text-text">{{ __('Type the six digits it shows.') }}</p>
                        <form method="POST" action="{{ route('mfa.confirm') }}" class="flex flex-col gap-3">
                            @csrf
                            <x-ui.input name="code" :label="__('Authentication code')" required
                                        autocomplete="one-time-code" inputmode="numeric" />
                            <div><x-ui.button>{{ __('Turn on two-factor authentication') }}</x-ui.button></div>
                        </form>
                    </li>
                </ol>
            </div>
        @endif
    </div>
</x-layouts.app>
