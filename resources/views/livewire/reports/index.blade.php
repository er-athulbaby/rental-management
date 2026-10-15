<div class="space-y-8">
    <div>
        <flux:heading size="xl">{{ __('Reports') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every report filters by building and date, and exports to Excel.') }}</flux:text>
    </div>

    @if ($canBuild)
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('My reports') }}</flux:heading>
                <flux:button variant="primary" icon="plus" :href="route('reports.builder')" wire:navigate>{{ __('New report') }}</flux:button>
            </div>
            @if ($myReports->isEmpty() && $sharedReports->isEmpty())
                <flux:text>{{ __('Build your own report: pick a subject, columns and filters, then save it here.') }}</flux:text>
            @else
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ([[__('Mine'), $myReports], [__('Shared with me'), $sharedReports]] as [$heading, $list])
                        @foreach ($list as $r)
                            <a href="{{ route('reports.builder', $r) }}" wire:navigate class="block rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:outline-none dark:border-zinc-700 dark:bg-zinc-900">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $r->name }}</span>
                                <span class="mt-0.5 block text-sm text-zinc-500">
                                    {{ (App\Reports\Builder\Registry::all()[$r->subject] ?? null)?->label() ?? $r->subject }} · {{ $heading }}@if ($r->creator && $r->created_by !== auth()->id()) · {{ $r->creator->name }}@endif
                                    @if ($r->last_run_at) · {{ __('run :d', ['d' => $r->last_run_at->timezone('Asia/Bahrain')->format('d/m/Y')]) }}@endif
                                </span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </section>
    @endif

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
