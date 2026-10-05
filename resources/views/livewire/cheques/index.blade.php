<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Cheques') }}</flux:heading>
        @if ($canManage)
            <flux:modal.trigger name="enter-cheques">
                <flux:button variant="primary">{{ __('Enter cheques') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if ($canManage)
        <flux:modal name="enter-cheques" class="md:w-96">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Enter cheques') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Post-dated cheques are entered against an active agreement, one cheque per rent invoice.') }}</flux:text>
                <flux:input wire:model.live.debounce.300ms="agreementSearch" :label="__('Agreement')" :placeholder="__('Agreement no., tenant, mobile or unit code')" icon="magnifying-glass" autocomplete="off" />
                @if (mb_strlen(trim($agreementSearch)) < 2)
                    <flux:text class="text-sm">{{ __('Type at least 2 letters or digits.') }}</flux:text>
                @elseif ($agreementResults->isEmpty())
                    <flux:text class="text-sm">{{ __('No active agreement found.') }}</flux:text>
                @else
                    <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($agreementResults as $a)
                            <li wire:key="pick-{{ $a->id }}">
                                <button type="button" wire:click="startEntry({{ $a->id }})" class="w-full px-3 py-2 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                    <span class="font-medium">{{ $a->label() }}</span>
                                    <span class="text-zinc-500">· {{ $a->customer->name_en }} · {{ $a->customer->mobile }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </flux:modal>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="status" :label="__('Status')" class="max-w-48">
            <option value="all">{{ __('All') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <x-building-filter :buildings="$this->buildings" />
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Cheque no., tenant or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />
        @if ($canManage && $status === 'held')
            <x-date-input wire:model="depositedOn" :label="__('Deposited on')" class="max-w-44" />
            <flux:button variant="primary" wire:click="depositSelected" :disabled="count($selected) === 0">{{ __('Deposit selected') }}</flux:button>
        @endif
    </div>
    <flux:error name="selected" />
    <flux:error name="deposited_on" />

    <div class="overflow-x-auto">
        <flux:table :paginate="$cheques">
            <flux:table.columns>
                @if ($canManage && $status === 'held')<flux:table.column></flux:table.column>@endif
                <flux:table.column>{{ __('Cheque') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Invoice') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($cheques as $cheque)
                    <flux:table.row :key="$cheque->id">
                        @if ($canManage && $status === 'held')
                            <flux:table.cell><flux:checkbox wire:model.live="selected" value="{{ $cheque->id }}" /></flux:table.cell>
                        @endif
                        <flux:table.cell><flux:link :href="route('cheques.show', $cheque)" wire:navigate>{{ $cheque->bank_name }} #{{ $cheque->cheque_no }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $cheque->customer->name_en }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $cheque->cheque_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $cheque->amount }}</flux:table.cell>
                        <flux:table.cell>{{ $cheque->invoice?->label() ?? '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $cheque->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
