<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Balance owed to you') }}</flux:heading>
        @forelse ($balances as $b)
            <flux:card wire:key="bal-{{ $b['contract']->id }}" class="flex flex-wrap items-center justify-between gap-2">
                <span>{{ $b['contract']->building->name }} · {{ $b['statement']->number }}</span>
                <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($b['statement']->closing_balance))]) }}</span>
            </flux:card>
        @empty
            <flux:text>{{ __('No finalised statement yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Last payout') }}</flux:text>
            @if ($lastPayout)
                <div class="text-lg font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($lastPayout->amount))]) }}</div>
                <flux:text size="sm">{{ $lastPayout->paid_on?->format('d/m/Y') }}</flux:text>
            @else
                <flux:text>{{ __('No payouts yet.') }}</flux:text>
            @endif
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Next lease payment') }}</flux:text>
            @if ($nextPayable)
                <div class="text-lg font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($nextPayable->amount))]) }}</div>
                <flux:text size="sm">{{ __('Due :date', ['date' => $nextPayable->due_date->format('d/m/Y')]) }}</flux:text>
            @else
                <flux:text>{{ __('Nothing is due.') }}</flux:text>
            @endif
        </flux:card>
    </div>

    @if ($buildings->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg" level="2">{{ __('Occupancy') }}</flux:heading>
            @foreach ($buildings as $card)
                <div wire:key="occ-{{ $card['building']->id }}" class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $card['building']->name }}</span>
                    <span>{{ __(':occupied of :total units let', ['occupied' => $card['occupied'], 'total' => $card['total']]) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Contracts ending soon') }}</flux:heading>
        @forelse ($ending as $c)
            <div wire:key="end-{{ $c->id }}" class="flex flex-wrap justify-between gap-2 text-sm">
                <span>{{ $c->label() }} · {{ $c->building->name }}</span>
                <span>{{ __('Ends :date', ['date' => $c->end_date->format('d/m/Y')]) }}</span>
            </div>
        @empty
            <flux:text>{{ __('No contract ends in the next 90 days.') }}</flux:text>
        @endforelse
    </div>
</section>
