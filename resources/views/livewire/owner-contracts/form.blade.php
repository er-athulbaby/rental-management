<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $contractId ? __('Edit draft contract') : __('New owner contract') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <flux:select wire:model="form.owner_id" :label="__('Owner')">
            <option value="">{{ __('Choose…') }}</option>
            @foreach ($owners as $owner)<option value="{{ $owner->id }}">{{ $owner->name_en }}</option>@endforeach
        </flux:select>

        <flux:select wire:model.live="form.building_id" :label="__('Building')">
            <option value="">{{ __('Choose…') }}</option>
            @foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>@endforeach
        </flux:select>

        <flux:radio.group wire:model.live="form.type" :label="__('Arrangement')" variant="segmented">
            <flux:radio value="managed" :label="__('Managed')" />
            <flux:radio value="leased" :label="__('Leased')" />
        </flux:radio.group>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-date-input wire:model="form.start_date" :label="__('Start date')" />
            <x-date-input wire:model="form.end_date" :label="__('End date')" />
        </div>

        @if (($form['type'] ?? '') === 'leased')
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.rent_amount" inputmode="decimal" :label="__('Rent per payment period (BHD)')" />
                <flux:select wire:model="form.payment_frequency" :label="__('Paid')">
                    @foreach ($frequencies as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                </flux:select>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.fee_type" :label="__('Management fee')">
                    @foreach ($feeTypes as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.fee_value" inputmode="decimal" :label="__('Fee (% or BHD per month)')" />
                <flux:input wire:model="form.expense_approval_limit" inputmode="decimal" :label="__('Owner approval needed above (BHD)')" />
                <flux:select wire:model="form.deposits_held_by" :label="__('Tenant deposits held by')">
                    @foreach ($holders as $h)<option value="{{ $h->value }}">{{ str($h->value)->headline() }}</option>@endforeach
                </flux:select>
            </div>
        @endif

        <flux:fieldset>
            <div class="flex items-center justify-between">
                <flux:legend>{{ __('Units') }}</flux:legend>
                @if ($units->isNotEmpty())
                    <flux:button size="sm" variant="ghost" wire:click="selectAllUnits">{{ __('Whole building') }}</flux:button>
                @endif
            </div>
            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @forelse ($units as $unit)
                    <flux:checkbox wire:model="form.unit_ids" :value="$unit->id" :label="$unit->code" />
                @empty
                    <flux:text>{{ __('Choose a building first.') }}</flux:text>
                @endforelse
            </div>
            <flux:error name="form.unit_ids" />
        </flux:fieldset>

        <flux:select wire:model="form.previous_contract_id" :label="__('Replaces contract (successor)')">
            <option value="">{{ __('None') }}</option>
            @foreach ($predecessors as $p)<option value="{{ $p->id }}">{{ $p->number }}</option>@endforeach
        </flux:select>

        <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />

        <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
    </form>
</section>
