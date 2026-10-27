<section class="w-full max-w-5xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New manual journal') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-date-input wire:model="date" :label="__('Date')" />
            <div class="sm:col-span-2"><flux:input wire:model="description" :label="__('Description')" /></div>
        </div>

        <div class="overflow-x-auto">
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
        </div>

        <flux:text>{{ __('Debits :debits · Credits :credits', ['debits' => $debits, 'credits' => $credits]) }}</flux:text>
        <flux:error name="lines" />
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" wire:click="addLine">{{ __('Add line') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('Post journal') }}</flux:button>
        </div>
    </form>
</section>
