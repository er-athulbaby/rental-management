<section class="w-full max-w-xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New payment out') }}</flux:heading>
    <flux:text>{{ __('A payment out with no invoice, payment or settlement behind it needs Management approval before it is paid.') }}</flux:text>

    <form wire:submit="save" class="space-y-4">
        <flux:radio.group wire:model.live="form.payee_type" :label="__('Pay to')" variant="segmented">
            <flux:radio value="owner" :label="__('Owner')" />
            <flux:radio value="customer" :label="__('Customer')" />
        </flux:radio.group>
        <flux:select wire:model="form.payee_id" :label="__('Payee')">
            <option value="">{{ __('Choose…') }}</option>
            @foreach ($payees as $p)<option value="{{ $p->id }}">{{ $p->name_en }}</option>@endforeach
        </flux:select>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
            <flux:select wire:model="form.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
        </div>
        <flux:textarea wire:model="form.reason" :label="__('Reason')" rows="3" />
        @foreach (['payee_type', 'payee_id', 'amount', 'method', 'reason'] as $f)<flux:error name="form.{{ $f }}" />@endforeach
        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Send for approval') }}</flux:button>
    </form>
</section>
