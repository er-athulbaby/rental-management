<section class="w-full max-w-5xl space-y-6">
    <x-ui.page-header :title="__('New manual journal')" :back="route('ledger.journals')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Journal')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-date-input wire:model="date" :label="__('Date')" aria-required="true" />
                <div class="sm:col-span-2"><flux:input wire:model="description" :label="__('Description')" aria-required="true" /></div>
            </div>
        </x-ui.section>

        <x-ui.section :title="__('Lines')" flush>
            <x-ui.table>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Account') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Debit') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Credit') }}</flux:table.column>
                        <flux:table.column>{{ __('Memo') }}</flux:table.column>
                        <flux:table.column />
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($rows as $i => $line)
                            <flux:table.row :key="'l-'.$i">
                                <flux:table.cell>
                                    <flux:select wire:model="lines.{{ $i }}.account_id" :aria-label="__('Account')">
                                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                                        @foreach ($accounts as $account)
                                            <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </flux:table.cell>
                                <flux:table.cell><flux:input wire:model.blur="lines.{{ $i }}.debit" inputmode="decimal" :aria-label="__('Debit')" /></flux:table.cell>
                                <flux:table.cell><flux:input wire:model.blur="lines.{{ $i }}.credit" inputmode="decimal" :aria-label="__('Credit')" /></flux:table.cell>
                                <flux:table.cell><flux:input wire:model="lines.{{ $i }}.memo" :aria-label="__('Memo')" /></flux:table.cell>
                                <flux:table.cell><flux:button size="sm" wire:click="removeLine({{ $i }})">{{ __('Remove') }}</flux:button></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-ui.table>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 p-4 dark:border-zinc-700">
                <flux:button type="button" icon="plus" wire:click="addLine">{{ __('Add line') }}</flux:button>
                <p @class(['text-sm font-semibold tabular-nums', 'text-red-700 dark:text-red-400' => $debits !== $credits])>{{ __('Debits :debits · Credits :credits', ['debits' => $debits, 'credits' => $credits]) }}</p>
            </div>
            <flux:error name="lines" class="px-4 pb-3" />
        </x-ui.section>

        <x-ui.form-actions :cancel="route('ledger.journals')">
            <flux:button type="submit" variant="primary">{{ __('Post journal') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
