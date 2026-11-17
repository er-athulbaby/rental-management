@php
    $filtered = $status !== 'open' || $this->building;
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Deposit settlements')" />

    <x-ui.filters :clear="$filtered ? route('deposit-settlements.index') : null">
        <flux:select wire:model.live="status" :label="__('Show')" class="sm:max-w-48">
            <option value="open">{{ __('Open') }}</option>
            <option value="all">{{ __('All') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <x-building-filter :buildings="$this->buildings" />
    </x-ui.filters>

    <x-ui.table>
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
                        <flux:table.cell><x-status-badge :status="$row->status" /></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4" class="whitespace-normal text-zinc-500 dark:text-zinc-400">{{ __('Nothing to settle. A settlement opens by itself when a tenant moves out or an agreement ends, to work out the deposit refund less any deductions.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </x-ui.table>
</section>
