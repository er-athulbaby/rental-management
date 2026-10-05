@props(['chart'])
{{--
    One chart from a report's chart() spec, drawn in HTML/CSS (no chart library). The report's table stays below as the exact view.
    type: bars    – a ranked list, one bar per item (magnitude), value printed at the end
          columns – values over time, hover or focus a column for its figure
          stack   – one bar split into parts (share of a whole), with a legend
    items: [label, value (number, for geometry only), display (the formatted figure), color (a --viz-* role), url?]
--}}
@php
    $items = collect($chart['items'] ?? []);
    $max = $chart['max'] ?? max(1e-9, (float) $items->max(fn ($i) => abs((float) $i['value'])));
    $total = (float) $items->sum(fn ($i) => max(0, (float) $i['value']));
    $pct = fn (float $v, float $of) => $of > 0 ? round(100 * $v / $of, 2) : 0;
    $summary = $items->map(fn ($i) => $i['label'].': '.$i['display'])->implode('; ');
@endphp

<figure class="viz min-w-0 space-y-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
    <figcaption class="flex flex-wrap items-baseline justify-between gap-2">
        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ $chart['title'] }}</span>
        @isset($chart['caption'])<span class="text-xs text-zinc-500">{{ $chart['caption'] }}</span>@endisset
    </figcaption>

    @if ($items->isEmpty() || $total <= 0 && ($chart['type'] ?? '') !== 'bars')
        <p class="py-6 text-center text-sm text-zinc-500">{{ __('Nothing to chart yet.') }}</p>
    @elseif ($chart['type'] === 'bars')
        <ul class="space-y-2" role="img" aria-label="{{ $chart['title'] }}: {{ $summary }}">
            @foreach ($items as $item)
                @php($v = (float) $item['value'])
                <li class="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 text-sm sm:grid-cols-[minmax(0,13rem)_1fr_auto]" aria-hidden="true">
                    <span class="truncate text-zinc-600 dark:text-zinc-300" title="{{ $item['label'] }}">
                        @if (! empty($item['url']))<a href="{{ $item['url'] }}" wire:navigate class="hover:underline" tabindex="-1">{{ $item['label'] }}</a>@else{{ $item['label'] }}@endif
                    </span>
                    <span class="h-2.5 rounded-full" style="background: var(--viz-track)">
                        <span class="block h-full rounded-full" style="width: {{ $v != 0 ? max(1, $pct(abs($v), $max)) : 0 }}%; background: var({{ $v < 0 ? '--viz-neg' : ($item['color'] ?? '--viz-1') }})"></span>
                    </span>
                    <span class="text-end font-medium text-zinc-800 tabular-nums dark:text-white">{{ $item['display'] }}</span>
                </li>
            @endforeach
        </ul>
    @elseif ($chart['type'] === 'columns')
        @php($every = max(1, (int) ceil($items->count() / 8)))
        <div role="img" aria-label="{{ $chart['title'] }}: {{ $summary }}">
            <div class="flex h-40 items-end gap-0.5 border-b" style="border-color: var(--viz-grid)" aria-hidden="true">
                @foreach ($items as $item)
                    @php($v = max(0, (float) $item['value']))
                    <div class="group relative flex h-full min-w-0 flex-1 items-end" tabindex="0">
                        <div class="mx-auto w-full max-w-10 rounded-t transition-opacity group-hover:opacity-80" style="height: {{ $v > 0 ? max(1.5, $pct($v, $max)) : 0 }}%; background: var({{ $item['color'] ?? '--viz-1' }})"></div>
                        <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded-md bg-zinc-900 px-2 py-1 text-xs whitespace-nowrap text-white shadow group-hover:block group-focus:block dark:bg-white dark:text-zinc-900">
                            {{ $item['label'] }} · <span class="font-medium tabular-nums">{{ $item['display'] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex gap-0.5 text-[11px] text-zinc-500" aria-hidden="true">
                @foreach ($items as $i => $item)
                    <span class="min-w-0 flex-1 truncate text-center">{{ $i % $every === 0 ? ($item['short'] ?? $item['label']) : '' }}</span>
                @endforeach
            </div>
        </div>
    @else {{-- stack --}}
        <div class="flex h-3 gap-0.5 overflow-hidden rounded-full" role="img" aria-label="{{ $chart['title'] }}: {{ $summary }}">
            @foreach ($items as $item)
                @php($v = max(0, (float) $item['value']))
                @if ($v > 0)
                    <span class="h-full first:rounded-s-full last:rounded-e-full" style="width: {{ $pct($v, $total) }}%; background: var({{ $item['color'] ?? '--viz-1' }})" title="{{ $item['label'] }}: {{ $item['display'] }}"></span>
                @endif
            @endforeach
        </div>
        <ul class="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2" aria-hidden="true">
            @foreach ($items as $item)
                <li class="flex items-center gap-2">
                    <span class="size-2.5 shrink-0 rounded-sm" style="background: var({{ $item['color'] ?? '--viz-1' }})"></span>
                    <span class="truncate text-zinc-600 dark:text-zinc-300">{{ $item['label'] }}</span>
                    <span class="ms-auto font-medium text-zinc-800 tabular-nums dark:text-white">{{ $item['display'] }}</span>
                    <span class="w-12 text-end text-xs text-zinc-500 tabular-nums">{{ number_format($pct(max(0, (float) $item['value']), $total), 0) }}%</span>
                </li>
            @endforeach
        </ul>
    @endif
</figure>
