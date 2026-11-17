<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Enter cheques — :a, :c', ['a' => $agreement->number, 'c' => $agreement->customer->name_en])" :back="route('cheques.index')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Bank details')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-bank-select wire:model="bank_name" aria-required="true" />
                <flux:input wire:model="account_holder" :label="__('Account holder (optional)')" />
            </div>
            <flux:error name="bank_name" />
        </x-ui.section>

        <x-ui.section :title="__('Cheques')" :hint="__('Post-dated cheques are entered against an active agreement, one cheque per rent invoice.')" flush>
            <div class="hidden grid-cols-[minmax(0,1.6fr)_1fr_1fr_1fr] gap-3 border-b border-zinc-200 bg-zinc-50 px-4 py-2 text-xs font-semibold text-zinc-600 sm:grid sm:px-6 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300" aria-hidden="true">
                <span>{{ __('Invoice') }}</span><span>{{ __('Cheque no.') }}</span><span>{{ __('Cheque date') }}</span><span>{{ __('Amount') }}</span>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($rows as $i => $row)
                    <div class="grid gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1.6fr)_1fr_1fr_1fr] sm:items-start sm:px-6 sm:[&_[data-flux-label]]:sr-only" wire:key="row-{{ $i }}">
                        <flux:text class="font-medium text-zinc-900 sm:pt-2 dark:text-white">{{ $row['label'] }}</flux:text>
                        <flux:input wire:model="rows.{{ $i }}.cheque_no" :label="__('Cheque no.')" aria-required="true" />
                        <x-date-input wire:model="rows.{{ $i }}.cheque_date" :label="__('Cheque date')" aria-required="true" />
                        <flux:input wire:model="rows.{{ $i }}.amount" inputmode="decimal" :label="__('Amount')" aria-required="true" class="text-end tabular-nums" />
                        <div class="sm:col-span-4 empty:hidden">
                            @foreach (['cheque_no', 'cheque_date', 'amount', 'invoice_id'] as $f)<flux:error name="rows.{{ $i }}.{{ $f }}" />@endforeach
                        </div>
                    </div>
                @empty
                    <flux:text class="px-4 py-3 sm:px-6">{{ __('Every open invoice already has a cheque.') }}</flux:text>
                @endforelse
            </div>
            <flux:error name="rows" class="px-4 py-2 sm:px-6" />
        </x-ui.section>

        <x-ui.form-actions :cancel="route('cheques.index')">
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Save cheques') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
