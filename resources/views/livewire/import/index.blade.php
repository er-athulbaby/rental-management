<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Data import') }}</flux:heading>
    <flux:text>{{ __('Fill the templates (ID, phone, IBAN and money columns formatted as Text), then run a dry run. Nothing is saved until every row of every file passes.') }}</flux:text>

    @if ($closed)
        <flux:callout variant="danger" icon="lock-closed" :heading="$closed" />
    @else
        @if ($cutover)
            <flux:callout icon="calendar" :heading="__('Cutover date: :d', ['d' => $cutover->format('d/m/Y')])">
                {{ __('Balances, deposits and cheques are as at this date. Schedules start with the first period on or after it.') }}
            </flux:callout>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Set the go-live date first (php artisan rms:setting go_live_at YYYY-MM-DD).')" />
        @endif

        <flux:error name="import" />

        <div class="space-y-4">
            @foreach ($kinds as $kind)
                <flux:field wire:key="file-{{ $kind->value }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:label>{{ str($kind->value)->headline() }}</flux:label>
                        <flux:link :href="route('import.template', $kind)">{{ __('Download template') }}</flux:link>
                    </div>
                    <x-file-button wire:model="files.{{ $kind->value }}" accept=".xlsx,.csv" />
                    <flux:error name="files.{{ $kind->value }}" />
                </flux:field>
            @endforeach
            <flux:error name="files" />
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="run(false)">{{ __('Dry run') }}</flux:button>
            <flux:button variant="primary" wire:click="run(true)" wire:confirm="{{ __('Import these files for real?') }}">{{ __('Import') }}</flux:button>
        </div>
    @endif

    @if ($result)
        <flux:callout :variant="$result['errors'] === [] ? 'success' : 'warning'"
            :heading="$result['committed'] ? __('Imported.') : ($result['errors'] === [] ? __('Dry run passed: nothing was saved.') : __('Problems found: nothing was saved.'))" />

        <ul class="text-sm">
            @foreach ($result['counts'] as $kind => $count)
                <li>{{ str($kind)->replace('_', ' ')->ucfirst() }}: {{ trans_choice(':count row passed|:count rows passed', $count) }}</li>
            @endforeach
        </ul>

        @if (! empty($result['totals']))
            <ul class="text-sm">
                @foreach ($result['totals'] as $kind => $total)
                    <li>{{ __(':kind total: :total BHD', ['kind' => str($kind)->replace('_', ' ')->ucfirst(), 'total' => $total]) }}</li>
                @endforeach
            </ul>
        @endif

        @foreach ($result['errors'] as $kind => $lines)
            <div class="space-y-1">
                <flux:heading>{{ str($kind)->headline() }}</flux:heading>
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($lines as $line => $messages)
                        <li>{{ __('Line :line', ['line' => $line]) }}: {{ implode(' ', $messages) }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif
</section>
