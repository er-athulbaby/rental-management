@use('App\Models\ReminderSend')
@use('App\Models\ReminderSetting')
<section class="w-full space-y-4">
    <flux:heading size="xl" level="1">{{ __('Reminders') }}</flux:heading>
    <flux:text>{{ __('Every reminder emailed to tenants, automatic or sent by hand, and the ones skipped.') }}</flux:text>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <x-date-input wire:model.live="from" :label="__('From')" />
        <x-date-input wire:model.live="to" :label="__('To')" />
        <flux:select wire:model.live="type" :label="__('Type')">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" :label="__('Status')">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>
        <x-building-filter :buildings="$buildings" />
        <div class="flex items-end"><flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Item') }}</flux:table.column>
                <flux:table.column>{{ __('Channel') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Reason') }}</flux:table.column>
                <flux:table.column>{{ __('Sent by') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $row)
                    <flux:table.row :key="'rs-'.$row->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $row->sent_on->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $row->customer?->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ ReminderSetting::label($row->type) }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="$row->itemUrl()" wire:navigate>{{ $row->itemLabel() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ __('Email') }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$row->statusColor()">{{ $row->statusLabel() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ ReminderSend::reasonText($row->reason) }}</flux:table.cell>
                        <flux:table.cell>{{ $row->manual ? __('Manual · :name', ['name' => $row->sender?->name]) : __('Automatic') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="8">{{ $valid ? __('No reminders in this period.') : __('Fix the filters above to see reminders.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    {{ $rows->links() }}
</section>
