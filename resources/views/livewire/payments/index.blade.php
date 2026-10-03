<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Payments') }}</flux:heading>
        @if ($canCreate)
            <flux:modal.trigger name="new-payment">
                <flux:button variant="primary">{{ __('New payment') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if ($canCreate)
        <flux:modal name="new-payment" class="md:w-96">
            <form wire:submit="startPayment" class="space-y-4">
                <flux:heading size="lg">{{ __('New payment') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Record money received from a customer by cash, transfer or card. Post-dated cheques are entered from the agreement page.') }}</flux:text>
                <flux:select wire:model="newCustomerId" :label="__('Customer')">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name_en }}</option>
                    @endforeach
                </flux:select>
                <div class="flex justify-end"><flux:button type="submit" variant="primary">{{ __('Continue') }}</flux:button></div>
            </form>
        </flux:modal>
    @endif
    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Receipt, reference or customer')" icon="magnifying-glass" class="sm:max-w-xs" />

    <div class="overflow-x-auto">
        <flux:table :paginate="$payments">
            <flux:table.columns>
                <flux:table.column>{{ __('Receipt') }}</flux:table.column>
                <flux:table.column>{{ __('Customer') }}</flux:table.column>
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
                        <flux:table.cell>{{ $payment->method->label() }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $payment->amount }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ str($payment->status->value)->headline() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
