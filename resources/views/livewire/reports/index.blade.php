<div class="space-y-6">
    <flux:heading size="xl">{{ __('Reports') }}</flux:heading>
    @forelse ($groups as $group => $reports)
        <div class="space-y-2">
            <flux:heading size="lg">{{ $group }}</flux:heading>
            <ul class="space-y-1">
                @foreach ($reports as $report)
                    <li><flux:link :href="route($report['route'])" wire:navigate>{{ $report['label'] }}</flux:link></li>
                @endforeach
            </ul>
        </div>
    @empty
        <flux:text>{{ __('You have no reports.') }}</flux:text>
    @endforelse
</div>
