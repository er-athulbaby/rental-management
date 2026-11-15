@props(['cols' => 4])
{{-- Design pass §3: the summary figures of a page; two per row on phones. --}}
<div {{ $attributes->class([
    'grid gap-3 sm:gap-4',
    match ((int) $cols) {
        2 => 'grid-cols-2',
        3 => 'grid-cols-1 sm:grid-cols-3',
        5 => 'grid-cols-2 lg:grid-cols-5',
        default => 'grid-cols-2 lg:grid-cols-4',
    },
]) }} data-ui-stats>
    {{ $slot }}
</div>
