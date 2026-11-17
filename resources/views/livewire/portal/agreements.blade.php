<section class="space-y-6">
    <x-ui.page-header :title="__('Agreements')" />
    <ul class="space-y-3">
        @forelse ($agreements as $a)
            <li wire:key="ag-{{ $a->id }}" class="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $a->number }}</span>
                    <x-status-badge :status="$a->status" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $a->agreementUnits->map(fn ($au) => $au->unit->building->name.' '.$au->unit->code)->implode(', ') }}</div>
                <x-custom-fields.display inline :items="$details[$a->id] ?? []" file-route="portal.custom-fields.file" />
                @foreach ($a->agreementUnits as $au)
                    @if ($a->status === \App\Enums\AgreementStatus::Active && ($unitDetails[$au->unit_id] ?? []) !== [])
                        <flux:text size="sm" class="font-medium">{{ __('Unit') }} {{ $au->unit->code }}</flux:text>
                        <x-custom-fields.display inline :items="$unitDetails[$au->unit_id]" file-route="portal.custom-fields.file" />
                    @endif
                @endforeach
                <div class="flex flex-wrap justify-between gap-2 border-t border-zinc-100 pt-2 text-sm dark:border-zinc-800">
                    <span class="tabular-nums">{{ $a->start_date->format('d/m/Y') }} – {{ $a->end_date->format('d/m/Y') }}</span>
                    <span class="font-semibold tabular-nums">{{ __(':amount BHD a month', ['amount' => App\Support\Fils::grouped($a->monthlyRentFils())]) }}</span>
                </div>
                @if ($a->import_ref === null)
                    <flux:link :href="route('portal.agreements.pdf', $a)" class="inline-flex min-h-11 items-center text-sm">{{ __('Agreement PDF') }}</flux:link>
                @endif
            </li>
        @empty
            <li class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><x-ui.empty :title="__('No agreements yet.')" /></li>
        @endforelse
    </ul>
</section>
