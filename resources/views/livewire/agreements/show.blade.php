@use('App\Enums\AgreementStatus')
@use('App\Support\Fils')
@php
    $list = $agreement->listRentFils();
    $discount = $agreement->discountFils();
@endphp
<section class="w-full max-w-4xl space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $agreement->label() }}</flux:heading>
            <flux:badge>{{ $agreement->status->label() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canManage && $agreement->status === AgreementStatus::Draft)
                <flux:button :href="route('agreements.edit', $agreement)" wire:navigate>{{ __('Edit') }}</flux:button>
                <flux:button variant="ghost" wire:click="deleteDraft" wire:confirm="{{ __('Delete this draft?') }}">{{ __('Delete draft') }}</flux:button>
            @endif
            {{-- Task 7: submit + approvals. Task 8: contract PDF. Task 9: notice. Task 10: invoices. --}}
        </div>
    </div>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Customer') }}</dt><dd><flux:link :href="route('customers.edit', $agreement->customer)" wire:navigate>{{ $agreement->customer->name_en }}</flux:link></dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Dates') }}</dt><dd>{{ $agreement->start_date->format('d/m/Y') }} – {{ $agreement->end_date->format('d/m/Y') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Billing') }}</dt><dd>{{ str($agreement->frequency->value)->headline() }}{{ $agreement->billing_day ? ', '.__('day :d', ['d' => $agreement->billing_day]) : '' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Grace / notice') }}</dt><dd>{{ __(':g days / :n days', ['g' => $agreement->grace_days, 'n' => $agreement->notice_period_days]) }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Monthly rent (list → agreed)') }}</dt>
            <dd class="tabular-nums">{{ Fils::toDecimal($list) }} → {{ Fils::toDecimal($agreement->monthlyRentFils()) }}
                @if ($discount !== 0)
                    · {{ __('discount :bhd BHD (:pct%)', ['bhd' => Fils::toDecimal($discount), 'pct' => $list > 0 ? number_format($discount * 100 / $list, 1) : '0.0']) }}
                @endif
            </dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Total deposit') }}</dt><dd class="tabular-nums">{{ Fils::toDecimal($agreement->depositFils()) }} BHD</dd></div>
        @if ($agreement->planned_exit_date)
            <div><dt class="text-sm text-zinc-500">{{ __('Notice') }}</dt><dd>{{ __('given :n, leaving :p', ['n' => $agreement->notice_date?->format('d/m/Y'), 'p' => $agreement->planned_exit_date->format('d/m/Y')]) }}</dd></div>
        @endif
        <div><dt class="text-sm text-zinc-500">{{ __('Created by') }}</dt><dd>{{ $agreement->creator->name }}</dd></div>
    </dl>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Dates') }}</flux:table.column>
                <flux:table.column>{{ __('Charges / month') }}</flux:table.column>
                <flux:table.column>{{ __('List rent') }}</flux:table.column>
                <flux:table.column>{{ __('Deposit') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($agreement->agreementUnits as $au)
                    <flux:table.row :key="$au->id">
                        <flux:table.cell>{{ $au->unit->building->code }} / {{ $au->unit->code }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $au->start_date->format('d/m/Y') }} – {{ $au->end_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>
                            @foreach ($au->charges as $c)
                                <div class="tabular-nums">{{ $c->type->label() }}: {{ $c->monthly_amount }} <span class="text-xs text-zinc-500">{{ $c->tax_category->label() }}</span></div>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $au->list_rent }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $au->deposit_amount }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <livewire:documents.panel :documentable="$agreement" :key="'docs-agreement-'.$agreement->id" />
</section>
