<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Owner income statement') }}</flux:heading>
        <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
    </div>

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <flux:input wire:model.live.debounce.300ms="ownerSearch" :label="__('Find an owner')" :placeholder="__('Name')" icon="magnifying-glass" autocomplete="off" />
        <flux:select wire:model.live="owner" :label="__('Owner')">
            <option value="">{{ __('Choose an owner') }}</option>
            @foreach ($owners as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </flux:select>
        @include('livewire.reports.partials.statement-inputs')
    </div>

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

    <flux:text class="text-sm">{{ __('Collected: rent and charges received on the owner\'s units, net of VAT, without deposits, as in the owner ledger. Months agree with finalised owner statements, which cover calendar months.') }}</flux:text>
</div>
