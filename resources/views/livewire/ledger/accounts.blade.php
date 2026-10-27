<section class="w-full max-w-5xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Accounts and rules') }}</flux:heading>

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Chart of accounts') }}</flux:heading>
        <form wire:submit="save" class="grid gap-3 sm:grid-cols-5">
            <flux:input wire:model="code" :label="__('Code')" />
            <flux:input wire:model="name_en" :label="__('Name (English)')" />
            <flux:input wire:model="name_ar" :label="__('Name (Arabic)')" dir="rtl" />
            <flux:select wire:model="type" :label="__('Type')">
                @foreach ($types as $t)
                    <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex items-end gap-2">
                <flux:button type="submit" variant="primary">{{ $editingId ? __('Save') : __('Add account') }}</flux:button>
                @if ($editingId)
                    <flux:button wire:click="cancel">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
        <flux:error name="code" />
        <flux:error name="name_en" />
        <flux:error name="name_ar" />
        <flux:error name="type" />
        <flux:error name="accounts" />

        <div class="overflow-x-auto">
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
                            <flux:table.cell>{{ $account->type->label() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$account->active ? 'green' : 'zinc'">{{ $account->active ? __('Active') : __('Inactive') }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="space-x-1 text-end">
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
        </div>
    </div>

    <form wire:submit="saveRules" class="space-y-4">
        <flux:heading size="lg">{{ __('Posting rules') }}</flux:heading>
        <flux:text>{{ __('Each event posts to the account chosen here. A change applies to new postings; rebuild the open months to restate them.') }}</flux:text>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($ruleKeys as $i => $key)
                <flux:select wire:model="ruleAccounts.{{ $i }}" :label="$ruleLabels[$key]" wire:key="rule-{{ $i }}">
                    @foreach ($active as $account)
                        <flux:select.option :value="(string) $account->id">{{ $account->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endforeach
        </div>
        <flux:error name="rules" />
        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary">{{ __('Save rules') }}</flux:button>
            @if ($offerRebuild)
                <flux:button type="button" wire:click="rebuildOpen">{{ __('Rebuild the open months') }}</flux:button>
            @endif
        </div>
        <flux:error name="rebuild" />
    </form>
</section>
