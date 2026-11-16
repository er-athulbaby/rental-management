<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Contract templates')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('admin.templates.create')" wire:navigate>{{ __('New template') }}</flux:button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Clauses') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($templates as $template)
                    <flux:table.row :key="$template->id">
                        <flux:table.cell><flux:link :href="route('admin.templates.edit', $template)" wire:navigate>{{ $template->name }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $template->clauses_count }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($template->is_default)<flux:badge size="sm" color="green">{{ __('Default') }}</flux:badge>@endif
                            @unless ($template->active)<flux:badge size="sm">{{ __('Inactive') }}</flux:badge>@endunless
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($templates->isEmpty())
            <x-ui.empty />
        @endif
    </x-ui.table>
</section>
