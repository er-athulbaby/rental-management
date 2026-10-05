<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $invoice->label() }}</flux:heading>
            <x-status-badge :status="$invoice->displayLabel()" />
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
            @if ($canEdit && $invoice->type->value === 'credit_note')
                <flux:button :href="route('invoices.credit.edit', $invoice)" wire:navigate>{{ __('Edit') }}</flux:button>
                <flux:button variant="primary" wire:click="submitCreditNote">{{ __('Send for approval') }}</flux:button>
            @endif
            @if ($canPay)
                <flux:button variant="primary" icon="banknotes" :href="route('payments.create', ['customer' => $invoice->customer_id, 'invoice' => $invoice->id])" wire:navigate>{{ __('Record payment') }}</flux:button>
            @endif
            @if ($canCredit)
                <flux:button :href="route('invoices.credit', $invoice)" wire:navigate>{{ __('Credit note') }}</flux:button>
            @endif
            @if ($invoice->status->value === 'issued')
                <flux:button icon="document-arrow-down" :href="route('invoices.pdf', $invoice)" target="_blank">{{ __('PDF') }}</flux:button>
            @endif
        </div>
    </div>
    <flux:error name="invoice" />

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
        <div><dt class="text-sm text-zinc-500">{{ __('Tenant') }}</dt><dd>{{ $invoice->customer->name_en }}</dd></div>
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
                <flux:table.column align="end">{{ __('Net') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Tax') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
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
        @if ((float) $invoice->credited > 0)<dt>{{ __('Credited') }}</dt><dd class="text-end">{{ $invoice->credited }}</dd>@endif
        @if ($invoice->status->value === 'issued')
            <dt>{{ __('Balance') }}</dt><dd class="text-end">{{ $invoice->balance }}</dd>
        @endif
    </dl>
    @if ($payments->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="sm">{{ __('Payments received') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($payments as $p)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span><flux:link :href="route('payments.show', $p['payment'])" wire:navigate>{{ $p['payment']->number }}</flux:link> <span class="text-zinc-500">· {{ $p['payment']->received_on->format('d/m/Y') }} · {{ $p['payment']->method->label() }}</span>@if ($p['payment']->status->value === 'reversed') <flux:badge size="sm" color="red">{{ __('Reversed') }}</flux:badge>@endif</span>
                        <span class="font-medium tabular-nums">{{ $p['amount'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($invoice->relatedInvoice)
        <flux:text>{{ __('Credits') }} <flux:link :href="route('invoices.show', $invoice->related_invoice_id)" wire:navigate>{{ $invoice->relatedInvoice->label() }}</flux:link>. {{ $invoice->credit_reason }}</flux:text>
    @endif
    @if ($invoice->creditNotes->isNotEmpty())
        <div class="space-y-1">
            <flux:heading size="sm">{{ __('Credit notes') }}</flux:heading>
            @foreach ($invoice->creditNotes as $cn)
                <div class="flex items-center gap-2 text-sm"><flux:link :href="route('invoices.show', $cn)" wire:navigate>{{ $cn->number ?? __('Credit note #:id', ['id' => $cn->id]) }}</flux:link> <span class="tabular-nums">{{ $cn->total }}</span> <x-status-badge :status="$cn->status" /></div>
            @endforeach
        </div>
    @endif
</section>
