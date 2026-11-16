<section class="w-full max-w-6xl space-y-6">
    <x-ui.page-header :title="__('VAT returns')" :hint="__('Record the VAT you paid to, or got back from, NBR for a period.')" />

    @if ($canManage)
        <form wire:submit="save">
        <x-ui.section :title="__('Record a return')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-date-input wire:model.live="period_from" :label="__('Period from')" />
                <x-date-input wire:model.live="period_to" :label="__('Period to')" />
            </div>
            @if ($figure !== null)
                <flux:callout icon="information-circle" :color="$figure < 0 ? 'green' : 'amber'" class="sm:col-span-2">
                    <flux:callout.text>{{ $figure < 0
                        ? __('VAT refundable for the period: :a BHD. Record the refund from NBR.', ['a' => $figureAmount])
                        : __('VAT payable for the period: :a BHD. Record the payment to NBR.', ['a' => $figureAmount]) }}</flux:callout.text>
                </flux:callout>
            @endif
            @if ($figure === 0)
                <flux:select wire:model="direction" :label="__('Direction')">
                    <flux:select.option value="payment">{{ __('Payment') }}</flux:select.option>
                    <flux:select.option value="refund">{{ __('Refund') }}</flux:select.option>
                </flux:select>
            @endif
            <div class="grid gap-4 sm:grid-cols-4">
                <x-date-input wire:model="date" :label="__('Date')" />
                <flux:select wire:model="method" :label="__('Method')">
                    @foreach ($methods as $m)
                        <flux:select.option :value="$m->value">{{ __($m->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="reference" :label="__('Reference')" />
                <flux:input wire:model="amount" inputmode="decimal" :label="__('Amount')" />
            </div>
            <flux:input wire:model="note" :label="__('Note')" />
            @foreach (['period_from', 'period_to', 'date', 'method', 'reference', 'amount', 'note', 'direction'] as $field)
                <flux:error name="{{ $field }}" />
            @endforeach
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Record return') }}</flux:button>
            </div>
        </x-ui.section>
        </form>
    @endif

    @if ($reversingId !== null)
        <form wire:submit="reverse">
            <x-ui.section :title="__('Reverse').' #'.$reversingId" tone="danger" class="max-w-4xl">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-date-input wire:model="reverseDate" :label="__('Reversal date')" />
                    <flux:input wire:model="reverseReason" :label="__('Reason')" />
                </div>
                <flux:error name="reverseDate" />
                <flux:error name="reverseReason" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancelReverse">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="danger">{{ __('Reverse') }}</flux:button>
                </div>
            </x-ui.section>
        </form>
    @endif

    <x-ui.table>
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>{{ __('Period') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Ledger figure') }}</flux:table.column>
                <flux:table.column>{{ __('Paid or refunded') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Difference') }}</flux:table.column>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Method') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row :key="'v-'.$row->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $row->period_from->format('d/m/Y') }} – {{ $row->period_to->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $lines[$row->id]['figure'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row->direction === 'refund' ? __('Refunded by NBR') : __('Paid to NBR') }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $row->amount }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $lines[$row->id]['difference'] ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $row->date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ __($row->method->label()) }}</flux:table.cell>
                        <flux:table.cell>{{ $row->reference }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            @if ($row->reversal !== null)
                                <flux:badge size="sm" color="zinc">{{ __('Reversed') }} {{ $row->reversal->date->format('d/m/Y') }}</flux:badge>
                            @elseif ($canManage)
                                <flux:button size="sm" wire:click="startReverse({{ $row->id }})">{{ __('Reverse') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($rows->isEmpty())
            <x-ui.empty />
        @endif
    </x-ui.table>
</section>
