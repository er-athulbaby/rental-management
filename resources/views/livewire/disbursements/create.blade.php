<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('New payment out')" :hint="__('A payment out with no invoice, payment or settlement behind it needs Management approval before it is paid.')" :back="route('disbursements.index')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Payee')" :hint="__('Who is being paid.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:radio.group wire:model.live="form.payee_type" :label="__('Pay to')" variant="segmented" class="sm:col-span-2">
                    <flux:radio value="owner" :label="__('Owner')" />
                    <flux:radio value="customer" :label="__('Tenant')" />
                    <flux:radio value="vendor" :label="__('Vendor')" />
                </flux:radio.group>
                <flux:select wire:model.live="form.payee_id" :label="__('Payee')" aria-required="true" class="sm:col-span-2">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($payees as $p)<option value="{{ $p->id }}">{{ $p->name_en }}</option>@endforeach
                </flux:select>
            </div>
        </x-ui.section>

        @if ($vendorMode)
            <x-ui.section :title="__('Unpaid expenses')" :hint="__('Unpaid expenses: this vendor\'s work orders first; add any other unpaid expense below.')" flush>
                @if ($candidates->isEmpty())
                    <flux:text class="px-4 py-3 sm:px-6">{{ __('No unpaid expenses.') }}</flux:text>
                @else
                    <flux:checkbox.group wire:model.live="form.expense_ids">
                        <x-ui.table>
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column class="w-10"></flux:table.column>
                                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                                    <flux:table.column>{{ __('Building') }}</flux:table.column>
                                    <flux:table.column>{{ __('Expense') }}</flux:table.column>
                                    <flux:table.column align="end">{{ __('Total (BHD)') }}</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($candidates as $e)
                                        <flux:table.row :key="'ex-'.$e->id">
                                            <flux:table.cell><flux:checkbox wire:key="ex-{{ $e->id }}" :value="(string) $e->id" :aria-label="$e->expense_date->format('d/m/Y').' · '.$e->building->code.' · '.$e->description.' · '.$e->total" /></flux:table.cell>
                                            <flux:table.cell class="whitespace-nowrap">{{ $e->expense_date->format('d/m/Y') }}</flux:table.cell>
                                            <flux:table.cell>{{ $e->building->code }}</flux:table.cell>
                                            <flux:table.cell>{{ $e->description }}</flux:table.cell>
                                            <flux:table.cell class="text-end tabular-nums">{{ $e->total }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </x-ui.table>
                    </flux:checkbox.group>
                @endif
                <div class="flex items-end gap-2 border-t border-zinc-200 px-4 py-3 sm:px-6 dark:border-zinc-700">
                    <flux:select wire:model="extra" :label="__('Add another unpaid expense')">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($others as $e)<option value="{{ $e->id }}">{{ $e->expense_date->format('d/m/Y') }} · {{ $e->building->code }} · {{ $e->description }} · {{ $e->total }}</option>@endforeach
                    </flux:select>
                    <flux:button type="button" wire:click="addExpense">{{ __('Add') }}</flux:button>
                </div>
                <flux:error name="extra" class="px-4 pb-3 sm:px-6" />
                <x-ui.totals :rows="[[__('Total: :t BHD', ['t' => $total]), null, true]]" />
            </x-ui.section>
        @endif

        <x-ui.section :title="__('Payment')">
            @if ($vendorMode)
                <flux:select wire:model="form.method" :label="__('Method')" aria-required="true">
                    @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                </flux:select>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.amount" inputmode="decimal" :label="__('Amount (BHD)')" aria-required="true" />
                    <flux:select wire:model="form.method" :label="__('Method')" aria-required="true">
                        @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                    </flux:select>
                </div>
                <flux:textarea wire:model="form.reason" :label="__('Reason')" rows="3" aria-required="true" />
            @endif
            @foreach (['payee_type', 'payee_id', 'amount', 'method', 'reason', 'expense_ids'] as $f)<flux:error name="form.{{ $f }}" />@endforeach
        </x-ui.section>

        <x-ui.form-actions :cancel="route('disbursements.index')">
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Send for approval') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
