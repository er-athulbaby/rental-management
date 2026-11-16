@php
    $filtered = $search !== '' || $this->building;
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Payments')">
        <x-slot:actions>
            @if ($canCreate)
                <flux:modal.trigger name="new-payment">
                    <flux:button variant="primary" icon="plus">{{ __('New payment') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Collected this month')" :value="__(':amount BHD', ['amount' => $stats['collected']])" />
        <x-ui.stat :label="__('Receipts this month')" :value="$stats['receipts']" />
    </x-ui.stats>

    @if ($canCreate)
        <flux:modal name="new-payment" class="md:w-96">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('New payment') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Record money received from a tenant by cash, transfer or card. Post-dated cheques are entered from the Cheques list.') }}</flux:text>
                <x-customer-picker :results="$customerResults" :term="$customerSearch" action="startPayment" />
            </div>
        </flux:modal>
    @endif
    <x-ui.filters :clear="$filtered ? route('payments.index') : null">
        <x-building-filter :buildings="$this->buildings" label="" />
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Receipt, reference, tenant or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />
    </x-ui.filters>

    <x-ui.table>
        <flux:table :paginate="$payments">
            <flux:table.columns>
                <flux:table.column>{{ __('Receipt') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Received') }}</flux:table.column>
                <flux:table.column>{{ __('Method') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($payments as $payment)
                    <flux:table.row :key="$payment->id">
                        <flux:table.cell><flux:link :href="route('payments.show', $payment)" wire:navigate>{{ $payment->number }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $payment->customer->name_en }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $payment->received_on->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $payment->methodSummary() }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $payment->amount }}</flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$payment->status" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($payments->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
