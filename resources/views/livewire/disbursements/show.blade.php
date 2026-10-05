<section class="w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $out->label() }}</flux:heading>
            <flux:badge>{{ $out->status->label() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canPay)
                <flux:modal.trigger name="pay"><flux:button variant="primary">{{ __('Record as paid') }}</flux:button></flux:modal.trigger>
            @endif
            @if ($pendingReversal)
                <flux:badge color="amber">{{ __('Reversal waiting for approval') }}</flux:badge>
            @elseif ($canReverse)
                <flux:modal.trigger name="reverse"><flux:button variant="danger">{{ __('Request reversal') }}</flux:button></flux:modal.trigger>
            @endif
        </div>
    </div>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
        <div><dt class="text-sm text-zinc-500">{{ __('Payee') }}</dt><dd>{{ $out->payee()->name_en }} ({{ $out->payee_type->value === 'customer' ? __('tenant') : __('owner') }})</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Purpose') }}</dt><dd>{{ $out->purpose->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Amount') }}</dt><dd class="tabular-nums">{{ $out->amount }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Method') }}</dt><dd>{{ $out->method->label() }}{{ $out->reference ? ' · '.$out->reference : '' }}</dd></div>
        @if ($out->paid_on)<div><dt class="text-sm text-zinc-500">{{ __('Paid on') }}</dt><dd>{{ $out->paid_on->format('d/m/Y') }} · {{ $out->recorder?->name }}</dd></div>@endif
        @if ($out->source_type === 'payment')<div><dt class="text-sm text-zinc-500">{{ __('Refunds credit of') }}</dt><dd><flux:link :href="route('payments.show', $out->source_id)" wire:navigate>{{ $out->source()?->number }}</flux:link></dd></div>@endif
        @if ($out->reason)<div class="sm:col-span-3"><dt class="text-sm text-zinc-500">{{ __('Reason') }}</dt><dd>{{ $out->reason }}</dd></div>@endif
        <div><dt class="text-sm text-zinc-500">{{ __('Requested by') }}</dt><dd>{{ $out->creator->name }}</dd></div>
        @if ($out->reversed_at)<div><dt class="text-sm text-zinc-500">{{ __('Reversed') }}</dt><dd>{{ $out->reversed_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</dd></div>@endif
    </dl>

    @if ($out->cheque)
        <flux:card class="flex flex-wrap items-end justify-between gap-3">
            <div>{{ __('Cheque :n on :b, dated :d', ['n' => $out->cheque->cheque_no, 'b' => $out->cheque->bank_name, 'd' => $out->cheque->cheque_date->format('d/m/Y')]) }} · <flux:badge size="sm">{{ $out->cheque->status->label() }}</flux:badge></div>
            @if ($canClearCheque)
                <form wire:submit="clearCheque" class="flex items-end gap-2">
                    <x-date-input wire:model="clearedOn" :label="__('Cleared on')" />
                    <flux:button type="submit">{{ __('Cleared') }}</flux:button>
                </form>
            @endif
        </flux:card>
    @endif

    <flux:modal name="pay" class="md:w-96">
        <form wire:submit="payNow" class="space-y-4">
            <flux:heading size="lg">{{ __('Record as paid') }}</flux:heading>
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
            @foreach (['method', 'paid_on', 'reference', 'cheque_no', 'bank_name', 'cheque_date'] as $f)<flux:error name="pay.{{ $f }}" />@endforeach
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="payNow">{{ __('Save') }}</flux:button>
        </form>
    </flux:modal>

    <flux:modal name="reverse" class="md:w-96">
        <form wire:submit="requestReversal" class="space-y-4">
            <flux:heading size="lg">{{ __('Reverse :n', ['n' => $out->label()]) }}</flux:heading>
            <flux:textarea wire:model="reversalReason" :label="__('Reason')" rows="3" />
            <flux:error name="reversalReason" />
            <flux:button variant="danger" type="submit">{{ __('Send for approval') }}</flux:button>
        </form>
    </flux:modal>
</section>
