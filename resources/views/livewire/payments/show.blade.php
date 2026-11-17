<section class="w-full max-w-3xl space-y-6">
    <x-ui.summary :title="$payment->number" :status="$payment->status" :back="route('payments.index')">
        <x-slot:actions>
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
        </x-slot:actions>
        <x-slot:facts>
            <x-ui.fact :label="__('Tenant')"><flux:link :href="route('customers.edit', $payment->customer)" wire:navigate>{{ $payment->customer->name_en }}</flux:link></x-ui.fact>
            <x-ui.fact :label="__('Received')">{{ $payment->received_on->format('d/m/Y') }} · {{ $payment->methodSummary() }}</x-ui.fact>
            <x-ui.fact :label="__('Amount / credit left')">{{ $payment->amount }} / {{ $credit }}</x-ui.fact>
            @if ($payment->reference)<x-ui.fact :label="__('Reference')">{{ $payment->reference }}</x-ui.fact>@endif
            <x-ui.fact :label="__('Recorded by')">{{ $payment->recorder->name }}</x-ui.fact>
        </x-slot:facts>
    </x-ui.summary>

    <x-ui.section :title="__('Allocations')" :hint="__('Which invoice lines this payment settled.')" flush>
    <x-ui.table>
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
    </x-ui.table>
    </x-ui.section>

    <flux:modal name="reverse-payment" class="md:w-[28rem]">
        <form wire:submit="requestReversal" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Reverse :n', ['n' => $payment->number]) }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('Every allocation is undone and the invoices are owed again. Management approves.') }}</flux:text>
            </div>
            <flux:textarea wire:model="reversalReason" :label="__('Reason')" rows="3" />
            <flux:error name="reversalReason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" type="submit">{{ __('Send for approval') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="refund-credit" class="md:w-[32rem]">
        <form wire:submit="refundCredit" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Refund credit — up to :c BHD', ['c' => $credit]) }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('Pay the tenant back from the credit they hold.') }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="refund.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
                <flux:select wire:model.live="refund.method" :label="__('Method')">
                    @foreach (\App\Enums\DisbursementMethod::cases() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                </flux:select>
                <x-date-input wire:model="refund.paid_on" :label="__('Paid on')" />
                <flux:input wire:model="refund.reference" :label="__('Reference')" />
                @if (($refund['method'] ?? '') === 'cheque')
                    <flux:input wire:model="refund.cheque_no" :label="__('Cheque no.')" />
                    <x-date-input wire:model="refund.cheque_date" :label="__('Cheque date')" />
                    <div class="sm:col-span-2"><x-bank-select wire:model="refund.bank_name" /></div>
                @endif
            </div>
            @foreach (['amount', 'method', 'paid_on', 'reference', 'cheque_no', 'bank_name', 'cheque_date'] as $f)<flux:error name="refund.{{ $f }}" />@endforeach
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="refundCredit">{{ __('Pay refund') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
