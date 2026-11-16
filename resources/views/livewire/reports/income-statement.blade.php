<div class="space-y-4">
    <x-ui.page-header :title="__('Income statement')">
        <x-slot:actions>
            <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters>
        <x-building-filter :buildings="$buildings" />
        <flux:select wire:model.live="owner" :label="__('Owner')">
            <option value="">{{ __('All owners') }}</option>
            @foreach ($owners as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </flux:select>
        @include('livewire.reports.partials.statement-inputs')
    </x-ui.filters>

    @if ($statement !== null)
        @if (\App\Reports\IncomeStatement::isEmpty($statement))
            <flux:text>{{ __('Nothing in this period.') }}</flux:text>
        @else
            @include('livewire.reports.partials.statement-table')
        @endif
    @endif

    <flux:text class="text-sm">{{ __('Collected: money received, by posting date, and head lease paid. Billed: invoices issued less credit notes, and head lease due. Amounts are net of VAT; rent on managed units and deposits are left out.') }}</flux:text>
</div>
