<div class="space-y-6">
    <x-ui.page-header :title="__('Dashboard')"
        :hint="__('Hello, :name', ['name' => auth()->user()->name]).' · '.now('Asia/Bahrain')->format('l d/m/Y')" />

    @if ($tiles === [])
        <flux:text>{{ __('Welcome. Use the menu to get started.') }}</flux:text>
    @else
        <x-ui.stats>
            @foreach ($tiles as $tile)
                <x-ui.stat :label="$tile['label']" :href="$tile['url']" :tone="$tile['tone'] ?? null"
                    :value="preg_match('/^-?\d+\.\d{3}$/', $tile['value']) ? number_format((float) $tile['value'], 3) : $tile['value']" />
            @endforeach
        </x-ui.stats>
    @endif

    @if ($tiles !== [] || $attention !== [])
        <x-ui.section :title="__('Needs attention')">
            @if ($attention === [])
                <flux:text>{{ __('Nothing needs attention.') }}</flux:text>
            @else
                <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($attention as $item)
                        <li wire:key="attention-{{ $loop->index }}">
                            <a href="{{ $item['url'] }}" wire:navigate class="flex items-center justify-between gap-3 py-2 text-sm hover:text-accent-content">
                                <span>{{ $item['label'] }}</span>
                                <span class="flex items-center gap-1 font-semibold tabular-nums">
                                    {{ $item['count'] }}
                                    <flux:icon.chevron-right variant="micro" class="text-zinc-400 dark:text-zinc-500 rtl:rotate-180" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.section>
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

    @if ($urgent)
        <x-ui.section :title="__('Urgent maintenance')">
            <x-slot:actions>
                <flux:link :href="route('maintenance.requests.index', ['priority' => 'urgent', 'tab' => 'open'])" wire:navigate class="text-xs">{{ __('Urgent: :n', ['n' => $urgent['count']]) }}</flux:link>
            </x-slot:actions>
            @if ($urgent['requests']->isEmpty())
                <flux:text class="text-sm">{{ __('No urgent requests.') }}</flux:text>
            @else
                <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($urgent['requests'] as $r)
                        <li wire:key="urgent-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span><flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link> · {{ $r->place() }} · {{ $r->summary() }}</span>
                            <span class="text-zinc-500">{{ $r->created_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.section>
    @endif
</div>
