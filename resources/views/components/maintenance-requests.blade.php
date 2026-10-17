@props(['requests'])
{{-- Spec 2026-10-17 maintenance §5: the "Maintenance" section on the unit and tenant pages, latest first. --}}
<div class="space-y-3">
    <flux:heading size="lg">{{ __('Maintenance') }}</flux:heading>
    @if ($requests->isEmpty())
        <flux:text>{{ __('No maintenance requests.') }}</flux:text>
    @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($requests as $r)
                <li wire:key="mr-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span><flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link> · {{ $r->category->label() }} · {{ $r->summary() }}</span>
                    <span class="flex items-center gap-2">
                        <span class="text-zinc-500">{{ $r->created_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</span>
                        <x-status-badge :status="$r->status" />
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
