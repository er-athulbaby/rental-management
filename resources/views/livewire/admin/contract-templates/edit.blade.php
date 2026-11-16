<section class="w-full max-w-4xl space-y-6">
    <x-ui.page-header :title="$templateId ? $name : __('New template')" :back="route('admin.templates.index')" />
    <flux:text>{{ __('Merge fields:') }} @foreach ($fields as $f)<code class="text-xs">{{ '{'.$f.'}' }}</code> @endforeach</flux:text>
    <flux:text size="sm">{{ __('Separate paragraphs with a blank line. English and Arabic need the same number of paragraphs. A clause whose body is just {units_table} prints the schedule of units.') }}</flux:text>

    <form wire:submit="save" class="space-y-6">
        <x-ui.section>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="name" :label="__('Name')" class="sm:col-span-3" aria-required="true" />
                <flux:checkbox wire:model="active" :label="__('Active')" />
                <flux:checkbox wire:model="isDefault" :label="__('Default for new agreements')" />
            </div>
            <flux:error name="name" />
            <flux:error name="clauses" />

            @foreach ($clauses as $i => $clause)
                <flux:card class="space-y-3" wire:key="clause-{{ $i }}">
                    <div class="flex items-center justify-between gap-2">
                        <flux:heading>{{ $i + 1 }}.</flux:heading>
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="moveUp({{ $i }})" :disabled="$i === 0" :aria-label="__('Move up')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove({{ $i }})" :aria-label="__('Remove')" />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="clauses.{{ $i }}.heading_en" :label="__('Heading (English)')" aria-required="true" />
                        <flux:input wire:model="clauses.{{ $i }}.heading_ar" :label="__('Heading (Arabic)')" dir="rtl" aria-required="true" />
                        <flux:textarea wire:model="clauses.{{ $i }}.body_en" :label="__('Text (English)')" rows="5" aria-required="true" />
                        <flux:textarea wire:model="clauses.{{ $i }}.body_ar" :label="__('Text (Arabic)')" rows="5" dir="rtl" aria-required="true" />
                    </div>
                    <flux:error name="clauses.{{ $i }}.heading_en" />
                    <flux:error name="clauses.{{ $i }}.heading_ar" />
                    <flux:error name="clauses.{{ $i }}.body_en" />
                    <flux:error name="clauses.{{ $i }}.body_ar" />
                </flux:card>
            @endforeach

            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="addClause" icon="plus">{{ __('Add clause') }}</flux:button>
            </div>
        </x-ui.section>

        <x-ui.form-actions :cancel="route('admin.templates.index')">
            <flux:button variant="primary" type="submit">{{ __('Save template') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
