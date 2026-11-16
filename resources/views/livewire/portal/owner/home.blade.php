<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>
    <x-custom-fields.display :items="$details" file-route="portal.owner.custom-fields.file" />

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Balance owed to you') }}</flux:heading>
        @if ($balances->isEmpty())
            <flux:text>{{ __('No finalised statement yet.') }}</flux:text>
        @else
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
        @foreach ($balances as $b)
            <li wire:key="bal-{{ $b['contract']->id }}" class="flex flex-col gap-1 p-3 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
                <span>{{ $b['contract']->building->name }} · {{ $b['statement']->number }}</span>
                <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($b['statement']->closing_balance))]) }}</span>
            </li>
        @endforeach
        </ul>
        @endif
    </div>

    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Last payout')" :value="$lastPayout ? __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($lastPayout->amount))]) : __('No payouts yet.')" :hint="$lastPayout?->paid_on?->format('d/m/Y')" />
        <x-ui.stat :label="__('Next lease payment')" :value="$nextPayable ? __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($nextPayable->amount))]) : __('Nothing is due.')" :hint="$nextPayable ? __('Due :date', ['date' => $nextPayable->due_date->format('d/m/Y')]) : null" />
    </x-ui.stats>

    @if ($buildings->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg" level="2">{{ __('Occupancy') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
            @foreach ($buildings as $card)
                <li wire:key="occ-{{ $card['building']->id }}" class="flex flex-col gap-1 p-3 text-sm sm:flex-row sm:justify-between sm:gap-2">
                    <span>{{ $card['building']->name }}</span>
                    <span>{{ __(':occupied of :total units let', ['occupied' => $card['occupied'], 'total' => $card['total']]) }}</span>
                </li>
            @endforeach
            </ul>
        </div>
    @endif

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Contracts ending soon') }}</flux:heading>
        @if ($ending->isEmpty())
            <flux:text>{{ __('No contract ends in the next 90 days.') }}</flux:text>
        @else
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
        @foreach ($ending as $c)
            <li wire:key="end-{{ $c->id }}" class="flex flex-col gap-1 p-3 text-sm sm:flex-row sm:justify-between sm:gap-2">
                <span>{{ $c->label() }} · {{ $c->building->name }}</span>
                <span>{{ __('Ends :date', ['date' => $c->end_date->format('d/m/Y')]) }}</span>
            </li>
        @endforeach
        </ul>
        @endif
    </div>
</section>
