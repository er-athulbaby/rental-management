<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>
    <x-custom-fields.display :items="$details" file-route="portal.custom-fields.file" />

    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Balance owed')" :value="__(':amount BHD', ['amount' => $owed])" :tone="in_array($owed, ['0.000', '0'], true) ? null : 'danger'" />
        <x-ui.stat :label="__('Credit')" :value="__(':amount BHD', ['amount' => $credit])" />
    </x-ui.stats>

    <x-ui.section :title="__('Next invoice due')" flush>
        @if ($nextDue)
            <div class="flex flex-col gap-2 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <span class="font-medium">{{ $nextDue->number }}</span>
                <span class="text-sm">{{ __('Due :date', ['date' => $nextDue->due_date->format('d/m/Y')]) }}</span>
                <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($nextDue->balance))]) }}</span>
                <flux:link :href="route('portal.invoices.pdf', $nextDue)" class="inline-flex min-h-11 items-center">{{ __('Invoice PDF') }}</flux:link>
            </div>
        @else
            <x-ui.empty :title="__('Nothing is due.')" icon="check-circle" />
        @endif
    </x-ui.section>

    <x-ui.section :title="__('Upcoming cheques')" flush>
        @if ($cheques->isEmpty())
            <x-ui.empty :title="__('No cheques are waiting to be deposited.')" />
        @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($cheques as $c)
            <li wire:key="ch-{{ $c->id }}" class="flex flex-col gap-1 p-4 text-sm sm:flex-row sm:justify-between sm:gap-2">
                <span class="font-medium">{{ $c->cheque_no }} · {{ $c->bank_name }}</span>
                <span>{{ $c->cheque_date->format('d/m/Y') }}</span>
                <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($c->amount))]) }}</span>
            </li>
        @endforeach
        </ul>
        @endif
    </x-ui.section>

    <x-ui.section :title="__('Open maintenance requests')" flush>
        @if ($requests->isEmpty())
            <x-ui.empty :title="__('No open requests.')" />
        @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($requests as $r)
            <li wire:key="mr-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm">
                <span><flux:link :href="route('portal.maintenance.show', $r)">{{ $r->number }}</flux:link> · {{ $r->category->label() }}</span>
                <x-status-badge :status="$r->status" />
            </li>
        @endforeach
        </ul>
        @endif
    </x-ui.section>

    <x-ui.section :title="__('Agreements ending soon')" flush>
        @if ($ending->isEmpty())
            <x-ui.empty :title="__('No agreement ends in the next 90 days.')" />
        @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($ending as $a)
            <li wire:key="ag-{{ $a->id }}" class="flex flex-col gap-1 p-4 text-sm sm:flex-row sm:justify-between sm:gap-2">
                <span class="font-medium">{{ $a->number }}</span>
                <span>{{ __('Ends :date', ['date' => $a->ends_on->format('d/m/Y')]) }}</span>
            </li>
        @endforeach
        </ul>
        @endif
    </x-ui.section>
</section>
