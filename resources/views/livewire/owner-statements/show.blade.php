<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ $statement->label() }}</flux:heading>
            <flux:text>{{ $statement->contract->owner->name_en }} · <flux:link :href="route('owner-contracts.show', $statement->owner_contract_id)" wire:navigate>{{ $statement->contract->number }}</flux:link> · {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:badge>{{ $statement->status->label() }}</flux:badge>
            <flux:button size="sm" :href="route('owner-statements.pdf', $statement)" target="_blank">{{ __('PDF') }}</flux:button>
            <flux:button size="sm" :href="route('owner-statements.export', $statement)">{{ __('Excel') }}</flux:button>
            @if ($canSubmit)
                <flux:button size="sm" variant="primary" wire:click="submit">{{ __('Submit for approval') }}</flux:button>
            @endif
        </div>
    </div>
    <flux:error name="statement" />

    <div class="grid gap-3 sm:grid-cols-4">
        <flux:card><flux:text>{{ __('Opening') }}</flux:text><flux:heading class="tabular-nums">{{ $statement->opening_balance }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Fee base') }}</flux:text><flux:heading class="tabular-nums">{{ $statement->fee_base }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Fee + VAT') }}</flux:text><flux:heading class="tabular-nums">{{ $statement->fee_amount }} + {{ $statement->fee_tax }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Closing') }}</flux:text><flux:heading class="tabular-nums">{{ $statement->closing_balance }}</flux:heading></flux:card>
    </div>

    @if ($canRemit)
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Pay the owner') }}</flux:heading>
            <flux:text>{{ __('Owner ledger balance now: :b BHD', ['b' => \App\Support\Fils::toDecimal($live)]) }}</flux:text>
            <flux:text>{{ $statement->contract->owner->bank_name }} · {{ $statement->contract->owner->maskedIban() }} · {{ $statement->contract->owner->account_name }}</flux:text>
            @if ($statement->contract->owner->bankChangedRecently())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    {{ __('Bank details changed :d by :u', ['d' => $statement->contract->owner->bank_changed_at->format('d/m/Y'), 'u' => $statement->contract->owner->bankChanger?->name]) }}
                </flux:callout>
            @endif
            <form wire:submit="remit" class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model="remittance.amount" :label="__('Amount (BHD)')" inputmode="decimal" />
                <flux:select wire:model.live="remittance.method" :label="__('Method')">
                    @foreach ($methods as $m)
                        <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="date" wire:model="remittance.paid_on" :label="__('Paid on')" />
                <flux:input wire:model="remittance.reference" :label="__('Reference')" />
                @if (($remittance['method'] ?? null) === 'cheque')
                    <flux:input wire:model="remittance.cheque_no" :label="__('Cheque number')" />
                    <flux:input wire:model="remittance.bank_name" :label="__('Bank')" />
                    <flux:input type="date" wire:model="remittance.cheque_date" :label="__('Cheque date')" />
                @endif
                <flux:error name="remittance.owner_statement_id" />
                <div class="sm:col-span-2 flex justify-end"><flux:button type="submit" variant="primary">{{ __('Record payment') }}</flux:button></div>
            </form>
        </flux:card>
    @endif

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Entry') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column>{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Balance') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $i => $r)
                    <flux:table.row :key="'e-'.$i">
                        <flux:table.cell class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($r['date'])->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ \App\Billing\OwnerLedger::kindLabel($r['kind']) }}</flux:table.cell>
                        <flux:table.cell>{{ $r['reference'] }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($r['amount']) }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::toDecimal($r['balance']) }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No entries this month.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($approvals->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Approval history') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($approvals as $approval)
                    <li class="py-2 text-sm">
                        {{ $approval->action->label() }} · {{ str($approval->status->value)->headline() }} ·
                        {{ __('requested by :name', ['name' => $approval->requester->name]) }}
                        @if ($approval->decider) · {{ __('decided by :name', ['name' => $approval->decider->name]) }} @endif
                        @if ($approval->comment) — {{ $approval->comment }} @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
