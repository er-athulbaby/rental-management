<section class="space-y-6">
    <x-ui.page-header :title="__('Contracts')" />
    <ul class="space-y-3">
        @forelse ($contracts as $c)
            <li wire:key="oc-{{ $c->id }}" class="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $c->label() }}</span>
                    <x-status-badge :status="$c->status" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span class="text-zinc-600 dark:text-zinc-300">{{ $c->building->name }} · {{ $c->type === App\Enums\OwnerContractType::Managed ? __('Managed') : __('Leased') }}</span>
                    <span class="tabular-nums">{{ $c->start_date->format('d/m/Y') }} – {{ $c->end_date->format('d/m/Y') }}</span>
                </div>
                @if ($c->terminated_on)
                    <flux:text size="sm">{{ __('Terminated on :date', ['date' => $c->terminated_on->format('d/m/Y')]) }}</flux:text>
                @endif
            </li>
        @empty
            <li class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><x-ui.empty :title="__('No contracts yet.')" icon="document-text" /></li>
        @endforelse
    </ul>
</section>
