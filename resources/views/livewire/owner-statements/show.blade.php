<div class="space-y-6">
    <x-ui.summary :title="$statement->label()" :status="$statement->status" :back="route('owner-statements.index')">
        <x-slot:meta>
            <flux:text>{{ $statement->contract->owner->name_en }} · <flux:link :href="route('owner-contracts.show', $statement->owner_contract_id)" wire:navigate>{{ $statement->contract->number }}</flux:link> · {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }}</flux:text>
        </x-slot:meta>
        <x-slot:actions>
            <flux:button size="sm" :href="route('owner-statements.pdf', $statement)" target="_blank">{{ __('PDF') }}</flux:button>
            <flux:button size="sm" :href="route('owner-statements.export', $statement)">{{ __('Excel') }}</flux:button>
            @if ($canSubmit)
                <flux:button size="sm" variant="primary" wire:click="submit">{{ __('Submit for approval') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-ui.summary>
    <flux:error name="statement" />

    <x-ui.stats :cols="4">
        <x-ui.stat :label="__('Opening')" :value="$statement->opening_balance" />
        <x-ui.stat :label="__('Fee base')" :value="$statement->fee_base" />
        <x-ui.stat :label="__('Fee + VAT')" :value="$statement->fee_amount.' + '.$statement->fee_tax" />
        <x-ui.stat :label="__('Closing')" :value="$statement->closing_balance" />
    </x-ui.stats>

    @if ($canRemit)
        <x-ui.section :title="__('Pay the owner')">
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
                <x-date-input wire:model="remittance.paid_on" :label="__('Paid on')" />
                <flux:input wire:model="remittance.reference" :label="__('Reference')" />
                @if (($remittance['method'] ?? null) === 'cheque')
                    <flux:input wire:model="remittance.cheque_no" :label="__('Cheque number')" />
                    <x-bank-select wire:model="remittance.bank_name" />
                    <x-date-input wire:model="remittance.cheque_date" :label="__('Cheque date')" />
                @endif
                <flux:error name="remittance.owner_statement_id" />
                <div class="sm:col-span-2 flex justify-end"><flux:button type="submit" variant="primary">{{ __('Record payment') }}</flux:button></div>
            </form>
        </x-ui.section>
    @endif

    <x-ui.table>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Entry') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Balance') }}</flux:table.column>
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
    </x-ui.table>

    @if ($approvals->isNotEmpty())
        <x-ui.section :title="__('Approval history')">
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
        </x-ui.section>
    @endif
</div>
