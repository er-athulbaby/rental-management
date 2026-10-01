<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $typeLabel }} — {{ $agreement->label() }}</flux:heading>
    <flux:text>{{ __('Management approves. On approval the schedule is re-billed from the effective date; issued periods are credited or invoiced.') }}</flux:text>

    <form wire:submit="submit" class="space-y-4">
        @if ($type === 'release_unit')
            <flux:select wire:model="form.agreement_unit_id" :label="__('Unit to release')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($agreement->agreementUnits as $au)<option value="{{ $au->id }}">{{ $au->unit->building->code }} / {{ $au->unit->code }}</option>@endforeach
            </flux:select>
        @endif
        @if ($type === 'add_unit')
            <flux:select wire:model="form.unit_id" :label="__('Unit to add')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->building->code }} / {{ $u->code }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.deposit_amount" inputmode="decimal" :label="__('Deposit (BHD)')" />
            @foreach ($form['charges'] ?? [] as $i => $charge)
                <div class="grid gap-3 sm:grid-cols-3" wire:key="ch-{{ $i }}">
                    <flux:select wire:model="form.charges.{{ $i }}.type" :label="__('Charge')">
                        @foreach ($chargeTypes as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.charges.{{ $i }}.monthly_amount" inputmode="decimal" :label="__('Monthly (BHD)')" />
                    <flux:select wire:model="form.charges.{{ $i }}.tax_category" :label="__('Tax')">
                        @foreach ($taxCategories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
                    </flux:select>
                    <div class="sm:col-span-3">
                        @foreach (['type', 'description', 'monthly_amount', 'tax_category'] as $f)<flux:error name="form.charges.{{ $i }}.{{ $f }}" />@endforeach
                    </div>
                </div>
            @endforeach
            <flux:button size="sm" wire:click="addCharge">{{ __('Add charge') }}</flux:button>
            <flux:error name="form.charges" />
        @endif
        <flux:input wire:model="form.effective_date" type="date" :label="$type === 'add_unit' ? __('Let from') : __('Last day of occupancy')" />
        <flux:textarea wire:model="form.reason" :label="__('Reason')" rows="3" />
        @foreach (['type', 'agreement_unit_id', 'unit_id', 'deposit_amount', 'effective_date', 'reason', 'approval', 'status'] as $f)<flux:error name="form.{{ $f }}" />@endforeach
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="saveDraft">{{ __('Save draft') }}</flux:button>
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="submit">{{ __('Send for approval') }}</flux:button>
        </div>
    </form>
</section>
