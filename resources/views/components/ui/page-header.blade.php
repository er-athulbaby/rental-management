@props(['title', 'hint' => null, 'back' => null, 'backLabel' => null])
{{-- Design pass §3: the page's h1, an optional one-line hint and back link, and the page's actions at the end. --}}
<div {{ $attributes->class('flex flex-wrap items-start justify-between gap-x-4 gap-y-3') }} data-ui-page-header>
    <div class="min-w-0 space-y-1">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-accent-content dark:text-zinc-400">
                <flux:icon.chevron-left variant="micro" class="rtl:rotate-180" />{{ $backLabel ?? __('Back') }}
            </a>
        @endif
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
        @if (filled($hint))
            <flux:text>{{ $hint }}</flux:text>
        @endif
        {{ $meta ?? '' }}
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
