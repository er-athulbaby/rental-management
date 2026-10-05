<div class="space-y-4">
    <flux:heading size="xl">{{ __('Building profitability') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-4">
        <flux:select wire:model.live="building" :label="__('Building')">
            <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
            @foreach ($buildings as $b)
                <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <x-date-input wire:model.live="from" :label="__('From')" />
        <x-date-input wire:model.live="to" :label="__('To')" />
        <div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
    </div>

    @if (count($rows) > 0)
        @php($money = fn (int $fils) => number_format($fils / 1000, 3))
        <div @class(['grid gap-4', 'lg:grid-cols-2' => count($rows) > 1])>
            <x-report-chart :chart="['type' => 'bars', 'title' => __('Result by building'), 'caption' => __('Income less head lease and expenses; red is a loss'),
                'items' => collect($rows)->sortByDesc(fn ($r) => $r['figures']['result'])->map(fn ($r) => ['label' => $r['building']->code.' — '.$r['building']->name, 'value' => $r['figures']['result'] / 1000, 'display' => $money($r['figures']['result'])])->values()->all()]" />
            @php($sum = fn (string $k) => collect($rows)->sum(fn ($r) => $r['figures'][$k]))
            <x-report-chart :chart="['type' => 'bars', 'title' => __('All buildings: income and costs'), 'caption' => __('BHD, :from to :to', ['from' => \Carbon\CarbonImmutable::parse($from)->format('d/m/Y'), 'to' => \Carbon\CarbonImmutable::parse($to)->format('d/m/Y')]),
                'items' => [
                    ['label' => __('Income'), 'value' => $sum('income') / 1000, 'display' => $money($sum('income'))],
                    ['label' => __('Head lease'), 'value' => $sum('head_lease') / 1000, 'display' => $money($sum('head_lease'))],
                    ['label' => __('Expenses'), 'value' => $sum('expenses') / 1000, 'display' => $money($sum('expenses'))],
                ]]" />
        </div>
    @endif

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
