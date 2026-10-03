<section class="w-full max-w-2xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Company settings') }}</flux:heading>

    <form wire:submit="save" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Profile') }}</flux:legend>
            <div class="space-y-4">
                <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
                <flux:input wire:model="form.name_ar" :label="__('Name (Arabic)')" dir="rtl" />
                <flux:input wire:model="form.cr_number" :label="__('CR number')" />
                <flux:textarea wire:model="form.address_en" :label="__('Address (English)')" rows="2" />
                <flux:textarea wire:model="form.address_ar" :label="__('Address (Arabic)')" rows="2" dir="rtl" />
                <flux:input wire:model="form.phone" :label="__('Phone')" type="tel" />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.website" :label="__('Website')" type="url" />
                <flux:field>
                    <flux:label>{{ __('Logo (PNG or JPG, max 2 MB)') }}</flux:label>
                    <input type="file" wire:model="logo" accept="image/png,image/jpeg" class="block w-full text-sm" />
                    <flux:error name="logo" />
                </flux:field>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Contract layout') }}</flux:legend>
            <div class="space-y-4">
                <flux:input wire:model="form.contract_stamp_space_mm" type="number" min="0" max="120" :label="__('Blank space at the top of page 1 (mm)')"
                    :description="__('For printing contracts on official stamp paper: leave enough room for the printed stamp band. 0 = no space.')" />
                <flux:field>
                    <flux:label>{{ __('Contract letterhead (PNG or JPG, max 2 MB)') }}</flux:label>
                    <flux:description>{{ __('Your company\'s own letterhead, shown at the top of page 1. Not a government stamp: print on official stamp paper for that.') }}</flux:description>
                    <input type="file" wire:model="contractHeader" accept="image/png,image/jpeg" class="block w-full text-sm" />
                    <flux:error name="contractHeader" />
                </flux:field>
                @if ($hasContractHeader)
                    <flux:checkbox wire:model="removeContractHeader" :label="__('Remove the current letterhead')" />
                @endif
                <flux:text class="text-sm">{{ __('Applies to contracts approved from now on. Contracts already approved keep the PDF they were stored with.') }}</flux:text>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Tax') }}</flux:legend>
            <div class="space-y-4">
                <flux:checkbox wire:model="form.vat_registered" :label="__('VAT registered')" />
                <flux:input wire:model="form.trn" :label="__('TRN')" />
                <flux:input wire:model="form.vat_rate" :label="__('VAT rate (%)')" inputmode="decimal" />
                <flux:select wire:model="form.residential_tax_category" :label="__('Default tax for residential units')">
                    @foreach ($taxCategories as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="form.commercial_tax_category" :label="__('Default tax for commercial units')">
                    @foreach ($taxCategories as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </flux:select>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Locale and billing') }}</flux:legend>
            <div class="space-y-4">
                <flux:input wire:model="form.currency_code" :label="__('Currency code')" maxlength="3" />
                <flux:select wire:model="form.date_format" :label="__('Date format')">
                    <option value="d/m/Y">DD/MM/YYYY</option>
                    <option value="d-m-Y">DD-MM-YYYY</option>
                    <option value="Y-m-d">YYYY-MM-DD</option>
                </flux:select>
                <flux:input wire:model="form.default_grace_days" :label="__('Default grace days')" type="number" min="0" max="60" />
                <flux:input wire:model="form.invoice_lead_days" :label="__('Invoice lead days')" type="number" min="0" max="60" />
                <flux:select wire:model="form.proration_basis" :label="__('Proration basis')">
                    @foreach ($prorationBases as $basis)
                        <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                    @endforeach
                </flux:select>
            </div>
        </flux:fieldset>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
