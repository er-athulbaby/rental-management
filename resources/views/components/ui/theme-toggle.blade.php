@props(['compact' => false])
{{-- Light / dark / system, one click away (the same $flux.appearance the Appearance settings page sets). Sits on charcoal. --}}
@if ($compact)
    <button type="button" x-data x-on:click="$flux.appearance = $flux.dark ? 'light' : 'dark'" aria-label="{{ __('Switch between light and dark') }}"
        {{ $attributes->class('inline-flex size-11 items-center justify-center rounded-lg text-zinc-300 hover:bg-charcoal-raised hover:text-white') }} data-ui-theme-toggle>
        <span x-show="! $flux.dark"><flux:icon.moon variant="mini" /></span>
        <span x-show="$flux.dark" style="display: none"><flux:icon.sun variant="mini" /></span>
    </button>
@else
    <div x-data role="group" aria-label="{{ __('Theme') }}" {{ $attributes->class('flex gap-0.5 rounded-lg bg-charcoal-raised p-1 text-xs') }} data-ui-theme-toggle>
        @foreach (['light' => ['sun', __('Light')], 'dark' => ['moon', __('Dark')], 'system' => ['computer-desktop', __('System')]] as $mode => [$icon, $label])
            <button type="button" x-on:click="$flux.appearance = '{{ $mode }}'" x-bind:aria-pressed="$flux.appearance === '{{ $mode }}'"
                x-bind:class="$flux.appearance === '{{ $mode }}' ? 'bg-charcoal text-brand-400' : 'text-zinc-400 hover:text-white'"
                class="flex min-h-8 flex-1 items-center justify-center gap-1 rounded-md px-1 font-medium">
                <flux:icon :name="$icon" variant="micro" />
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>
@endif
