@php
    // Ledger reports show their key totals as figures, read from the total rows the report already built.
    $isLedger = request()->routeIs('ledger.*');
    $totalRows = $isLedger ? array_values(array_filter($rows, fn ($r) => ($r['_kind'] ?? null) === 'total')) : [];
    $figures = [];
    if ($isLedger && count($totalRows) === 1) {
        foreach ($numeric as $k) {
            if (filled($totalRows[0][$k] ?? null)) {
                $figures[] = [$columns[$k], $totalRows[0][$k]];
            }
        }
    } elseif ($isLedger) {
        foreach ($totalRows as $tr) {
            $value = collect($numeric)->reverse()->map(fn ($k) => $tr[$k] ?? '')->first(fn ($v) => filled($v));
            $label = collect(array_keys($columns))->diff($numeric)->map(fn ($k) => $tr[$k] ?? '')->first(fn ($v) => filled($v));
            if (filled($value) && filled($label)) {
                $figures[] = [$label, $value];
            }
        }
    }
    $figures = array_slice($figures, 0, 5);
@endphp
<div class="space-y-4">
    <x-ui.page-header :title="$title">
        <x-slot:actions>
            <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export to Excel') }}</flux:button>
            <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters>
        @if ($buildingFilter)
            <x-building-filter :buildings="$buildings" />
        @endif
        @if ($dateMode === 'range')
            <x-date-input wire:model.live="from" :label="__('From')" />
            <x-date-input wire:model.live="to" :label="__('To')" />
        @elseif ($dateMode === 'single')
            <x-date-input wire:model.live="to" :label="__('As at')" />
        @endif
        @foreach ($options as $property => $choices)
            <flux:select wire:model.live="{{ $property }}" :label="str($property)->headline()">
                @foreach ($choices as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        @endforeach
    </x-ui.filters>

    @if ($figures !== [])
        <x-ui.stats :cols="count($figures) >= 4 ? 4 : max(2, count($figures))">
            @foreach ($figures as [$figLabel, $figValue])
                <x-ui.stat :label="$figLabel" :value="$figValue" />
            @endforeach
        </x-ui.stats>
    @endif

    @if ($charts !== [])
        <div @class(['grid gap-4', 'lg:grid-cols-2' => count($charts) > 1])>
            @foreach ($charts as $chart)<x-report-chart :chart="$chart" />@endforeach
        </div>
    @endif

    @if ($capped)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Too many lines to show: narrow the filters or download Excel.')" />
    @endif

    <x-ui.table>
        <flux:table>
            <flux:table.columns>
                @foreach ($columns as $key => $label)
                    <flux:table.column :align="in_array($key, $numeric, true) ? 'end' : 'start'">{{ $label }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $i => $row)
                    @php
                        $isHeading = ($row['_kind'] ?? null) === 'total' && collect($numeric)->every(fn ($k) => blank($row[$k] ?? null));
                    @endphp
                    <flux:table.row :key="'r-'.$i" :class="empty($row['_kind']) ? '' : ($isHeading ? 'bg-zinc-100 font-semibold dark:bg-zinc-800' : 'bg-zinc-50 font-semibold dark:bg-zinc-800/50')">
                        @foreach (array_keys($columns) as $j => $key)
                            <flux:table.cell :class="(in_array($key, $numeric, true) ? 'text-end tabular-nums' : '').(($row['_muted'] ?? null) === $key ? ' text-zinc-500 dark:text-zinc-400' : '').((! empty($row['_kind']) && ! $isHeading) ? ' border-t-2 border-zinc-300 dark:border-zinc-600' : '')">
                                @if ($j === 0 && ! empty($row['_url']))
                                    <flux:link :href="$row['_url']" wire:navigate>{{ $row[$key] }}</flux:link>
                                @else
                                    {{ $row[$key] ?? '' }}
                                @endif
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell :colspan="count($columns)" class="py-8 text-center text-zinc-500">{{ __('Nothing to show.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </x-ui.table>
</div>
