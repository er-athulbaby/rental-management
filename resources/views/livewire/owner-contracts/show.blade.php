<section class="w-full max-w-3xl space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $contract->label() }}</flux:heading>
            <flux:badge>{{ $contract->status->label() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Draft)
                <flux:button :href="route('owner-contracts.edit', $contract)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endif
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Active)
                <flux:button :href="route('owner-contracts.create', ['previous' => $contract->id])" wire:navigate>{{ __('New successor') }}</flux:button>
            @endif
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Draft)
                <flux:button variant="primary" wire:click="submit" wire:confirm="{{ __('Submit this contract for Management approval?') }}">{{ __('Submit for approval') }}</flux:button>
            @endif
        </div>
    </div>

    <flux:error name="unit_ids" />
    <flux:error name="status" />
    <flux:error name="approval" />

    @php($lastRejection = $contract->status === \App\Enums\OwnerContractStatus::Draft ? $approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected) : null)
    @if ($lastRejection)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Rejected by :name', ['name' => $lastRejection->decider?->name])" :text="$lastRejection->comment" />
    @endif

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Owner') }}</dt><dd>{{ $contract->owner->name_en }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Building') }}</dt><dd>{{ $contract->building->code }} — {{ $contract->building->name }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Arrangement') }}</dt><dd>{{ str($contract->type->value)->headline() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Dates') }}</dt><dd>{{ $contract->start_date->format('d/m/Y') }} – {{ $contract->end_date->format('d/m/Y') }}</dd></div>
        @if ($contract->type === \App\Enums\OwnerContractType::Leased)
            <div><dt class="text-sm text-zinc-500">{{ __('Rent') }}</dt><dd class="tabular-nums">{{ $contract->rent_amount }} BHD, {{ str($contract->payment_frequency?->value)->headline() }}</dd></div>
        @else
            <div><dt class="text-sm text-zinc-500">{{ __('Fee') }}</dt><dd class="tabular-nums">{{ $contract->fee_value }} ({{ str($contract->fee_type?->value)->headline() }})</dd></div>
            <div><dt class="text-sm text-zinc-500">{{ __('Owner approval above') }}</dt><dd class="tabular-nums">{{ $contract->expense_approval_limit ?? __('No limit') }}</dd></div>
            <div><dt class="text-sm text-zinc-500">{{ __('Deposits held by') }}</dt><dd>{{ str($contract->deposits_held_by?->value)->headline() }}</dd></div>
        @endif
        @if ($contract->previous)
            <div><dt class="text-sm text-zinc-500">{{ __('Replaces') }}</dt><dd><flux:link :href="route('owner-contracts.show', $contract->previous_contract_id)" wire:navigate>{{ $contract->previous->number }}</flux:link></dd></div>
        @endif
        @if ($contract->terminated_on)
            <div><dt class="text-sm text-zinc-500">{{ __('Terminated on') }}</dt><dd>{{ $contract->terminated_on->format('d/m/Y') }} — {{ $contract->termination_reason }}</dd></div>
        @endif
        <div><dt class="text-sm text-zinc-500">{{ __('Created by') }}</dt><dd>{{ $contract->creator->name }}</dd></div>
    </dl>

    <div>
        <flux:heading size="lg">{{ __('Units (:count)', ['count' => $contract->units->count()]) }}</flux:heading>
        <p class="mt-2 flex flex-wrap gap-2">
            @foreach ($contract->units as $unit)<flux:badge size="sm">{{ $unit->code }}</flux:badge>@endforeach
        </p>
    </div>

    @php($terminationPending = $approvals->contains(fn ($a) => $a->action === \App\Enums\ApprovalAction::OwnerContractTermination && $a->status === \App\Enums\ApprovalStatus::Pending))
    @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Active && ! $contract->terminated_on)
        <flux:fieldset>
            <flux:legend>{{ __('Early termination') }}</flux:legend>
            @if ($terminationPending)
                <flux:text>{{ __('An early termination is waiting for Management approval.') }}</flux:text>
            @else
                <form wire:submit="requestTermination" class="mt-2 space-y-3">
                    <flux:input wire:model="terminatedOn" type="date" :label="__('Last day of the contract')" />
                    <flux:textarea wire:model="terminationReason" :label="__('Reason')" rows="2" />
                    <flux:button type="submit">{{ __('Request early termination') }}</flux:button>
                </form>
            @endif
        </flux:fieldset>
    @endif

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

    @if ($payables->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Head-lease payments') }}</flux:heading>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Period') }}</flux:table.column>
                        <flux:table.column>{{ __('Due') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($payables as $p)
                            <flux:table.row :key="'op-'.$p->id">
                                <flux:table.cell class="whitespace-nowrap">{{ $p->period_start->format('d/m/Y') }} – {{ $p->period_end->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ $p->due_date->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $p->amount }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm">{{ $p->status->label() }}</flux:badge>
                                    @if ($p->disbursement_id)
                                        <flux:link :href="route('disbursements.show', $p->disbursement_id)" wire:navigate>{{ __('Payment') }}</flux:link>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif

    <livewire:documents.panel :documentable="$contract" :key="'docs-oc-'.$contract->id" />
</section>
