@props(['label', 'value', 'tone' => null, 'hint' => null, 'href' => null])
{{-- Design pass §3: one figure. The tone colours the value; the label always says what it is (never colour alone). --}}
@php
    $valueClass = match ($tone) {
        'danger' => 'text-red-700 dark:text-red-400',
        'warning' => 'text-amber-700 dark:text-amber-400',
        'success' => 'text-green-700 dark:text-green-400',
        default => 'text-zinc-900 dark:text-white',
    };
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'block min-w-0 rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900',
        'transition hover:border-accent' => $href,
    ]) }} data-ui-stat data-tone="{{ $tone ?? 'neutral' }}">
    <span class="block truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
    <span class="mt-1 block break-words text-xl font-semibold tabular-nums sm:text-2xl {{ $valueClass }}">{{ $value }}</span>
    @if (filled($hint))
        <span class="mt-0.5 block truncate text-xs text-zinc-500 tabular-nums dark:text-zinc-400">{{ $hint }}</span>
    @endif
</{{ $tag }}>
