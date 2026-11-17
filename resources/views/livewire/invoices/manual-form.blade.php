<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Manual invoice — :name', ['name' => $customer->name_en])" :back="route('invoices.index')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Details')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-date-input wire:model="form.due_date" :label="__('Due date')" aria-required="true" />
            </div>
        </x-ui.section>

        <x-ui.section :title="__('Lines')" :hint="__('Tax is calculated when the invoice is issued.')">
            @foreach ($lines as $i => $line)
                <div class="space-y-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/40" wire:key="line-{{ $i }}">
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
                </div>
            @endforeach
            <flux:error name="lines" />

            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="addLine">{{ __('Add line') }}</flux:button>
            </div>
        </x-ui.section>

        <x-ui.form-actions :cancel="route('invoices.index')">
            <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
