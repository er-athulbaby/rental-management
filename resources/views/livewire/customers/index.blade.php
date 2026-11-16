@php
    $filtered = $search !== '' || $this->building || ! empty($more);
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Tenants')">
        <x-slot:actions>
            @can('create', \App\Models\Customer::class)
                <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>{{ __('New tenant') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters :clear="$filtered ? route('customers.index') : null">
        <x-building-filter :buildings="$this->buildings" label="" />
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name, mobile, ID number or unit')" icon="magnifying-glass" class="sm:max-w-xs" />
    </x-ui.filters>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />

    @if ($hint)
        <flux:callout icon="information-circle" :heading="$hint" />
    @endif

    <x-ui.table>
        <flux:table :paginate="$customers">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('ID') }}</flux:table.column>
                <flux:table.column>{{ __('Mobile') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($customers as $customer)
                    <flux:table.row :key="$customer->id">
                        <flux:table.cell><flux:link :href="route('customers.edit', $customer)" wire:navigate>{{ $customer->name_en }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $customer->id_type->label() }} {{ $customer->id_number }}</flux:table.cell>
                        <flux:table.cell>{{ $customer->mobile }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($customers->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
