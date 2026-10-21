<section class="w-full max-w-4xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Custom fields') }}</flux:heading>
    <flux:text>{{ __('Fields added here appear under "More details" on each record. Tenant, unit and agreement fields can show in the tenant portal; owner and building fields in the owner portal.') }}</flux:text>

    <div class="flex flex-wrap gap-2">
        @foreach ($kinds as $k)
            <flux:button size="sm" :variant="$k === $kind ? 'primary' : 'ghost'" wire:click="$set('entity', '{{ $k->value }}')">{{ $k->label() }}</flux:button>
        @endforeach
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Label') }}</flux:table.column>
                <flux:table.column>{{ __('Key') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($fields as $field)
                    <flux:table.row :key="'f-'.$field->id">
                        <flux:table.cell>
                            {{ $field->label_en }}@if ($field->label_ar) · <span dir="rtl">{{ $field->label_ar }}</span>@endif
                            @if ($field->required)<flux:badge size="sm">{{ __('Required') }}</flux:badge>@endif
                            @if ($field->in_portal)<flux:badge size="sm" color="blue">{{ __('Show in portal') }}</flux:badge>@endif
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $field->key }}</flux:table.cell>
                        <flux:table.cell>{{ $field->type->label() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$field->archived_at ? 'zinc' : 'green'">{{ $field->archived_at ? __('Archived') : __('Active') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            @if ($field->archived_at === null)
                                <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="move({{ $field->id }}, -1)" :aria-label="__('Move up')" />
                                <flux:button size="sm" variant="ghost" icon="arrow-down" wire:click="move({{ $field->id }}, 1)" :aria-label="__('Move down')" />
                                <flux:button size="sm" wire:click="edit({{ $field->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="archive({{ $field->id }})">{{ __('Archive') }}</flux:button>
                            @else
                                <flux:button size="sm" variant="ghost" wire:click="restore({{ $field->id }})">{{ __('Restore') }}</flux:button>
                            @endif
                            @unless ($field->values_exists)
                                <flux:button size="sm" variant="ghost" wire:click="delete({{ $field->id }})" wire:confirm="{{ __('Delete this field?') }}">{{ __('Delete') }}</flux:button>
                            @endunless
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No fields yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:error name="list" />

    @if ($editing)
        <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">{{ $editingId ? __('Edit field') : __('New field') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.label_en" :label="__('Label (English)')" required />
                <flux:input wire:model="form.label_ar" :label="__('Label (Arabic)')" dir="rtl" />
                <flux:select wire:model.live="form.type" :label="__('Type')" :disabled="$typeLocked">
                    @foreach ($types as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.help" :label="__('Help text')" />
            </div>
            <div class="flex flex-wrap gap-6">
                <flux:checkbox wire:model="form.required" :label="__('Required')" />
                <flux:checkbox wire:model="form.in_portal" :label="__('Show in portal')" />
            </div>
            @if (($form['type'] ?? '') === 'choice')
                <flux:fieldset>
                    <flux:legend>{{ __('Choices') }}</flux:legend>
                    <div class="space-y-2">
                        @foreach ($choices as $i => $choice)
                            <div class="flex flex-wrap items-end gap-2" wire:key="choice-{{ $i }}">
                                <flux:input wire:model="choices.{{ $i }}.label_en" :label="__('Choice (English)')" class="min-w-0 flex-1" />
                                <flux:input wire:model="choices.{{ $i }}.label_ar" :label="__('Choice (Arabic)')" dir="rtl" class="min-w-0 flex-1" />
                                <flux:checkbox wire:model="choices.{{ $i }}.hidden" :label="__('Hidden')" />
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeChoice({{ $i }})" :aria-label="__('Remove')" />
                                <flux:error name="choices.{{ $i }}.id" />
                            </div>
                        @endforeach
                        <flux:button size="sm" icon="plus" wire:click="addChoice">{{ __('Add choice') }}</flux:button>
                        <flux:error name="choices" />
                    </div>
                </flux:fieldset>
            @endif
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button wire:click="cancel">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add field') }}</flux:button>
    @endif
</section>
