<section class="w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $payment->number }}</flux:heading>
            <flux:badge>{{ str($payment->status->value)->headline() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="document-arrow-down" :href="route('payments.receipt', $payment)" target="_blank">{{ __('Receipt') }}</flux:button>
            @if ($canRefund)
                <flux:modal.trigger name="refund-credit"><flux:button>{{ __('Refund credit') }}</flux:button></flux:modal.trigger>
            @endif
            @if ($pendingReversal)
                <flux:badge color="amber">{{ __('Reversal waiting for approval') }}</flux:badge>
            @elseif ($canReverse)
                <flux:modal.trigger name="reverse-payment"><flux:button variant="danger">{{ __('Request reversal') }}</flux:button></flux:modal.trigger>
            @endif
            @if ($payment->cheque_id)<flux:button :href="route('cheques.show', $payment->cheque_id)" wire:navigate>{{ __('Cheque') }}</flux:button>@endif
        </div>
    </div>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
        <div><dt class="text-sm text-zinc-500">{{ __('Customer') }}</dt><dd><flux:link :href="route('customers.edit', $payment->customer)" wire:navigate>{{ $payment->customer->name_en }}</flux:link></dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Received') }}</dt><dd>{{ $payment->received_on->format('d/m/Y') }} · {{ $payment->method->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Amount / credit left') }}</dt><dd class="tabular-nums">{{ $payment->amount }} / {{ $credit }}</dd></div>
        @if ($payment->reference)<div><dt class="text-sm text-zinc-500">{{ __('Reference') }}</dt><dd>{{ $payment->reference }}</dd></div>@endif
        <div><dt class="text-sm text-zinc-500">{{ __('Recorded by') }}</dt><dd>{{ $payment->recorder->name }}</dd></div>
    </dl>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Invoice') }}</flux:table.column>
                <flux:table.column>{{ __('Line') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Of which tax') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($payment->allocations as $allocation)
                    <flux:table.row :key="'al-'.$allocation->id">
                        <flux:table.cell><flux:link :href="route('invoices.show', $allocation->line->invoice_id)" wire:navigate>{{ $allocation->line->invoice->number }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $allocation->line->description }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $allocation->amount }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $allocation->tax_amount }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:modal name="reverse-payment" class="md:w-96">
        <form wire:submit="requestReversal" class="space-y-4">
            <flux:heading size="lg">{{ __('Reverse :n', ['n' => $payment->number]) }}</flux:heading>
            <flux:text>{{ __('Every allocation is undone and the invoices are owed again. Management approves.') }}</flux:text>
            <flux:textarea wire:model="reversalReason" :label="__('Reason')" rows="3" />
            <flux:error name="reversalReason" />
            <flux:button variant="danger" type="submit">{{ __('Send for approval') }}</flux:button>
        </form>
    </flux:modal>

    <flux:modal name="refund-credit" class="md:w-96">
        <form wire:submit="refundCredit" class="space-y-4">
            <flux:heading size="lg">{{ __('Refund credit — up to :c BHD', ['c' => $credit]) }}</flux:heading>
            <flux:input wire:model="refund.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
            <flux:select wire:model.live="refund.method" :label="__('Method')">
                @foreach (\App\Enums\DisbursementMethod::cases() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="refund.paid_on" type="date" :label="__('Paid on')" />
            <flux:input wire:model="refund.reference" :label="__('Reference')" />
            @if (($refund['method'] ?? '') === 'cheque')
                <flux:input wire:model="refund.cheque_no" :label="__('Cheque no.')" />
                <x-bank-select wire:model="refund.bank_name" />
                <flux:input wire:model="refund.cheque_date" type="date" :label="__('Cheque date')" />
            @endif
            @foreach (['amount', 'method', 'paid_on', 'reference', 'cheque_no', 'bank_name', 'cheque_date'] as $f)<flux:error name="refund.{{ $f }}" />@endforeach
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="refundCredit">{{ __('Pay refund') }}</flux:button>
        </form>
    </flux:modal>
</section>
