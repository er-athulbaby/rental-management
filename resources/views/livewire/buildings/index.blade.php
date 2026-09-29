<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Buildings') }}</flux:heading>
        @can('create', \App\Models\Building::class)
            <flux:button variant="primary" :href="route('buildings.create')" wire:navigate>{{ __('New building') }}</flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search name or code')" icon="magnifying-glass" class="sm:max-w-xs" />

    <div class="overflow-x-auto">
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
    </div>
</section>
