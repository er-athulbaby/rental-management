{{-- An income statement: one row per line, one column per month, then Total. A null amount is a fee not yet final. --}}
<div class="overflow-x-auto">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Line') }}</flux:table.column>
            @foreach ($statement['months'] as $m)
                <flux:table.column align="end">{{ \Carbon\CarbonImmutable::parse($m.'-01')->format('m/Y') }}</flux:table.column>
            @endforeach
            <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($statement['rows'] as $key => $row)
                <flux:table.row :key="$key" :class="$row['kind'] === 'total' ? 'font-semibold' : ($row['kind'] === 'memo' ? 'italic text-zinc-500' : '')">
                    <flux:table.cell>{{ $row['label'] }}</flux:table.cell>
                    @foreach ($row['amounts'] as $amount)
                        <flux:table.cell class="text-end tabular-nums">{{ $amount === null ? '–' : \App\Support\Fils::toDecimal($amount) }}</flux:table.cell>
                    @endforeach
                    <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($row['total']) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
@if (collect($statement['rows'])->contains(fn (array $r) => in_array(null, $r['amounts'], true)))
    <flux:text class="text-sm">– {{ __('Fee not final until the owner statement for this month is finalised.') }}</flux:text>
@endif
