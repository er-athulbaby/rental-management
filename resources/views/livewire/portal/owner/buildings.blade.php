<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Buildings') }}</flux:heading>
    @forelse ($buildings as $card)
        <flux:card wire:key="b-{{ $card['building']->id }}" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">{{ $card['building']->name }}</flux:heading>
                @if ($card['roll'] !== null)
                    <flux:badge size="sm">{{ __(':occupied of :total units let', ['occupied' => $card['occupied'], 'total' => $card['total']]) }}</flux:badge>
                @endif
            </div>
            <x-custom-fields.display :items="$details[$card['building']->id] ?? []" file-route="portal.owner.custom-fields.file" />
            @if ($card['roll'] === null)
                <flux:text>{{ __('Units') }}: {{ implode(', ', $card['units']) }}</flux:text>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($card['roll'] as $row)
                        <li wire:key="u-{{ $card['building']->id }}-{{ $row['unit'] }}" class="space-y-1 py-2 text-sm">
                            <div class="flex flex-wrap justify-between gap-2">
                                <span class="font-medium">{{ __('Unit') }} {{ $row['unit'] }}</span>
                                <span>{{ $row['tenant'] ?? __('Vacant') }}</span>
                            </div>
                            @if ($row['tenant'] !== null)
                                <div class="flex flex-wrap justify-between gap-2 text-zinc-600 dark:text-zinc-300">
                                    <span>{{ $row['start'] }} – {{ $row['end'] }}</span>
                                    <span class="tabular-nums">{{ __('Monthly rent') }}: {{ __(':amount BHD', ['amount' => App\Support\Fils::grouped((int) $row['rent'])]) }}</span>
                                    @if ($row['owed'] !== null)
                                        <span class="tabular-nums">{{ __('Balance owed') }}: {{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($row['owed'])]) }}</span>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </flux:card>
    @empty
        <flux:text>{{ __('No buildings yet.') }}</flux:text>
    @endforelse
</section>
