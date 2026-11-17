<section class="space-y-6">
    <x-ui.page-header :title="__('Payments')" />

    <x-ui.section :title="__('Payouts')" flush>
        @if ($bank)
            <div class="border-b border-zinc-200 px-4 py-3 sm:px-6 dark:border-zinc-700"><flux:text>{{ $bank }}</flux:text></div>
        @endif
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($payouts as $p)
                <li wire:key="po-{{ $p->id }}" class="space-y-2 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $p->number }}</span>
                        <span class="text-sm tabular-nums">{{ $p->paid_on?->format('d/m/Y') }}</span>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2 text-sm">
                        <span class="text-zinc-600 dark:text-zinc-300">{{ implode(' · ', array_filter([__($p->method->label()), $p->reference ? __('Reference :ref', ['ref' => $p->reference]) : null])) }}</span>
                        <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($p->amount))]) }}</span>
                    </div>
                </li>
            @empty
                <li><x-ui.empty :title="__('No payouts yet.')" icon="banknotes" /></li>
            @endforelse
        </ul>
    </x-ui.section>

    @if ($payables->isNotEmpty())
        <x-ui.section :title="__('Rent schedule')" flush>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($payables as $r)
                    <li wire:key="op-{{ $r->id }}" class="space-y-1 p-4 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ __('Due :date', ['date' => $r->due_date->format('d/m/Y')]) }} · {{ $r->contract->building->name }}</span>
                            <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($r->amount))]) }}</span>
                        </div>
                        <div class="text-zinc-500 dark:text-zinc-400">{{ $r->status === App\Enums\OwnerPayableStatus::Paid ? __('Paid') : __('Not paid yet') }}</div>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif
</section>
