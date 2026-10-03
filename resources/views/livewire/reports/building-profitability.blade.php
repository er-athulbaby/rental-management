<div class="space-y-4">
    <flux:heading size="xl">{{ __('Building profitability') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-4">
        <flux:select wire:model.live="building" :label="__('Building')">
            <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
            @foreach ($buildings as $b)
                <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="from" :label="__('From')" />
        <flux:input type="date" wire:model.live="to" :label="__('To')" />
        <div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Building') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Collected') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Fees') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Income') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Billed') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Head lease') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Expenses') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Result') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $r)
                    <flux:table.row :key="'b-'.$r['building']->id">
                        <flux:table.cell>{{ $r['building']->code }} <span class="text-zinc-500">{{ $r['building']->name }}</span></flux:table.cell>
                        @foreach (['collected', 'fees', 'income', 'billed', 'head_lease', 'expenses', 'result'] as $k)
                            <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($r['figures'][$k]) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:text class="text-sm">{{ __('Cash basis: income by payment posting, net of VAT; head lease by payment date; expenses by expense date. Billed shows invoices issued less credit notes, for comparison.') }}</flux:text>
</div>
