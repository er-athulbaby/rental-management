<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Contracts') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($contracts as $c)
            <li wire:key="oc-{{ $c->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $c->label() }}</span>
                    <x-status-badge :status="$c->status" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $c->building->name }} · {{ $c->type === App\Enums\OwnerContractType::Managed ? __('Managed') : __('Leased') }}</span>
                    <span>{{ $c->start_date->format('d/m/Y') }} – {{ $c->end_date->format('d/m/Y') }}</span>
                </div>
                @if ($c->terminated_on)
                    <flux:text size="sm">{{ __('Terminated on :date', ['date' => $c->terminated_on->format('d/m/Y')]) }}</flux:text>
                @endif
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No contracts yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
