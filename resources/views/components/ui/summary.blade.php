@props(['title', 'status' => null, 'subtitle' => null, 'back' => null])
{{-- Design pass §3/§7: the header card of a record page: title (the h1), status, primary actions, key facts. --}}
<div {{ $attributes->class('rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900') }} data-ui-summary>
    <div class="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-6">
        <div class="min-w-0 space-y-1">
            @if ($back)
                <a href="{{ $back }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-accent-content dark:text-zinc-400">
                    <flux:icon.chevron-left variant="micro" class="rtl:rotate-180" />{{ __('Back') }}
                </a>
            @endif
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
                @if (filled($status))
                    <x-status-badge :status="$status" />
                @endif
            </div>
            @if (filled($subtitle))
                <flux:text>{{ $subtitle }}</flux:text>
            @endif
            {{ $meta ?? '' }}
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
    @isset($facts)
        <dl class="grid gap-x-6 gap-y-4 border-t border-zinc-200 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-3 dark:border-zinc-700">{{ $facts }}</dl>
    @endisset
</div>
