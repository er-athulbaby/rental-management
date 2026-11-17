<section class="space-y-6">
    <x-ui.page-header :title="__('Maintenance')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('portal.maintenance.create')">{{ __('New request') }}</flux:button>
        </x-slot:actions>
    </x-ui.page-header>
    <ul class="space-y-3">
        @forelse ($requests as $r)
            <li wire:key="mr-{{ $r->id }}" class="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:link :href="route('portal.maintenance.show', $r)" class="inline-flex min-h-11 items-center font-medium">{{ $r->number }}</flux:link>
                    <x-status-badge :status="$r->status" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $r->place() }} · {{ $r->category->label() }}</div>
                <div class="text-sm text-zinc-500 tabular-nums">
                    {{ __('Sent :date', ['date' => $r->created_at->format('d/m/Y')]) }}
                    @if ($r->resolved_at)
                        · {{ __('Completed :date', ['date' => $r->resolved_at->format('d/m/Y')]) }}
                    @endif
                </div>
            </li>
        @empty
            <li class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><x-ui.empty :title="__('No requests yet.')" icon="wrench-screwdriver" /></li>
        @endforelse
    </ul>
</section>
