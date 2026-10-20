<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Cheques') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($cheques as $c)
            <li wire:key="chq-{{ $c->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $c->cheque_no }}</span>
                    <x-status-badge :status="$c->status" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $c->bank_name }} · {{ $c->cheque_date->format('d/m/Y') }}</span>
                    <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($c->amount))]) }}</span>
                </div>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No cheques yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
