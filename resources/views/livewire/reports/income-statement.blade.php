<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Income statement') }}</flux:heading>
        <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
    </div>

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-building-filter :buildings="$buildings" />
        <flux:select wire:model.live="owner" :label="__('Owner')">
            <option value="">{{ __('All owners') }}</option>
            @foreach ($owners as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </flux:select>
        @include('livewire.reports.partials.statement-inputs')
    </div>

    @if ($statement !== null)
        @if (\App\Reports\IncomeStatement::isEmpty($statement))
            <flux:text>{{ __('Nothing in this period.') }}</flux:text>
        @else
            @include('livewire.reports.partials.statement-table')
        @endif
    @endif

    <flux:text class="text-sm">{{ __('Collected: money received, by posting date, and head lease paid. Billed: invoices issued less credit notes, and head lease due. Amounts are net of VAT; rent on managed units and deposits are left out.') }}</flux:text>
</div>
