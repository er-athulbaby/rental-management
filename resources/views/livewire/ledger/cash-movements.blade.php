<section class="w-full max-w-6xl space-y-6">
    <x-ui.page-header :title="__('Cash and bank movements')" :hint="__('Transfers, bank charges and bank interest between your money accounts.')" />

    @if ($canManage)
        <form wire:submit="save">
        <x-ui.section :title="__('Record a movement')">
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model.live="kind" :label="__('Kind')">
                    @foreach ($kinds as $k)
                        <flux:select.option :value="$k->value">{{ $k->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($kind !== 'bank_interest')
                    <flux:select wire:model="from_account_id" :label="__('From account')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($money as $account)
                            <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                @if ($kind !== 'bank_charge')
                    <flux:select wire:model="to_account_id" :label="__('To account')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($money as $account)
                            <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="amount" inputmode="decimal" :label="__('Amount')" />
                <x-date-input wire:model="date" :label="__('Date')" />
                <flux:input wire:model="reference" :label="__('Reference')" />
            </div>
            <flux:input wire:model="note" :label="__('Note')" />
            @foreach (['kind', 'from_account_id', 'to_account_id', 'amount', 'date', 'reference', 'note'] as $field)
                <flux:error name="{{ $field }}" />
            @endforeach
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Record movement') }}</flux:button>
            </div>
        </x-ui.section>
        </form>
    @endif

    @if ($reversingId !== null)
        <form wire:submit="reverse">
            <x-ui.section :title="__('Reverse').' #'.$reversingId" class="max-w-4xl border-red-300 dark:border-red-500/40">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-date-input wire:model="reverseDate" :label="__('Reversal date')" />
                    <flux:input wire:model="reverseReason" :label="__('Reason')" />
                </div>
                <flux:error name="reverseDate" />
                <flux:error name="reverseReason" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancelReverse">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="danger">{{ __('Reverse') }}</flux:button>
                </div>
            </x-ui.section>
        </form>
    @endif

    @php
        $filtered = filled($filterFrom) || filled($filterTo) || filled($filterKind) || filled($filterAccount);
    @endphp
    <x-ui.filters :clear="$filtered ? route('ledger.cash-movements') : null">
        <x-date-input wire:model.live="filterFrom" :label="__('From')" />
        <x-date-input wire:model.live="filterTo" :label="__('To')" />
        <flux:select wire:model.live="filterKind" :label="__('Kind')">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($kinds as $k)
                <flux:select.option :value="$k->value">{{ $k->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="filterAccount" :label="__('Account')">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($accounts as $account)
                <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-ui.filters>

    <x-ui.table>
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>#</flux:table.column>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Kind') }}</flux:table.column>
                <flux:table.column>{{ __('From account') }}</flux:table.column>
                <flux:table.column>{{ __('To account') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column>{{ __('Note') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row :key="'m-'.$row->id">
                        <flux:table.cell>{{ $row->id }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $row->date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" color="zinc">{{ $row->kind->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $row->fromAccount?->label() ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $row->toAccount?->label() ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $row->amount }}</flux:table.cell>
                        <flux:table.cell>{{ $row->reference }}</flux:table.cell>
                        <flux:table.cell>{{ $row->note }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            @if ($row->reverses_id !== null)
                                <flux:badge size="sm" color="amber">{{ __('Reversal of #:id', ['id' => $row->reverses_id]) }}</flux:badge>
                            @elseif ($row->reversal !== null)
                                <flux:badge size="sm" color="zinc">{{ __('Reversed') }}</flux:badge>
                            @elseif ($canManage)
                                <flux:button size="sm" wire:click="startReverse({{ $row->id }})">{{ __('Reverse') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($rows->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
