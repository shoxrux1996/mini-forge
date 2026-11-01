<x-filament-panels::page>
    <div wire:init="load" @if ($follow) wire:poll.5s="load" @endif>
        <div style="display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: 1rem">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="service" aria-label="Service">
                    @foreach ($this->services() as $role)
                        <option value="{{ $role->value }}">{{ $role->getLabel() }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="lines" aria-label="Lines">
                    @foreach (\App\Actions\Sites\ReadSiteLogs::LINE_CHOICES as $choice)
                        <option value="{{ $choice }}">Last {{ $choice }} lines</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper style="flex: 1; min-width: 12rem">
                <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter these lines" aria-label="Filter" />
            </x-filament::input.wrapper>

            <label style="display: flex; align-items: center; gap: .5rem; font-size: .875rem">
                <x-filament::input.checkbox wire:model.live="follow" />
                Refresh every 5s
            </label>

            <x-filament::button color="gray" wire:click="load" wire:loading.attr="disabled">Refresh</x-filament::button>
        </div>

        @if ($error !== null)
            <p style="color: var(--danger-600); font-size: .875rem">{{ $error }}</p>
        @elseif ($fetchedAt === null)
            <p style="color: var(--gray-500); font-size: .875rem">Loading from the server…</p>
        @else
            <p style="color: var(--gray-500); font-size: .75rem; margin-bottom: .5rem">Fetched at {{ $fetchedAt }}. Secret values are replaced with [redacted].</p>
            <pre style="margin: 0; max-height: 70vh; overflow: auto; white-space: pre-wrap; word-break: break-all; padding: .75rem; border-radius: .5rem; background: var(--gray-950); color: var(--gray-100); font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .75rem; line-height: 1.5">{{ $this->visibleOutput() ?: 'No log lines.' }}</pre>
        @endif
    </div>
</x-filament-panels::page>
