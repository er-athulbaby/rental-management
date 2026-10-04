<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ $agreementId ? __('Edit draft agreement') : __('New agreement') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:error name="form.status" />
        <flux:fieldset>
            <flux:legend>{{ __('Tenant') }}</flux:legend>
            @if ($customerLabel)
                <flux:text class="mb-2">{{ $customerLabel }}</flux:text>
            @endif
            <flux:input wire:model.live.debounce.300ms="customerSearch" :placeholder="__('Name, mobile, ID number or unit code')" icon="magnifying-glass" />
            @if ($customerResults->isNotEmpty())
                <ul class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($customerResults as $c)
                        <li><button type="button" wire:click="selectCustomer({{ $c->id }})" class="w-full px-3 py-2 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $c->name_en }} · {{ $c->maskedId() }} · {{ $c->mobile }}</button></li>
                    @endforeach
                </ul>
            @endif
            <flux:error name="form.customer_id" />
        </flux:fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.start_date" type="date" :label="__('Start date')" />
            <flux:input wire:model="form.end_date" type="date" :label="__('End date')" />
            <flux:select wire:model="form.frequency" :label="__('Billing frequency')">
                @foreach ($frequencies as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.billing_day" type="number" min="1" max="28" :label="__('Billing day (optional, 1–28)')" />
            <flux:input wire:model="form.grace_days" type="number" min="0" :label="__('Grace days (blank = company default)')" />
            <flux:input wire:model="form.notice_period_days" type="number" min="0" :label="__('Notice period (days)')" />
            <flux:select wire:model="form.contract_template_id" :label="__('Contract template')" class="sm:col-span-2">
                <option value="">{{ __('Default template') }}</option>
                @foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
            </flux:select>
        </div>

        <flux:fieldset>
            <flux:legend>{{ __('Units') }}</flux:legend>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <flux:select wire:model.live="pickBuilding" :label="__('Building')" class="sm:max-w-56">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($buildings as $b)<option value="{{ $b->id }}">{{ $b->code }} — {{ $b->name }}</option>@endforeach
                </flux:select>
                <flux:select wire:model="pickUnit" :label="__('Unit')" class="sm:max-w-44">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($pickableUnits as $u)<option value="{{ $u->id }}">{{ $u->code }} ({{ $u->list_rent }})</option>@endforeach
                </flux:select>
                <flux:button wire:click="addUnit" icon="plus">{{ __('Add unit') }}</flux:button>
            </div>
            <flux:error name="units" />

            @foreach ($units as $i => $line)
                <flux:card class="mt-4 space-y-3" wire:key="unit-{{ $line['unit_id'] }}">
                    <div class="flex items-center justify-between">
                        <flux:heading>{{ $line['label'] }}</flux:heading>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeUnit({{ $i }})" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <flux:input wire:model="units.{{ $i }}.deposit_amount" inputmode="decimal" :label="__('Deposit (BHD)')" />
                        <flux:input wire:model="units.{{ $i }}.start_date" type="date" :label="__('From (blank = agreement)')" />
                        <flux:input wire:model="units.{{ $i }}.end_date" type="date" :label="__('To (blank = agreement)')" />
                    </div>
                    <flux:error name="units.{{ $i }}.deposit_amount" />
                    <flux:error name="units.{{ $i }}.start_date" />
                    <flux:error name="units.{{ $i }}.end_date" />
                    @foreach ($line['charges'] as $j => $charge)
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]" wire:key="charge-{{ $line['unit_id'] }}-{{ $j }}">
                            <flux:select wire:model="units.{{ $i }}.charges.{{ $j }}.type">
                                @foreach ($chargeTypes as $ct)<option value="{{ $ct->value }}">{{ $ct->label() }}</option>@endforeach
                            </flux:select>
                            <flux:input wire:model="units.{{ $i }}.charges.{{ $j }}.monthly_amount" inputmode="decimal" :placeholder="__('BHD / month')" />
                            <flux:select wire:model="units.{{ $i }}.charges.{{ $j }}.tax_category">
                                @foreach ($taxCategories as $tc)<option value="{{ $tc->value }}">{{ $tc->label() }}</option>@endforeach
                            </flux:select>
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeCharge({{ $i }}, {{ $j }})" />
                        </div>
                        <flux:error name="units.{{ $i }}.charges.{{ $j }}.type" />
                        <flux:error name="units.{{ $i }}.charges.{{ $j }}.monthly_amount" />
                        <flux:error name="units.{{ $i }}.charges.{{ $j }}.tax_category" />
                    @endforeach
                    <flux:error name="units.{{ $i }}.charges" />
                    <flux:button size="sm" wire:click="addCharge({{ $i }})" icon="plus">{{ __('Add charge') }}</flux:button>
                </flux:card>
            @endforeach
        </flux:fieldset>

        <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
    </form>
</section>
