<section class="space-y-6">
    <x-ui.page-header :title="__('Expenses')" />
    <ul class="space-y-3">
        @forelse ($expenses as $e)
            <li wire:key="ex-{{ $e->id }}" class="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ implode(' · ', array_filter([$e->expense_date->format('d/m/Y'), $e->building->name, $e->unit?->code])) }}</span>
                    @if ($e->status === App\Enums\ExpenseStatus::Reversed)
                        <x-status-badge :status="$e->status" />
                    @endif
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span class="text-zinc-600 dark:text-zinc-300">{{ __(str($e->category->value)->headline()->toString()) }} · {{ $e->description }}</span>
                    <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($e->total))]) }}</span>
                </div>
            </li>
        @empty
            <li class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><x-ui.empty :title="__('No expenses yet.')" icon="receipt-percent" /></li>
        @endforelse
    </ul>
</section>
