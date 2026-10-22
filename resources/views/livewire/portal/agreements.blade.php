<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Agreements') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($agreements as $a)
            <li wire:key="ag-{{ $a->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $a->number }}</span>
                    <x-status-badge :status="$a->status" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $a->agreementUnits->map(fn ($au) => $au->unit->building->name.' '.$au->unit->code)->implode(', ') }}</div>
                <x-custom-fields.display :items="$details[$a->id] ?? []" file-route="portal.custom-fields.file" />
                @foreach ($a->agreementUnits as $au)
                    @if (($unitDetails[$au->unit_id] ?? []) !== [])
                        <flux:text size="sm" class="font-medium">{{ __('Unit') }} {{ $au->unit->code }}</flux:text>
                        <x-custom-fields.display :items="$unitDetails[$au->unit_id]" file-route="portal.custom-fields.file" />
                    @endif
                @endforeach
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $a->start_date->format('d/m/Y') }} – {{ $a->end_date->format('d/m/Y') }}</span>
                    <span class="tabular-nums">{{ __(':amount BHD a month', ['amount' => App\Support\Fils::grouped($a->monthlyRentFils())]) }}</span>
                </div>
                @if ($a->import_ref === null)
                    <flux:link :href="route('portal.agreements.pdf', $a)" class="text-sm">{{ __('Agreement PDF') }}</flux:link>
                @endif
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No agreements yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
