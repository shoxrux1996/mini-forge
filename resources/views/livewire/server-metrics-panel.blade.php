@php
    $tile = fn (string $label, ?int $used, ?int $total, string $unit) => [
        'label' => $label,
        'percent' => $used !== null && $total ? (int) round(100 * $used / $total) : null,
        'detail' => $used !== null && $total ? "{$used} of {$total} {$unit}" : null,
    ];
    $tiles = $metrics === null ? [] : [
        ['label' => 'CPU', 'percent' => $metrics['cpu_percent'], 'detail' => $metrics['load'] ? 'Load '.$metrics['load'] : null],
        $tile('Memory', $metrics['memory_used_mb'], $metrics['memory_total_mb'], 'MB'),
        $tile('Disk', $metrics['disk_used_gb'], $metrics['disk_total_gb'], 'GB'),
        $tile('Swap', $metrics['swap_used_mb'], $metrics['swap_total_mb'], 'MB'),
    ];
@endphp

{{-- Filament's CSS is precompiled, so this view styles itself with Filament's colour variables. --}}
<div wire:init="load" wire:poll.30s="load" class="mf-metrics">
    <style>
        .mf-metrics .mf-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 1.5rem; }
        .mf-metrics .mf-label { font-size: .875rem; color: var(--gray-500); }
        .mf-metrics .mf-value { font-size: 1.5rem; font-weight: 600; line-height: 2rem; font-variant-numeric: tabular-nums; }
        .mf-metrics .mf-note { margin-left: .5rem; font-size: .75rem; font-weight: 500; }
        .mf-metrics .mf-note-warning { color: var(--warning-600); }
        .mf-metrics .mf-note-danger { color: var(--danger-600); }
        .mf-metrics .mf-meter { height: .375rem; margin: .375rem 0; border-radius: 9999px; overflow: hidden;
            background: color-mix(in oklab, var(--gray-500) 20%, transparent); }
        .mf-metrics .mf-meter > span { display: block; height: 100%; border-radius: 9999px; background: var(--gray-500); }
        .mf-metrics .mf-meter > .mf-warning { background: var(--warning-500); }
        .mf-metrics .mf-meter > .mf-danger { background: var(--danger-500); }
        .mf-metrics .mf-detail { font-size: .75rem; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .mf-metrics table { width: 100%; margin-top: 1.5rem; border-collapse: collapse; font-size: .875rem; }
        .mf-metrics th { padding: .375rem .75rem .375rem 0; text-align: left; font-weight: 500; color: var(--gray-500); }
        .mf-metrics td { padding: .375rem .75rem .375rem 0; font-variant-numeric: tabular-nums;
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 15%, transparent); }
        .mf-metrics .mf-scroll { overflow-x: auto; }
    </style>

    <x-filament::section>
        <x-slot name="heading">Live usage</x-slot>
        <x-slot name="description">
            @if ($error !== null)
                Could not read metrics at {{ $fetchedAt }}.
            @elseif ($fetchedAt !== null)
                Live from the server at {{ $fetchedAt }}. Not stored, so there is no history.
            @else
                Loading from the server…
            @endif
        </x-slot>

        @if ($error !== null)
            <p class="mf-note-danger" style="font-size: .875rem">{{ $error }}</p>
        @elseif ($metrics !== null)
            <div class="mf-tiles">
                @foreach ($tiles as $item)
                    @php
                        $percent = $item['percent'];
                        $level = $percent === null ? null : ($percent >= 90 ? 'danger' : ($percent >= 75 ? 'warning' : null));
                    @endphp
                    <div>
                        <div class="mf-label">{{ $item['label'] }}</div>
                        <div class="mf-value">
                            {{ $percent === null ? '—' : $percent.'%' }}
                            @if ($level)
                                <span class="mf-note mf-note-{{ $level }}">{{ $level === 'danger' ? 'Critical' : 'High' }}</span>
                            @endif
                        </div>
                        <div class="mf-meter"><span @class(['mf-'.$level => $level]) style="width: {{ min(100, $percent ?? 0) }}%"></span></div>
                        <div class="mf-detail">{{ $item['detail'] ?? 'None configured' }}</div>
                    </div>
                @endforeach
            </div>

            @if ($metrics['containers'] !== [])
                <div class="mf-scroll">
                    <table>
                        <thead><tr><th>Container</th><th>CPU</th><th>Memory</th><th>Of limit</th><th>Processes</th></tr></thead>
                        <tbody>
                            @foreach ($metrics['containers'] as $container)
                                <tr wire:key="container-{{ $container['name'] }}">
                                    <td>{{ $container['name'] }}</td>
                                    <td>{{ $container['cpu'] }}</td>
                                    <td>{{ $container['memory'] }}</td>
                                    <td>{{ $container['memory_percent'] }}</td>
                                    <td>{{ $container['pids'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </x-filament::section>
</div>
