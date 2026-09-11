<x-filament-panels::page>

    {{-- What is being tested ------------------------------------------- --}}
    <x-filament::section
        heading="What to test"
        description="A short, bounded request — never more than a few words of output, so a test cannot become expensive.">

        <div class="flex flex-col gap-4">
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Provider</span>
                <select wire:model.live="providerId" class="fi-input fi-select-input w-full">
                    <option value="">Choose a provider</option>
                    @foreach ($this->providers() as $provider)
                        <option value="{{ $provider->id }}">
                            {{ $provider->name }} — {{ $provider->stateLabel() }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Model</span>
                <select wire:model="modelId" class="fi-input fi-select-input w-full">
                    <option value="">Choose a model</option>
                    @foreach ($this->models() as $model)
                        <option value="{{ $model->id }}">
                            {{ $model->display_name }}{{ $model->is_enabled ? '' : ' (switched off)' }}
                        </option>
                    @endforeach
                </select>
                @if ($this->models()->isEmpty() && $providerId)
                    <span class="text-xs text-text-muted">
                        No models yet. Use “Refresh models”, or add them by hand in Admin → AI → Models.
                    </span>
                @endif
            </label>

            <div class="flex flex-col gap-1 rounded-lg border border-divider p-3">
                <span class="text-xs text-text-muted">Key that will be used</span>
                {{-- §25: the last four characters, read from the stored hint.
                     Nothing is decrypted to render this. --}}
                <span class="font-mono text-sm text-heading">
                    {{ $this->credentialHint() ?? 'No key has been added for this provider' }}
                </span>
            </div>
        </div>

        <x-slot name="footerActions">
            <div class="flex flex-wrap gap-2">
                <x-filament::button wire:click="sendTestRequest" wire:loading.attr="disabled" :disabled="! $modelId">
                    Send test request
                </x-filament::button>

                <x-filament::button color="gray" wire:click="testCredential" wire:loading.attr="disabled" :disabled="! $providerId">
                    Test credential
                </x-filament::button>

                @can('models.sync')
                    <x-filament::button color="gray" wire:click="refreshModels" wire:loading.attr="disabled" :disabled="! $providerId">
                        Refresh models
                    </x-filament::button>
                @endcan

                @can('providers.manage')
                    <x-filament::button color="gray" wire:click="resetCircuit" :disabled="! $providerId">
                        Reset circuit breaker
                    </x-filament::button>
                @endcan
            </div>
        </x-slot>
    </x-filament::section>

    {{-- Result ---------------------------------------------------------- --}}
    @if ($result)
        <x-filament::section heading="Result">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$result['success'] ? 'success' : 'danger'" size="lg">
                        {{ $result['success'] ? 'Working' : $result['error_label'] }}
                    </x-filament::badge>

                    @if ($result['http_status'] ?? null)
                        <x-filament::badge color="gray">HTTP {{ $result['http_status'] }}</x-filament::badge>
                    @endif

                    <x-filament::badge color="gray">{{ $result['latency_ms'] }}ms</x-filament::badge>

                    @if (($result['retryable'] ?? false))
                        <x-filament::badge color="warning">Worth retrying</x-filament::badge>
                    @endif
                </div>

                @if (! $result['success'])
                    {{-- The remedy in plain language. Never the provider's own
                         wording, which on several APIs echoes the request back. --}}
                    <p class="text-sm text-text">{{ $result['detail'] }}</p>
                    <p class="text-xs text-text-muted">Reference: {{ $result['error_class'] }}</p>
                @else
                    @if (($result['content'] ?? null) !== null)
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-text-muted">What it replied</span>
                            <code class="aziv-test-output break-anywhere">{{ $result['content'] ?: '(empty)' }}</code>
                        </div>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            'Input tokens' => $result['input_tokens'] ?? null,
                            'Output tokens' => $result['output_tokens'] ?? null,
                            'Finish reason' => $result['finish_reason'] ?? null,
                            'Estimated cost' => $result['estimated_cost'] ?? null,
                            'Models visible' => $result['models_visible'] ?? null,
                        ] as $label => $value)
                            @if ($value !== null)
                                <div class="rounded-lg border border-divider p-3">
                                    <span class="block text-xs text-text-muted">{{ $label }}</span>
                                    <span class="block font-medium text-heading">{{ $value }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    @if (($result['estimated_cost'] ?? null) === null && ($result['kind'] ?? '') === 'request')
                        <p class="text-xs text-text-muted">
                            No price is recorded for this model yet, so the cost could not be estimated.
                            Add one under the model's Pricing section.
                        </p>
                    @endif
                @endif
            </div>
        </x-filament::section>
    @endif

</x-filament-panels::page>
