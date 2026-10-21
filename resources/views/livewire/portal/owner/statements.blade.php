<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Statements') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($statements as $row)
            <li wire:key="os-{{ $row['statement']->id }}" class="space-y-2 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $row['statement']->number }} · {{ $row['statement']->contract->building->name }}</span>
                    <span class="text-sm">{{ $row['statement']->period_start->format('d/m/Y') }} – {{ $row['statement']->period_end->format('d/m/Y') }}</span>
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                    @foreach (['opening' => __('Opening balance'), 'income' => __('Income'), 'charges' => __('Charges'), 'fees' => __('Fees'), 'paid' => __('Paid to you'), 'closing' => __('Closing balance')] as $key => $label)
                        <div>
                            <dt class="text-zinc-500">{{ $label }}</dt>
                            <dd class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($row[$key])]) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <flux:link :href="route('portal.owner.statements.pdf', $row['statement'])" class="text-sm">{{ __('Statement PDF') }}</flux:link>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No statements yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
