<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl">{{ $title }}</flux:heading>
        <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
    </div>

    <div class="grid gap-3 sm:grid-cols-4">
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
        <div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
    </div>

    @if ($charts !== [])
        <div @class(['grid gap-4', 'lg:grid-cols-2' => count($charts) > 1])>
            @foreach ($charts as $chart)<x-report-chart :chart="$chart" />@endforeach
        </div>
    @endif

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                @foreach ($columns as $key => $label)
                    <flux:table.column :align="in_array($key, $numeric, true) ? 'end' : 'start'">{{ $label }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $i => $row)
                    <flux:table.row :key="'r-'.$i" :class="empty($row['_kind']) ? '' : 'font-semibold'">
                        @foreach (array_keys($columns) as $j => $key)
                            <flux:table.cell :class="(in_array($key, $numeric, true) ? 'text-end tabular-nums' : '').(($row['_muted'] ?? null) === $key ? ' text-zinc-400 dark:text-zinc-500' : '')">
                                @if ($j === 0 && ! empty($row['_url']))
                                    <flux:link :href="$row['_url']" wire:navigate>{{ $row[$key] }}</flux:link>
                                @else
                                    {{ $row[$key] ?? '' }}
                                @endif
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell :colspan="count($columns)">{{ __('Nothing to show.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
