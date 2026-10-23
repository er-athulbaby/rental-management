@props(['fields', 'more' => []])
{{-- Spec 2026-10-21 custom fields §5 "More filters": one field, one condition (plan ruling 6). $more may be anything the browser sent. --}}
@if ($fields->isNotEmpty())
    @php
        $picked = $fields->firstWhere('key', data_get($more, 'field'));
        $op = data_get($more, 'op');
        $conditions = $picked ? App\CustomFields\CustomFieldFilter::CONDITIONS[$picked->type->value] : [];
        $labels = ['contains' => __('Contains'), 'range' => __('From – to'), 'yes' => __('Yes'), 'no' => __('No'), 'in' => __('Is one of'), 'empty' => __('Is empty')];
    @endphp
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <flux:select wire:model.live="more.field" :label="__('More filters')" class="sm:max-w-56">
            <option value="">{{ __('No extra filter') }}</option>
            @foreach ($fields as $f)
                <option value="{{ $f->key }}">{{ $f->label() }}</option>
            @endforeach
        </flux:select>
        @if ($picked)
            <flux:select wire:model.live="more.op" :label="__('Condition')" class="sm:max-w-44">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($conditions as $c)
                    <option value="{{ $c }}">{{ $labels[$c] }}</option>
                @endforeach
            </flux:select>
            @if ($op === 'contains')
                <flux:input wire:model.live.debounce.300ms="more.value" :label="__('Text')" class="sm:max-w-56" />
            @elseif ($op === 'range' && $picked->type === App\Enums\CustomFieldType::Date)
                <x-date-input wire:model.live="more.from" :label="__('From')" class="sm:max-w-44" />
                <x-date-input wire:model.live="more.to" :label="__('To')" class="sm:max-w-44" />
            @elseif ($op === 'range')
                <flux:input wire:model.live.debounce.300ms="more.from" :label="__('From')" inputmode="decimal" class="sm:max-w-32" />
                <flux:input wire:model.live.debounce.300ms="more.to" :label="__('To')" inputmode="decimal" class="sm:max-w-32" />
            @elseif ($op === 'in')
                <div class="flex flex-wrap gap-3">
                    @foreach ($picked->choices as $choice)
                        <label class="flex items-center gap-1 text-sm"><input type="checkbox" value="{{ $choice->id }}" wire:model.live="more.choices"> {{ $choice->label() }}</label>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
@endif
