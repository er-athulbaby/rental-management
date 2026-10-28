<section class="w-full max-w-xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New payment out') }}</flux:heading>
    <flux:text>{{ __('A payment out with no invoice, payment or settlement behind it needs Management approval before it is paid.') }}</flux:text>

    <form wire:submit="save" class="space-y-4">
        <flux:radio.group wire:model.live="form.payee_type" :label="__('Pay to')" variant="segmented">
            <flux:radio value="owner" :label="__('Owner')" />
            <flux:radio value="customer" :label="__('Tenant')" />
            <flux:radio value="vendor" :label="__('Vendor')" />
        </flux:radio.group>
        <flux:select wire:model.live="form.payee_id" :label="__('Payee')">
            <option value="">{{ __('Choose…') }}</option>
            @foreach ($payees as $p)<option value="{{ $p->id }}">{{ $p->name_en }}</option>@endforeach
        </flux:select>

        @if ($vendorMode)
            <flux:text>{{ __('Unpaid expenses: this vendor\'s work orders first; add any other unpaid expense below.') }}</flux:text>
            @if ($candidates->isEmpty())
                <flux:text>{{ __('No unpaid expenses.') }}</flux:text>
            @else
                <flux:checkbox.group wire:model.live="form.expense_ids">
                    @foreach ($candidates as $e)
                        <flux:checkbox wire:key="ex-{{ $e->id }}" :value="(string) $e->id"
                            :label="$e->expense_date->format('d/m/Y').' · '.$e->building->code.' · '.$e->description.' · '.$e->total" />
                    @endforeach
                </flux:checkbox.group>
            @endif
            <div class="flex items-end gap-2">
                <flux:select wire:model="extra" :label="__('Add another unpaid expense')">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($others as $e)<option value="{{ $e->id }}">{{ $e->expense_date->format('d/m/Y') }} · {{ $e->building->code }} · {{ $e->description }} · {{ $e->total }}</option>@endforeach
                </flux:select>
                <flux:button type="button" wire:click="addExpense">{{ __('Add') }}</flux:button>
            </div>
            <flux:error name="extra" />
            <flux:text class="tabular-nums">{{ __('Total: :t BHD', ['t' => $total]) }}</flux:text>
            <flux:select wire:model="form.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
                <flux:select wire:model="form.method" :label="__('Method')">
                    @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                </flux:select>
            </div>
            <flux:textarea wire:model="form.reason" :label="__('Reason')" rows="3" />
        @endif
        @foreach (['payee_type', 'payee_id', 'amount', 'method', 'reason', 'expense_ids'] as $f)<flux:error name="form.{{ $f }}" />@endforeach
        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Send for approval') }}</flux:button>
    </form>
</section>
