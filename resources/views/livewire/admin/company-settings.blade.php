<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Company settings')" :hint="__('Your company details, contract layout, tax and billing defaults. They appear on documents and set the defaults for new records.')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Profile')" :hint="__('Name, contact details and logo shown on documents.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
                <flux:input wire:model="form.name_ar" :label="__('Name (Arabic)')" dir="rtl" />
                <flux:input wire:model="form.cr_number" :label="__('CR number')" />
                <flux:input wire:model="form.phone" :label="__('Phone')" type="tel" />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.website" :label="__('Website')" type="url" />
                <flux:textarea wire:model="form.address_en" :label="__('Address (English)')" rows="2" />
                <flux:textarea wire:model="form.address_ar" :label="__('Address (Arabic)')" rows="2" dir="rtl" />
                <flux:field class="sm:col-span-2">
                    <flux:label>{{ __('Logo (PNG or JPG, max 2 MB)') }}</flux:label>
                    <x-file-button wire:model="logo" accept="image/png,image/jpeg" />
                    <flux:error name="logo" />
                </flux:field>
            </div>
        </x-ui.section>

        <x-ui.section :title="__('Contract layout')" :hint="__('The letterhead and blank space for printed contracts.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.contract_stamp_space_mm" type="number" min="0" max="120" :label="__('Blank space at the top of page 1 (mm)')"
                    :description="__('For printing contracts on official stamp paper: leave enough room for the printed stamp band. 0 = no space.')" />
                <flux:field class="sm:col-span-2">
                    <flux:label>{{ __('Contract letterhead (PNG or JPG, max 2 MB)') }}</flux:label>
                    <flux:description>{{ __('Your company\'s own letterhead, shown at the top of page 1. Not a government stamp: print on official stamp paper for that.') }}</flux:description>
                    <x-file-button wire:model="contractHeader" accept="image/png,image/jpeg" />
                    <flux:error name="contractHeader" />
                </flux:field>
                @if ($hasContractHeader)
                    <div class="sm:col-span-2"><flux:checkbox wire:model="removeContractHeader" :label="__('Remove the current letterhead')" /></div>
                @endif
                <flux:callout class="sm:col-span-2" icon="information-circle" :heading="__('Applies to contracts approved from now on. Contracts already approved keep the PDF they were stored with.')" />
            </div>
        </x-ui.section>

        <x-ui.section :title="__('Tax')" :hint="__('Whether you charge VAT, and the default tax for new units.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:checkbox wire:model="form.vat_registered" :label="__('VAT registered')" /></div>
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
        </x-ui.section>

        <x-ui.section :title="__('Locale and billing')" :hint="__('Currency, date format and when invoices are raised.')">
            <div class="grid gap-4 sm:grid-cols-2">
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
        </x-ui.section>

        <x-ui.form-actions>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
