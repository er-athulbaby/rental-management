<section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Maintenance') }}</flux:heading>
        <flux:button variant="primary" :href="route('portal.maintenance.create')">{{ __('New request') }}</flux:button>
    </div>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($requests as $r)
            <li wire:key="mr-{{ $r->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:link :href="route('portal.maintenance.show', $r)" class="font-medium">{{ $r->number }}</flux:link>
                    <x-status-badge :status="$r->status" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $r->place() }} · {{ $r->category->label() }}</div>
                <div class="text-sm text-zinc-500">
                    {{ __('Sent :date', ['date' => $r->created_at->format('d/m/Y')]) }}
                    @if ($r->resolved_at)
                        · {{ __('Completed :date', ['date' => $r->resolved_at->format('d/m/Y')]) }}
                    @endif
                </div>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No requests yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
