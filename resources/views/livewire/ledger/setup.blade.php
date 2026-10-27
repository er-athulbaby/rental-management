<section class="w-full max-w-3xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Start date and opening balances') }}</flux:heading>

    <form wire:submit="setStart" class="space-y-3">
        <flux:heading size="lg">{{ __('Start date') }}</flux:heading>
        <flux:text>{{ __('Nothing is posted before this date. It can move only while no month is locked; moving it rebuilds the ledger.') }}</flux:text>
        <div class="flex flex-wrap items-end gap-2">
            <x-date-input wire:model="startDate" :label="__('Start date')" />
            <flux:button type="submit" variant="primary">{{ __('Save start date') }}</flux:button>
        </div>
        <flux:error name="startDate" />
    </form>

    @if ($settings->start_date)
        <form wire:submit="saveOpening" class="space-y-3">
            <flux:heading size="lg">{{ __('Opening balances') }}</flux:heading>
            <flux:text>{{ __('One balance per account at the start date. Any difference goes to Opening balance equity.') }}</flux:text>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Account') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Debit') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Credit') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($accounts as $account)
                            <flux:table.row :key="'o-'.$account->id">
                                <flux:table.cell>{{ $account->label() }}</flux:table.cell>
                                <flux:table.cell><flux:input size="sm" wire:model.blur="balances.{{ $account->id }}.debit" inputmode="decimal" :aria-label="__('Debit')" /></flux:table.cell>
                                <flux:table.cell><flux:input size="sm" wire:model.blur="balances.{{ $account->id }}.credit" inputmode="decimal" :aria-label="__('Credit')" /></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
            <flux:text>{{ __('Difference to Opening balance equity: :amount', ['amount' => $difference]) }}</flux:text>
            <flux:error name="balances" />
            <flux:button type="submit" variant="primary">{{ __('Save opening balances') }}</flux:button>
        </form>
    @endif
</section>
