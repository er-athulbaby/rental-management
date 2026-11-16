<section class="w-full max-w-2xl space-y-6">
    <x-ui.page-header :title="$unit ? __('Unit :code', ['code' => $unit->code]) : __('New unit')" :back="route('units.index')">
        @if ($unit)
            <x-slot:meta>
                <div class="flex flex-wrap items-center gap-2">
                    <x-status-badge :status="$unit->status()" />
                    @if ($next = $unit->nextTenantFrom())
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('next tenant from :date', ['date' => $next->format('d/m/Y')]) }}</span>
                    @endif
                </div>
            </x-slot:meta>
        @endif
    </x-ui.page-header>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-6">
            <x-ui.section :title="__('Location')">
                <div class="grid items-end gap-4 sm:grid-cols-2">
                    <flux:select wire:model="form.building_id" :label="__('Building')" :disabled="(bool) $unit" aria-required="true" class="sm:col-span-2">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($buildings as $building)
                            <option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="form.code" :label="__('Unit code')" :required="(bool) $unit"
                        :description="$unit ? null : __('Leave blank to number it from the floor: 101, 102, G01.')" />
                    <flux:input wire:model="form.floor" :label="__('Floor')" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Details')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="form.use" :label="__('Use')" aria-required="true">
                        @foreach ($uses as $use)<option value="{{ $use->value }}">{{ str($use->value)->headline() }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="form.type" :label="__('Type')" aria-required="true">
                        @foreach ($types as $type)<option value="{{ $type->code }}">{{ $type->name }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.bedrooms" :label="__('Bedrooms')" type="number" min="0" />
                    <flux:input wire:model="form.bathrooms" :label="__('Bathrooms')" type="number" min="0" />
                    <flux:input wire:model="form.area_sqm" :label="__('Area (m²)')" inputmode="decimal" />
                    <flux:select wire:model="form.furnishing" :label="__('Furnishing')" aria-required="true">
                        @foreach ($furnishings as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.ewa_account_no" :label="__('EWA account no.')" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Money')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.list_rent" :label="__('List rent / month (BHD)')" inputmode="decimal" required />
                    <flux:input wire:model="form.list_deposit" :label="__('List deposit (BHD)')" inputmode="decimal" />
                    <flux:input wire:model="form.list_service_charge" :label="__('Service charge / month (BHD)')" inputmode="decimal" />
                    <flux:select wire:model="form.default_tax_category" :label="__('Tax category')">
                        <option value="">{{ __('Company default for this use') }}</option>
                        @foreach ($taxCategories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
                    </flux:select>
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Availability')">
                <flux:checkbox wire:model="form.blocked" :label="__('Blocked (not available to let)')" />
                <flux:input wire:model="form.blocked_reason" :label="__('Blocked reason')" />
                <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
            </x-ui.section>
        </fieldset>
        @if ($canEdit)
            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <x-ui.form-actions :cancel="route('units.index')">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </x-ui.form-actions>
        @endif
    </form>
    @unless ($canEdit)
        <x-custom-fields.display :items="$customDetails" />
    @endunless

    @if ($unit)
        @if ($maintenance)
            <x-maintenance-requests :requests="$maintenance" />
        @endif
        <livewire:documents.panel :documentable="$unit" :key="'docs-unit-'.$unit->id" />
    @endif
</section>
