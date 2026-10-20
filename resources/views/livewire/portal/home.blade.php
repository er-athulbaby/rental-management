<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>

    <div class="grid grid-cols-2 gap-3">
        <flux:card>
            <flux:text size="sm">{{ __('Balance owed') }}</flux:text>
            <div class="text-lg font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => $owed]) }}</div>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Credit') }}</flux:text>
            <div class="text-lg font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => $credit]) }}</div>
        </flux:card>
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Next invoice due') }}</flux:heading>
        @if ($nextDue)
            <flux:card class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium">{{ $nextDue->number }}</span>
                <span class="text-sm">{{ __('Due :date', ['date' => $nextDue->due_date->format('d/m/Y')]) }}</span>
                <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($nextDue->balance))]) }}</span>
                <flux:link :href="route('portal.invoices.pdf', $nextDue)">{{ __('Invoice PDF') }}</flux:link>
            </flux:card>
        @else
            <flux:text>{{ __('Nothing is due.') }}</flux:text>
        @endif
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Upcoming cheques') }}</flux:heading>
        @forelse ($cheques as $c)
            <div wire:key="ch-{{ $c->id }}" class="flex flex-wrap justify-between gap-2 text-sm">
                <span>{{ $c->cheque_no }} · {{ $c->bank_name }}</span>
                <span>{{ $c->cheque_date->format('d/m/Y') }}</span>
                <span class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($c->amount))]) }}</span>
            </div>
        @empty
            <flux:text>{{ __('No cheques are waiting to be deposited.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Open maintenance requests') }}</flux:heading>
        @forelse ($requests as $r)
            <div wire:key="mr-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span><flux:link :href="route('portal.maintenance.show', $r)">{{ $r->number }}</flux:link> · {{ $r->category->label() }}</span>
                <x-status-badge :status="$r->status" />
            </div>
        @empty
            <flux:text>{{ __('No open requests.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Agreements ending soon') }}</flux:heading>
        @forelse ($ending as $a)
            <div wire:key="ag-{{ $a->id }}" class="flex flex-wrap justify-between gap-2 text-sm">
                <span>{{ $a->number }}</span>
                <span>{{ __('Ends :date', ['date' => $a->end_date->format('d/m/Y')]) }}</span>
            </div>
        @empty
            <flux:text>{{ __('No agreement ends in the next 90 days.') }}</flux:text>
        @endforelse
    </div>
</section>
