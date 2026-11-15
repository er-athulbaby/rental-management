@props(['clear' => null])
{{-- Design pass §3: one row of filters. $clear is the page's URL without filters, given only when a filter is set (plan ruling 3). --}}
<div {{ $attributes->class('flex flex-col gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 sm:flex-row sm:flex-wrap sm:items-end dark:border-zinc-700 dark:bg-zinc-900') }} data-ui-filters>
    {{ $slot }}
    @if ($clear)
        <flux:button variant="ghost" size="sm" icon="x-mark" :href="$clear" wire:navigate class="sm:ms-auto">{{ __('Clear filters') }}</flux:button>
    @endif
</div>
