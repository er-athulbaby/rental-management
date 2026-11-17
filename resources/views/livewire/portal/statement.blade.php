<section class="space-y-6">
    <x-ui.page-header :title="__('Statement')" />

    <x-ui.section>
        <div class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <x-date-input wire:model.live="from" :label="__('From')" />
            <x-date-input wire:model.live="to" :label="__('To')" />
            @if ($valid)
                <flux:button icon="document-arrow-down" class="min-h-11" :href="route('portal.statement.pdf', ['from' => $from, 'to' => $to])" target="_blank">{{ __('PDF') }}</flux:button>
            @endif
        </div>
    </x-ui.section>

    @if (! $valid)
        <x-ui.section>
            <x-ui.empty :title="__('Choose a valid date range.')" icon="calendar-days" />
        </x-ui.section>
    @else
        <x-ui.stats :cols="2">
            <x-ui.stat :label="__('Opening balance')" :value="__(':amount BHD', ['amount' => App\Support\Fils::grouped($receivables['opening'])])" />
            <x-ui.stat :label="__('Closing balance')" :value="__(':amount BHD', ['amount' => App\Support\Fils::grouped($receivables['closing'])])" />
        </x-ui.stats>

        <x-ui.section flush>
            <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <li class="flex justify-between gap-2 p-4">
                    <span><span class="tabular-nums">{{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }}</span> · {{ __('Opening balance') }}</span>
                    <span class="tabular-nums">{{ App\Support\Fils::grouped($receivables['opening']) }}</span>
                </li>
                @foreach ($receivables['rows'] as $i => $row)
                    <li wire:key="st-{{ $i }}" class="space-y-1 p-4">
                        <div class="flex flex-wrap justify-between gap-2">
                            <span><span class="tabular-nums">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</span> · {{ __($row['kind']) }} {{ $row['reference'] }}</span>
                            <span class="tabular-nums" dir="ltr">{{ $row['debit'] ? App\Support\Fils::grouped($row['debit']) : '−'.App\Support\Fils::grouped($row['credit']) }}</span>
                        </div>
                        <div class="text-end text-zinc-500 dark:text-zinc-400 tabular-nums">{{ __('Balance :amount', ['amount' => App\Support\Fils::grouped($row['balance'])]) }}</div>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
        <flux:text>{{ __('Closing balance :amount BHD. A negative balance is credit in your favour.', ['amount' => App\Support\Fils::grouped($receivables['closing'])]) }}</flux:text>

        @foreach ($deposits as $d => $deposit)
            <x-ui.section wire:key="dep-{{ $d }}" :title="__('Deposit — :unit', ['unit' => $deposit['unit']])" flush>
                <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    @foreach ($deposit['rows'] as $i => $row)
                        <li wire:key="dep-{{ $d }}-{{ $i }}" class="flex flex-wrap justify-between gap-2 p-4">
                            <span><span class="tabular-nums">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</span> · {{ __($row['kind']) }}</span>
                            <span class="tabular-nums">{{ App\Support\Fils::grouped($row['amount']) }} · {{ __('Held :amount', ['amount' => App\Support\Fils::grouped($row['held'])]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.section>
        @endforeach
    @endif
</section>
