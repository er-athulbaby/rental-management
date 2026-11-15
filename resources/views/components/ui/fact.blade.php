@props(['label'])
<div {{ $attributes->class('min-w-0') }}>
    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
    <dd class="mt-0.5 font-medium text-zinc-900 tabular-nums dark:text-white">{{ $slot }}</dd>
</div>
