<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Record payment — :name', ['name' => $customer->name_en]) }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model.live="form.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                <option value="split">{{ __('Split — several methods, one receipt') }}</option>
            </flux:select>
            <flux:input wire:model="form.received_on" type="date" :label="__('Received on')" />
            @unless ($isSplit)
                <flux:input wire:model="form.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
                <flux:input wire:model="form.reference" :label="__('Reference')" />
            @endunless
        </div>

        @if ($isSplit)
            <flux:fieldset>
                <flux:legend>{{ __('Paid by') }}</flux:legend>
                <flux:text size="sm">{{ __('One line per method, e.g. card 300 and cash 200. The payment is their total, on one receipt.') }}</flux:text>
                <div class="mt-2 space-y-2">
                    @foreach ($tenders as $i => $t)
                        <div class="flex flex-wrap items-start gap-2" wire:key="tender-{{ $i }}">
                            <flux:select wire:model="tenders.{{ $i }}.method" :aria-label="__('Method')" class="max-w-40">
                                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                            </flux:select>
                            <flux:input wire:model.live.debounce.400ms="tenders.{{ $i }}.amount" inputmode="decimal" :placeholder="__('BHD')" :aria-label="__('Amount (BHD)')" class="max-w-32" />
                            <flux:input wire:model="tenders.{{ $i }}.reference" :placeholder="__('Reference, e.g. card slip no.')" :aria-label="__('Reference')" class="min-w-0 flex-1" />
                            @if (count($tenders) > 2)
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeTender({{ $i }})" :aria-label="__('Remove')" />
                            @endif
                        </div>
                        <flux:error name="tenders.{{ $i }}.method" /><flux:error name="tenders.{{ $i }}.amount" /><flux:error name="tenders.{{ $i }}.reference" />
                    @endforeach
                </div>
                <div class="mt-2 flex items-center justify-between gap-2">
                    <flux:button size="sm" wire:click="addTender" icon="plus">{{ __('Add a method') }}</flux:button>
                    <flux:text class="font-medium tabular-nums">{{ __('Total: :t BHD', ['t' => $tendersTotal ?? '—']) }}</flux:text>
                </div>
                <flux:error name="tenders" />
            </flux:fieldset>
        @endif
        <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        <flux:error name="form.amount" />
        <flux:error name="form.received_on" />
        <flux:error name="form.method" />

        <flux:fieldset>
            <flux:legend>{{ __('Allocate to') }}</flux:legend>
            <flux:text size="sm">{{ __('Leave every amount blank to pay the oldest invoices first. Anything not allocated stays as tenant credit.') }}</flux:text>
            <div class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($open as $invoice)
                    <div class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="open-{{ $invoice->id }}">
                        <div class="text-sm">{{ $invoice->number }} · {{ __('due :d', ['d' => $invoice->due_date->format('d/m/Y')]) }} · {{ __('balance :b', ['b' => $invoice->balance]) }}</div>
                        <flux:input wire:model="split.{{ $invoice->id }}" inputmode="decimal" :placeholder="__('BHD')" class="max-w-32" />
                    </div>
                @empty
                    <flux:text>{{ __('Nothing is owed: the whole payment will be credit.') }}</flux:text>
                @endforelse
            </div>
            <flux:error name="allocations" />
        </flux:fieldset>

        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Record payment') }}</flux:button>
    </form>
</section>
