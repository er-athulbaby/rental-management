@php
    $filtered = $status !== '' || $search !== '';
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Owner contracts')">
        <x-slot:actions>
            @can('create', \App\Models\OwnerContract::class)
                <flux:button variant="primary" icon="plus" :href="route('owner-contracts.create')" wire:navigate>{{ __('New contract') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters :clear="$filtered ? route('owner-contracts.index') : null">
        <flux:select wire:model.live="status" class="sm:max-w-48">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number or owner')" icon="magnifying-glass" class="sm:max-w-xs" />
    </x-ui.filters>

    <x-ui.table>
        <flux:table :paginate="$contracts">
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Building') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Dates') }}</flux:table.column>
                <flux:table.column>{{ __('Units') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($contracts as $contract)
                    <flux:table.row :key="$contract->id">
                        <flux:table.cell><flux:link :href="route('owner-contracts.show', $contract)" wire:navigate>{{ $contract->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $contract->owner->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $contract->building->name }}</flux:table.cell>
                        <flux:table.cell>{{ str($contract->type->value)->headline() }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $contract->start_date->format('d/m/Y') }} – {{ $contract->end_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $contract->units_count }}</flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$contract->status" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($contracts->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
