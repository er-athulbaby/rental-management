<div class="space-y-6">
    <x-ui.page-header :title="__('Reports')" :hint="__('Every report filters by building and date, and exports to Excel.')" />

    @if ($canBuild)
        <x-ui.section :title="__('My reports')" :flush="$myReports->isEmpty() && $sharedReports->isEmpty()">
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" :href="route('reports.builder')" wire:navigate>{{ __('New report') }}</flux:button>
            </x-slot:actions>
            @if ($myReports->isEmpty() && $sharedReports->isEmpty())
                <x-ui.empty icon="document-chart-bar" :title="__('Build your own report: pick a subject, columns and filters, then save it here.')" />
            @else
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ([[__('Mine'), $myReports], [__('Shared with me'), $sharedReports]] as [$heading, $list])
                        @foreach ($list as $r)
                            <a href="{{ route('reports.builder', $r) }}" wire:navigate class="group flex items-start gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 transition hover:border-accent focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none dark:border-zinc-700 dark:bg-zinc-800/50">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white text-zinc-600 group-hover:bg-charcoal group-hover:text-brand-400 dark:bg-zinc-800 dark:text-zinc-300">
                                    <flux:icon name="document-chart-bar" variant="mini" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $r->name }}</span>
                                    <span class="mt-0.5 block text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ (App\Reports\Builder\Registry::all()[$r->subject] ?? null)?->label() ?? $r->subject }} · {{ $heading }}@if ($r->creator && $r->created_by !== auth()->id()) · {{ $r->creator->name }}@endif
                                        @if ($r->last_run_at) · {{ __('run :d', ['d' => $r->last_run_at->timezone('Asia/Bahrain')->format('d/m/Y')]) }}@endif
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </x-ui.section>
    @endif

    @if ($canPackage)
        <x-ui.section :title="__('Packages')" :flush="$myPackages->isEmpty() && $sharedPackages->isEmpty()">
            <x-slot:actions>
                <flux:button icon="plus" :href="route('reports.package')" wire:navigate>{{ __('New package') }}</flux:button>
            </x-slot:actions>
            @if ($myPackages->isEmpty() && $sharedPackages->isEmpty())
                <x-ui.empty icon="archive-box" :title="__('Bundle several reports into one Excel workbook, and email it on a schedule.')" />
            @else
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ([[__('Mine'), $myPackages], [__('Shared with me'), $sharedPackages]] as [$heading, $list])
                        @foreach ($list as $p)
                            <a href="{{ route('reports.package', $p) }}" wire:navigate class="group flex items-start gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 transition hover:border-accent focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none dark:border-zinc-700 dark:bg-zinc-800/50">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white text-zinc-600 group-hover:bg-charcoal group-hover:text-brand-400 dark:bg-zinc-800 dark:text-zinc-300">
                                    <flux:icon name="archive-box" variant="mini" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $p->name }}</span>
                                    <span class="mt-0.5 block text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __(':n reports', ['n' => $p->items_count]) }} · {{ $heading }}@if ($p->creator && $p->created_by !== auth()->id()) · {{ $p->creator->name }}@endif
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </x-ui.section>
    @endif

    @forelse ($groups as $group => $reports)
        <x-ui.section :title="$group">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($reports as $report)
                    <a href="{{ route($report['route']) }}" wire:navigate
                        class="group flex items-start gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 transition hover:border-accent focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none dark:border-zinc-700 dark:bg-zinc-800/50">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white text-zinc-600 group-hover:bg-charcoal group-hover:text-brand-400 dark:bg-zinc-800 dark:text-zinc-300">
                            <flux:icon :name="$report['icon']" variant="mini" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $report['label'] }}</span>
                            <span class="mt-0.5 block text-sm text-zinc-500 dark:text-zinc-400">{{ $report['about'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </x-ui.section>
    @empty
        <x-ui.section flush><x-ui.empty :title="__('You have no reports.')" /></x-ui.section>
    @endforelse
</div>
