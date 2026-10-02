<div class="space-y-4">
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
    @if ($tiles === [])
        <flux:text>{{ __('Welcome. Use the menu to get started.') }}</flux:text>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($tiles as $tile)
                <a href="{{ $tile['url'] }}" wire:navigate class="block">
                    <flux:card class="space-y-1 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <flux:text>{{ $tile['label'] }}</flux:text>
                        <flux:heading size="xl" class="tabular-nums">{{ $tile['value'] }}</flux:heading>
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif
</div>
