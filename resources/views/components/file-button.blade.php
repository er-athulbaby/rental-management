@props(['label' => __('Attach file')])
@php($model = $attributes->wire('model')->value())

{{-- A labelled button instead of the browser's "Choose file"; the name clears when Livewire resets the field after saving. --}}
<div x-data="{ name: '' }" class="flex min-w-0 items-center gap-3">
    <label class="inline-flex shrink-0 cursor-pointer items-center gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-800 shadow-xs hover:bg-zinc-50 focus-within:ring-2 focus-within:ring-zinc-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-white dark:hover:bg-zinc-600">
        <flux:icon.paper-clip variant="micro" />
        {{ $label }}
        <input type="file" {{ $attributes }} class="sr-only" x-on:change="name = $event.target.files[0]?.name ?? ''" />
    </label>
    <span wire:loading wire:target="{{ $model }}" class="text-sm text-zinc-500">{{ __('Uploading…') }}</span>
    <span wire:loading.remove wire:target="{{ $model }}" class="truncate text-sm text-zinc-600 dark:text-zinc-300"
        x-text="$wire.$get(@js($model)) ? name : @js(__('No file chosen'))"></span>
</div>
