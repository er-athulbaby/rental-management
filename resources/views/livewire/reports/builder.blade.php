@php
    use App\Reports\Builder\Formatter;
    $opLabels = ['is' => __('is'), 'is_not' => __('is not'), 'contains' => __('contains'), 'empty' => __('is empty'), 'not_empty' => __('is not empty'),
        'in' => __('is any of'), 'not_in' => __('is none of'), 'eq' => '=', 'ne' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤',
        'between' => __('between'), 'on' => __('on'), 'before' => __('before'), 'after' => __('after'), 'yes' => __('is yes'), 'no' => __('is no')];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 flex-1">
            <flux:input wire:model.blur="name" :placeholder="__('Untitled report')" :aria-label="__('Report name')" class="max-w-md" />
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canEdit)
                <flux:button variant="primary" wire:click="save">{{ __('Save') }}</flux:button>
            @endif
            <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export to Excel') }}</flux:button>
            @if ($reportId)
                <flux:button wire:click="saveAsCopy">{{ __('Save as copy') }}</flux:button>
                @if ($canEdit)
                    @php($shared = App\Models\SavedReport::findOrFail($reportId)->visibility === 'shared')
                    <flux:button :icon="$shared ? 'users' : 'lock-closed'" wire:click="share({{ $shared ? 'false' : 'true' }})">{{ $shared ? __('Shared') : __('Only me') }}</flux:button>
                    <flux:button variant="ghost" icon="trash" wire:click="deleteReport" wire:confirm="{{ __('Delete this report?') }}" :aria-label="__('Delete')" />
                @endif
            @endif
            @error('name')<flux:text class="w-full text-sm text-red-600">{{ $message }}</flux:text>@enderror
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <aside class="space-y-4">
            <flux:select wire:change="setSubject($event.target.value)" :label="__('Subject')">
                @foreach (collect($subjects)->groupBy(fn ($s) => $s->group()) as $group => $items)
                    <optgroup label="{{ $group }}">
                        @foreach ($items as $s)<option value="{{ $s->key() }}" @selected($s->key() === $subject->key())>{{ $s->label() }}</option>@endforeach
                    </optgroup>
                @endforeach
            </flux:select>

            <x-building-filter :buildings="$buildings" model="def.building_id" />

            @if ($subject->periodFields() !== [])
                <div class="grid grid-cols-2 gap-2">
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

            <flux:fieldset>
                <flux:legend>{{ __('Columns') }}</flux:legend>
                <div class="max-h-72 space-y-3 overflow-y-auto pe-1">
                    @foreach ($sections as $section => $sectionFields)
                        <div>
                            <div class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ $section }}</div>
                            @foreach ($sectionFields as $f)
                                <label class="flex items-center gap-2 py-0.5 text-sm">
                                    <input type="checkbox" wire:click="toggleColumn('{{ $f->key }}')" @checked(in_array($f->key, is_array($def['columns'] ?? null) ? $def['columns'] : [], true))>
                                    {{ $f->label }}
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @error('columns')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Filters') }}</flux:legend>
                <div class="space-y-2">
                    @foreach (is_array($def['filters'] ?? null) ? $def['filters'] : [] as $i => $filter)
                        @php($filter = is_array($filter) ? $filter : [])
                        @php($ff = is_string($filter['field'] ?? null) ? ($fields[$filter['field']] ?? null) : null)
                        <div class="space-y-1 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" wire:key="filter-{{ $i }}">
                            <div class="flex gap-1">
                                <flux:select wire:model.live="def.filters.{{ $i }}.field" size="sm" :aria-label="__('Field')">
                                    <option value="">{{ __('Field…') }}</option>
                                    @foreach ($fields as $f)@if ($f->filterable)<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
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
                                @isset($defErrors["filters.$i.$part"])<flux:text class="text-xs text-red-600">{{ $defErrors["filters.$i.$part"][0] }}</flux:text>@endisset
                            @endforeach
                        </div>
                    @endforeach
                    <flux:button size="sm" icon="plus" wire:click="addFilter">{{ __('Add filter') }}</flux:button>
                </div>
            </flux:fieldset>

            <div class="grid grid-cols-2 gap-2">
                <flux:select wire:model.live="def.sort.field" :label="__('Sort by')">
                    @foreach ($fields as $f)<option value="{{ $f->key }}">{{ $f->label }}</option>@endforeach
                </flux:select>
                <flux:select wire:model.live="def.sort.dir" :label="__('Order')">
                    <option value="asc">{{ __('Ascending') }}</option>
                    <option value="desc">{{ __('Descending') }}</option>
                </flux:select>
            </div>
            {{-- Group by, totals, compare, chart: Tasks 11–14 --}}
        </aside>

        <section class="min-w-0 space-y-3">
            @if ($defErrors !== [])
                <flux:callout icon="exclamation-triangle" color="amber" :heading="__('Finish the settings to see results')">
                    <flux:callout.text>{{ collect($defErrors)->flatten()->first() }}</flux:callout.text>
                </flux:callout>
            @elseif ($result)
                <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm text-zinc-500">
                    <span>{{ trans_choice(':n row|:n rows', $result->rowCount, ['n' => number_format($result->rowCount)]) }}@if ($result->periodLabel) · {{ $result->periodLabel }}@endif</span>
                    @if ($result->truncated)<span>{{ __('Showing the first :n — export to Excel for all.', ['n' => App\Reports\Builder\Engine::DETAIL_LIMIT]) }}</span>@endif
                </div>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            @foreach ($result->columns as $col)
                                <flux:table.column :align="$col->type->numeric() ? 'end' : 'start'">{{ $col->label }}</flux:table.column>
                            @endforeach
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($result->rows as $i => $row)
                                <flux:table.row :key="'r'.$i">
                                    @foreach ($result->columns as $col)
                                        <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric()])>{{ Formatter::display($col, $row[$col->key] ?? null) }}</flux:table.cell>
                                    @endforeach
                                </flux:table.row>
                            @empty
                                <flux:table.row><flux:table.cell :colspan="count($result->columns)">{{ __('No rows match.') }}</flux:table.cell></flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </section>
    </div>
</div>
