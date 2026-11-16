<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Data import')" :hint="__('Fill the templates (ID, phone, IBAN and money columns formatted as Text), then run a dry run. Nothing is saved until every row of every file passes.')" />

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

        <x-ui.section :title="__('1. Download the templates')" :hint="__('One template per kind of data. Fill them in a spreadsheet and save as .xlsx or .csv.')">
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($kinds as $kind)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $kind->label() }}</span>
                        <flux:link :href="route('import.template', $kind)">{{ __('Download template') }}</flux:link>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>

        <x-ui.section :title="__('2. Choose your files')" :hint="__('Attach each filled template. Leave a kind out if you have nothing to import for it.')">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($kinds as $kind)
                    <flux:field wire:key="file-{{ $kind->value }}">
                        <flux:label>{{ $kind->label() }}</flux:label>
                        <x-file-button wire:model="files.{{ $kind->value }}" accept=".xlsx,.csv" />
                        <flux:error name="files.{{ $kind->value }}" />
                    </flux:field>
                @endforeach
            </div>
            <flux:error name="files" />
        </x-ui.section>

        <x-ui.section :title="__('3. Check with a dry run')" :hint="__('Reads every row and reports problems. Nothing is saved.')">
            <div><flux:button wire:click="run(false)">{{ __('Dry run') }}</flux:button></div>
        </x-ui.section>

        <x-ui.section :title="__('4. Import')" :hint="__('Saves everything. Only possible when every row of every file passes.')">
            <div><flux:button variant="primary" wire:click="run(true)" wire:confirm="{{ __('Import these files for real?') }}">{{ __('Import') }}</flux:button></div>
        </x-ui.section>
    @endif

    @if ($result)
        <flux:callout :variant="$result['errors'] === [] ? 'success' : 'warning'"
            :heading="$result['committed'] ? __('Imported.') : ($result['errors'] === [] ? __('Dry run passed: nothing was saved.') : __('Problems found: nothing was saved.'))">
            <ul class="space-y-0.5 text-sm">
                @foreach ($result['counts'] as $kind => $count)
                    <li>{{ str($kind)->replace('_', ' ')->ucfirst() }}: {{ trans_choice(':count row passed|:count rows passed', $count) }}</li>
                @endforeach
                @if (! empty($result['totals']))
                    @foreach ($result['totals'] as $kind => $total)
                        <li>{{ __(':kind total: :total BHD', ['kind' => App\Enums\ImportKind::tryFrom($kind)?->label() ?? str($kind)->replace('_', ' ')->ucfirst(), 'total' => $total]) }}</li>
                    @endforeach
                @endif
            </ul>
        </flux:callout>

        @foreach ($result['errors'] as $kind => $lines)
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="App\Enums\ImportKind::tryFrom($kind)?->label() ?? str($kind)->headline()">
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($lines as $line => $messages)
                        <li>{{ __('Line :line', ['line' => $line]) }}: {{ implode(' ', $messages) }}</li>
                    @endforeach
                </ul>
            </flux:callout>
        @endforeach
    @endif
</section>
