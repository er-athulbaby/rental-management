<div class="space-y-4">
    <flux:heading size="xl">{{ __('Owner statements') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="status" :label="__('Status')">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($statuses as $s)
                <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="building" :label="__('Building')">
            <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
            @foreach ($buildings as $b)
                <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$statements">
            <flux:table.columns>
                <flux:table.column>{{ __('Month') }}</flux:table.column>
                <flux:table.column>{{ __('Statement') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Closing balance') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($statements as $s)
                    <flux:table.row :key="'os-'.$s->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $s->period_start->format('M Y') }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('owner-statements.show', $s)" wire:navigate>{{ $s->label() }}</flux:link> <span class="text-zinc-500">{{ $s->contract->number }}</span></flux:table.cell>
                        <flux:table.cell>{{ $s->contract->owner->name_en }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $s->closing_balance }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $s->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No statements yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
