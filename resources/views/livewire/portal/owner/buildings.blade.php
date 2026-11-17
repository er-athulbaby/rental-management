<section class="space-y-6">
    <x-ui.page-header :title="__('Buildings')" />
    @forelse ($buildings as $card)
        <x-ui.section wire:key="b-{{ $card['building']->id }}" :title="$card['building']->name" flush>
            @if ($card['roll'] !== null)
                <x-slot:actions>
                    <flux:badge size="sm">{{ __(':occupied of :total units let', ['occupied' => $card['occupied'], 'total' => $card['total']]) }}</flux:badge>
                </x-slot:actions>
            @endif
            @if (($details[$card['building']->id] ?? []) !== [])
                <div class="p-4 sm:p-6"><x-custom-fields.display inline :items="$details[$card['building']->id] ?? []" file-route="portal.owner.custom-fields.file" /></div>
            @endif
            @if ($card['roll'] === null)
                <flux:text class="p-4 sm:p-6">{{ __('Units') }}: {{ implode(', ', $card['units']) }}</flux:text>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($card['roll'] as $row)
                        <li wire:key="u-{{ $card['building']->id }}-{{ $row['unit'] }}" class="space-y-1 px-4 py-3 text-sm sm:px-6">
                            <div class="flex flex-wrap justify-between gap-2">
                                <span class="font-medium">{{ __('Unit') }} {{ $row['unit'] }}</span>
                                <span @class(['text-zinc-500' => $row['tenant'] === null])>{{ $row['tenant'] ?? __('Vacant') }}</span>
                            </div>
                            @if ($row['tenant'] !== null)
                                <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 text-zinc-600 dark:text-zinc-300">
                                    <span class="tabular-nums">{{ $row['start'] }} – {{ $row['end'] }}</span>
                                    <span class="tabular-nums">{{ __('Monthly rent') }}: {{ __(':amount BHD', ['amount' => App\Support\Fils::grouped((int) $row['rent'])]) }}</span>
                                    @if ($row['overdue'] !== null)
                                        <span class="tabular-nums">{{ __('Overdue') }}: {{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($row['overdue'])]) }}</span>
                                        <span class="tabular-nums">{{ __('Not yet due') }}: {{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($row['not_due'])]) }}</span>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.section>
    @empty
        <x-ui.section><x-ui.empty :title="__('No buildings yet.')" icon="building-office" /></x-ui.section>
    @endforelse
</section>
