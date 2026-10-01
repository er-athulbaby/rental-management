<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $invoice->label() }}</flux:heading>
            <flux:badge>{{ $invoice->displayLabel() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canEdit && $invoice->type->value === 'manual')
                <flux:button :href="route('invoices.edit', $invoice)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endif
            @if ($canEdit)
                <flux:button variant="ghost" wire:click="cancelDraft" wire:confirm="{{ __('Cancel this draft?') }}">{{ __('Cancel draft') }}</flux:button>
            @endif
            @if ($canIssue)
                <flux:button variant="primary" wire:click="issueNow" wire:confirm="{{ __('Issue this invoice now?') }}">{{ $invoice->status->value === 'draft' ? __('Issue') : __('Issue now') }}</flux:button>
            @endif
            {{-- Task 6: credit note. Task 9: PDF. --}}
        </div>
    </div>
    <flux:error name="invoice" />

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
        <div><dt class="text-sm text-zinc-500">{{ __('Customer') }}</dt><dd>{{ $invoice->customer->name_en }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Agreement') }}</dt><dd>@if ($invoice->agreement)<flux:link :href="route('agreements.show', $invoice->agreement_id)" wire:navigate>{{ $invoice->agreement->number }}</flux:link>@endif</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Type') }}</dt><dd>{{ $invoice->type->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Issue date') }}</dt><dd>{{ $invoice->issue_date->format('d/m/Y') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Due / grace until') }}</dt><dd>{{ $invoice->due_date->format('d/m/Y') }}{{ $invoice->grace_until ? ' / '.$invoice->grace_until->format('d/m/Y') : '' }}</dd></div>
        @if ($invoice->issuer)
            <div><dt class="text-sm text-zinc-500">{{ __('Issued by') }}</dt><dd>{{ $invoice->issuer->name }}</dd></div>
        @endif
    </dl>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Line') }}</flux:table.column>
                <flux:table.column>{{ __('Net') }}</flux:table.column>
                <flux:table.column>{{ __('Tax') }}</flux:table.column>
                <flux:table.column>{{ __('Total') }}</flux:table.column>
                <flux:table.column>{{ __('Owner contract') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($invoice->lines as $line)
                    <flux:table.row :key="$line->id">
                        <flux:table.cell>{{ $line->description }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->net }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->tax_amount }} <span class="text-xs text-zinc-500">{{ $line->tax_category->label() }}{{ (float) $line->tax_rate > 0 ? ' '.$line->tax_rate.'%' : '' }}</span></flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->total }}</flux:table.cell>
                        <flux:table.cell>{{ $line->ownerContract?->number ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <dl class="ms-auto grid max-w-xs grid-cols-2 gap-y-1 tabular-nums">
        <dt>{{ __('Subtotal') }}</dt><dd class="text-end">{{ $invoice->subtotal }}</dd>
        <dt>{{ __('Tax') }}</dt><dd class="text-end">{{ $invoice->tax_total }}</dd>
        <dt class="font-semibold">{{ __('Total (BHD)') }}</dt><dd class="text-end font-semibold">{{ $invoice->total }}</dd>
        @if ($invoice->status->value === 'issued')
            <dt>{{ __('Balance') }}</dt><dd class="text-end">{{ $invoice->balance }}</dd>
        @endif
    </dl>
</section>
