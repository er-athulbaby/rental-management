<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Record expense') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model.live="form.building_id" :label="__('Building')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>@endforeach
            </flux:select>
            <flux:select wire:model="form.unit_id" :label="__('Unit')">
                <option value="">{{ __('Whole building') }}</option>
                @foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->code }}</option>@endforeach
            </flux:select>
            <flux:select wire:model="form.category" :label="__('Category')">
                @foreach ($categories as $c)<option value="{{ $c->value }}">{{ str($c->value)->headline() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.expense_date" type="date" :label="__('Date')" />
        </div>
        <flux:input wire:model="form.description" :label="__('Description')" />
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.net" inputmode="decimal" :label="__('Net (BHD)')" />
            <flux:input wire:model="form.tax_amount" inputmode="decimal" :label="__('VAT (BHD)')" />
        </div>

        <flux:radio.group wire:model.live="form.charge_to" :label="__('Charge to')" variant="segmented">
            <flux:radio value="company" :label="__('Company')" />
            <flux:radio value="owner" :label="__('Owner')" />
            @if ($canChargeTenant)
                <flux:radio value="tenant" :label="__('Tenant')" />
            @endif
        </flux:radio.group>

        @if (($form['charge_to'] ?? '') === 'tenant')
            <flux:text size="sm">{{ __('Choose the unit: its current tenant is invoiced for the net amount when you save.') }}</flux:text>
        @endif

        @if (($form['charge_to'] ?? '') === 'owner')
            <flux:textarea wire:model="form.owner_approval_note" :label="__('Owner approval note (needed above the contract limit)')" rows="2" />
            <flux:field>
                <flux:label>{{ __('Owner approval document') }}</flux:label>
                <x-file-button wire:model="ownerApproval" />
                <flux:error name="ownerApproval" />
            </flux:field>
        @endif

        <flux:button variant="primary" type="submit">{{ __('Record') }}</flux:button>
    </form>
</section>
