<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Manual invoice — :name', ['name' => $customer->name_en])" :back="route('invoices.index')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.section>
            <x-date-input wire:model="form.due_date" :label="__('Due date')" class="max-w-48" aria-required="true" />
        </x-ui.section>

        <x-ui.section>
            @foreach ($lines as $i => $line)
                <flux:card class="space-y-3" wire:key="line-{{ $i }}">
                    <flux:input wire:model="lines.{{ $i }}.description" :label="__('Description')" aria-required="true" />
                    <div class="grid gap-3 sm:grid-cols-4">
                        <flux:select wire:model="lines.{{ $i }}.charge_type" :label="__('Type')" aria-required="true">
                            @foreach ($chargeTypes as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                        </flux:select>
                        <flux:select wire:model="lines.{{ $i }}.unit_id" :label="__('Unit (optional)')">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->building->code }} / {{ $u->code }}</option>@endforeach
                        </flux:select>
                        <flux:input wire:model="lines.{{ $i }}.net" inputmode="decimal" :label="__('Net (BHD)')" aria-required="true" />
                        <flux:select wire:model="lines.{{ $i }}.tax_category" :label="__('Tax')" aria-required="true">
                            @foreach ($taxCategories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
                        </flux:select>
                    </div>
                    @foreach (['description', 'charge_type', 'unit_id', 'net', 'tax_category'] as $field)
                        <flux:error name="lines.{{ $i }}.{{ $field }}" />
                    @endforeach
                    @if (count($lines) > 1)
                        <flux:button size="sm" variant="ghost" wire:click="removeLine({{ $i }})">{{ __('Remove line') }}</flux:button>
                    @endif
                </flux:card>
            @endforeach
            <flux:error name="lines" />

            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="addLine">{{ __('Add line') }}</flux:button>
            </div>
            <flux:text size="sm">{{ __('Tax is calculated when the invoice is issued.') }}</flux:text>
        </x-ui.section>

        <x-ui.form-actions :cancel="route('invoices.index')">
            <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
