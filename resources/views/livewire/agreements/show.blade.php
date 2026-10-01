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
            @if ($canManage && $agreement->status === AgreementStatus::Draft)
                <flux:button variant="primary" wire:click="submit" wire:confirm="{{ __('Submit this agreement for Management approval?') }}">{{ __('Submit for approval') }}</flux:button>
            @endif
            @if ($canAmend)
                <flux:dropdown>
                    <flux:button icon:trailing="chevron-down">{{ __('Amend') }}</flux:button>
                    <flux:menu>
                        <flux:menu.item :href="route('agreements.amend', ['agreement' => $agreement, 'type' => 'add_unit'])" wire:navigate>{{ __('Add a unit') }}</flux:menu.item>
                        <flux:menu.item :href="route('agreements.amend', ['agreement' => $agreement, 'type' => 'release_unit'])" wire:navigate>{{ __('Release a unit') }}</flux:menu.item>
                        <flux:menu.item :href="route('agreements.amend', ['agreement' => $agreement, 'type' => 'terminate'])" wire:navigate>{{ __('Early termination') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif
            @if ($canEnterCheques)
                <flux:button :href="route('cheques.entry', ['agreement' => $agreement->id])" wire:navigate>{{ __('Enter cheques') }}</flux:button>
            @endif
            <flux:button :href="route('agreements.pdf', $agreement)" target="_blank" icon="document-arrow-down">
                {{ in_array($agreement->status, [AgreementStatus::Draft, AgreementStatus::PendingApproval], true) ? __('Draft contract PDF') : __('Contract PDF') }}
            </flux:button>
        </div>
    </div>

    <flux:error name="units" />
    <flux:error name="status" />
    <flux:error name="approval" />
    <flux:error name="contract_template_id" />

    @php($lastRejection = $agreement->status === AgreementStatus::Draft ? $approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected) : null)
    @if ($lastRejection)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Rejected by :name', ['name' => $lastRejection->decider?->name])" :text="$lastRejection->comment" />
    @endif

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

    @if ($amendments->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Amendments') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($amendments as $m)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="am-{{ $m->id }}">
                        <span>{{ $m->type->label() }} · {{ __('from :d', ['d' => $m->effective_date->format('d/m/Y')]) }} · {{ $m->status->label() }} · {{ $m->reason }}</span>
                        @if ($canManage && $m->status->value === 'draft')
                            <span class="flex gap-2">
                                <flux:button size="sm" :href="route('agreements.amend.edit', $m)" wire:navigate>{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="deleteAmendmentDraft({{ $m->id }})" wire:confirm="{{ __('Delete this draft amendment?') }}">{{ __('Delete') }}</flux:button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (auth()->user()->can('agreements.manage') && in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true))
        <flux:fieldset>
            <flux:legend>{{ __('Record notice') }}</flux:legend>
            <form wire:submit="recordNotice" class="mt-2 grid gap-3 sm:grid-cols-3 sm:items-end">
                <flux:select wire:model="noticeTarget" :label="__('For')">
                    <option value="">{{ __('The whole agreement') }}</option>
                    @foreach ($agreement->agreementUnits as $au)<option value="{{ $au->id }}">{{ $au->unit->building->code }} / {{ $au->unit->code }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="noticeDate" type="date" :label="__('Notice given on')" />
                <flux:input wire:model="plannedExit" type="date" :label="__('Planned exit')" />
                <flux:button type="submit" class="sm:col-span-3 sm:justify-self-start">{{ __('Record notice') }}</flux:button>
            </form>
        </flux:fieldset>
    @endif

    @if ($invoices->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Invoices') }}</flux:heading>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.rows>
                        @foreach ($invoices as $invoice)
                            <flux:table.row :key="'inv-'.$invoice->id">
                                <flux:table.cell><flux:link :href="route('invoices.show', $invoice)" wire:navigate>{{ $invoice->label() }}</flux:link></flux:table.cell>
                                <flux:table.cell>{{ $invoice->type->label() }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ __('due :d', ['d' => $invoice->due_date->format('d/m/Y')]) }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $invoice->total }}</flux:table.cell>
                                <flux:table.cell><flux:badge size="sm">{{ $invoice->displayLabel() }}</flux:badge></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif

    @if ($settlements->isNotEmpty())
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Deposit settlements') }}</flux:heading>
            @foreach ($settlements as $st)
                <div class="text-sm"><flux:link :href="route('deposit-settlements.show', $st)" wire:navigate>{{ $st->label() }}</flux:link> · {{ $st->status->label() }}</div>
            @endforeach
        </div>
    @endif

    @if ($approvals->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Approval history') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($approvals as $approval)
                    <li class="py-2 text-sm">
                        {{ $approval->action->label() }} · {{ str($approval->status->value)->headline() }} · {{ __('requested by :name', ['name' => $approval->requester->name]) }}
                        @if ($approval->decider) · {{ __('decided by :name', ['name' => $approval->decider->name]) }} @endif
                        @if ($approval->comment) — {{ $approval->comment }} @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <livewire:documents.panel :documentable="$agreement" :key="'docs-agreement-'.$agreement->id" />
</section>
