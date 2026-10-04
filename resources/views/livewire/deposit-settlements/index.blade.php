<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Deposit settlements') }}</flux:heading>
    <div class="flex flex-col gap-3 sm:flex-row">
    <flux:select wire:model.live="status" :label="__('Show')" class="sm:max-w-48">
        <option value="open">{{ __('Open') }}</option>
        <option value="all">{{ __('All') }}</option>
        @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
    </flux:select>
    <x-building-filter :buildings="$this->buildings" />
    </div>
    <div class="overflow-x-auto">
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>{{ __('Settlement') }}</flux:table.column>
                <flux:table.column>{{ __('Agreement') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $row)
                    <flux:table.row :key="$row->id">
                        <flux:table.cell><flux:link :href="route('deposit-settlements.show', $row)" wire:navigate>{{ $row->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $row->agreement->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $row->agreement->customer->name_en }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $row->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4" class="whitespace-normal text-zinc-500">{{ __('Nothing to settle. A settlement opens by itself when a tenant moves out or an agreement ends, to work out the deposit refund less any deductions.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
