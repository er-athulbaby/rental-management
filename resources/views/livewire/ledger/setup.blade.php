<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Start date and opening balances')" />

    <form wire:submit="setStart" class="space-y-6">
        <x-ui.section :title="__('Start date')" :hint="__('Nothing is posted before this date. It can move only while no month is locked; moving it rebuilds the ledger.')">
            <div class="flex flex-wrap items-end gap-3">
                <x-date-input wire:model="startDate" :label="__('Start date')" aria-required="true" />
                <flux:button type="submit" variant="primary">{{ __('Save start date') }}</flux:button>
            </div>
            <flux:error name="startDate" />
        </x-ui.section>
    </form>

    @if ($settings->start_date)
        <form wire:submit="saveOpening" class="space-y-6">
            <x-ui.section :title="__('Opening balances')" :hint="__('One balance per account at the start date. Any difference goes to Opening balance equity.')" flush>
                <x-ui.table>
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
                </x-ui.table>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <span class="text-sm font-semibold tabular-nums">{{ __('Difference to Opening balance equity: :amount', ['amount' => $difference]) }}</span>
                    <flux:error name="balances" />
                </div>
            </x-ui.section>

            <x-ui.form-actions>
                <flux:button type="submit" variant="primary">{{ __('Save opening balances') }}</flux:button>
            </x-ui.form-actions>
        </form>
    @endif
</section>
