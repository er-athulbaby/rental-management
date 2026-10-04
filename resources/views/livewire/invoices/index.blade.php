<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Invoices') }}</flux:heading>
        @if ($canCreate)
            <flux:modal.trigger name="new-invoice">
                <flux:button variant="primary">{{ __('New invoice') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if ($canCreate)
        <flux:modal name="new-invoice" class="md:w-96">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('New invoice') }}</flux:heading>
                <flux:text class="text-sm">{{ __('For charges outside the rent schedule, such as a repair or a fee. Rent invoices are created automatically from agreements.') }}</flux:text>
                <x-customer-picker :results="$customerResults" :term="$customerSearch" action="startInvoice" />
            </div>
        </flux:modal>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="status" class="sm:max-w-44">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="type" class="sm:max-w-44">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
        </flux:select>
        <x-building-filter :buildings="$this->buildings" label="" />
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number, customer name or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$invoices">
            <flux:table.columns>
                <flux:table.column>{{ __('Invoice') }}</flux:table.column>
                <flux:table.column>{{ __('Customer') }}</flux:table.column>
                <flux:table.column>{{ __('Period / due') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Balance') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($invoices as $invoice)
                    <flux:table.row :key="$invoice->id">
                        <flux:table.cell><flux:link :href="route('invoices.show', $invoice)" wire:navigate>{{ $invoice->label() }}</flux:link> <span class="text-xs text-zinc-500">{{ $invoice->type->label() }}</span></flux:table.cell>
                        <flux:table.cell>{{ $invoice->customer->name_en }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $invoice->period_start?->format('d/m/Y') }}{{ $invoice->period_start ? ' – '.$invoice->period_end?->format('d/m/Y') : '' }} · {{ __('due :d', ['d' => $invoice->due_date->format('d/m/Y')]) }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $invoice->total }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $invoice->status->value === 'issued' ? $invoice->balance : '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$invoice->displayLabel() === __('Overdue') ? 'red' : 'zinc'">{{ $invoice->displayLabel() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
