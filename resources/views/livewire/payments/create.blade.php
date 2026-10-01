<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Record payment — :name', ['name' => $customer->name_en]) }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.amount" inputmode="decimal" :label="__('Amount (BHD)')" />
            <flux:input wire:model="form.received_on" type="date" :label="__('Received on')" />
            <flux:select wire:model="form.method" :label="__('Method')">
                @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.reference" :label="__('Reference')" />
        </div>
        <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        <flux:error name="form.amount" />
        <flux:error name="form.received_on" />
        <flux:error name="form.method" />

        <flux:fieldset>
            <flux:legend>{{ __('Allocate to') }}</flux:legend>
            <flux:text size="sm">{{ __('Leave every amount blank to pay the oldest invoices first. Anything not allocated stays as customer credit.') }}</flux:text>
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
