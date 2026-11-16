<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>
    <x-custom-fields.display :items="$details" file-route="portal.custom-fields.file" />

    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Balance owed')" :value="__(':amount BHD', ['amount' => $owed])" :tone="in_array($owed, ['0.000', '0'], true) ? null : 'danger'" />
        <x-ui.stat :label="__('Credit')" :value="__(':amount BHD', ['amount' => $credit])" />
    </x-ui.stats>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Next invoice due') }}</flux:heading>
        @if ($nextDue)
            <div class="flex flex-col gap-2 p-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <span class="font-medium">{{ $nextDue->number }}</span>
                <span class="text-sm">{{ __('Due :date', ['date' => $nextDue->due_date->format('d/m/Y')]) }}</span>
                <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($nextDue->balance))]) }}</span>
                <flux:link :href="route('portal.invoices.pdf', $nextDue)">{{ __('Invoice PDF') }}</flux:link>
            </div>
        @else
            <flux:text>{{ __('Nothing is due.') }}</flux:text>
        @endif
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Upcoming cheques') }}</flux:heading>
        @if ($cheques->isEmpty())
            <flux:text>{{ __('No cheques are waiting to be deposited.') }}</flux:text>
        @else
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
        @foreach ($cheques as $c)
            <li wire:key="ch-{{ $c->id }}" class="flex flex-col gap-1 p-3 text-sm sm:flex-row sm:justify-between sm:gap-2">
                <span>{{ $c->cheque_no }} · {{ $c->bank_name }}</span>
                <span>{{ $c->cheque_date->format('d/m/Y') }}</span>
                <span class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($c->amount))]) }}</span>
            </li>
        @endforeach
        </ul>
        @endif
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Open maintenance requests') }}</flux:heading>
        @if ($requests->isEmpty())
            <flux:text>{{ __('No open requests.') }}</flux:text>
        @else
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
        @foreach ($requests as $r)
            <li wire:key="mr-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                <span><flux:link :href="route('portal.maintenance.show', $r)">{{ $r->number }}</flux:link> · {{ $r->category->label() }}</span>
                <x-status-badge :status="$r->status" />
            </li>
        @endforeach
        </ul>
        @endif
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Agreements ending soon') }}</flux:heading>
        @if ($ending->isEmpty())
            <flux:text>{{ __('No agreement ends in the next 90 days.') }}</flux:text>
        @else
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
        @foreach ($ending as $a)
            <li wire:key="ag-{{ $a->id }}" class="flex flex-col gap-1 p-3 text-sm sm:flex-row sm:justify-between sm:gap-2">
                <span>{{ $a->number }}</span>
                <span>{{ __('Ends :date', ['date' => $a->ends_on->format('d/m/Y')]) }}</span>
            </li>
        @endforeach
        </ul>
        @endif
    </div>
</section>
