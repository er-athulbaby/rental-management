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

    @php
        $tabLabels = ['unpaid' => __('Unpaid'), 'overdue' => __('Overdue'), 'paid' => __('Paid'), 'upcoming' => __('Upcoming'), 'drafts' => __('Drafts'), 'all' => __('All')];
    @endphp
    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-1 border-b border-zinc-200 dark:border-zinc-700">
        <div role="tablist" aria-label="{{ __('Invoices') }}" class="-mb-px flex max-w-full gap-1 overflow-x-auto">
            @foreach ($tabLabels as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $currentTab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'flex shrink-0 items-center gap-2 rounded-t-md border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-inset',
                        'border-zinc-800 text-zinc-900 dark:border-white dark:text-white' => $currentTab === $key,
                        'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $currentTab !== $key,
                    ])>
                    {{ $label }}
                    @if ($key !== 'all')
                        <flux:badge size="sm" :color="$key === 'overdue' && $counts[$key] > 0 ? 'red' : 'zinc'">{{ $counts[$key] }}</flux:badge>
                    @endif
                </button>
            @endforeach
        </div>
        @if (in_array($currentTab, ['unpaid', 'overdue'], true))
            <flux:text @class(['pb-2 text-sm font-medium tabular-nums', 'text-red-600 dark:text-red-400' => $currentTab === 'overdue'])>
                {{ $currentTab === 'overdue' ? __('Overdue: :amount BHD', ['amount' => $owed['overdue']]) : __('Owed: :amount BHD', ['amount' => $owed['unpaid']]) }}
            </flux:text>
        @endif
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="type" class="sm:max-w-44">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
        </flux:select>
        <x-building-filter :buildings="$this->buildings" label="" />
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number, tenant name or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$invoices">
            <flux:table.columns>
                <flux:table.column>{{ __('Invoice') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
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
                        <flux:table.cell class="whitespace-nowrap">{{ $invoice->period_start?->format('d/m/Y') }}{{ $invoice->period_start ? ' – '.$invoice->period_end?->format('d/m/Y') : '' }} · {{ __('due :d', ['d' => $invoice->due_date->format('d/m/Y')]) }}@if ($currentTab === 'overdue') · <span class="text-red-600 dark:text-red-400">{{ trans_choice(':n day overdue|:n days overdue', $n = (int) $invoice->due_date->diffInDays(now('Asia/Bahrain')->startOfDay()), ['n' => $n]) }}</span>@endif</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $invoice->total }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $invoice->status->value === 'issued' ? $invoice->balance : '—' }}</flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$invoice->status->value === 'issued' ? $invoice->displayLabel() : $invoice->status" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
