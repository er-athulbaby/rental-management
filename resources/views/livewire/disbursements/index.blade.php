@php
    $filtered = $status !== 'all' || $this->building;
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Payments out')">
        <x-slot:actions>
            @can('create', App\Models\Disbursement::class)
                <flux:button variant="primary" icon="plus" :href="route('disbursements.create')" wire:navigate>{{ __('New payment out') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters :clear="$filtered ? route('disbursements.index') : null">
        <flux:select wire:model.live="status" :label="__('Status')" class="sm:max-w-48">
            <option value="all">{{ __('All') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <x-building-filter :buildings="$this->buildings" />
    </x-ui.filters>

    <x-ui.table>
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Payee') }}</flux:table.column>
                <flux:table.column>{{ __('Purpose') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row :key="$row->id">
                        <flux:table.cell><flux:link :href="route('disbursements.show', $row)" wire:navigate>{{ $row->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $row->payeeName() }}</flux:table.cell>
                        <flux:table.cell>{{ $row->purpose->label() }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $row->amount }}</flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$row->status" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($rows->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
