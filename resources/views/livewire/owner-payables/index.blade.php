<div class="space-y-4">
    <flux:heading size="xl">{{ __('Head-lease payments due') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="building" :label="__('Building')">
            <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
            @foreach ($buildings as $b)
                <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="dueBy" :label="__('Due by')" />
        @can('reports.financial')<div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>@endcan
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Due') }}</flux:table.column>
                <flux:table.column>{{ __('Contract') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Period') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $p)
                    <flux:table.row :key="'op-'.$p->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $p->due_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('owner-contracts.show', $p->owner_contract_id)" wire:navigate>{{ $p->contract->number }}</flux:link> <span class="text-zinc-500">{{ $p->contract->building->code }}</span></flux:table.cell>
                        <flux:table.cell>{{ $p->contract->owner->name_en }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $p->period_start->format('d/m/Y') }} – {{ $p->period_end->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $p->amount }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($canPay)
                                <flux:button size="sm" wire:click="startPaying({{ $p->id }})">{{ __('Pay') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="6">{{ __('Nothing due.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="pay-head-lease" class="md:w-96">
        <form wire:submit="pay" class="space-y-4">
            <flux:heading size="lg">{{ __('Pay head-lease') }}</flux:heading>
            <flux:input wire:model="form.amount" :label="__('Amount (BHD)')" inputmode="decimal" />
            <flux:select wire:model.live="form.method" :label="__('Method')">
                @foreach ($methods as $m)
                    <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model="form.paid_on" :label="__('Paid on')" />
            <flux:input wire:model="form.reference" :label="__('Reference')" />
            @if (($form['method'] ?? null) === 'cheque')
                <flux:input wire:model="form.cheque_no" :label="__('Cheque number')" />
                <x-bank-select wire:model="form.bank_name" />
                <flux:input type="date" wire:model="form.cheque_date" :label="__('Cheque date')" />
            @endif
            <flux:error name="form.owner_payable_id" />
            <div class="flex justify-end"><flux:button type="submit" variant="primary">{{ __('Record payment') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
