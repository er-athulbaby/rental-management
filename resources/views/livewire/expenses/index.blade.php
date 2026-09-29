<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Expenses') }}</flux:heading>
        @can('create', \App\Models\Expense::class)
            <flux:button variant="primary" :href="route('expenses.create')" wire:navigate>{{ __('Record expense') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="buildingId" class="sm:max-w-64">
            <option value="">{{ __('All buildings') }}</option>
            @foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="status" class="sm:max-w-40">
            <option value="">{{ __('Any status') }}</option>
            <option value="recorded">{{ __('Recorded') }}</option>
            <option value="reversed">{{ __('Reversed') }}</option>
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$expenses">
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Building / unit') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column>{{ __('Charged to') }}</flux:table.column>
                <flux:table.column>{{ __('Total (BHD)') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($expenses as $expense)
                    <flux:table.row :key="$expense->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $expense->expense_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $expense->building->code }}{{ $expense->unit ? ' / '.$expense->unit->code : '' }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('expenses.show', $expense)" wire:navigate>{{ str($expense->description)->limit(40) }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ str($expense->charge_to->value)->headline() }}{{ $expense->ownerContract ? ' ('.$expense->ownerContract->number.')' : '' }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $expense->total }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$expense->status->value === 'reversed' ? 'zinc' : 'green'">{{ str($expense->status->value)->headline() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
