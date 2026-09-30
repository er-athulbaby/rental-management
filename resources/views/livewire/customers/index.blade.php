<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Customers') }}</flux:heading>
        @can('create', \App\Models\Customer::class)
            <flux:button variant="primary" :href="route('customers.create')" wire:navigate>{{ __('New customer') }}</flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name, exact ID or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />

    @if ($hint)
        <flux:callout icon="information-circle" :heading="$hint" />
    @endif

    <div class="overflow-x-auto">
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
    </div>
</section>
