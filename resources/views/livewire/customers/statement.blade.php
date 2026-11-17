@php($money = fn (int $fils) => App\Support\Fils::toDecimal($fils))
<section class="w-full max-w-4xl space-y-6">
    <x-ui.page-header :title="__('Statement — :n', ['n' => $customer->name_en])" :back="route('customers.edit', $customer)">
        <x-slot:actions>
            @if ($valid)
                <flux:button icon="document-arrow-down" :href="route('customers.statement.pdf', ['customer' => $customer, 'from' => $from, 'to' => $to])" target="_blank">{{ __('PDF') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters>
        <x-date-input wire:model.live="from" :label="__('From')" />
        <x-date-input wire:model.live="to" :label="__('To')" />
    </x-ui.filters>

    @if (! $valid)
        <x-ui.section><flux:text>{{ __('Choose a valid date range.') }}</flux:text></x-ui.section>
    @else
        <x-ui.stats :cols="2">
            <x-ui.stat :label="__('Opening balance')" :value="__(':amount BHD', ['amount' => $money($receivables['opening'])])" />
            <x-ui.stat :label="__('Closing balance')" :value="__(':amount BHD', ['amount' => $money($receivables['closing'])])" :tone="$receivables['closing'] > 0 ? 'danger' : null" />
        </x-ui.stats>

        <x-ui.section :title="__('Account')" flush>
        <x-ui.table>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Entry') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Debit') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Credit') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Balance') }}</flux:table.column>
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
                            <flux:table.cell>{{ $row['kind'] }} @if ($row['url'])<flux:link :href="$row['url']" wire:navigate>{{ $row['reference'] }}</flux:link>@else{{ $row['reference'] }}@endif</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $row['debit'] ? $money($row['debit']) : '' }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $row['credit'] ? $money($row['credit']) : '' }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $money($row['balance']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-ui.table>
        <p class="border-t border-zinc-200 bg-zinc-50 px-4 py-3 text-sm tabular-nums text-zinc-600 sm:px-6 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">{{ __('Closing balance :b BHD (negative means the tenant is in credit).', ['b' => $money($receivables['closing'])]) }}</p>
        </x-ui.section>

        @foreach ($deposits as $d => $deposit)
            <x-ui.section :title="__('Deposit — :u', ['u' => $deposit['unit']])" flush wire:key="dep-{{ $d }}">
            <x-ui.table>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Movement') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Held') }}</flux:table.column>
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
            </x-ui.table>
            </x-ui.section>
        @endforeach
    @endif
</section>
