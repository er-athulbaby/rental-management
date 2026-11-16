@php
    $filtered = $search !== '';
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Owners')">
        <x-slot:actions>
            @can('create', \App\Models\Owner::class)
                <flux:button variant="primary" icon="plus" :href="route('owners.create')" wire:navigate>{{ __('New owner') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters :clear="$filtered ? route('owners.index') : null">
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name or exact ID number')" icon="magnifying-glass" class="sm:max-w-xs" />
    </x-ui.filters>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />

    <x-ui.table>
        <flux:table :paginate="$owners">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('ID') }}</flux:table.column>
                <flux:table.column>{{ __('Phone') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($owners as $owner)
                    <flux:table.row :key="$owner->id">
                        <flux:table.cell><flux:link :href="route('owners.edit', $owner)" wire:navigate>{{ $owner->name_en }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $owner->id_type->label() }} {{ $owner->id_number }}</flux:table.cell>
                        <flux:table.cell>{{ $owner->phone }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($owners->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
