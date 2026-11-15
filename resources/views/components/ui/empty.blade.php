@props(['title' => null, 'icon' => 'inbox', 'filtered' => false])
{{-- Design pass §3: an empty list says so in one line; the slot holds an optional action. --}}
<div {{ $attributes->class('flex flex-col items-center gap-2 px-6 py-10 text-center') }} data-ui-empty>
    <flux:icon :icon="$icon" class="size-8 text-zinc-400 dark:text-zinc-500" />
    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $title ?? __('Nothing to show here.') }}</p>
    @if ($filtered)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Try clearing the filters.') }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-2 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
