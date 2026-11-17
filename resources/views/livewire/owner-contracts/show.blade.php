<section class="w-full max-w-3xl space-y-8">
    @php($lastRejection = $contract->status === \App\Enums\OwnerContractStatus::Draft ? $approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected) : null)
    <x-ui.summary :title="$contract->label()" :status="$contract->status" :back="route('owner-contracts.index')">
        <x-slot:actions>
            @if ($contract->type === \App\Enums\OwnerContractType::Managed && auth()->user()->can('finance.view'))
                <flux:button size="sm" :href="route('owner-statements.index', ['contract' => $contract->id])" wire:navigate>{{ __('Statements') }}</flux:button>
            @endif
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Draft)
                <flux:button :href="route('owner-contracts.edit', $contract)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endif
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Active)
                <flux:button :href="route('owner-contracts.create', ['previous' => $contract->id])" wire:navigate>{{ __('New successor') }}</flux:button>
            @endif
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Draft)
                <flux:button variant="primary" wire:click="submit" wire:confirm="{{ __('Submit this contract for Management approval?') }}">{{ __('Submit for approval') }}</flux:button>
            @endif
        </x-slot:actions>
        <x-slot:notices>
            <flux:error name="unit_ids" />
            <flux:error name="status" />
            <flux:error name="approval" />
            @if ($lastRejection)
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Rejected by :name', ['name' => $lastRejection->decider?->name])" :text="$lastRejection->comment" />
            @endif
        </x-slot:notices>
        <x-slot:facts>
            <x-ui.fact :label="__('Owner')">{{ $contract->owner->name_en }}</x-ui.fact>
            <x-ui.fact :label="__('Building')">{{ $contract->building->code }} — {{ $contract->building->name }}</x-ui.fact>
            <x-ui.fact :label="__('Arrangement')">{{ str($contract->type->value)->headline() }}</x-ui.fact>
            <x-ui.fact :label="__('Dates')">{{ $contract->start_date->format('d/m/Y') }} – {{ $contract->end_date->format('d/m/Y') }}</x-ui.fact>
            @if ($contract->type === \App\Enums\OwnerContractType::Leased)
                <x-ui.fact :label="__('Rent')">{{ $contract->rent_amount }} BHD, {{ str($contract->payment_frequency?->value)->headline() }}</x-ui.fact>
            @else
                <x-ui.fact :label="__('Fee')">{{ $contract->fee_value }} ({{ str($contract->fee_type?->value)->headline() }})</x-ui.fact>
                <x-ui.fact :label="__('Owner approval above')">{{ $contract->expense_approval_limit ?? __('No limit') }}</x-ui.fact>
                <x-ui.fact :label="__('Deposits held by')">{{ str($contract->deposits_held_by?->value)->headline() }}</x-ui.fact>
            @endif
            @if ($contract->previous)
                <x-ui.fact :label="__('Replaces')"><flux:link :href="route('owner-contracts.show', $contract->previous_contract_id)" wire:navigate>{{ $contract->previous->number }}</flux:link></x-ui.fact>
            @endif
            @if ($contract->terminated_on)
                <x-ui.fact :label="__('Terminated on')">{{ $contract->terminated_on->format('d/m/Y') }} — {{ $contract->termination_reason }}</x-ui.fact>
            @endif
            <x-ui.fact :label="__('Created by')">{{ $contract->creator->name }}</x-ui.fact>
        </x-slot:facts>
    </x-ui.summary>

    <x-ui.section :title="__('Units (:count)', ['count' => $contract->units->count()])" :hint="__('The units this contract covers.')">
        <p class="flex flex-wrap gap-2">
            @foreach ($contract->units as $unit)<flux:badge size="sm">{{ $unit->code }}</flux:badge>@endforeach
        </p>
    </x-ui.section>

    @php($terminationPending = $approvals->contains(fn ($a) => $a->action === \App\Enums\ApprovalAction::OwnerContractTermination && $a->status === \App\Enums\ApprovalStatus::Pending))
    @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Active && ! $contract->terminated_on)
        <x-ui.section :title="__('Early termination')" :hint="__('End the contract before its end date; Management approves.')">
            @if ($terminationPending)
                <flux:text>{{ __('An early termination is waiting for Management approval.') }}</flux:text>
            @else
                <form wire:submit="requestTermination" class="grid gap-4 sm:grid-cols-2">
                    <x-date-input wire:model="terminatedOn" :label="__('Last day of the contract')" />
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="terminationReason" :label="__('Reason')" rows="2" />
                    </div>
                    <flux:button type="submit" class="justify-self-start">{{ __('Request early termination') }}</flux:button>
                </form>
            @endif
        </x-ui.section>
    @endif

    @if ($approvals->isNotEmpty())
        <x-ui.section :title="__('Approval history')" :hint="__('Who asked for what, and what was decided.')">
            <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($approvals as $approval)
                    <li class="py-3 text-sm">
                        {{ $approval->action->label() }} · {{ str($approval->status->value)->headline() }} ·
                        {{ __('requested by :name', ['name' => $approval->requester->name]) }}
                        @if ($approval->decider) · {{ __('decided by :name', ['name' => $approval->decider->name]) }} @endif
                        @if ($approval->comment) — {{ $approval->comment }} @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    @if ($payables->isNotEmpty())
        <x-ui.section :title="__('Head-lease payments')" :hint="__('Rent due to the owner under this head lease.')" flush>
            <x-ui.table>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Period') }}</flux:table.column>
                        <flux:table.column>{{ __('Due') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($payables as $p)
                            <flux:table.row :key="'op-'.$p->id">
                                <flux:table.cell class="whitespace-nowrap">{{ $p->period_start->format('d/m/Y') }} – {{ $p->period_end->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ $p->due_date->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $p->amount }}</flux:table.cell>
                                <flux:table.cell>
                                    <x-status-badge :status="$p->status" />
                                    @if ($p->disbursement_id)
                                        <flux:link :href="route('disbursements.show', $p->disbursement_id)" wire:navigate>{{ __('Payment') }}</flux:link>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-ui.table>
        </x-ui.section>
    @endif

    <livewire:documents.panel :documentable="$contract" :key="'docs-oc-'.$contract->id" />
</section>
