<section class="w-full max-w-6xl space-y-8">
    <x-ui.page-header :title="__('Bank reconciliation').' · '.$rec->account->label().' · '.$rec->end_date->format('d/m/Y')"
        :hint="$open ? __('Open') : __('Finished :date by :name', ['date' => $rec->finished_at?->timezone('Asia/Bahrain')->format('d/m/Y H:i'), 'name' => $rec->finisher?->name])"
        :back="route('ledger.bank-reconciliation', ['account' => (string) $rec->ledger_account_id])" />

    @php
        [, $closing, , , , $ledgerFigure, $difference, $unmatchedLines] = $figures; // not $inTransit/$outstanding: those are the entry lists below
    @endphp
    <x-ui.stats>
        <x-ui.stat :label="$closing[0]" :value="$closing[1]" />
        <x-ui.stat :label="$ledgerFigure[0]" :value="$ledgerFigure[1]" />
        <x-ui.stat :label="$difference[0]" :value="$difference[1]" :tone="$difference[1] === '0.000' ? 'success' : 'danger'" />
        <x-ui.stat :label="$unmatchedLines[0]" :value="$unmatchedLines[1]" :tone="$unmatchedLines[1] === '0' ? null : 'warning'" />
    </x-ui.stats>
    <x-ui.section>
        <dl class="grid max-w-xl gap-1 tabular-nums">
            @foreach ($figures as [$label, $value])
                <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
    </x-ui.section>

    <div class="flex flex-wrap gap-2">
        @if ($open && $canManage)
            <flux:button variant="primary" wire:click="finish">{{ __('Finish') }}</flux:button>
            <flux:button variant="danger" wire:click="discard" wire:confirm="{{ __('Discard this open reconciliation and its matches?') }}">{{ __('Discard') }}</flux:button>
        @endif
        @if (! $open && $canAdmin)
            <flux:button wire:click="reopen">{{ __('Reopen') }}</flux:button>
        @endif
        <flux:button wire:click="download">{{ __('Download report') }}</flux:button>
    </div>
    @foreach (['finish', 'discard', 'reopen', 'line', 'picked'] as $field)
        @foreach ($errors->get($field) as $message)
            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @endforeach
    @endforeach

    <x-ui.section :title="__('Unmatched statement lines')">
        <x-ui.table><flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($unmatched as $l)
                    <flux:table.row :key="'u-'.$l->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $l->date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $l->description }}</flux:table.cell>
                        <flux:table.cell>{{ $l->reference }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $l->amount }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            @if ($open && $canManage)
                                <flux:button size="sm" wire:click="pick({{ $l->id }})">{{ __('Match') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table></x-ui.table>
        @if ($unmatchedCount > $unmatched->count())
            <p class="text-sm">{{ __('Showing :shown of :total', ['shown' => $unmatched->count(), 'total' => $unmatchedCount]) }}</p>
        @endif
    </x-ui.section>

    @if ($line !== null)
        <div class="space-y-4 rounded-lg border border-zinc-300 p-4 dark:border-zinc-600">
            <flux:heading size="lg">{{ $line->date->format('d/m/Y') }} · {{ $line->description }} · {{ $line->amount }}</flux:heading>

            <flux:heading>{{ __('Suggested entries') }}</flux:heading>
            @forelse ($suggestions as $e)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live.debounce.300ms="picked" value="{{ $e['key'] }}" />
                    {{ $e['date'] }} · {{ $e['source'] }} · {{ $e['description'] }} · <span class="tabular-nums">{{ $e['amount'] }}</span>
                </label>
            @empty
                <p class="text-sm">{{ __('No suggestions.') }}</p>
            @endforelse

            <flux:input wire:model.live.debounce.400ms="search" :label="__('Find other entries (description or amount)')" />
            @foreach ($others as $e)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live.debounce.300ms="picked" value="{{ $e['key'] }}" />
                    {{ $e['date'] }} · {{ $e['source'] }} · {{ $e['description'] }} · <span class="tabular-nums">{{ $e['amount'] }}</span>
                </label>
            @endforeach

            <p class="text-sm tabular-nums">{{ __('Chosen: :total of :amount', ['total' => $chosen, 'amount' => $line->amount]) }}</p>
            <div class="flex gap-2">
                <flux:button variant="primary" wire:click="match">{{ __('Match') }}</flux:button>
                <flux:button wire:click="cancelPick">{{ __('Cancel') }}</flux:button>
            </div>

            <flux:heading>{{ __('Not in the ledger yet') }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:select wire:model.live="kind" :label="__('Record as')">
                    @foreach ($kinds as $k)
                        <flux:select.option :value="$k->value">{{ $k->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($kind === 'transfer')
                    <flux:select wire:model="otherAccount" :label="__('Other money account')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($money as $a)
                            <flux:select.option :value="(string) $a->id">{{ $a->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
            @foreach (['kind', 'from_account_id', 'to_account_id', 'date', 'amount', 'reference'] as $field)
                <flux:error name="{{ $field }}" />
            @endforeach
            <flux:button wire:click="bankOnly" :wire:confirm="$duplicateWarning ? __('An entry with this amount already exists. Record a new one anyway?') : null">{{ __('Record and match') }}</flux:button>
        </div>
    @endif

    <x-ui.section :title="__('Matched statement lines')">
        <x-ui.table><flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Entries') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($matched as $l)
                    <flux:table.row :key="'m-'.$l->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $l->date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $l->description }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $l->amount }}</flux:table.cell>
                        <flux:table.cell>
                            @foreach ($l->matches as $m)
                                <div>{{ \App\Ledger\Sources::label($m->entry_type) }} #{{ $m->entry_id }} · <span class="tabular-nums">{{ $m->amount }}</span></div>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            @if ($open && $canManage)
                                <flux:button size="sm" wire:click="unmatch({{ $l->id }})">{{ __('Remove') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table></x-ui.table>
    </x-ui.section>

    @foreach ([__('Deposits in transit') => $inTransit, __('Outstanding payments') => $outstanding] as $title => $list)
        <x-ui.section :title="$title">
            <x-ui.table><flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Source') }}</flux:table.column>
                    <flux:table.column>{{ __('Description') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($list as $e)
                        <flux:table.row :key="'e-'.$e['key']">
                            <flux:table.cell class="whitespace-nowrap">{{ $e['date'] }}</flux:table.cell>
                            <flux:table.cell>{{ $e['source'] }}</flux:table.cell>
                            <flux:table.cell><a href="{{ $e['url'] }}" wire:navigate class="underline">{{ $e['description'] }}</a></flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $e['amount'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table></x-ui.table>
        </x-ui.section>
    @endforeach
</section>
