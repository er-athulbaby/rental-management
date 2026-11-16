@php
    $filtered = $status !== '' || $expiring || $search !== '' || $this->building || ! empty($more);
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Agreements')">
        <x-slot:actions>
            @can('create', \App\Models\Agreement::class)
                <flux:button variant="primary" icon="plus" :href="route('agreements.create')" wire:navigate>{{ __('New agreement') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.stats :cols="3">
        <x-ui.stat :label="__('Active')" :value="$stats['active']" :href="route('agreements.index', ['status' => 'active'])" />
        <x-ui.stat :label="__('Pending approval')" :value="$stats['pending']" :tone="$stats['pending'] > 0 ? 'warning' : null" :href="route('agreements.index', ['status' => 'pending_approval'])" />
        <x-ui.stat :label="__('Expiring in 60 days')" :value="$stats['expiring']" :tone="$stats['expiring'] > 0 ? 'warning' : null" :href="route('agreements.index', ['expiring' => 60])" />
    </x-ui.stats>

    <x-ui.filters :clear="$filtered ? route('agreements.index') : null">
        <flux:select wire:model.live="status" class="sm:max-w-48">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="expiring" class="sm:max-w-48">
            <option value="">{{ __('Any end date') }}</option>
            <option value="30">{{ __('Expiring in 30 days') }}</option>
            <option value="60">{{ __('Expiring in 60 days') }}</option>
            <option value="90">{{ __('Expiring in 90 days') }}</option>
        </flux:select>
        <x-building-filter :buildings="$this->buildings" label="" />
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number, tenant, mobile or unit')" icon="magnifying-glass" class="sm:max-w-xs" />
    </x-ui.filters>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />

    <x-ui.table>
        <flux:table :paginate="$agreements">
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Units') }}</flux:table.column>
                <flux:table.column>{{ __('Dates') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($agreements as $agreement)
                    <flux:table.row :key="$agreement->id">
                        <flux:table.cell><flux:link :href="route('agreements.show', $agreement)" wire:navigate>{{ $agreement->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $agreement->customer->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $agreement->agreementUnits->groupBy(fn ($au) => $au->unit->building->code)->map(fn ($units, $building) => $building.' · '.$units->pluck('unit.code')->unique()->implode(', '))->implode('; ') ?: '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $agreement->start_date->format('d/m/Y') }} – {{ $agreement->end_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$agreement->status" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($agreements->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
