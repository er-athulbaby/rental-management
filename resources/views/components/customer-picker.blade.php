@props(['results', 'term', 'action'])

{{-- Type-to-search customer list for components with a public $customerSearch; clicking a row calls $action(customerId). --}}
<div class="space-y-2">
    <flux:input wire:model.live.debounce.300ms="customerSearch" :label="__('Customer')" :placeholder="__('Name, mobile, ID number or unit code')" icon="magnifying-glass" autocomplete="off" />
    @if (mb_strlen(trim($term)) < 2)
        <flux:text class="text-sm">{{ __('Type at least 2 letters or digits.') }}</flux:text>
    @elseif ($results->isEmpty())
        <flux:text class="text-sm">{{ __('No customer found.') }}</flux:text>
    @else
        <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($results as $c)
                <li wire:key="pick-{{ $c->id }}">
                    <button type="button" wire:click="{{ $action }}({{ $c->id }})" class="w-full px-3 py-2 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <span class="font-medium">{{ $c->name_en }}</span>
                        <span class="text-zinc-500">· {{ $c->mobile }} · {{ $c->maskedId() }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
