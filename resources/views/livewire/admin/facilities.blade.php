<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Facilities') }}</flux:heading>
    <flux:text>{{ __('The facilities buildings can tick. Switch one off to stop offering it; buildings that already have it keep it.') }}</flux:text>

    <form wire:submit="add" class="flex flex-wrap items-end gap-2">
        <div class="min-w-0 flex-1"><flux:input wire:model="name" :label="__('New facility')" :placeholder="__('e.g. Rooftop terrace')" /></div>
        <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
    </form>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Buildings') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($facilities as $facility)
                    <flux:table.row :key="'f-'.$facility->id">
                        <flux:table.cell>
                            <input type="text" value="{{ $facility->name }}" aria-label="{{ __('Name') }}"
                                wire:change="rename({{ $facility->id }}, $event.target.value)"
                                class="w-full rounded border border-zinc-200 bg-transparent px-2 py-1 text-sm dark:border-zinc-700" />
                        </flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $facility->buildings_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$facility->active ? 'green' : 'zinc'">{{ $facility->active ? __('Offered') : __('Switched off') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" wire:click="toggle({{ $facility->id }})">{{ $facility->active ? __('Switch off') : __('Switch on') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4">{{ __('No facilities yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:error name="name" />
</section>
