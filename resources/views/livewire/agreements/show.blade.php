@use('App\Enums\AgreementStatus')
@use('App\Support\Fils')
@php
    $list = $agreement->listRentFils();
    $discount = $agreement->discountFils();
    $lastRejection = $agreement->status === AgreementStatus::Draft ? $approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected) : null;
    $showNotice = auth()->user()->can('agreements.manage') && in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true);
    $showDetails = $canManage ? $customFields->isNotEmpty() : $customDetails !== [];
    $nav = array_values(array_filter([
        ['units', __('Units')],
        $showDetails ? ['details', __('More details')] : null,
        $amendments->isNotEmpty() ? ['amendments', __('Amendments')] : null,
        $showNotice ? ['notice', __('Notice and move-out')] : null,
        $invoices->isNotEmpty() ? ['invoices', __('Invoices')] : null,
        $chequesToReturn->isNotEmpty() ? ['cheques', __('Cheques to return')] : null,
        $settlements->isNotEmpty() ? ['settlements', __('Deposit settlements')] : null,
        $approvals->isNotEmpty() ? ['approvals', __('Approval history')] : null,
        ['reminders', __('Reminders')],
        ['documents', __('Documents')],
    ]));
@endphp
<section class="w-full max-w-5xl space-y-6">
    <x-ui.summary :title="$agreement->label()" :status="$agreement->status" :back="route('agreements.index')">
        <x-slot:actions>
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
            @if ($canRenew)
                <flux:modal.trigger name="renew"><flux:button>{{ __('Renew') }}</flux:button></flux:modal.trigger>
            @endif
            @if ($canEnterCheques)
                <flux:button :href="route('cheques.entry', ['agreement' => $agreement->id])" wire:navigate>{{ __('Enter cheques') }}</flux:button>
            @endif
            @if ($agreement->import_ref === null)
                <flux:button :href="route('agreements.pdf', $agreement)" target="_blank" icon="document-arrow-down">
                    {{ in_array($agreement->status, [AgreementStatus::Draft, AgreementStatus::PendingApproval], true) ? __('Draft contract PDF') : __('Contract PDF') }}
                </flux:button>
            @else
                <flux:text>{{ __('Imported from the previous system: the signed contract is kept outside this system.') }}</flux:text>
            @endif
        </x-slot:actions>
        <x-slot:facts>
            <x-ui.fact :label="__('Tenant')"><flux:link :href="route('customers.edit', $agreement->customer)" wire:navigate>{{ $agreement->customer->name_en }}</flux:link></x-ui.fact>
            <x-ui.fact :label="__('Dates')">{{ $agreement->start_date->format('d/m/Y') }} – {{ $agreement->end_date->format('d/m/Y') }}</x-ui.fact>
            <x-ui.fact :label="__('Billing')">{{ str($agreement->frequency->value)->headline() }}{{ $agreement->billing_day ? ', '.__('day :d', ['d' => $agreement->billing_day]) : '' }}</x-ui.fact>
            <x-ui.fact :label="__('Grace / notice')">{{ __(':g days / :n days', ['g' => $agreement->grace_days, 'n' => $agreement->notice_period_days]) }}</x-ui.fact>
            <x-ui.fact :label="__('Monthly rent (list → agreed)')">
                {{ Fils::toDecimal($list) }} → {{ Fils::toDecimal($agreement->monthlyRentFils()) }}
                @if ($discount !== 0)
                    · {{ __('discount :bhd BHD (:pct%)', ['bhd' => Fils::toDecimal($discount), 'pct' => $list > 0 ? number_format($discount * 100 / $list, 1) : '0.0']) }}
                @endif
            </x-ui.fact>
            <x-ui.fact :label="__('Total deposit')">{{ Fils::toDecimal($agreement->depositFils()) }} BHD</x-ui.fact>
            @if ($agreement->planned_exit_date)
                <x-ui.fact :label="__('Notice')">{{ __('given :n, leaving :p', ['n' => $agreement->notice_date?->format('d/m/Y'), 'p' => $agreement->planned_exit_date->format('d/m/Y')]) }}</x-ui.fact>
            @endif
            <x-ui.fact :label="__('Created by')">{{ $agreement->creator->name }}</x-ui.fact>
        </x-slot:facts>
    </x-ui.summary>

    <flux:error name="units" />
    <flux:error name="status" />
    <flux:error name="approval" />
    <flux:error name="contract_template_id" />

    @if ($lastRejection)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Rejected by :name', ['name' => $lastRejection->decider?->name])" :text="$lastRejection->comment" />
    @endif

    @if ($renewal)
        <flux:text>{{ __('Renewed by') }} <flux:link :href="route('agreements.show', $renewal)" wire:navigate>{{ $renewal->label() }}</flux:link></flux:text>
    @endif

    <x-ui.section-nav :items="$nav" />

    <x-ui.section :title="__('Units')" :hint="__('The units on this agreement, with rent, deposit and move-out.')" id="units" flush>
        <x-ui.table>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Dates') }}</flux:table.column>
                <flux:table.column>{{ __('Charges / month') }}</flux:table.column>
                <flux:table.column align="end">{{ __('List rent') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Deposit') }}</flux:table.column>
                <flux:table.column>{{ __('Move-out') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($agreement->agreementUnits as $au)
                    <flux:table.row :key="$au->id">
                        <flux:table.cell>{{ $au->unit->building->code }} / {{ $au->unit->code }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $au->start_date->format('d/m/Y') }} – {{ $au->end_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>
                            @foreach ($au->charges as $c)
                                <div class="tabular-nums">{{ $c->type->label() }}: {{ $c->monthly_amount }} <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $c->tax_category->label() }}</span></div>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $au->list_rent }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $au->deposit_amount }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $au->move_out_date?->format('d/m/Y') ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        </x-ui.table>

    @foreach ($agreement->agreementUnits->whereNotNull('move_out_date') as $au)
        <div class="space-y-1 border-t border-zinc-200 p-4 sm:p-6 dark:border-zinc-700" wire:key="mo-{{ $au->id }}">
            <flux:heading size="sm">{{ __('Move-out photos — :u', ['u' => $au->unit->code]) }}</flux:heading>
            @if ($au->move_out_notes || $au->move_out_readings)<flux:text size="sm">{{ $au->move_out_readings }} {{ $au->move_out_notes }}</flux:text>@endif
            <livewire:documents.panel :documentable="$au" :key="'docs-au-'.$au->id" />
        </div>
    @endforeach
    </x-ui.section>

    @if ($showDetails)
        <div id="details" class="scroll-mt-20">
    @if ($canManage && $customFields->isNotEmpty())
        <form wire:submit="saveDetails" class="space-y-4">
            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <div class="flex justify-end"><flux:button type="submit">{{ __('Save details') }}</flux:button></div>
        </form>
    @elseif (! $canManage)
        <x-custom-fields.display :items="$customDetails" />
    @endif
        </div>
    @endif

    @if ($amendments->isNotEmpty())
        <x-ui.section :title="__('Amendments')" :hint="__('Changes made after the agreement became active.')" id="amendments">
            <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($amendments as $m)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm" wire:key="am-{{ $m->id }}">
                        <span class="min-w-0">{{ $m->type->label() }} · {{ __('from :d', ['d' => $m->effective_date->format('d/m/Y')]) }} · {{ $m->status->label() }} · {{ $m->reason }}</span>
                        @if ($canManage && $m->status->value === 'draft')
                            <span class="flex gap-2">
                                <flux:button size="sm" :href="route('agreements.amend.edit', $m)" wire:navigate>{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="deleteAmendmentDraft({{ $m->id }})" wire:confirm="{{ __('Delete this draft amendment?') }}">{{ __('Delete') }}</flux:button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    @if ($showNotice)
        <x-ui.section :title="__('Notice and move-out')" :hint="__('Record the tenant\'s notice, or the day they hand back the keys.')" id="notice">
        <details wire:ignore.self class="group rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" @if ($errors->hasAny(['noticeTarget', 'noticeDate', 'plannedExit'])) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 marker:hidden [&::-webkit-details-marker]:hidden">
                <span><span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ __('Record notice') }}</span><span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __('The tenant has said they are leaving, and when.') }}</span></span>
                <flux:icon.chevron-down variant="mini" class="text-zinc-400 transition group-open:rotate-180" />
            </summary>
            <form wire:submit="recordNotice" class="grid gap-3 border-t border-zinc-200 p-4 sm:grid-cols-3 sm:items-end dark:border-zinc-700">
                <flux:select wire:model="noticeTarget" :label="__('For')">
                    <option value="">{{ __('The whole agreement') }}</option>
                    @foreach ($agreement->agreementUnits as $au)<option value="{{ $au->id }}">{{ $au->unit->building->code }} / {{ $au->unit->code }}</option>@endforeach
                </flux:select>
                <x-date-input wire:model="noticeDate" :label="__('Notice given on')" />
                <x-date-input wire:model="plannedExit" :label="__('Planned exit')" />
                <flux:button type="submit" class="sm:col-span-3 sm:justify-self-start">{{ __('Record notice') }}</flux:button>
            </form>
        </details>
        <details wire:ignore.self class="group rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" @if ($errors->hasAny(['moveOutTarget', 'moveOutDate', 'moveOutReadings', 'moveOutNotes'])) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 marker:hidden [&::-webkit-details-marker]:hidden">
                <span><span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ __('Record move-out') }}</span><span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __('The tenant has handed back the keys. This opens their deposit settlement.') }}</span></span>
                <flux:icon.chevron-down variant="mini" class="text-zinc-400 transition group-open:rotate-180" />
            </summary>
            <form wire:submit="recordMoveOut" class="grid gap-3 border-t border-zinc-200 p-4 sm:grid-cols-2 sm:items-end dark:border-zinc-700">
                <flux:select wire:model="moveOutTarget" :label="__('For')">
                    <option value="">{{ __('Every unit still in') }}</option>
                    @foreach ($agreement->agreementUnits->whereNull('move_out_date') as $au)<option value="{{ $au->id }}">{{ $au->unit->building->code }} / {{ $au->unit->code }}</option>@endforeach
                </flux:select>
                <x-date-input wire:model="moveOutDate" :label="__('Moved out on')" />
                <flux:textarea wire:model="moveOutReadings" :label="__('Meter readings')" rows="2" />
                <flux:textarea wire:model="moveOutNotes" :label="__('Notes')" rows="2" />
                <flux:button type="submit" class="sm:col-span-2 sm:justify-self-start">{{ __('Record move-out') }}</flux:button>
            </form>
        </details>
        </x-ui.section>
    @endif

    @if ($invoices->isNotEmpty())
        <x-ui.section :title="__('Invoices')" :hint="__('Rent and other charges billed on this agreement.')" id="invoices" flush>
            <x-ui.table>
                <flux:table>
                    <flux:table.rows>
                        @foreach ($invoices as $invoice)
                            <flux:table.row :key="'inv-'.$invoice->id">
                                <flux:table.cell><flux:link :href="route('invoices.show', $invoice)" wire:navigate>{{ $invoice->label() }}</flux:link></flux:table.cell>
                                <flux:table.cell>{{ $invoice->type->label() }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ __('due :d', ['d' => $invoice->due_date->format('d/m/Y')]) }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $invoice->total }}</flux:table.cell>
                                <flux:table.cell><x-status-badge :status="$invoice->displayLabel()" /></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-ui.table>
        </x-ui.section>
    @endif

    @if ($chequesToReturn->isNotEmpty())
        <x-ui.section :title="__('Cheques to return')" :hint="__('Held cheques still to be handed back to the tenant.')" id="cheques">
            <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($chequesToReturn as $cheque)
                <li class="py-3 text-sm" wire:key="chq-{{ $cheque->id }}"><flux:link :href="route('cheques.show', $cheque)" wire:navigate>{{ $cheque->cheque_no }}</flux:link> · {{ $cheque->bank_name }} · {{ $cheque->cheque_date->format('d/m/Y') }} · <span class="tabular-nums">{{ $cheque->amount }}</span> · {{ $cheque->status->label() }}</li>
            @endforeach
            </ul>
        </x-ui.section>
    @endif

    @if ($settlements->isNotEmpty())
        <x-ui.section :title="__('Deposit settlements')" :hint="__('Settlements of the deposit paid on this agreement.')" id="settlements">
            <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($settlements as $st)
                <li class="py-3 text-sm" wire:key="st-{{ $st->id }}"><flux:link :href="route('deposit-settlements.show', $st)" wire:navigate>{{ $st->label() }}</flux:link> · {{ $st->status->label() }}</li>
            @endforeach
            </ul>
        </x-ui.section>
    @endif

    @if ($approvals->isNotEmpty())
        <x-ui.section :title="__('Approval history')" :hint="__('Who asked for what, and what was decided.')" id="approvals">
            <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($approvals as $approval)
                    <li class="py-3 text-sm">
                        {{ $approval->action->label() }} · {{ str($approval->status->value)->headline() }} · {{ __('requested by :name', ['name' => $approval->requester->name]) }}
                        @if ($approval->decider) · {{ __('decided by :name', ['name' => $approval->decider->name]) }} @endif
                        @if ($approval->comment) — {{ $approval->comment }} @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    <div id="reminders" class="scroll-mt-20">
        <livewire:reminders.record-panel :record="$agreement" :key="'reminders-agreement-'.$agreement->id" />
    </div>

    <div id="documents" class="scroll-mt-20">
        <livewire:documents.panel :documentable="$agreement" :key="'docs-agreement-'.$agreement->id" />
    </div>

    <flux:modal name="renew" class="md:w-[32rem]">
        <form wire:submit="renew" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Renew from :d', ['d' => $agreement->end_date->addDay()->format('d/m/Y')]) }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ __('A draft opens with the current rents and deposits; change them before sending it for approval.') }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:checkbox.group wire:model="renewUnits" :label="__('Units to carry')">
                        @foreach ($agreement->agreementUnits as $au)<flux:checkbox value="{{ $au->unit_id }}" :label="$au->unit->building->code.' / '.$au->unit->code" />@endforeach
                    </flux:checkbox.group>
                </div>
                <x-date-input wire:model="renewEnd" :label="__('New end date')" />
            </div>
            <flux:error name="renewUnits" />
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create renewal draft') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
