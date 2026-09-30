<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $customer?->name_en ?? __('New customer') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:radio.group wire:model.live="form.type" :label="__('Customer type')" variant="segmented">
                <flux:radio value="individual" :label="__('Individual')" />
                <flux:radio value="company" :label="__('Company')" />
            </flux:radio.group>
            <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
            <flux:input wire:model="form.name_ar" :label="__('Name (Arabic, used in contracts)')" dir="rtl" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.id_type" :label="__('ID type')">
                    @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                <flux:input wire:model="form.mobile" :label="__('Mobile')" type="tel" required />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.nationality" :label="__('Nationality')" />
                @if (($form['type'] ?? '') === 'company')
                    <flux:input wire:model="form.contact_person" :label="__('Contact person')" />
                @endif
                <flux:input wire:model="form.emergency_contact_name" :label="__('Emergency contact')" />
                <flux:input wire:model="form.emergency_contact_phone" :label="__('Emergency phone')" type="tel" />
            </div>
            <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" />
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($customer)
        <livewire:documents.panel :documentable="$customer" :key="'docs-customer-'.$customer->id" />
    @endif
</section>
