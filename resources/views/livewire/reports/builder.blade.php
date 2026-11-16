@php
    use App\Reports\Builder\Formatter;
    $opLabels = ['is' => __('is'), 'is_not' => __('is not'), 'contains' => __('contains'), 'empty' => __('is empty'), 'not_empty' => __('is not empty'),
        'in' => __('is any of'), 'not_in' => __('is none of'), 'eq' => '=', 'ne' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤',
        'between' => __('between'), 'on' => __('on'), 'before' => __('before'), 'after' => __('after'), 'yes' => __('is yes'), 'no' => __('is no')];
@endphp
<div class="space-y-4">
    <x-ui.page-header :title="$name !== '' ? $name : __('Report builder')" :back="route('reports.index')">
        <x-slot:actions>
            @if ($canEdit)
                <flux:button variant="primary" wire:click="save">{{ __('Save') }}</flux:button>
            @endif
            <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export to Excel') }}</flux:button>
            @if ($reportId)
                <flux:button wire:click="saveAsCopy">{{ __('Save as copy') }}</flux:button>
                @if ($canEdit)
                    @php $shared = App\Models\SavedReport::findOrFail($reportId)->visibility === 'shared'; @endphp
                    <flux:button :icon="$shared ? 'users' : 'lock-closed'" wire:click="share({{ $shared ? 'false' : 'true' }})">{{ $shared ? __('Shared') : __('Only me') }}</flux:button>
                    <flux:modal.trigger name="schedule-email"><flux:button icon="envelope">{{ __('Schedule email') }}</flux:button></flux:modal.trigger>
                    <flux:button variant="ghost" icon="trash" wire:click="deleteReport" wire:confirm="{{ __('Delete this report?') }}" :aria-label="__('Delete')" />
                @endif
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($reportId && $canEdit)
        @include('livewire.reports.partials.schedule-modal', ['intro' => __('Each person gets the report with only the data they are allowed to see.'),
            'fixedDates' => ($def['period']['preset'] ?? null) === 'custom'])
    @endif

    <div class="grid items-start gap-4 lg:grid-cols-[22rem_minmax(0,1fr)]">
        <aside class="space-y-4 lg:sticky lg:top-4 lg:max-h-[calc(100dvh-2rem)] lg:self-start lg:overflow-y-auto lg:pe-1">
        <x-ui.section :title="__('1. What to report')" :hint="__('Name the report and choose what it is about.')">
            <flux:input wire:model.blur="name" :placeholder="__('Untitled report')" :label="__('Report name')" />
            @error('name')<flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>@enderror
            <flux:select wire:change="setSubject($event.target.value)" :label="__('Subject')">
                @foreach (collect($subjects)->groupBy(fn ($s) => $s->group()) as $group => $items)
                    <optgroup label="{{ $group }}">
                        @foreach ($items as $s)<option value="{{ $s->key() }}" @selected($s->key() === $subject->key())>{{ $s->label() }}</option>@endforeach
                    </optgroup>
                @endforeach
            </flux:select>

            <x-building-filter :buildings="$buildings" model="def.building_id" />

            @if ($subject->periodFields() !== [])
                <div class="grid grid-cols-2 gap-3">
                    <flux:select wire:model.live="def.period.field" :label="__('Period by')">
                        @foreach ($subject->periodFields() as $p)<option value="{{ $p }}">{{ $fields[$p]->label }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="def.period.preset" :label="__('Period')">
                        @foreach ($presets as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </flux:select>
                    @if (($def['period']['preset'] ?? '') === 'custom')
                        <x-date-input wire:model.live="def.period.from" :label="__('From')" />
                        <x-date-input wire:model.live="def.period.to" :label="__('To')" />
                    @endif
                </div>
            @elseif ($subject->usesAsAt())
                <x-date-input wire:model.live="def.as_at" :label="__('As at')" />
            @endif
        </x-ui.section>

        <x-ui.section :title="__('2. Columns')" :hint="__('Tick the columns you want to see.')">
            <flux:fieldset>
                <flux:legend class="sr-only">{{ __('Columns') }}</flux:legend>
                <div class="max-h-96 space-y-4 overflow-y-auto pe-1">
                    @foreach ($sections as $section => $sectionFields)
                        <div>
                            <div class="mb-1 border-b border-zinc-200 pb-1 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:border-zinc-700 dark:text-zinc-400">{{ $section }}</div>
                            @foreach ($sectionFields as $f)
                                @continue($f->archived && ! in_array($f->key, is_array($def['columns'] ?? null) ? $def['columns'] : [], true))
                                <label class="flex items-center gap-2 py-1 text-sm">
                                    <input type="checkbox" wire:click="toggleColumn('{{ $f->key }}')" @checked(in_array($f->key, is_array($def['columns'] ?? null) ? $def['columns'] : [], true))>
                                    {{ $f->label }}
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @error('columns')<flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>@enderror
            </flux:fieldset>
        </x-ui.section>

        <x-ui.section :title="__('3. Filters')" :hint="__('Only rows that match every filter are shown.')">
            <flux:fieldset>
                <flux:legend class="sr-only">{{ __('Filters') }}</flux:legend>
                <div class="space-y-2">
                    @if (! is_array($def['filters'] ?? null) || $def['filters'] === [])
                        <flux:text class="text-sm">{{ __('No filters yet. Add one to narrow the report.') }}</flux:text>
                    @endif
                    @foreach (is_array($def['filters'] ?? null) ? $def['filters'] : [] as $i => $filter)
                        @php $filter = is_array($filter) ? $filter : []; @endphp
                        @php $ff = is_string($filter['field'] ?? null) ? ($fields[$filter['field']] ?? null) : null; @endphp
                        <div class="space-y-2 rounded-lg border border-zinc-200 bg-zinc-50 p-2 dark:border-zinc-700 dark:bg-zinc-800/50" wire:key="filter-{{ $i }}">
                            <div class="flex gap-1">
                                <flux:select wire:model.live="def.filters.{{ $i }}.field" size="sm" :aria-label="__('Field')">
                                    <option value="">{{ __('Field…') }}</option>
                                    @foreach ($fields as $f)@if ($f->filterable && (! $f->archived || ($filter['field'] ?? null) === $f->key))<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
                                </flux:select>
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeFilter({{ $i }})" :aria-label="__('Remove filter')" />
                            </div>
                            @if ($ff)
                                <flux:select wire:model.live="def.filters.{{ $i }}.op" size="sm" :aria-label="__('Comparison')">
                                    <option value="">{{ __('How…') }}</option>
                                    @foreach ($operators[$ff->type->value] as $op)<option value="{{ $op }}">{{ $opLabels[$op] }}</option>@endforeach
                                </flux:select>
                                @if ($ff->type->value === 'enum')
                                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                                        @foreach ($ff->options as $value => $label)
                                            <label class="flex items-center gap-1 text-sm"><input type="checkbox" value="{{ $value }}" wire:model.live="def.filters.{{ $i }}.value"> {{ $label }}</label>
                                        @endforeach
                                    </div>
                                @elseif (($filter['op'] ?? '') === 'between')
                                    <div class="grid grid-cols-2 gap-1">
                                        @if ($ff->type->value === 'date')
                                            <x-date-input wire:model.live="def.filters.{{ $i }}.value.0" :aria-label="__('From')" />
                                            <x-date-input wire:model.live="def.filters.{{ $i }}.value.1" :aria-label="__('To')" />
                                        @else
                                            <flux:input wire:model.live.debounce.500ms="def.filters.{{ $i }}.value.0" size="sm" inputmode="decimal" :placeholder="__('From')" :aria-label="__('From')" />
                                            <flux:input wire:model.live.debounce.500ms="def.filters.{{ $i }}.value.1" size="sm" inputmode="decimal" :placeholder="__('To')" :aria-label="__('To')" />
                                        @endif
                                    </div>
                                @elseif (! in_array($filter['op'] ?? '', ['empty', 'not_empty', 'yes', 'no', ''], true))
                                    @if ($ff->type->value === 'date')
                                        <x-date-input wire:model.live="def.filters.{{ $i }}.value" :aria-label="__('Value')" />
                                    @else
                                        <flux:input wire:model.live.debounce.500ms="def.filters.{{ $i }}.value" size="sm" :inputmode="$ff->type->numeric() ? 'decimal' : 'text'" :aria-label="__('Value')"
                                            :placeholder="match (true) {
                                                $ff->type->value === 'money' => __('Amount in BHD, e.g. 100.000'),
                                                $ff->type->value === 'number' => __('Number, e.g. 30'),
                                                ($filter['op'] ?? '') === 'contains' => __('Type part of the :label', ['label' => $ff->label]),
                                                default => __('Exact :label', ['label' => $ff->label]),
                                            }" />
                                    @endif
                                @endif
                            @endif
                            @if ($ff && ($filter['op'] ?? '') !== '')
                                <flux:text class="text-xs text-zinc-500">{{ $ff->label }} {{ $opLabels[$filter['op']] ?? '' }}@if ($ff->type->value === 'enum') {{ __('the ticked values') }}@elseif (! in_array($filter['op'], ['empty', 'not_empty', 'yes', 'no'], true)) …@endif</flux:text>
                            @endif
                            @foreach (['field', 'op', 'value'] as $part)
                                @isset($defErrors["filters.$i.$part"])<flux:text class="text-xs text-red-600 dark:text-red-400">{{ $defErrors["filters.$i.$part"][0] }}</flux:text>@endisset
                            @endforeach
                        </div>
                    @endforeach
                    <flux:button size="sm" icon="plus" wire:click="addFilter">{{ __('Add filter') }}</flux:button>
                </div>
            </flux:fieldset>
        </x-ui.section>

        <x-ui.section :title="__('4. Sort and group')" :hint="__('Order the rows, group them and add totals.')">
            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model.live="def.sort.field" :label="__('Sort by')">
                    @foreach ($fields as $f)@if (! $f->archived || ($def['sort']['field'] ?? null) === $f->key)<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
                </flux:select>
                <flux:select wire:model.live="def.sort.dir" :label="__('Order')">
                    <option value="asc">{{ __('Ascending') }}</option>
                    <option value="desc">{{ __('Descending') }}</option>
                </flux:select>
            </div>

            <flux:fieldset>
                <flux:legend>{{ __('Group by') }}</flux:legend>
                <div class="space-y-2">
                @foreach ([0, 1] as $level)
                    @php $g = is_array($def['groups'][$level] ?? null) ? $def['groups'][$level] : null; @endphp
                    @php $gf = is_string($g['field'] ?? null) ? ($fields[$g['field']] ?? null) : null; @endphp
                    @if ($level === 0 || (is_array($def['groups'][0] ?? null) && is_string($def['groups'][0]['field'] ?? null) && $def['groups'][0]['field'] !== ''))
                        <div class="flex gap-1" wire:key="group-{{ $level }}">
                            <flux:select wire:model.live="def.groups.{{ $level }}.field" size="sm" :aria-label="__('Group level :n', ['n' => $level + 1])">
                                <option value="">{{ $level === 0 ? __('No grouping') : __('No second level') }}</option>
                                @foreach ($fields as $f)@if ($f->groupable && (! $f->archived || ($g['field'] ?? null) === $f->key))<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
                            </flux:select>
                            @if ($gf?->type->value === 'date')
                                <flux:select wire:model.live="def.groups.{{ $level }}.bucket" size="sm" :aria-label="__('Date grouping')">
                                    @foreach (['day' => __('Day'), 'month' => __('Month'), 'quarter' => __('Quarter'), 'year' => __('Year')] as $b => $l)<option value="{{ $b }}" @selected((is_string($g['bucket'] ?? null) ? $g['bucket'] : 'month') === $b)>{{ $l }}</option>@endforeach
                                </flux:select>
                            @endif
                        </div>
                    @endif
                @endforeach
                <flux:checkbox wire:model.live="def.summary_only" :label="__('Summary only (hide detail rows)')" />
                </div>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Totals') }}</flux:legend>
                @foreach (is_array($def['columns'] ?? null) ? $def['columns'] : [] as $key)
                    @if (is_string($key) && ($fields[$key] ?? null)?->type->numeric())
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <span>{{ $fields[$key]->label }}</span>
                            <flux:select wire:model.live="def.totals.{{ $key }}" size="sm" class="max-w-32" :aria-label="__('Total for :c', ['c' => $fields[$key]->label])">
                                <option value="">{{ __('None') }}</option>
                                <option value="sum">{{ __('Sum') }}</option><option value="avg">{{ __('Average') }}</option>
                                <option value="min">{{ __('Minimum') }}</option><option value="max">{{ __('Maximum') }}</option>
                            </flux:select>
                        </div>
                    @endif
                @endforeach
            </flux:fieldset>

            @if (is_array($def['groups'][0] ?? null) && is_string($def['groups'][0]['field'] ?? null) && $def['groups'][0]['field'] !== '')
                <div class="grid grid-cols-2 gap-3">
                    <flux:select wire:model.live="def.chart.type" :label="__('Chart')">
                        <option value="">{{ __('None') }}</option><option value="bars">{{ __('Show chart') }}</option>
                    </flux:select>
                    <flux:select wire:model.live="def.chart.total" :label="__('Chart value')">
                        <option value="count">{{ __('Count of rows') }}</option>
                        @foreach (is_array($def['totals'] ?? null) ? array_filter($def['totals'], 'is_string') : [] as $k => $fn)
                            @if (isset($fields[$k]))<option value="{{ $k }}:{{ $fn }}">{{ $fields[$k]->label }} ({{ __($fn) }})</option>@endif
                        @endforeach
                    </flux:select>
                </div>
            @endif
            @if ($subject->periodFields() !== [] && filled($def['period']['preset'] ?? null))
                <flux:select wire:model.live="def.compare" :label="__('Compare with')">
                    <option value="">{{ __('Nothing') }}</option>
                    <option value="previous">{{ __('Previous period') }}</option>
                    <option value="last_year">{{ __('Same period last year') }}</option>
                </flux:select>
            @endif
        </x-ui.section>
        </aside>

        <section class="min-w-0 space-y-4">
            @if ($dropped !== [])
                <flux:callout icon="information-circle" color="amber">
                    <flux:callout.text>{{ __('Some fields aren\'t available to you and were left out: :list', ['list' => implode(', ', $dropped)]) }}</flux:callout.text>
                </flux:callout>
            @endif
            @if ($defErrors !== [])
                <flux:callout icon="exclamation-triangle" color="amber" :heading="__('Finish the settings to see results')">
                    <flux:callout.text>{{ collect($defErrors)->flatten()->first() }}</flux:callout.text>
                </flux:callout>
            @elseif ($result)
                <div class="flex flex-wrap items-baseline justify-between gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                    <span>{{ trans_choice(':n row|:n rows', $result->rowCount, ['n' => number_format($result->rowCount)]) }}@if ($result->periodLabel) · {{ $result->periodLabel }}@endif</span>
                    @if ($result->truncated)<span>{{ __('Showing the first :n — export to Excel for all.', ['n' => App\Reports\Builder\Engine::DETAIL_LIMIT]) }}</span>@endif
                    @if ($result->groupsTruncated)<span>{{ __('Showing the first :n groups — export for all', ['n' => Illuminate\Support\Number::format(App\Reports\Builder\Aggregator::GROUP_LIMIT)]) }}</span>@endif
                </div>
                @if ($chart)<x-report-chart :chart="$chart" />@endif
                @if ($result->comparison)
                    @php $cmp = $result->comparison; @endphp
                    @php $tkeys = array_keys($cmp['rows'][0]['current'] ?? ['count' => 0]); @endphp
                    @if ($cmp['truncated'])<p class="text-sm text-zinc-500">{{ __('Showing the first :n groups — export for all', ['n' => Illuminate\Support\Number::format(App\Reports\Builder\Aggregator::GROUP_LIMIT)]) }}</p>@endif
                    <x-ui.table>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Compared') }}</flux:table.column>
                                @foreach ($tkeys as $tk)
                                    @php $tl = $tk === 'count' ? __('Rows') : (($fields[explode(':', $tk)[0]] ?? null)?->label ?? $tk).' ('.__(explode(':', $tk)[1] ?? '').')'; @endphp
                                    <flux:table.column align="end">{{ $tl }}<br><span class="text-xs font-normal">{{ $cmp['label'] }} · {{ $cmp['compareLabel'] }} · {{ __('Change') }}</span></flux:table.column>
                                @endforeach
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($cmp['rows'] as $ri => $cr)
                                    <flux:table.row :key="'c'.$ri">
                                        <flux:table.cell>{{ $cr['label'] }}</flux:table.cell>
                                        @foreach ($tkeys as $tk)
                                            @php $ch = App\Reports\Builder\Comparison::change($cr['current'][$tk] ?? null, $cr['previous'][$tk] ?? null); @endphp
                                            @php $tf = $tk === 'count' ? null : ($fields[explode(':', $tk)[0]] ?? null); @endphp
                                            @php $tfn = $tk === 'count' ? 'count' : (explode(':', $tk)[1] ?? 'sum'); @endphp
                                            @php $sign = $ch['diff'] === null || bccomp($ch['diff'], '0', 3) === 0 ? 0 : (bccomp($ch['diff'], '0', 3) > 0 ? 1 : -1); @endphp
                                            <flux:table.cell class="text-end tabular-nums whitespace-nowrap">
                                                {{ Formatter::total($tf, $tfn, $cr['current'][$tk] ?? null) }}
                                                · {{ Formatter::total($tf, $tfn, $cr['previous'][$tk] ?? null) }}
                                                · @if ($ch['diff'] === null)—@else{{ ['0' => '', '1' => '▲ +', '-1' => '▼ −'][(string) $sign] }}{{ Formatter::total($tf, $tfn, ltrim($ch['diff'], '-')) }}@if ($ch['pct'] !== null) ({{ $sign < 0 || str_starts_with($ch['pct'], '-') ? '−' : ($sign > 0 ? '+' : '') }}{{ number_format((float) ltrim($ch['pct'], '-'), 1) }}%)@endif @endif
                                            </flux:table.cell>
                                        @endforeach
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </x-ui.table>
                @endif
                <x-ui.table x-data="{ closed: {} }">
                    <flux:table>
                        <flux:table.columns>
                            @foreach ($result->columns as $col)
                                <flux:table.column :align="$col->type->numeric() ? 'end' : 'start'">{{ $col->label }}</flux:table.column>
                            @endforeach
                        </flux:table.columns>
                        <flux:table.rows>
                            @if ($result->groups !== [])
                                @php $deepest = collect($result->groups)->max('level'); @endphp
                                @php $byGroup = App\Reports\Builder\Grouping::rowsByGroup($result->rows); @endphp
                                @php $parent = 0; @endphp
                                @foreach ($result->groups as $gi => $g)
                                    @php $parent = $g['level'] === 1 ? $gi : $parent; @endphp
                                    <flux:table.row :key="'g'.$gi" class="bg-zinc-50 font-medium dark:bg-zinc-800/60" data-group-level="{{ $g['level'] }}"
                                        :x-show="$g['level'] === 2 ? '! closed.g'.$parent : 'true'">
                                        @foreach ($result->columns as $ci => $col)
                                            <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric(), 'ps-8' => $ci === 0 && $g['level'] === 2])>
                                                @if ($ci === 0)
                                                    <button type="button" class="inline-flex items-center gap-1 rounded text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                                        @click="closed.g{{ $gi }} = ! closed.g{{ $gi }}" :aria-expanded="(! closed.g{{ $gi }}).toString()"
                                                        :aria-label="closed.g{{ $gi }} ? @js(__('Expand :label', ['label' => $g['label']])) : @js(__('Collapse :label', ['label' => $g['label']]))">
                                                        <span class="inline-block w-3 text-zinc-500" aria-hidden="true" x-text="closed.g{{ $gi }} ? '▸' : '▾'"></span>{{ $g['label'] }}</button>
                                                    <span class="text-xs font-normal text-zinc-500">({{ number_format($g['totals']['count']) }})</span>
                                                @else
                                                    @foreach ($g['totals'] as $tk => $tv)@if (str_starts_with($tk, $col->key.':')){{ Formatter::total($col, explode(':', $tk)[1], $tv) }}@endif @endforeach
                                                @endif
                                            </flux:table.cell>
                                        @endforeach
                                    </flux:table.row>
                                    @if ($g['level'] === $deepest)
                                        @foreach ($byGroup[App\Reports\Builder\Grouping::keyId($g['keys'])] ?? [] as $ri => $row)
                                            <flux:table.row :key="'g'.$gi.'r'.$ri" :x-show="'! closed.g'.$parent.' && ! closed.g'.$gi">
                                                @foreach ($result->columns as $col)
                                                    <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric()])>{{ Formatter::display($col, $row[$col->key] ?? null) }}</flux:table.cell>
                                                @endforeach
                                            </flux:table.row>
                                        @endforeach
                                    @endif
                                @endforeach
                            @else
                                @forelse ($result->rows as $i => $row)
                                    <flux:table.row :key="'r'.$i">
                                        @foreach ($result->columns as $col)
                                            <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric()])>{{ Formatter::display($col, $row[$col->key] ?? null) }}</flux:table.cell>
                                        @endforeach
                                    </flux:table.row>
                                @empty
                                    <flux:table.row><flux:table.cell :colspan="count($result->columns)" class="py-8 text-center text-zinc-500">{{ __('No rows match.') }}</flux:table.cell></flux:table.row>
                                @endforelse
                            @endif
                            @if ($result->rows !== [] || $result->groups !== [])
                                <flux:table.row class="font-semibold">
                                    @foreach ($result->columns as $ci => $col)
                                        <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric()])>
                                            @if ($ci === 0){{ __('Total') }} ({{ number_format($result->rowCount) }})@else @foreach ($result->grandTotals as $tk => $tv)@if (str_starts_with($tk, $col->key.':')){{ Formatter::total($col, explode(':', $tk)[1], $tv) }}@endif @endforeach @endif
                                        </flux:table.cell>
                                    @endforeach
                                </flux:table.row>
                            @endif
                        </flux:table.rows>
                    </flux:table>
                </x-ui.table>
            @endif
        </section>
    </div>
</div>
