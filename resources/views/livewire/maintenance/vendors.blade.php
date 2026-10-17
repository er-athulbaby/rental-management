<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Vendors') }}</flux:heading>
        @can('create', \App\Models\Vendor::class)
            <flux:button variant="primary" :href="route('maintenance.vendors.create')" wire:navigate>{{ __('New vendor') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="trade" class="sm:max-w-48">
            <option value="">{{ __('All trades') }}</option>
            @foreach ($trades as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="type" class="sm:max-w-44">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="active" class="sm:max-w-40">
            <option value="1">{{ __('Active') }}</option>
            <option value="0">{{ __('Inactive') }}</option>
            <option value="">{{ __('All') }}</option>
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$vendors">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Trades') }}</flux:table.column>
                <flux:table.column>{{ __('Mobile') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($vendors as $vendor)
                    <flux:table.row :key="$vendor->id">
                        <flux:table.cell><flux:link :href="route('maintenance.vendors.edit', $vendor)" wire:navigate>{{ $vendor->name }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $vendor->type->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $vendor->tradeLabels() }}</flux:table.cell>
                        <flux:table.cell>{{ $vendor->mobile }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$vendor->active ? 'green' : 'zinc'">{{ $vendor->active ? __('Active') : __('Inactive') }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No vendors found.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
