<x-filament-panels::page>
    <x-filament::section compact heading="What this screen is for">
        <p class="text-sm text-text-muted">
            These are the things that would normally need a command line. Each one runs a single named
            task — nothing here takes a command from you and runs it.
            Anything needing Composer or Node cannot be done from a browser; the release package ships
            those already built.
        </p>
    </x-filament::section>

    @if ($this->installState['open'])
        {{-- The most urgent thing this screen can say. --}}
        <x-filament::section compact heading="The installer is still open">
            <p class="text-sm" style="color: var(--color-danger);">
                Anybody who finds /install can rewrite your configuration and create an administrator account.
                It closes itself once an administrator exists, or you can set <code>AZIV_INSTALLER=off</code> in .env.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Tasks">
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($this->tasks() as $key => $task)
                <div class="flex flex-col gap-2 rounded-lg border border-border p-4">
                    <p class="font-medium text-heading">{{ $task['label'] }}</p>
                    <p class="text-sm text-text-muted">{{ $task['description'] }}</p>
                    <p class="text-xs text-text-muted"><code>{{ $task['equivalent'] }}</code></p>

                    @if ($this->canRun())
                        <div>
                            <x-filament::button wire:click="runTask('{{ $key }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="runTask('{{ $key }}')">
                                Run
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($output !== '')
            <div class="mt-4">
                <p class="mb-2 text-sm font-medium text-heading">
                    {{ $lastOk ? 'Result' : 'It did not work' }} — {{ $lastTask }}
                </p>
                {{-- Scrolls rather than widening the page: command output has
                     long lines and the six-viewport gate is not negotiable. --}}
                <pre class="overflow-x-auto rounded-md border border-border p-3 text-xs"
                     style="background: var(--color-surface-alt); color: var(--color-text); white-space: pre-wrap; word-break: break-word;">{{ $output }}</pre>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Health report">
        <p class="mb-4 text-sm text-text-muted">
            A plain-text summary of everything the System Health screen knows, written to be forwarded to
            your hosting provider. It contains no passwords, API keys or other credentials.
        </p>
        <x-filament::button wire:click="downloadReport">Download report</x-filament::button>
    </x-filament::section>

    <x-filament::section heading="Recent log" collapsible>
        <pre class="overflow-x-auto rounded-md border border-border p-3 text-xs"
             style="background: var(--color-surface-alt); color: var(--color-text); max-height: 24rem; white-space: pre-wrap; word-break: break-word;">{{ $this->log }}</pre>
    </x-filament::section>
</x-filament-panels::page>
