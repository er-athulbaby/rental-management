<section class="space-y-6">
    <x-ui.page-header :title="__('Statements')" />
    <ul class="space-y-3">
        @forelse ($statements as $row)
            <li wire:key="os-{{ $row['statement']->id }}" class="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="space-y-1">
                    <div class="font-medium">{{ $row['statement']->number }} · {{ $row['statement']->contract->building->name }}</div>
                    <div class="text-sm text-zinc-600 tabular-nums dark:text-zinc-300">{{ $row['statement']->period_start->format('d/m/Y') }} – {{ $row['statement']->period_end->format('d/m/Y') }}</div>
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg bg-zinc-50 p-3 text-sm sm:grid-cols-3 dark:bg-zinc-800/60">
                    @foreach (['opening' => __('Opening balance'), 'income' => __('Income'), 'charges' => __('Charges'), 'fees' => __('Fees'), 'paid' => __('Paid to you'), 'closing' => __('Closing balance')] as $key => $label)
                        <div>
                            <dt class="text-zinc-500">{{ $label }}</dt>
                            <dd @class(['tabular-nums', 'font-semibold' => $key === 'closing'])>{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($row[$key])]) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <flux:link :href="route('portal.owner.statements.pdf', $row['statement'])" class="inline-flex min-h-11 items-center text-sm">{{ __('Statement PDF') }}</flux:link>
            </li>
        @empty
            <li class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><x-ui.empty :title="__('No statements yet.')" icon="document-text" /></li>
        @endforelse
    </ul>
</section>
