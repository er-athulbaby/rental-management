<div class="space-y-6">
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
    @if ($tiles === [])
        <flux:text>{{ __('Welcome. Use the menu to get started.') }}</flux:text>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($tiles as $tile)
                <a href="{{ $tile['url'] }}" wire:navigate
                    class="group block rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
                    <span class="flex items-center justify-between gap-2 text-sm text-zinc-500">
                        {{ $tile['label'] }}
                        <flux:icon.arrow-up-right variant="micro" class="opacity-0 transition group-hover:opacity-100" />
                    </span>
                    <span class="mt-2 block text-2xl font-semibold text-zinc-900 dark:text-white">{{ $tile['value'] }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($collections || $occupancy)
        <div @class(['grid gap-4', 'lg:grid-cols-3' => $collections && $occupancy])>
            @if ($collections)
                <div class="lg:col-span-2"><x-report-chart :chart="$collections" /></div>
            @endif
            @if ($occupancy)
                <figure class="viz flex flex-col rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <figcaption class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Occupancy today') }}</span>
                        <flux:link :href="route('reports.occupancy')" wire:navigate class="text-xs">{{ __('By building') }}</flux:link>
                    </figcaption>
                    <div class="flex flex-1 items-center justify-center gap-6 py-4">
                        <div class="relative grid place-items-center">
                            <progress class="viz-ring" max="100" value="{{ $occupancy['percent'] }}" style="--value: {{ $occupancy['percent'] }}" aria-label="{{ __('Occupancy') }}"></progress>
                            <span class="absolute text-xl font-semibold text-zinc-900 dark:text-white">{{ $occupancy['units'] > 0 ? number_format($occupancy['percent'], 1).'%' : '—' }}</span>
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex items-center gap-2"><span class="size-2.5 rounded-sm" style="background: var(--viz-1)"></span><dt class="text-zinc-500">{{ __('Occupied') }}</dt><dd class="ms-auto ps-3 font-medium text-zinc-900 dark:text-white">{{ $occupancy['occupied'] }}</dd></div>
                            <div class="flex items-center gap-2"><span class="size-2.5 rounded-sm" style="background: var(--viz-track)"></span><dt class="text-zinc-500">{{ __('Vacant') }}</dt><dd class="ms-auto ps-3 font-medium text-zinc-900 dark:text-white">{{ $occupancy['units'] - $occupancy['occupied'] }}</dd></div>
                        </dl>
                    </div>
                    <p class="text-xs text-zinc-500">{{ __('Of :n units that can be let (blocked units left out).', ['n' => $occupancy['units']]) }}</p>
                </figure>
            @endif
        </div>
    @endif
</div>
