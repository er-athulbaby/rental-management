@php
    $filtered = $search !== '';
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Buildings')">
        <x-slot:actions>
            @can('create', \App\Models\Building::class)
                <flux:button variant="primary" icon="plus" :href="route('buildings.create')" wire:navigate>{{ __('New building') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters :clear="$filtered ? route('buildings.index') : null">
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search name or code')" icon="magnifying-glass" class="sm:max-w-xs" />
    </x-ui.filters>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />

    <x-ui.table>
        <flux:table :paginate="$buildings">
            <flux:table.columns>
                <flux:table.column>{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Location') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($buildings as $building)
                    <flux:table.row :key="$building->id">
                        <flux:table.cell>{{ $building->code }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('buildings.edit', $building)" wire:navigate>{{ $building->name }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $building->location }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($buildings->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
