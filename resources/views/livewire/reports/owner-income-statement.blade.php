<div class="space-y-4">
    <x-ui.page-header :title="__('Owner income statement')">
        <x-slot:actions>
            <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters>
        <flux:input wire:model.live.debounce.300ms="ownerSearch" :label="__('Find an owner')" :placeholder="__('Name')" icon="magnifying-glass" autocomplete="off" />
        <flux:select wire:model.live="owner" :label="__('Owner')">
            <option value="">{{ __('Choose an owner') }}</option>
            @foreach ($owners as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </flux:select>
        @include('livewire.reports.partials.statement-inputs')
    </x-ui.filters>

    @error('owner')
        <flux:text class="text-sm text-red-600">{{ $message }}</flux:text>
    @enderror

    @if ($chosen === null)
        <flux:text>{{ __('Choose an owner to see their statement.') }}</flux:text>
    @elseif ($statement !== null)
        @if (\App\Reports\IncomeStatement::isEmpty($statement))
            <flux:text>{{ __('Nothing in this period.') }}</flux:text>
        @else
            @include('livewire.reports.partials.statement-table')
        @endif
    @endif

    <flux:text class="text-sm">{{ $basis === 'billed' ? __('Billed: head lease due, and charges invoiced on the owner\'s units less credit notes, net of VAT, without deposits.') : __('Collected: rent and charges received on the owner\'s units, net of VAT, without deposits, as in the owner ledger. Rent and charges follow the same entries as owner statements; expenses are counted by expense date, so a month can differ from its statement when an expense was posted late.') }}</flux:text>
</div>
