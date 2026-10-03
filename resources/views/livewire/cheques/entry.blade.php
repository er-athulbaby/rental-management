<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Enter cheques — :a, :c', ['a' => $agreement->number, 'c' => $agreement->customer->name_en]) }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-bank-select wire:model="bank_name" />
            <flux:input wire:model="account_holder" :label="__('Account holder (optional)')" />
        </div>
        <flux:error name="bank_name" />

        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($rows as $i => $row)
                <div class="grid gap-2 py-3 sm:grid-cols-4 sm:items-end" wire:key="row-{{ $i }}">
                    <flux:text class="sm:col-span-4">{{ $row['label'] }}</flux:text>
                    <flux:input wire:model="rows.{{ $i }}.cheque_no" :label="__('Cheque no.')" />
                    <flux:input wire:model="rows.{{ $i }}.cheque_date" type="date" :label="__('Cheque date')" />
                    <flux:input wire:model="rows.{{ $i }}.amount" inputmode="decimal" :label="__('Amount')" />
                    <div>
                        @foreach (['cheque_no', 'cheque_date', 'amount', 'invoice_id'] as $f)<flux:error name="rows.{{ $i }}.{{ $f }}" />@endforeach
                    </div>
                </div>
            @empty
                <flux:text>{{ __('Every open invoice already has a cheque.') }}</flux:text>
            @endforelse
        </div>
        <flux:error name="rows" />
        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Save cheques') }}</flux:button>
    </form>
</section>
