<section class="w-full max-w-6xl space-y-6">
    <x-ui.page-header :title="$agreementId ? __('Edit draft agreement') : __('New agreement')" :back="$agreementId ? route('agreements.show', $agreementId) : route('agreements.index')" />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
        <form wire:submit="save" class="space-y-6">
            <flux:error name="form.status" />
            <x-ui.section :title="__('Tenant')">
                @if ($customerLabel)
                    <flux:text>{{ $customerLabel }}</flux:text>
                @endif
                <div>
                    <flux:input wire:model.live.debounce.300ms="customerSearch" :placeholder="__('Name, mobile, ID number or unit code')" icon="magnifying-glass" aria-required="true" :aria-label="__('Tenant')" />
                    @if ($customerResults->isNotEmpty())
                        <ul class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                            @foreach ($customerResults as $c)
                                <li><button type="button" wire:click="selectCustomer({{ $c->id }})" class="w-full px-3 py-2 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $c->name_en }} · {{ $c->maskedId() }} · {{ $c->mobile }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                    <flux:error name="form.customer_id" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Terms')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-date-input wire:model="form.start_date" :label="__('Start date')" aria-required="true" />
                    <x-date-input wire:model="form.end_date" :label="__('End date')" aria-required="true" />
                    <flux:select wire:model="form.frequency" :label="__('Billing frequency')" aria-required="true">
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
            </x-ui.section>

            <x-ui.section :title="__('Units')">
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
                    <flux:card class="space-y-3" wire:key="unit-{{ $line['unit_id'] }}">
                        <div class="flex items-center justify-between">
                            <flux:heading>{{ $line['label'] }}</flux:heading>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeUnit({{ $i }})" :aria-label="__('Remove unit')" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <flux:input wire:model="units.{{ $i }}.deposit_amount" inputmode="decimal" :label="__('Deposit (BHD)')" />
                            <x-date-input wire:model="units.{{ $i }}.start_date" :label="__('From (blank = agreement)')" />
                            <x-date-input wire:model="units.{{ $i }}.end_date" :label="__('To (blank = agreement)')" />
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
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeCharge({{ $i }}, {{ $j }})" :aria-label="__('Remove charge')" />
                            </div>
                            <flux:error name="units.{{ $i }}.charges.{{ $j }}.type" />
                            <flux:error name="units.{{ $i }}.charges.{{ $j }}.monthly_amount" />
                            <flux:error name="units.{{ $i }}.charges.{{ $j }}.tax_category" />
                        @endforeach
                        <flux:error name="units.{{ $i }}.charges" />
                        <flux:button size="sm" wire:click="addCharge({{ $i }})" icon="plus">{{ __('Add charge') }}</flux:button>
                    </flux:card>
                @endforeach
            </x-ui.section>

            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <x-ui.form-actions :cancel="$agreementId ? route('agreements.show', $agreementId) : route('agreements.index')">
                <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
            </x-ui.form-actions>
        </form>

        {{-- Display only (plan ruling 9): integer fils from the same $wire state the inputs bind; a value that is not up to 3 decimals shows a dash. The server alone validates and prices. --}}
        <aside class="lg:sticky lg:top-6" aria-label="{{ __('Running total') }}"
            x-data="{
                fils(v) { const s = String(v ?? '').trim(); if (s === '') return 0; const m = /^(\d{1,9})(?:\.(\d{1,3}))?$/.exec(s); return m ? Number(m[1]) * 1000 + Number((m[2] ?? '').padEnd(3, '0')) : null },
                sum(list) { let t = 0; for (const v of list) { const f = this.fils(v); if (f === null) return null; t += f } return t },
                show(f) { return f === null ? '—' : Math.floor(f / 1000) + '.' + String(f % 1000).padStart(3, '0') },
                lines() { return Object.values($wire.units ?? {}) },
                charges(type) { return this.sum(this.lines().flatMap(u => Object.values(u?.charges ?? {})).filter(c => type === null || c?.type === type).map(c => c?.monthly_amount)) },
                deposits() { return this.sum(this.lines().map(u => u?.deposit_amount)) },
            }">
            <x-ui.section :title="__('Running total')">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Monthly rent') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(charges('rent'))"></span> {{ __('BHD') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('All monthly charges') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(charges(null))"></span> {{ __('BHD') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Total deposit') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(deposits())"></span> {{ __('BHD') }}</dd></div>
                </dl>
            </x-ui.section>
        </aside>
    </div>
</section>
