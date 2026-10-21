<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Payments') }}</flux:heading>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Payouts') }}</flux:heading>
        @if ($bank)
            <flux:text>{{ $bank }}</flux:text>
        @endif
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
            @forelse ($payouts as $p)
                <li wire:key="po-{{ $p->id }}" class="space-y-1 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $p->number }}</span>
                        <span class="text-sm">{{ $p->paid_on?->format('d/m/Y') }}</span>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2 text-sm">
                        <span>{{ implode(' · ', array_filter([__($p->method->label()), $p->reference ? __('Reference :ref', ['ref' => $p->reference]) : null])) }}</span>
                        <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($p->amount))]) }}</span>
                    </div>
                </li>
            @empty
                <li class="p-4"><flux:text>{{ __('No payouts yet.') }}</flux:text></li>
            @endforelse
        </ul>
    </div>

    @if ($payables->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg" level="2">{{ __('Rent schedule') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                @foreach ($payables as $r)
                    <li wire:key="op-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm">
                        <span>{{ __('Due :date', ['date' => $r->due_date->format('d/m/Y')]) }} · {{ $r->contract->building->name }}</span>
                        <span class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($r->amount))]) }}</span>
                        <span>{{ $r->status === App\Enums\OwnerPayableStatus::Paid ? __('Paid') : __('Not paid yet') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
