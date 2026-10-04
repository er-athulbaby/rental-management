<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Units') }}</flux:heading>
        @can('create', \App\Models\Unit::class)
            <flux:button variant="primary" :href="route('units.create')" wire:navigate>{{ __('New unit') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
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
    </div>

    <div class="overflow-x-auto">
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
                            <flux:badge size="sm">{{ $unit->status()->label() }}</flux:badge>
                            @if ($next = $unit->nextTenantFrom())
                                <span class="text-xs text-zinc-500">{{ __('next tenant from :date', ['date' => $next->format('d/m/Y')]) }}</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
