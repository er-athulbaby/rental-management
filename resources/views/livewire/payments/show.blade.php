<section class="w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $payment->number }}</flux:heading>
            <flux:badge>{{ str($payment->status->value)->headline() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            {{-- Task 4: reversal. Task 9: receipt PDF. --}}
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
                <flux:table.column>{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Of which tax') }}</flux:table.column>
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
</section>
