<section class="w-full max-w-5xl space-y-6">
    <x-ui.page-header :title="__('Accounts and rules')" :hint="__('The chart of accounts and where each event posts.')" />

    <div class="space-y-4">
        <x-ui.section :title="$editingId ? __('Edit account') : __('Add account')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
            <flux:input wire:model="code" :label="__('Code')" />
            <div class="sm:col-span-2"><flux:input wire:model="name_en" :label="__('Name (English)')" /></div>
            <div class="sm:col-span-2"><flux:input wire:model="name_ar" :label="__('Name (Arabic)')" dir="rtl" /></div>
            <flux:select wire:model="type" :label="__('Type')">
                @foreach ($types as $t)
                    <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex items-end sm:col-span-3"><flux:checkbox wire:model="is_money" :label="__('Money account')" /></div>
            <div class="flex items-end justify-end gap-2 sm:col-span-3">
                <flux:button type="submit" variant="primary">{{ $editingId ? __('Save') : __('Add account') }}</flux:button>
                @if ($editingId)
                    <flux:button wire:click="cancel">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
        </x-ui.section>
        <flux:error name="code" />
        <flux:error name="name_en" />
        <flux:error name="name_ar" />
        <flux:error name="type" />
        <flux:error name="is_money" />
        <flux:error name="accounts" />

        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Chart of accounts') }}</h2>
        <x-ui.table>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Code') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($accounts as $account)
                        <flux:table.row :key="'a-'.$account->id">
                            <flux:table.cell>{{ $account->code }}</flux:table.cell>
                            <flux:table.cell>{{ $account->name_en }} · <span dir="rtl">{{ $account->name_ar }}</span></flux:table.cell>
                            <flux:table.cell>{{ $account->type->label() }}{{ $account->is_money ? ' · '.__('Money account') : '' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$account->active ? 'green' : 'zinc'">{{ $account->active ? __('Active') : __('Inactive') }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-end whitespace-nowrap">
                                <flux:button size="sm" wire:click="edit({{ $account->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" wire:click="toggle({{ $account->id }})">{{ $account->active ? __('Deactivate') : __('Activate') }}</flux:button>
                                @if (! $account->lines_exists && $account->system_key === null)
                                    <flux:button size="sm" variant="danger" wire:click="delete({{ $account->id }})" wire:confirm="{{ __('Delete this account?') }}">{{ __('Delete') }}</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            @if ($accounts->isEmpty())
                <x-ui.empty />
            @endif
        </x-ui.table>
    </div>

    <form wire:submit="saveRules">
    <x-ui.section :title="__('Posting rules')" :hint="__('Each event posts to the account chosen here. A change applies to new postings; rebuild the open months to restate them.')">
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($ruleKeys as $i => $key)
                <flux:select wire:model="ruleAccounts.{{ $i }}" :label="$ruleLabels[$key]" wire:key="rule-{{ $i }}">
                    @foreach ($active as $account)
                        <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endforeach
        </div>
        <flux:error name="rules" />
        <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
            @if ($offerRebuild)
                <flux:button type="button" wire:click="rebuildOpen">{{ __('Rebuild the open months') }}</flux:button>
            @endif
            <flux:button type="submit" variant="primary">{{ __('Save rules') }}</flux:button>
        </div>
        <flux:error name="rebuild" />
    </x-ui.section>
    </form>
</section>
