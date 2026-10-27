<section class="w-full max-w-5xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Journal :id', ['id' => $journal->id]) }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <flux:text>{{ __('Date') }}</flux:text>
            <div>{{ $journal->date->format('d/m/Y') }}</div>
        </div>
        <div>
            <flux:text>{{ __('Source') }}</flux:text>
            <div class="flex flex-wrap items-center gap-2">
                @if ($sourceUrl)
                    <flux:link :href="$sourceUrl" wire:navigate>{{ $source }}</flux:link>
                @else
                    {{ $source }}
                @endif
                @if ($journal->late_posting)
                    <flux:badge size="sm" color="amber">{{ __('Late posting') }}</flux:badge>
                @endif
            </div>
        </div>
        <div class="sm:col-span-2">
            <flux:text>{{ __('Description') }}</flux:text>
            <div>{{ $journal->description }}</div>
        </div>
        @if ($journal->reverses_journal_id)
            <flux:link :href="route('ledger.journals.show', $journal->reverses_journal_id)" wire:navigate>{{ __('Reverses journal :id', ['id' => $journal->reverses_journal_id]) }}</flux:link>
        @endif
        @if ($reversal)
            <flux:link :href="route('ledger.journals.show', $reversal)" wire:navigate>{{ __('Reversed by journal :id', ['id' => $reversal]) }}</flux:link>
        @endif
    </div>

    <div class="overflow-x-auto">
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
                <flux:table.row class="font-semibold">
                    <flux:table.cell colspan="6">{{ __('Total') }}</flux:table.cell>
                    <flux:table.cell class="text-end tabular-nums">{{ $debits }}</flux:table.cell>
                    <flux:table.cell class="text-end tabular-nums">{{ $credits }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($canReverse)
        <form wire:submit="reverse" class="flex flex-wrap items-end gap-2">
            <x-date-input wire:model="reverseDate" :label="__('Reversal date')" />
            <flux:button type="submit" variant="danger" wire:confirm="{{ __('Post the exact opposite of this journal?') }}">{{ __('Reverse') }}</flux:button>
        </form>
        <flux:error name="reverseDate" />
    @endif
</section>
