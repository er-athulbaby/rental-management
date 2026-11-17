@php
    $mismatches = collect($health)->where('ok', false)->count();
@endphp
<section class="w-full max-w-6xl space-y-6" @if ($settings->rebuilding()) wire:poll.2s @endif>
    <x-ui.page-header :title="__('Ledger')" />

    @if (! $settings->start_date)
        <x-ui.section>
            <x-ui.empty :title="__('Not set up yet: Admin sets the start date.')" icon="book-open" />
        </x-ui.section>
    @endif

    <x-ui.stats>
        <x-ui.stat :label="__('Start date')" :value="$settings->start_date ? $settings->start_date->format('d/m/Y') : '—'" />
        <x-ui.stat :label="__('Locked up to')" :value="$settings->locked_until ? $settings->locked_until->format('d/m/Y') : __('Nothing is locked.')" />
        <x-ui.stat :label="__('Last checked')" :value="$settings->health_checked_at ? $settings->health_checked_at->format('d/m/Y H:i') : __('Not checked yet.')" />
        <x-ui.stat :label="__('Checks with a difference')" :value="$settings->health_checked_at ? (string) $mismatches : '—'" :tone="$mismatches > 0 ? 'danger' : null" />
    </x-ui.stats>

    <x-ui.section :title="__('Ledger health')" flush>
        <x-slot:actions>
            <flux:button size="sm" wire:click="checkNow">{{ __('Check now') }}</flux:button>
        </x-slot:actions>
        @if ($health !== [])
            <x-ui.table>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Check') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In the ledger') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In the app') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Difference') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($health as $i => $row)
                            <flux:table.row :key="'h-'.$i">
                                <flux:table.cell>{{ $row['label'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $row['ledger'] }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $row['app'] }}</flux:table.cell>
                                <flux:table.cell @class(['text-end tabular-nums', 'font-semibold text-red-700 dark:text-red-400' => ! $row['ok']])>{{ $row['difference'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-ui.table>
        @endif
    </x-ui.section>

    @if ($canManage || $canAdmin)
        <div class="grid items-start gap-6 lg:grid-cols-2">
            @if ($canManage || ($canAdmin && $settings->locked_until))
                <x-ui.section :title="__('Lock a period')">
                    @if ($canManage)
                        <form wire:submit="lock" class="grid gap-4 sm:grid-cols-2 sm:items-end">
                            <x-date-input wire:model="lockDate" :label="__('Lock up to')" />
                            <div><flux:button type="submit">{{ __('Lock') }}</flux:button></div>
                        </form>
                    @endif

                    @if ($canAdmin && $settings->locked_until)
                        <form wire:submit="unlock" class="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <div class="grid gap-4 sm:grid-cols-2 sm:items-end">
                                <x-date-input wire:model="unlockDate" :label="__('Unlock back to')" />
                                <div><flux:button type="submit" variant="danger">{{ __('Unlock') }}</flux:button></div>
                            </div>
                            <flux:text>{{ __('Leave empty to unlock everything.') }}</flux:text>
                        </form>
                    @endif
                </x-ui.section>
            @endif

            <x-ui.section :title="__('Rebuild months')">
                <form wire:submit="rebuild" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3 sm:items-end">
                        <flux:input type="month" wire:model="fromMonth" :label="__('From month')" />
                        <flux:input type="month" wire:model="toMonth" :label="__('To month')" />
                        <div><flux:button type="submit" :disabled="$settings->rebuilding()">{{ __('Rebuild months') }}</flux:button></div>
                    </div>
                    <flux:error name="rebuild" />
                    @if ($settings->rebuild_status === 'queued')
                        <flux:text>{{ __('Rebuild waiting to start.') }}</flux:text>
                    @elseif ($settings->rebuild_status === 'running')
                        <flux:text>{{ __('Rebuilding: :done of :total records posted.', ['done' => $settings->rebuild_done, 'total' => $settings->rebuild_total]) }}</flux:text>
                    @elseif ($settings->rebuild_status === 'done' && $settings->rebuild_from && $settings->rebuild_to)
                        <flux:text>{{ __('Rebuilt :from – :to.', ['from' => $settings->rebuild_from->format('d/m/Y'), 'to' => $settings->rebuild_to->format('d/m/Y')]) }}</flux:text>
                    @elseif ($settings->rebuild_status === 'failed')
                        <flux:text class="text-red-700 dark:text-red-400">{{ __('The last rebuild failed: :message', ['message' => $rebuildMessage]) }}</flux:text>
                    @endif
                </form>
            </x-ui.section>
        </div>
    @endif
</section>
