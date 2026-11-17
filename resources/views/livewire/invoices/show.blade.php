<section class="w-full max-w-4xl space-y-6">
    <x-ui.summary :title="$invoice->label()" :status="$invoice->displayLabel()" :back="route('invoices.index')">
        <x-slot:actions>
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
        </x-slot:actions>
        <x-slot:notices>
            <flux:error name="invoice" />
        </x-slot:notices>
        <x-slot:facts>
            <x-ui.fact :label="__('Tenant')">{{ $invoice->customer->name_en }}</x-ui.fact>
            <x-ui.fact :label="__('Agreement')">@if ($invoice->agreement)<flux:link :href="route('agreements.show', $invoice->agreement_id)" wire:navigate>{{ $invoice->agreement->number }}</flux:link>@endif</x-ui.fact>
            <x-ui.fact :label="__('Type')">{{ $invoice->type->label() }}</x-ui.fact>
            <x-ui.fact :label="__('Issue date')">{{ $invoice->issue_date->format('d/m/Y') }}</x-ui.fact>
            <x-ui.fact :label="__('Due / grace until')">{{ $invoice->due_date->format('d/m/Y') }}{{ $invoice->grace_until ? ' / '.$invoice->grace_until->format('d/m/Y') : '' }}</x-ui.fact>
            @if ($invoice->issuer)
                <x-ui.fact :label="__('Issued by')">{{ $invoice->issuer->name }}</x-ui.fact>
            @endif
        </x-slot:facts>
    </x-ui.summary>

    <x-ui.section :title="__('Lines')" flush>
    <x-ui.table>
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
    </x-ui.table>
        <x-ui.totals :rows="array_values(array_filter([
            [__('Subtotal'), $invoice->subtotal],
            [__('Tax'), $invoice->tax_total],
            [__('Total (BHD)'), $invoice->total, true],
            (float) $invoice->credited > 0 ? [__('Credited'), $invoice->credited] : null,
            $invoice->status->value === 'issued' ? [__('Balance'), $invoice->balance, true] : null,
        ]))" />
    </x-ui.section>

    @if ($payments->isNotEmpty())
        <x-ui.section :title="__('Payments received')" flush>
            <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                @foreach ($payments as $p)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-6">
                        <span><flux:link :href="route('payments.show', $p['payment'])" wire:navigate>{{ $p['payment']->number }}</flux:link> <span class="text-zinc-500">· {{ $p['payment']->received_on->format('d/m/Y') }} · {{ $p['payment']->method->label() }}</span>@if ($p['payment']->status->value === 'reversed') <flux:badge size="sm" color="red">{{ __('Reversed') }}</flux:badge>@endif</span>
                        <span class="ms-auto font-medium tabular-nums">{{ $p['amount'] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif
    @if ($invoice->relatedInvoice)
        <x-ui.section>
            <flux:text>{{ __('Credits') }} <flux:link :href="route('invoices.show', $invoice->related_invoice_id)" wire:navigate>{{ $invoice->relatedInvoice->label() }}</flux:link>. {{ $invoice->credit_reason }}</flux:text>
        </x-ui.section>
    @endif
    @if ($invoice->creditNotes->isNotEmpty())
        <x-ui.section :title="__('Credit notes')" flush>
            <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            @foreach ($invoice->creditNotes as $cn)
                <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-6"><flux:link :href="route('invoices.show', $cn)" wire:navigate>{{ $cn->number ?? __('Credit note #:id', ['id' => $cn->id]) }}</flux:link> <span class="ms-auto tabular-nums">{{ $cn->total }}</span> <x-status-badge :status="$cn->status" /></li>
            @endforeach
            </ul>
        </x-ui.section>
    @endif

    <livewire:reminders.record-panel :record="$invoice" :key="'reminders-invoice-'.$invoice->id" />
</section>
