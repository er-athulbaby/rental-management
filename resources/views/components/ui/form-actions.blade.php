@props(['cancel' => null])
{{-- Design pass §6: the Save bar of a long form stays in view at the bottom of the screen. --}}
<div {{ $attributes->class('sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-2 rounded-xl border border-zinc-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95') }} data-ui-form-actions>
    @if ($cancel)
        <flux:button variant="ghost" :href="$cancel" wire:navigate>{{ __('Cancel') }}</flux:button>
    @endif
    {{ $slot }}
</div>
