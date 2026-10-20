<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Statement') }}</flux:heading>

    <div class="flex flex-wrap items-end gap-2">
        <x-date-input wire:model.live="from" :label="__('From')" />
        <x-date-input wire:model.live="to" :label="__('To')" />
        @if ($valid)
            <flux:button icon="document-arrow-down" :href="route('portal.statement.pdf', ['from' => $from, 'to' => $to])" target="_blank">{{ __('PDF') }}</flux:button>
        @endif
    </div>

    @if (! $valid)
        <flux:text>{{ __('Choose a valid date range.') }}</flux:text>
    @else
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white text-sm dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
            <li class="flex justify-between gap-2 p-3">
                <span>{{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }} · {{ __('Opening balance') }}</span>
                <span class="tabular-nums">{{ App\Support\Fils::grouped($receivables['opening']) }}</span>
            </li>
            @foreach ($receivables['rows'] as $i => $row)
                <li wire:key="st-{{ $i }}" class="space-y-1 p-3">
                    <div class="flex flex-wrap justify-between gap-2">
                        <span>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }} · {{ __($row['kind']) }} {{ $row['reference'] }}</span>
                        <span class="tabular-nums">{{ $row['debit'] ? App\Support\Fils::grouped($row['debit']) : '−'.App\Support\Fils::grouped($row['credit']) }}</span>
                    </div>
                    <div class="text-end text-zinc-500 tabular-nums">{{ __('Balance :amount', ['amount' => App\Support\Fils::grouped($row['balance'])]) }}</div>
                </li>
            @endforeach
        </ul>
        <flux:text>{{ __('Closing balance :amount BHD. A negative balance is credit in your favour.', ['amount' => App\Support\Fils::grouped($receivables['closing'])]) }}</flux:text>

        @foreach ($deposits as $d => $deposit)
            <div wire:key="dep-{{ $d }}" class="space-y-2">
                <flux:heading size="lg" level="2">{{ __('Deposit — :unit', ['unit' => $deposit['unit']]) }}</flux:heading>
                <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white text-sm dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($deposit['rows'] as $i => $row)
                        <li wire:key="dep-{{ $d }}-{{ $i }}" class="flex flex-wrap justify-between gap-2 p-3">
                            <span>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }} · {{ __($row['kind']) }}</span>
                            <span class="tabular-nums">{{ App\Support\Fils::grouped($row['amount']) }} · {{ __('Held :amount', ['amount' => App\Support\Fils::grouped($row['held'])]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif
</section>
