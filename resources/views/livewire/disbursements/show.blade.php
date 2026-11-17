<section class="w-full max-w-3xl space-y-6">
    <x-ui.summary :title="$out->label()" :status="$out->status" :back="route('disbursements.index')">
        <x-slot:actions>
            @if ($canPay)
                <flux:modal.trigger name="pay"><flux:button variant="primary">{{ __('Record as paid') }}</flux:button></flux:modal.trigger>
            @endif
            @if ($pendingReversal)
                <flux:badge color="amber">{{ __('Reversal waiting for approval') }}</flux:badge>
            @elseif ($canReverse)
                <flux:modal.trigger name="reverse"><flux:button variant="danger">{{ __('Request reversal') }}</flux:button></flux:modal.trigger>
            @endif
        </x-slot:actions>
        <x-slot:facts>
            <x-ui.fact :label="__('Payee')">{{ $out->payeeName() }} ({{ $out->payee_type->value === 'customer' ? __('tenant') : ($out->payee_type->value === 'vendor' ? __('vendor') : __('owner')) }})</x-ui.fact>
            <x-ui.fact :label="__('Purpose')">{{ $out->purpose->label() }}</x-ui.fact>
            <x-ui.fact :label="__('Amount')">{{ $out->amount }}</x-ui.fact>
            <x-ui.fact :label="__('Method')">{{ $out->method->label() }}{{ $out->reference ? ' · '.$out->reference : '' }}</x-ui.fact>
            @if ($out->paid_on)<x-ui.fact :label="__('Paid on')">{{ $out->paid_on->format('d/m/Y') }} · {{ $out->recorder?->name }}</x-ui.fact>@endif
            @if ($out->source_type === 'payment')<x-ui.fact :label="__('Refunds credit of')"><flux:link :href="route('payments.show', $out->source_id)" wire:navigate>{{ $out->source()?->number }}</flux:link></x-ui.fact>@endif
            @if ($out->reason)<x-ui.fact :label="__('Reason')" class="sm:col-span-2 lg:col-span-3">{{ $out->reason }}</x-ui.fact>@endif
            <x-ui.fact :label="__('Requested by')">{{ $out->creator->name }}</x-ui.fact>
            @if ($out->reversed_at)<x-ui.fact :label="__('Reversed')">{{ $out->reversed_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</x-ui.fact>@endif
        </x-slot:facts>
    </x-ui.summary>

    @if ($out->purpose->value === 'vendor_bill')
        <x-ui.section :title="__('Expenses')" flush>
        <x-ui.table>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Expense') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Total (BHD)') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($out->expenses->sortBy('id') as $expense)
                        <flux:table.row :key="'e-'.$expense->id">
                            <flux:table.cell class="whitespace-nowrap">{{ $expense->expense_date->format('d/m/Y') }}</flux:table.cell>
                            <flux:table.cell><flux:link :href="route('expenses.show', $expense)" wire:navigate>{{ $expense->description }}</flux:link></flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $expense->total }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-ui.table>
        </x-ui.section>
    @endif

    @if ($out->cheque)
        <x-ui.section :title="__('Cheque')">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>{{ __('Cheque :n on :b, dated :d', ['n' => $out->cheque->cheque_no, 'b' => $out->cheque->bank_name, 'd' => $out->cheque->cheque_date->format('d/m/Y')]) }} · <x-status-badge :status="$out->cheque->status" /></div>
            @if ($canClearCheque)
                <form wire:submit="clearCheque" class="flex items-end gap-2">
                    <x-date-input wire:model="clearedOn" :label="__('Cleared on')" />
                    <flux:button type="submit">{{ __('Cleared') }}</flux:button>
                </form>
            @endif
        </div>
        </x-ui.section>
    @endif

    <flux:modal name="pay" class="md:w-[32rem]">
        <form wire:submit="payNow" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Record as paid') }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('Say how and when this payment out was made.') }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="pay.method" :label="__('Method')">
                    @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                </flux:select>
                <x-date-input wire:model="pay.paid_on" :label="__('Paid on')" />
                <flux:input wire:model="pay.reference" :label="__('Reference')" />
                @if (($pay['method'] ?? '') === 'cheque')
                    <flux:input wire:model="pay.cheque_no" :label="__('Cheque no.')" />
                    <x-bank-select wire:model="pay.bank_name" />
                    <x-date-input wire:model="pay.cheque_date" :label="__('Cheque date')" />
                @endif
            </div>
            @foreach (['method', 'paid_on', 'reference', 'cheque_no', 'bank_name', 'cheque_date'] as $f)<flux:error name="pay.{{ $f }}" />@endforeach
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="payNow">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reverse" class="md:w-[28rem]">
        <form wire:submit="requestReversal" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Reverse :n', ['n' => $out->label()]) }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('The payment out is undone once Management approves.') }}</flux:text>
            </div>
            <flux:textarea wire:model="reversalReason" :label="__('Reason')" rows="3" />
            <flux:error name="reversalReason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" type="submit">{{ __('Send for approval') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
