<section class="w-full max-w-2xl space-y-6">
    <x-ui.summary :title="$expense->description" :status="$expense->status" :back="route('expenses.index')">
      <x-slot:facts>
        <x-ui.fact :label="__('Date')">{{ $expense->expense_date->format('d/m/Y') }}</x-ui.fact>
        <x-ui.fact :label="__('Building / unit')">{{ $expense->building->code }}{{ $expense->unit ? ' / '.$expense->unit->code : ' ('.__('whole building').')' }}</x-ui.fact>
        <x-ui.fact :label="__('Category')">{{ str($expense->category->value)->headline() }}</x-ui.fact>
        <x-ui.fact :label="__('Charged to')">{{ str($expense->charge_to->value)->headline() }}{{ $expense->ownerContract ? ' ('.$expense->ownerContract->number.')' : '' }}</x-ui.fact>
        @if ($expense->invoice_id)
            <x-ui.fact :label="__('Tenant invoice')"><flux:link :href="route('invoices.show', $expense->invoice_id)" wire:navigate>{{ $expense->invoice?->label() }}</flux:link> <span class="text-xs text-zinc-500">{{ __('(corrected only by a credit note)') }}</span></x-ui.fact>
        @endif
        <x-ui.fact :label="__('Net / VAT / total (BHD)')">{{ $expense->net }} / {{ $expense->tax_amount }} / {{ $expense->total }}</x-ui.fact>
        <x-ui.fact :label="__('Recorded by')">{{ $expense->recorder->name }}</x-ui.fact>
        <x-ui.fact :label="__('Payment')">
            @if ($payment)
                @can('view', $payment)
                <flux:link :href="route('disbursements.show', $payment)" wire:navigate>{{ $payment->status->value === 'paid' ? __('Paid') : __('In payment') }} · {{ $payment->label() }}</flux:link>
                @else
                    {{ $payment->status->value === 'paid' ? __('Paid') : __('In payment') }} · {{ $payment->label() }}
                @endcan
            @elseif ($expense->status->value === 'recorded')
                {{ __('Unpaid') }}
            @else
                —
            @endif
        </x-ui.fact>
        @if ($expense->owner_approval_note)
            <x-ui.fact :label="__('Owner approval')" class="sm:col-span-2">{{ $expense->owner_approval_note }}</x-ui.fact>
        @endif
        @if ($expense->reversal_reason)
            <x-ui.fact :label="__('Reversed')" class="sm:col-span-2">{{ $expense->reversed_at?->timezone('Asia/Bahrain')->format('d/m/Y H:i') }} — {{ $expense->reversal_reason }}</x-ui.fact>
        @endif
      </x-slot:facts>
    </x-ui.summary>

    @if ($canReverse)
      <x-ui.section :title="__('Reverse expense')" :hint="__('Marks this expense as reversed. This cannot be undone.')" tone="danger">
        <form wire:submit="reverse" class="grid gap-4 sm:grid-cols-2">
            <flux:textarea wire:model="reason" :label="__('Reason')" rows="2" class="sm:col-span-2" />
            <flux:button type="submit" variant="danger" class="justify-self-start" wire:confirm="{{ __('Reverse this expense? This cannot be undone.') }}">{{ __('Reverse') }}</flux:button>
        </form>
      </x-ui.section>
    @endif

    <livewire:documents.panel :documentable="$expense" :key="'docs-expense-'.$expense->id" />
</section>
