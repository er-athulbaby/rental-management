<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $vendor?->name ?? __('New vendor') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:input wire:model="form.name" :label="__('Name')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.type" :label="__('Type')">
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.mobile" :label="__('Mobile')" type="tel" required />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.cr_number" :label="__('CR number')" />
                <flux:input wire:model="form.vat_number" :label="__('VAT number')" />
            </div>
            <flux:checkbox.group wire:model="form.trades" :label="__('Trades')" class="grid gap-2 sm:grid-cols-2">
                @foreach ($trades as $t)<flux:checkbox :value="$t->value" :label="$t->label()" />@endforeach
            </flux:checkbox.group>
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
            <flux:switch wire:model="form.active" :label="__('Active: can get new work orders')" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>
</section>
