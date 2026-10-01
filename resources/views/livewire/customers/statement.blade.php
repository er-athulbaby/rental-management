@php($money = fn (int $fils) => App\Support\Fils::toDecimal($fils))
<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Statement — :n', ['n' => $customer->name_en]) }}</flux:heading>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input wire:model.live="from" type="date" :label="__('From')" />
            <flux:input wire:model.live="to" type="date" :label="__('To')" />
            @if ($valid)
                <flux:button icon="document-arrow-down" :href="route('customers.statement.pdf', ['customer' => $customer, 'from' => $from, 'to' => $to])" target="_blank">{{ __('PDF') }}</flux:button>
            @endif
        </div>
    </div>

    @if (! $valid)
        <flux:text>{{ __('Choose a valid date range.') }}</flux:text>
    @else
        <flux:heading size="lg">{{ __('Account') }}</flux:heading>
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Entry') }}</flux:table.column>
                    <flux:table.column>{{ __('Debit') }}</flux:table.column>
                    <flux:table.column>{{ __('Credit') }}</flux:table.column>
                    <flux:table.column>{{ __('Balance') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    <flux:table.row>
                        <flux:table.cell>{{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ __('Opening balance') }}</flux:table.cell>
                        <flux:table.cell></flux:table.cell><flux:table.cell></flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $money($receivables['opening']) }}</flux:table.cell>
                    </flux:table.row>
                    @foreach ($receivables['rows'] as $i => $row)
                        <flux:table.row :key="'r'.$i">
                            <flux:table.cell class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</flux:table.cell>
                            <flux:table.cell>{{ $row['kind'] }} <flux:link :href="$row['url']" wire:navigate>{{ $row['reference'] }}</flux:link></flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $row['debit'] ? $money($row['debit']) : '' }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $row['credit'] ? $money($row['credit']) : '' }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $money($row['balance']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
        <flux:text>{{ __('Closing balance :b BHD (negative means the customer is in credit).', ['b' => $money($receivables['closing'])]) }}</flux:text>

        @foreach ($deposits as $d => $deposit)
            <flux:heading size="lg" wire:key="dep-{{ $d }}">{{ __('Deposit — :u', ['u' => $deposit['unit']]) }}</flux:heading>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Movement') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Held') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($deposit['rows'] as $i => $row)
                            <flux:table.row :key="'d'.$d.'-'.$i">
                                <flux:table.cell>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell>{{ $row['kind'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $money($row['amount']) }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $money($row['held']) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endforeach
    @endif
</section>
