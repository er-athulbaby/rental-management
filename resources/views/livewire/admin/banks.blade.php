<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Banks') }}</flux:heading>
    <flux:text>{{ __('The banks offered for owners and cheques. Switch one off to stop offering it; records that already name it keep it.') }}</flux:text>

    <form wire:submit="add" class="flex flex-wrap items-end gap-2">
        <div class="min-w-0 flex-1"><flux:input wire:model="name" :label="__('New bank')" :placeholder="__('e.g. Khaleeji Bank')" /></div>
        <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
    </form>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column align="end">{{ __('In use') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($banks as $bank)
                    <flux:table.row :key="'b-'.$bank->id">
                        <flux:table.cell>
                            <input type="text" value="{{ $bank->name }}" aria-label="{{ __('Name') }}"
                                wire:change="rename({{ $bank->id }}, $event.target.value)"
                                class="w-full rounded border border-zinc-200 bg-transparent px-2 py-1 text-sm dark:border-zinc-700" />
                        </flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $uses[mb_strtolower($bank->name)] ?? 0 }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$bank->active ? 'green' : 'zinc'">{{ $bank->active ? __('Offered') : __('Switched off') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" wire:click="toggle({{ $bank->id }})">{{ $bank->active ? __('Switch off') : __('Switch on') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4">{{ __('No banks yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:error name="name" />
</section>
