<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ __('Deposit settlement :n', ['n' => $s->label()]) }}</flux:heading>
            <flux:badge>{{ $s->status->label() }}</flux:badge>
        </div>
        @if ($canRefund)
            <flux:modal.trigger name="refund"><flux:button variant="primary">{{ __('Pay refund (:c BHD left)', ['c' => $left]) }}</flux:button></flux:modal.trigger>
        @endif
    </div>
    <flux:text>{{ $s->agreement->label() }} · {{ $s->agreement->customer->name_en }}</flux:text>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Held') }}</flux:table.column>
                <flux:table.column>{{ __('Applied') }}</flux:table.column>
                <flux:table.column>{{ __('Refund') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($s->units as $u)
                    <flux:table.row :key="'u'.$u->id">
                        <flux:table.cell>{{ $u->agreementUnit->unit->building->code }} / {{ $u->agreementUnit->unit->code }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $u->held_amount }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $u->applied_amount ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $u->refund_amount ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:heading size="lg">{{ __('Deductions') }}</flux:heading>
    @foreach ($lines as $i => $line)
        <flux:card class="space-y-3" wire:key="ded-{{ $i }}">
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:select wire:model="lines.{{ $i }}.agreement_unit_id" :label="__('Unit')" :disabled="! $canEdit">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($s->units as $u)<option value="{{ $u->agreement_unit_id }}">{{ $u->agreementUnit->unit->code }}</option>@endforeach
                </flux:select>
                <flux:select wire:model.live="lines.{{ $i }}.type" :label="__('Type')" :disabled="! $canEdit">
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="lines.{{ $i }}.amount" inputmode="decimal" :label="__('Amount (BHD)')" :disabled="! $canEdit" />
                @if (($line['type'] ?? '') === 'unpaid_rent')
                    <flux:select wire:model="lines.{{ $i }}.invoice_line_id" :label="__('Unpaid line')" :disabled="! $canEdit">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($rentLines as $rl)<option value="{{ $rl->id }}">{{ $rl->description }} ({{ \App\Support\Fils::toDecimal($rl->balanceFils()) }})</option>@endforeach
                    </flux:select>
                @endif
            </div>
            <flux:input wire:model="lines.{{ $i }}.description" :label="__('Reason')" :disabled="! $canEdit" />
            @foreach (['agreement_unit_id', 'type', 'description', 'amount', 'invoice_line_id'] as $f)<flux:error name="lines.{{ $i }}.{{ $f }}" />@endforeach
            @if ($canEdit)<flux:button size="sm" variant="ghost" wire:click="removeLine({{ $i }})">{{ __('Remove') }}</flux:button>@endif
        </flux:card>
    @endforeach
    <flux:error name="lines" />
    @if ($canEdit)
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="addLine">{{ __('Add deduction') }}</flux:button>
            <flux:button wire:click="saveLines">{{ __('Save') }}</flux:button>
            <flux:button variant="primary" wire:click="submit" wire:confirm="{{ __('Send this settlement for Management approval?') }}">{{ __('Send for approval') }}</flux:button>
        </div>
    @endif

    @if ($s->deductionsInvoice)
        <flux:text>{{ __('Deductions invoice') }} <flux:link :href="route('invoices.show', $s->deductionsInvoice)" wire:navigate>{{ $s->deductionsInvoice->label() }}</flux:link>@if ($s->payment) · {{ __('deposit applied') }} <flux:link :href="route('payments.show', $s->payment)" wire:navigate>{{ $s->payment->number }}</flux:link>@endif</flux:text>
    @endif

    <flux:modal name="refund" class="md:w-96">
        <form wire:submit="payRefund" class="space-y-4">
            <flux:heading size="lg">{{ __('Refund the deposit') }}</flux:heading>
            <flux:input wire:model="refund.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
            <flux:select wire:model.live="refund.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="refund.paid_on" type="date" :label="__('Paid on')" />
            <flux:input wire:model="refund.reference" :label="__('Reference')" />
            @if (($refund['method'] ?? '') === 'cheque')
                <flux:input wire:model="refund.cheque_no" :label="__('Cheque no.')" />
                <flux:input wire:model="refund.bank_name" :label="__('Bank')" />
                <flux:input wire:model="refund.cheque_date" type="date" :label="__('Cheque date')" />
            @endif
            @foreach (['amount', 'method', 'paid_on', 'reference', 'cheque_no', 'bank_name', 'cheque_date'] as $f)<flux:error name="refund.{{ $f }}" />@endforeach
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="payRefund">{{ __('Pay refund') }}</flux:button>
        </form>
    </flux:modal>
</section>
