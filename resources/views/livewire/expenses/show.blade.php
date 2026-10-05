<section class="w-full max-w-2xl space-y-8">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ $expense->description }}</flux:heading>
        <x-status-badge :status="$expense->status" />
    </div>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Date') }}</dt><dd>{{ $expense->expense_date->format('d/m/Y') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Building / unit') }}</dt><dd>{{ $expense->building->code }}{{ $expense->unit ? ' / '.$expense->unit->code : ' ('.__('whole building').')' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Category') }}</dt><dd>{{ str($expense->category->value)->headline() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Charged to') }}</dt><dd>{{ str($expense->charge_to->value)->headline() }}{{ $expense->ownerContract ? ' ('.$expense->ownerContract->number.')' : '' }}</dd></div>
        @if ($expense->invoice_id)
            <div><dt class="text-sm text-zinc-500">{{ __('Tenant invoice') }}</dt><dd><flux:link :href="route('invoices.show', $expense->invoice_id)" wire:navigate>{{ $expense->invoice?->label() }}</flux:link> <span class="text-xs text-zinc-500">{{ __('(corrected only by a credit note)') }}</span></dd></div>
        @endif
        <div><dt class="text-sm text-zinc-500">{{ __('Net / VAT / total (BHD)') }}</dt><dd class="tabular-nums">{{ $expense->net }} / {{ $expense->tax_amount }} / {{ $expense->total }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Recorded by') }}</dt><dd>{{ $expense->recorder->name }}</dd></div>
        @if ($expense->owner_approval_note)
            <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Owner approval') }}</dt><dd>{{ $expense->owner_approval_note }}</dd></div>
        @endif
        @if ($expense->reversal_reason)
            <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Reversed') }}</dt><dd>{{ $expense->reversed_at?->timezone('Asia/Bahrain')->format('d/m/Y H:i') }} — {{ $expense->reversal_reason }}</dd></div>
        @endif
    </dl>

    @if ($canReverse)
        <form wire:submit="reverse" class="space-y-3">
            <flux:heading size="lg">{{ __('Reverse expense') }}</flux:heading>
            <flux:textarea wire:model="reason" :label="__('Reason')" rows="2" />
            <flux:button type="submit" variant="danger" wire:confirm="{{ __('Reverse this expense? This cannot be undone.') }}">{{ __('Reverse') }}</flux:button>
        </form>
    @endif

    <livewire:documents.panel :documentable="$expense" :key="'docs-expense-'.$expense->id" />
</section>
