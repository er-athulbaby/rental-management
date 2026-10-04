<section class="w-full space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <flux:heading size="xl" level="1">{{ __('Payments out') }}</flux:heading>
        @can('create', App\Models\Disbursement::class)
            <flux:button variant="primary" :href="route('disbursements.create')" wire:navigate>{{ __('New payment out') }}</flux:button>
        @endcan
    </div>
    <div class="flex flex-col gap-3 sm:flex-row">
    <flux:select wire:model.live="status" :label="__('Status')" class="sm:max-w-48">
        <option value="all">{{ __('All') }}</option>
        @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
    </flux:select>
    <x-building-filter :buildings="$this->buildings" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Payee') }}</flux:table.column>
                <flux:table.column>{{ __('Purpose') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row :key="$row->id">
                        <flux:table.cell><flux:link :href="route('disbursements.show', $row)" wire:navigate>{{ $row->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $row->payee()->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $row->purpose->label() }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $row->amount }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $row->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
