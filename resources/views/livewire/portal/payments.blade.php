<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Payments') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($payments as $p)
            <li wire:key="pay-{{ $p->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $p->number }}</span>
                    <x-status-badge :status="$p->status" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $p->received_on->format('d/m/Y') }} · {{ __($p->method->label()) }}</span>
                    <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($p->amount))]) }}</span>
                </div>
                @if ($p->status === App\Enums\PaymentStatus::Confirmed)
                    <flux:link :href="route('portal.payments.receipt', $p)" class="text-sm">{{ __('Receipt PDF') }}</flux:link>
                @endif
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No payments yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
