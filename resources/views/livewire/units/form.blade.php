<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $unit ? __('Unit :code', ['code' => $unit->code]) : __('New unit') }}</flux:heading>
    @if ($unit)
        <flux:badge>{{ $unit->status()->label() }}</flux:badge>
        @if ($next = $unit->nextTenantFrom())
            <span class="text-xs text-zinc-500">{{ __('next tenant from :date', ['date' => $next->format('d/m/Y')]) }}</span>
        @endif
    @endif

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:select wire:model="form.building_id" :label="__('Building')" :disabled="(bool) $unit">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($buildings as $building)
                    <option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.code" :label="__('Unit code')" required />
                <flux:input wire:model="form.floor" :label="__('Floor')" />
                <flux:select wire:model="form.use" :label="__('Use')">
                    @foreach ($uses as $use)<option value="{{ $use->value }}">{{ str($use->value)->headline() }}</option>@endforeach
                </flux:select>
                <flux:select wire:model="form.type" :label="__('Type')">
                    @foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.bedrooms" :label="__('Bedrooms')" type="number" min="0" />
                <flux:input wire:model="form.bathrooms" :label="__('Bathrooms')" type="number" min="0" />
                <flux:input wire:model="form.area_sqm" :label="__('Area (m²)')" inputmode="decimal" />
                <flux:select wire:model="form.furnishing" :label="__('Furnishing')">
                    @foreach ($furnishings as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.list_rent" :label="__('List rent / month (BHD)')" inputmode="decimal" required />
                <flux:input wire:model="form.list_deposit" :label="__('List deposit (BHD)')" inputmode="decimal" />
                <flux:input wire:model="form.list_service_charge" :label="__('Service charge / month (BHD)')" inputmode="decimal" />
                <flux:select wire:model="form.default_tax_category" :label="__('Tax category')">
                    <option value="">{{ __('Company default for this use') }}</option>
                    @foreach ($taxCategories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.ewa_account_no" :label="__('EWA account no.')" />
            </div>
            <flux:checkbox wire:model="form.blocked" :label="__('Blocked (not available to let)')" />
            <flux:input wire:model="form.blocked_reason" :label="__('Blocked reason')" />
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($unit)
        <livewire:documents.panel :documentable="$unit" :key="'docs-unit-'.$unit->id" />
    @endif
</section>
