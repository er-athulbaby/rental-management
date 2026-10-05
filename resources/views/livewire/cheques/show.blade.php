<section class="w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $cheque->bank_name }} #{{ $cheque->cheque_no }}</flux:heading>
            <x-status-badge :status="$cheque->status" />
        </div>
        @if ($canManage)
            <div class="flex flex-wrap gap-2">
                @if ($cheque->status->value === 'deposited')
                    <flux:modal.trigger name="clear"><flux:button variant="primary">{{ __('Cleared') }}</flux:button></flux:modal.trigger>
                @endif
                @if (in_array($cheque->status->value, ['deposited', 'cleared'], true))
                    <flux:modal.trigger name="bounce"><flux:button variant="danger">{{ __('Bounced') }}</flux:button></flux:modal.trigger>
                @endif
                @if ($cheque->status->value === 'bounced')
                    <flux:modal.trigger name="replace"><flux:button variant="primary">{{ __('Replace') }}</flux:button></flux:modal.trigger>
                @endif
                @if (in_array($cheque->status->value, ['held', 'bounced'], true))
                    <flux:button wire:click="end('returned')" wire:confirm="{{ __('Mark this cheque as returned to the tenant?') }}">{{ __('Returned to tenant') }}</flux:button>
                @endif
                @if ($cheque->status->value === 'held')
                    <flux:button variant="ghost" wire:click="end('cancelled')" wire:confirm="{{ __('Cancel this cheque (entered in error)?') }}">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        @endif
    </div>
    <flux:error name="outcome" />

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
        <div><dt class="text-sm text-zinc-500">{{ __('Tenant') }}</dt><dd>{{ $cheque->customer->name_en }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Cheque date / amount') }}</dt><dd class="tabular-nums">{{ $cheque->cheque_date->format('d/m/Y') }} · {{ $cheque->amount }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('For invoice') }}</dt><dd>@if ($cheque->invoice)<flux:link :href="route('invoices.show', $cheque->invoice)" wire:navigate>{{ $cheque->invoice->label() }}</flux:link>@else — @endif</dd></div>
        @if ($cheque->agreement)<div><dt class="text-sm text-zinc-500">{{ __('Agreement') }}</dt><dd><flux:link :href="route('agreements.show', $cheque->agreement_id)" wire:navigate>{{ $cheque->agreement->number }}</flux:link></dd></div>@endif
        @if ($cheque->deposited_on)<div><dt class="text-sm text-zinc-500">{{ __('Deposited') }}</dt><dd>{{ $cheque->deposited_on->format('d/m/Y') }}</dd></div>@endif
        @if ($cheque->payment)<div><dt class="text-sm text-zinc-500">{{ __('Payment') }}</dt><dd><flux:link :href="route('payments.show', $cheque->payment)" wire:navigate>{{ $cheque->payment->number }}</flux:link> ({{ $cheque->cleared_on?->format('d/m/Y') }})</dd></div>@endif
        @if ($cheque->bounced_on)<div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Bounced') }}</dt><dd>{{ $cheque->bounced_on->format('d/m/Y') }} — {{ $cheque->bounce_reason }}</dd></div>@endif
        @if ($cheque->replacedBy)<div><dt class="text-sm text-zinc-500">{{ __('Replaced by') }}</dt><dd><flux:link :href="route('cheques.show', $cheque->replacedBy)" wire:navigate>#{{ $cheque->replacedBy->cheque_no }}</flux:link></dd></div>@endif
    </dl>

    <livewire:documents.panel :documentable="$cheque" />

    <flux:modal name="clear" class="md:w-96">
        <form wire:submit="clear" class="space-y-4">
            <flux:heading size="lg">{{ __('Cheque cleared') }}</flux:heading>
            <x-date-input wire:model="clearedOn" :label="__('Cleared on')" />
            <flux:error name="cleared_on" />
            <flux:button variant="primary" type="submit">{{ __('Record payment') }}</flux:button>
        </form>
    </flux:modal>

    <flux:modal name="bounce" class="md:w-96">
        <form wire:submit="bounce" class="space-y-4">
            <flux:heading size="lg">{{ __('Cheque bounced') }}</flux:heading>
            @if ($cheque->status->value === 'cleared')
                <flux:text>{{ __('This cheque has cleared: its payment will be reversed once Management approves.') }}</flux:text>
            @endif
            <x-date-input wire:model="bouncedOn" :label="__('Bounced on')" />
            <flux:textarea wire:model="bounceReason" :label="__('Reason')" rows="2" />
            <flux:error name="bounceReason" />
            <flux:error name="approval" />
            <flux:button variant="danger" type="submit">{{ __('Confirm') }}</flux:button>
        </form>
    </flux:modal>

    <flux:modal name="replace" class="md:w-96">
        <form wire:submit="replace" class="space-y-4">
            <flux:heading size="lg">{{ __('Replacement cheque') }}</flux:heading>
            <flux:input wire:model="replacement.cheque_no" :label="__('Cheque no.')" />
            <x-bank-select wire:model="replacement.bank_name" />
            <x-date-input wire:model="replacement.cheque_date" :label="__('Cheque date')" />
            <flux:input wire:model="replacement.amount" inputmode="decimal" :label="__('Amount')" />
            <flux:error name="cheque_no" />
            @foreach (['cheque_no', 'bank_name', 'cheque_date', 'amount', 'invoice_id'] as $f)<flux:error name="rows.0.{{ $f }}" />@endforeach
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </form>
    </flux:modal>
</section>
