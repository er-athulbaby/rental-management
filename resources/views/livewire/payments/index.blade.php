<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Payments') }}</flux:heading>
    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Receipt, reference or customer')" icon="magnifying-glass" class="sm:max-w-xs" />

    <div class="overflow-x-auto">
        <flux:table :paginate="$payments">
            <flux:table.columns>
                <flux:table.column>{{ __('Receipt') }}</flux:table.column>
                <flux:table.column>{{ __('Customer') }}</flux:table.column>
                <flux:table.column>{{ __('Received') }}</flux:table.column>
                <flux:table.column>{{ __('Method') }}</flux:table.column>
                <flux:table.column>{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($payments as $payment)
                    <flux:table.row :key="$payment->id">
                        <flux:table.cell><flux:link :href="route('payments.show', $payment)" wire:navigate>{{ $payment->number }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $payment->customer->name_en }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $payment->received_on->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $payment->method->label() }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $payment->amount }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ str($payment->status->value)->headline() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
