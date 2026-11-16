<section class="w-full max-w-5xl space-y-6">
    <x-ui.page-header :title="__('Journal :id', ['id' => $journal->id])" :back="route('ledger.journals')" />

    <x-ui.section>
        <dl class="grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</dt>
                <dd class="mt-0.5 font-medium">{{ $journal->date->format('d/m/Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Source') }}</dt>
                <dd class="mt-0.5 flex flex-wrap items-center gap-2 font-medium">
                    @if ($sourceUrl)
                        <flux:link :href="$sourceUrl" wire:navigate>{{ $source }}</flux:link>
                    @else
                        {{ $source }}
                    @endif
                    @if ($journal->late_posting)
                        <flux:badge size="sm" color="amber">{{ __('Late posting') }}</flux:badge>
                    @endif
                </dd>
            </div>
            <div class="sm:col-span-3">
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Description') }}</dt>
                <dd class="mt-0.5">{{ $journal->description }}</dd>
            </div>
        </dl>
        @if ($journal->reverses_journal_id || $reversal)
            <div class="flex flex-wrap gap-4 border-t border-zinc-200 pt-3 text-sm dark:border-zinc-700">
                @if ($journal->reverses_journal_id)
                    <flux:link :href="route('ledger.journals.show', $journal->reverses_journal_id)" wire:navigate>{{ __('Reverses journal :id', ['id' => $journal->reverses_journal_id]) }}</flux:link>
                @endif
                @if ($reversal)
                    <flux:link :href="route('ledger.journals.show', $reversal)" wire:navigate>{{ __('Reversed by journal :id', ['id' => $reversal]) }}</flux:link>
                @endif
            </div>
        @endif
    </x-ui.section>

    <x-ui.table>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Account') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Building') }}</flux:table.column>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Memo') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Debit') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Credit') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($journal->lines as $line)
                    <flux:table.row :key="'jl-'.$line->id">
                        <flux:table.cell>{{ $line->account->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $line->customer?->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $line->owner?->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $line->building?->code }}</flux:table.cell>
                        <flux:table.cell>{{ $line->unit?->code }}</flux:table.cell>
                        <flux:table.cell>{{ $line->memo }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->debit > 0 ? $line->debit : '' }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->credit > 0 ? $line->credit : '' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
                <flux:table.row class="bg-zinc-50 font-semibold dark:bg-zinc-800/50">
                    <flux:table.cell colspan="6" class="border-t-2 border-zinc-300 dark:border-zinc-600">{{ __('Total') }}</flux:table.cell>
                    <flux:table.cell class="border-t-2 border-zinc-300 text-end tabular-nums dark:border-zinc-600">{{ $debits }}</flux:table.cell>
                    <flux:table.cell class="border-t-2 border-zinc-300 text-end tabular-nums dark:border-zinc-600">{{ $credits }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </x-ui.table>

    @if ($canReverse)
        <form wire:submit="reverse">
            <x-ui.section :title="__('Reverse')" class="max-w-xl">
                <div class="grid gap-4 sm:grid-cols-2 sm:items-end">
                    <x-date-input wire:model="reverseDate" :label="__('Reversal date')" />
                    <div><flux:button type="submit" variant="danger" wire:confirm="{{ __('Post the exact opposite of this journal?') }}">{{ __('Reverse') }}</flux:button></div>
                </div>
                <flux:error name="reverseDate" />
            </x-ui.section>
        </form>
    @endif
</section>
