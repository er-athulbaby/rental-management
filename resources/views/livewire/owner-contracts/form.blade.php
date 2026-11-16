<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="$contractId ? __('Edit draft contract') : __('New owner contract')" :back="route('owner-contracts.index')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Owner and building')">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.owner_id" :label="__('Owner')" aria-required="true">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($owners as $owner)<option value="{{ $owner->id }}">{{ $owner->name_en }}</option>@endforeach
                </flux:select>

                <flux:select wire:model.live="form.building_id" :label="__('Building')" aria-required="true">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>@endforeach
                </flux:select>
            </div>
        </x-ui.section>

        <x-ui.section :title="__('Terms')">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:radio.group wire:model.live="form.type" :label="__('Arrangement')" variant="segmented" aria-required="true" class="sm:col-span-2">
                    <flux:radio value="managed" :label="__('Managed')" />
                    <flux:radio value="leased" :label="__('Leased')" />
                </flux:radio.group>

                <x-date-input wire:model="form.start_date" :label="__('Start date')" aria-required="true" />
                <x-date-input wire:model="form.end_date" :label="__('End date')" aria-required="true" />

                @if (($form['type'] ?? '') === 'leased')
                    <flux:input wire:model="form.rent_amount" inputmode="decimal" :label="__('Rent per payment period (BHD)')" />
                    <flux:select wire:model="form.payment_frequency" :label="__('Paid')">
                        @foreach ($frequencies as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                    </flux:select>
                @else
                    <flux:select wire:model="form.fee_type" :label="__('Management fee')">
                        @foreach ($feeTypes as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.fee_value" inputmode="decimal" :label="__('Fee (% or BHD per month)')" />
                    <flux:input wire:model="form.expense_approval_limit" inputmode="decimal" :label="__('Owner approval needed above (BHD)')" />
                    <flux:select wire:model="form.deposits_held_by" :label="__('Tenant deposits held by')">
                        @foreach ($holders as $h)<option value="{{ $h->value }}">{{ str($h->value)->headline() }}</option>@endforeach
                    </flux:select>
                @endif
            </div>
        </x-ui.section>

        <x-ui.section :title="__('Units')">
            @if ($units->isNotEmpty())
                <x-slot:actions>
                    <flux:button size="sm" variant="ghost" wire:click="selectAllUnits">{{ __('Whole building') }}</flux:button>
                </x-slot:actions>
            @endif
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @forelse ($units as $unit)
                    <flux:checkbox wire:model="form.unit_ids" :value="$unit->id" :label="$unit->code" />
                @empty
                    <flux:text>{{ __('Choose a building first.') }}</flux:text>
                @endforelse
            </div>
            <flux:error name="form.unit_ids" />
        </x-ui.section>

        <x-ui.section>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.previous_contract_id" :label="__('Replaces contract (successor)')">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($predecessors as $p)<option value="{{ $p->id }}">{{ $p->number }}</option>@endforeach
                </flux:select>

                <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" class="sm:col-span-2" />
            </div>
        </x-ui.section>

        <x-ui.form-actions :cancel="route('owner-contracts.index')">
            <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
