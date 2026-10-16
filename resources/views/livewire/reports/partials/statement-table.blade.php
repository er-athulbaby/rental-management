{{-- An income statement: one row per line, one column per month, then Total. A null amount is a fee not yet final; $statement['fee_pending'] (owner statements only) lists the months whose fee is still pending. --}}
<div class="overflow-x-auto">
    <flux:table>
        <flux:table.columns>
            <flux:table.column scope="col">{{ __('Line') }}</flux:table.column>
            @foreach ($statement['months'] as $m)
                <flux:table.column scope="col" align="end">{{ \Carbon\CarbonImmutable::parse($m.'-01')->format('m/Y') }}</flux:table.column>
            @endforeach
            <flux:table.column scope="col" align="end">{{ __('Total') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($statement['rows'] as $key => $row)
                <flux:table.row :key="$key" :class="$row['kind'] === 'total' ? 'font-semibold border-t-2 border-zinc-300 dark:border-zinc-600' : ($row['kind'] === 'memo' ? 'italic text-zinc-500' : '')">
                    <th scope="row" @if ($row['kind'] !== 'line') aria-label="{{ $row['label'] }} ({{ $row['kind'] === 'total' ? __('total') : __('memo') }})" @endif class="py-3 px-3 first:ps-0 text-start text-sm font-[inherit] whitespace-nowrap">{{ $row['label'] }}</th>
                    @foreach ($row['amounts'] as $month => $amount)
                        <flux:table.cell class="text-end tabular-nums">{{ $amount === null ? '–' : \App\Support\Fils::toDecimal($amount) }}{{ $key === 'fee' && $amount !== null && in_array($month, $statement['fee_pending'] ?? [], true) ? ' *' : '' }}</flux:table.cell>
                    @endforeach
                    <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($row['total']) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
@if (($statement['fee_pending'] ?? []) !== [])
    @php
        $feeCells = $statement['rows']['fee']['amounts'] ?? [];
        $glyphs = collect([in_array(null, $feeCells, true) ? '–' : null, collect($feeCells)->contains(fn ($a, $m) => $a !== null && in_array($m, $statement['fee_pending'], true)) ? '*' : null])->filter()->implode(' / ');
    @endphp
    <flux:text class="text-sm">{{ $glyphs }} {{ __('Fee not final until the owner statement for this month is finalised.') }}</flux:text>
@endif
