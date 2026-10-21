<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Expenses') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($expenses as $e)
            <li wire:key="ex-{{ $e->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ implode(' · ', array_filter([$e->expense_date->format('d/m/Y'), $e->building->name, $e->unit?->code])) }}</span>
                    @if ($e->status === App\Enums\ExpenseStatus::Reversed)
                        <x-status-badge :status="$e->status" />
                    @endif
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ __(str($e->category->value)->headline()->toString()) }} · {{ $e->description }}</span>
                    <span class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($e->total))]) }}</span>
                </div>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No expenses yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
