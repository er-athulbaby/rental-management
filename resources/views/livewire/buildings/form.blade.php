<section class="w-full max-w-2xl space-y-8">
    <flux:heading size="xl" level="1">{{ $building?->name ?? __('New building') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:input wire:model="form.name" :label="__('Name')" required />
            <flux:input wire:model="form.code" :label="__('Code')" required />
            <flux:select wire:model="form.type" :label="__('Type')">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="form.location" :label="__('Location')" />
            <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" />
            <flux:input wire:model="form.floors_count" :label="__('Floors')" type="number" min="0" />
            <flux:input wire:model="form.parking" :label="__('Parking')" />
            <flux:textarea wire:model="form.facilities" :label="__('Facilities')" rows="2" />
            <flux:select wire:model="form.property_manager_user_id" :label="__('Property manager')">
                <option value="">{{ __('None') }}</option>
                @foreach ($managers as $manager)
                    <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($building)
        <livewire:documents.panel :documentable="$building" :key="'docs-building-'.$building->id" />
    @endif
</section>
