@props(['fields', 'values' => [], 'documents' => []])
{{-- Spec 2026-10-21 custom fields §4: the "More details" inputs, the same for all five record kinds (plan ruling 2). --}}
@if ($fields->isNotEmpty())
    <flux:fieldset class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:legend>{{ __('More details') }}</flux:legend>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($fields as $field)
                @php
                    $model = 'custom.'.$field->key;
                    $current = $values[$field->key] ?? null;
                    $held = is_scalar($current) ? (string) $current : '';
                    $wide = in_array($field->type, [App\Enums\CustomFieldType::LongText, App\Enums\CustomFieldType::File], true);
                @endphp
                <div wire:key="cf-{{ $field->id }}" @class(['min-w-0', 'sm:col-span-2' => $wide])>
                    @switch($field->type)
                        @case(App\Enums\CustomFieldType::LongText)
                            <flux:textarea wire:model="{{ $model }}" :label="$field->label()" :description="$field->help" rows="3" />
                            @break
                        @case(App\Enums\CustomFieldType::Number)
                            <flux:input wire:model="{{ $model }}" :label="$field->label()" :description="$field->help" inputmode="decimal" />
                            @break
                        @case(App\Enums\CustomFieldType::Date)
                            <x-date-input wire:model="{{ $model }}" :label="$field->label()" :description="$field->help" />
                            @break
                        @case(App\Enums\CustomFieldType::Bool)
                            <flux:select wire:model="{{ $model }}" :label="$field->label()" :description="$field->help">
                                <option value="">—</option>
                                <option value="1">{{ __('Yes') }}</option>
                                <option value="0">{{ __('No') }}</option>
                            </flux:select>
                            @break
                        @case(App\Enums\CustomFieldType::Choice)
                            <flux:select wire:model="{{ $model }}" :label="$field->label()" :description="$field->help">
                                <option value="">—</option>
                                @foreach ($field->choices as $choice)
                                    @if ($choice->hidden_at === null || (string) $choice->id === $held)
                                        <option value="{{ $choice->id }}">{{ $choice->label() }}</option>
                                    @endif
                                @endforeach
                            </flux:select>
                            @break
                        @case(App\Enums\CustomFieldType::File)
                            <flux:field>
                                <flux:label>{{ $field->label() }}</flux:label>
                                <flux:description>{{ $field->help ?: __('JPG, PNG, WebP or PDF, max 10 MB') }}</flux:description>
                                <x-file-button wire:model="{{ $model }}" accept=".jpg,.jpeg,.png,.webp,.pdf" />
                                @isset($documents[$field->key])
                                    <flux:text size="sm">
                                        {{ __('Current file: :name', ['name' => $documents[$field->key]->original_name]) }}
                                        <flux:link :href="route('documents.view', $documents[$field->key])" target="_blank">{{ __('View') }}</flux:link>
                                    </flux:text>
                                @endisset
                                <flux:error :name="$model" />
                            </flux:field>
                            @break
                        @default
                            <flux:input wire:model="{{ $model }}" :label="$field->label()" :description="$field->help" />
                    @endswitch
                </div>
            @endforeach
        </div>
    </flux:fieldset>
@endif
