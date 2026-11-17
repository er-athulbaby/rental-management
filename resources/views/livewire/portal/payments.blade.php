<section class="space-y-6">
    <x-ui.page-header :title="__('Payments')" />
    <ul class="space-y-3">
        @forelse ($payments as $p)
            <li wire:key="pay-{{ $p->id }}" class="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $p->number }}</span>
                    <x-status-badge :status="$p->status" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span class="text-zinc-600 dark:text-zinc-300"><span class="tabular-nums">{{ $p->received_on->format('d/m/Y') }}</span> · {{ __($p->method->label()) }}</span>
                    <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($p->amount))]) }}</span>
                </div>
                @if ($p->status === App\Enums\PaymentStatus::Confirmed)
                    <flux:link :href="route('portal.payments.receipt', $p)" class="inline-flex min-h-11 items-center text-sm">{{ __('Receipt PDF') }}</flux:link>
                @endif
            </li>
        @empty
            <li class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><x-ui.empty :title="__('No payments yet.')" /></li>
        @endforelse
    </ul>
</section>
