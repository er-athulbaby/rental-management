@php
    $filtered = $buildingId || $type !== '' || $search !== '' || ! empty($more);
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Units')">
        <x-slot:actions>
            @can('create', \App\Models\Unit::class)
                <flux:button variant="primary" icon="plus" :href="route('units.create')" wire:navigate>{{ __('New unit') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.stats :cols="3">
        <x-ui.stat :label="__('Occupied')" :value="$stats['occupied']" />
        <x-ui.stat :label="__('Vacant')" :value="$stats['vacant']" />
        <x-ui.stat :label="__('Blocked')" :value="$stats['blocked']" />
    </x-ui.stats>

    <x-ui.filters :clear="$filtered ? route('units.index') : null">
        <flux:select wire:model.live="buildingId" class="sm:max-w-64">
            <option value="">{{ __('All buildings') }}</option>
            @foreach ($buildings as $building)
                <option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="type" class="sm:max-w-56">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $t)<option value="{{ $t->code }}">{{ $t->name }}</option>@endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Unit code')" icon="magnifying-glass" class="sm:max-w-40" />
    </x-ui.filters>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />

    <x-ui.table>
        <flux:table :paginate="$units">
            <flux:table.columns>
                <flux:table.column>{{ __('Building') }}</flux:table.column>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column align="end">{{ __('List rent (BHD)') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($units as $unit)
                    <flux:table.row :key="$unit->id">
                        <flux:table.cell>{{ $unit->building->code }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('units.edit', $unit)" wire:navigate>{{ $unit->code }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $unit->unitType?->name ?? $unit->type }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $unit->list_rent }}</flux:table.cell>
                        <flux:table.cell>
                            <x-status-badge :status="$unit->status()" />
                            @if ($next = $unit->nextTenantFrom())
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('next tenant from :date', ['date' => $next->format('d/m/Y')]) }}</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($units->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
