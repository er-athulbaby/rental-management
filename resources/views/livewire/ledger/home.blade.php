<section class="w-full max-w-4xl space-y-8" @if ($settings->rebuilding()) wire:poll.2s @endif>
    <flux:heading size="xl" level="1">{{ __('Ledger') }}</flux:heading>

    <div class="space-y-1">
        <flux:text>{{ $settings->start_date ? __('Start date :date', ['date' => $settings->start_date->format('d/m/Y')]) : __('Not set up yet: Admin sets the start date.') }}</flux:text>
        <flux:text>{{ $settings->locked_until ? __('Locked up to :date', ['date' => $settings->locked_until->format('d/m/Y')]) : __('Nothing is locked.') }}</flux:text>
    </div>

    <flux:card class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">{{ __('Ledger health') }}</flux:heading>
            <flux:button size="sm" wire:click="checkNow">{{ __('Check now') }}</flux:button>
        </div>
        <flux:text>{{ $settings->health_checked_at ? __('Last checked :when', ['when' => $settings->health_checked_at->format('d/m/Y H:i')]) : __('Not checked yet.') }}</flux:text>
        @if ($health !== [])
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Check') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In the ledger') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In the app') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Difference') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($health as $i => $row)
                            <flux:table.row :key="'h-'.$i" :class="$row['ok'] ? '' : 'text-red-600 dark:text-red-400'">
                                <flux:table.cell>{{ $row['label'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $row['ledger'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $row['app'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $row['difference'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </flux:card>

    @if ($canManage)
        <form wire:submit="lock" class="flex flex-wrap items-end gap-2">
            <x-date-input wire:model="lockDate" :label="__('Lock up to')" />
            <flux:button type="submit">{{ __('Lock') }}</flux:button>
        </form>
    @endif

    @if ($canAdmin && $settings->locked_until)
        <form wire:submit="unlock" class="space-y-1">
            <div class="flex flex-wrap items-end gap-2">
                <x-date-input wire:model="unlockDate" :label="__('Unlock back to')" />
                <flux:button type="submit" variant="danger">{{ __('Unlock') }}</flux:button>
            </div>
            <flux:text>{{ __('Leave empty to unlock everything.') }}</flux:text>
        </form>
    @endif

    @if ($canManage || $canAdmin)
        <form wire:submit="rebuild" class="space-y-2">
            <div class="flex flex-wrap items-end gap-2">
                <flux:input type="month" wire:model="fromMonth" :label="__('From month')" />
                <flux:input type="month" wire:model="toMonth" :label="__('To month')" />
                <flux:button type="submit" :disabled="$settings->rebuilding()">{{ __('Rebuild months') }}</flux:button>
            </div>
            <flux:error name="rebuild" />
            @if ($settings->rebuild_status === 'queued')
                <flux:text>{{ __('Rebuild waiting to start.') }}</flux:text>
            @elseif ($settings->rebuild_status === 'running')
                <flux:text>{{ __('Rebuilding: :done of :total records posted.', ['done' => $settings->rebuild_done, 'total' => $settings->rebuild_total]) }}</flux:text>
            @elseif ($settings->rebuild_status === 'done' && $settings->rebuild_from && $settings->rebuild_to)
                <flux:text>{{ __('Rebuilt :from – :to.', ['from' => $settings->rebuild_from->format('d/m/Y'), 'to' => $settings->rebuild_to->format('d/m/Y')]) }}</flux:text>
            @elseif ($settings->rebuild_status === 'failed')
                <flux:text class="text-red-600 dark:text-red-400">{{ __('The last rebuild failed: :message', ['message' => $rebuildMessage]) }}</flux:text>
            @endif
        </form>
    @endif
</section>
