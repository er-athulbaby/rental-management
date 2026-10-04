<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Unit types') }}</flux:heading>
    <flux:text>{{ __('The types a unit can be. The usual use fills in the unit\'s Residential or Commercial when the type is picked. Switch a type off to stop offering it; units that already have it keep it.') }}</flux:text>

    <form wire:submit="add" class="flex flex-wrap items-end gap-2">
        <div class="min-w-0 flex-1"><flux:input wire:model="name" :label="__('New unit type')" :placeholder="__('e.g. Car wash bay')" /></div>
        <flux:select wire:model="defaultUse" :label="__('Usual use')" class="max-w-44">
            @foreach ($uses as $use)<option value="{{ $use->value }}">{{ str($use->value)->headline() }}</option>@endforeach
            <option value="">{{ __('Either') }}</option>
        </flux:select>
        <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
    </form>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Usual use') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Units') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($types as $type)
                    <flux:table.row :key="'t-'.$type->id">
                        <flux:table.cell>
                            <input type="text" value="{{ $type->name }}" aria-label="{{ __('Name') }}"
                                wire:change="rename({{ $type->id }}, $event.target.value)"
                                class="w-full rounded border border-zinc-200 bg-transparent px-2 py-1 text-sm dark:border-zinc-700" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <select aria-label="{{ __('Usual use') }}" wire:change="setUse({{ $type->id }}, $event.target.value)"
                                class="rounded border border-zinc-200 bg-transparent px-2 py-1 text-sm dark:border-zinc-700">
                                @foreach ($uses as $use)<option value="{{ $use->value }}" @selected($type->default_use === $use)>{{ str($use->value)->headline() }}</option>@endforeach
                                <option value="" @selected($type->default_use === null)>{{ __('Either') }}</option>
                            </select>
                        </flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $type->units_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$type->active ? 'green' : 'zinc'">{{ $type->active ? __('Offered') : __('Switched off') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" wire:click="toggle({{ $type->id }})">{{ $type->active ? __('Switch off') : __('Switch on') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No unit types yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:error name="name" />
    <flux:error name="use" />
</section>
