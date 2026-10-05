<div class="space-y-8">
    <div>
        <flux:heading size="xl">{{ __('Reports') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every report filters by building and date, and exports to Excel.') }}</flux:text>
    </div>

    @forelse ($groups as $group => $reports)
        <section class="space-y-3">
            <flux:heading size="lg">{{ $group }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($reports as $report)
                    <a href="{{ route($report['route']) }}" wire:navigate
                        class="group flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 group-hover:bg-zinc-800 group-hover:text-white dark:bg-zinc-800 dark:text-zinc-300 dark:group-hover:bg-white dark:group-hover:text-zinc-900">
                            <flux:icon :name="$report['icon']" variant="mini" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $report['label'] }}</span>
                            <span class="mt-0.5 block text-sm text-zinc-500">{{ $report['about'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <flux:text>{{ __('You have no reports.') }}</flux:text>
    @endforelse
</div>
