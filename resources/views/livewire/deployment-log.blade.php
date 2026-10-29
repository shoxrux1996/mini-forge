{{-- Filament's CSS is precompiled, so this view styles itself with Filament's colour variables. --}}
<div @if ($running) wire:poll.2s @endif class="mf-log">
    <style>
        .mf-log ol { list-style: none; margin: 0; padding: 0; }
        .mf-log li + li { border-top: 1px solid color-mix(in oklab, var(--gray-500) 15%, transparent); }
        .mf-log summary { display: flex; align-items: center; gap: .75rem; padding: .55rem 0; cursor: pointer; font-size: .875rem; list-style: none; }
        .mf-log summary::-webkit-details-marker { display: none; }
        .mf-log summary::before { content: '›'; width: .75rem; color: var(--gray-400); transition: transform .15s; }
        .mf-log details[open] > summary::before { transform: rotate(90deg); }
        .mf-log details:not(:has(pre)) > summary { cursor: default; }
        .mf-log details:not(:has(pre)) > summary::before { visibility: hidden; }
        .mf-log .mf-position { width: 1.25rem; text-align: right; font-family: var(--mono-font-family, ui-monospace, monospace); font-size: .75rem; color: var(--gray-400); }
        .mf-log .mf-name { flex: 1; font-weight: 500; }
        .mf-log .mf-skipped .mf-name { font-weight: 400; color: var(--gray-400); }
        .mf-log .mf-time { font-size: .75rem; color: var(--gray-500); }
        .mf-log pre { margin: 0 0 .75rem 2rem; max-height: 24rem; overflow: auto; white-space: pre-wrap; word-break: break-all;
            padding: .75rem; border-radius: .5rem; background: var(--gray-950); color: var(--gray-100);
            font-family: var(--mono-font-family, ui-monospace, monospace); font-size: .75rem; line-height: 1.5; }
        .mf-log .mf-error { margin: 0 0 1rem; padding: .75rem; border-radius: .5rem; white-space: pre-wrap; word-break: break-all;
            background: color-mix(in oklab, var(--danger-500) 12%, transparent); color: var(--danger-600);
            font-family: var(--mono-font-family, ui-monospace, monospace); font-size: .75rem; }
        .dark .mf-log .mf-error { color: var(--danger-400); }
        .mf-log .mf-heading { display: flex; align-items: center; gap: .75rem; }
        .mf-log .mf-mono { font-family: var(--mono-font-family, ui-monospace, monospace); }
    </style>

    <x-filament::section>
        <x-slot name="heading">
            <span class="mf-heading">
                Steps
                <x-filament::badge :color="$deployment->status->getColor()">
                    {{ $deployment->status->getLabel() }}
                </x-filament::badge>
                @if ($deployment->durationInSeconds() !== null)
                    <span class="mf-time">{{ gmdate($deployment->durationInSeconds() >= 3600 ? 'H:i:s' : 'i:s', $deployment->durationInSeconds()) }}</span>
                @endif
            </span>
        </x-slot>

        <x-slot name="description">
            <span class="mf-mono">{{ $deployment->image ?? 'Image is named after the build' }}</span>
            @if ($deployment->commit_sha)
                · commit <span class="mf-mono">{{ substr($deployment->commit_sha, 0, 12) }}</span>
            @endif
        </x-slot>

        @if (filled($deployment->error))
            <pre class="mf-error">{{ $deployment->error }}</pre>
        @endif

        <ol>
            @foreach ($deployment->steps as $step)
                <li wire:key="step-{{ $step->id }}" @class(['mf-skipped' => $step->status === \App\Enums\RunStatus::Skipped])>
                    <details @if (in_array($step->status, [\App\Enums\RunStatus::Running, \App\Enums\RunStatus::Failed], true)) open @endif>
                        <summary>
                            <span class="mf-position">{{ $step->position }}</span>
                            <span class="mf-name">{{ $step->name }}</span>
                            @if ($step->started_at && $step->finished_at)
                                <span class="mf-time">{{ max(0, (int) $step->started_at->diffInSeconds($step->finished_at)) }}s</span>
                            @endif
                            <x-filament::badge :color="$step->status->getColor()" size="sm">
                                {{ $step->status->getLabel() }}
                            </x-filament::badge>
                        </summary>
                        @if (filled($step->output))
                            <pre>{{ $step->output }}</pre>
                        @endif
                    </details>
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</div>
