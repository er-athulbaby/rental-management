<section class="w-full max-w-4xl space-y-6">
    <x-ui.page-header :title="$templateId ? $name : __('New template')" :back="route('admin.templates.index')" />
    <div class="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
        <p class="text-zinc-700 dark:text-zinc-300">{{ __('Merge fields:') }} @foreach ($fields as $f)<code class="rounded bg-white px-1 py-0.5 text-xs dark:bg-zinc-900">{{ '{'.$f.'}' }}</code> @endforeach</p>
        <p class="text-zinc-500 dark:text-zinc-400">{{ __('Separate paragraphs with a blank line. English and Arabic need the same number of paragraphs. A clause whose body is just {units_table} prints the schedule of units.') }}</p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Template')" :hint="__('The name, and whether agreements can use it.')">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3"><flux:input wire:model="name" :label="__('Name')" aria-required="true" /></div>
                <flux:checkbox wire:model="active" :label="__('Active')" />
                <flux:checkbox wire:model="isDefault" :label="__('Default for new agreements')" />
            </div>
            <flux:error name="name" />
            <flux:error name="clauses" />
        </x-ui.section>

            @foreach ($clauses as $i => $clause)
                <x-ui.section :title="__('Clause :number', ['number' => $i + 1])" wire:key="clause-{{ $i }}">
                    <x-slot:actions>
                        <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="moveUp({{ $i }})" :disabled="$i === 0" :aria-label="__('Move up')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove({{ $i }})" :aria-label="__('Remove')" />
                    </x-slot:actions>
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
                </x-ui.section>
            @endforeach

            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="addClause" icon="plus">{{ __('Add clause') }}</flux:button>
            </div>

        <x-ui.form-actions :cancel="route('admin.templates.index')">
            <flux:button variant="primary" type="submit">{{ __('Save template') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
