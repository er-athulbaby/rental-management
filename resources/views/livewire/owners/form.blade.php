<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $owner?->name_en ?? __('New owner') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:select wire:model="form.type" :label="__('Owner type')">
                @foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
            <flux:input wire:model="form.name_ar" :label="__('Name (Arabic)')" dir="rtl" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.id_type" :label="__('ID type')">
                    @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                <flux:input wire:model="form.nationality" :label="__('Nationality')" />
                <flux:input wire:model="form.phone" :label="__('Phone')" type="tel" />
            </div>
            <flux:input wire:model="form.email" :label="__('Email')" type="email" />
            <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" />
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>

        @if ($canViewBank)
            <flux:fieldset>
                <flux:legend>{{ __('Bank details') }}</flux:legend>
                @if ($owner?->bankChangedRecently())
                    <flux:callout variant="warning" icon="exclamation-triangle"
                        :heading="__('Bank details changed :date by :user', ['date' => $owner->bank_changed_at->timezone('Asia/Bahrain')->format('d/m/Y'), 'user' => $owner->bankChanger?->name])" />
                @endif
                <fieldset @disabled(! $canEditBank) class="mt-4 space-y-4">
                    <flux:input wire:model="form.bank_name" :label="__('Bank')" />
                    <flux:input wire:model="form.iban" :label="__('IBAN')" />
                    <flux:input wire:model="form.account_name" :label="__('Account name')" />
                </fieldset>
            </flux:fieldset>
        @endif

        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($owner)
        <livewire:documents.panel :documentable="$owner" :key="'docs-owner-'.$owner->id" />
    @endif
</section>
