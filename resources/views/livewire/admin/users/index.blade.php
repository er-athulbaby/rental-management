<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Users')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('admin.users.create')" wire:navigate>{{ __('New user') }}</flux:button>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $filtered = $search !== '' || $status !== 'active';
    @endphp
    <x-ui.filters :clear="$filtered ? route('admin.users.index') : null">
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search name or email')" icon="magnifying-glass" class="sm:max-w-xs" />
        <flux:select wire:model.live="status" class="sm:max-w-40">
            <option value="active">{{ __('Active') }}</option>
            <option value="inactive">{{ __('Inactive') }}</option>
            <option value="all">{{ __('All') }}</option>
        </flux:select>
    </x-ui.filters>

    <x-ui.table>
        <flux:table :paginate="$users">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Roles') }}</flux:table.column>
                <flux:table.column>{{ __('Buildings') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            <flux:link :href="route('admin.users.edit', $user)" wire:navigate>{{ $user->name }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $user->roles->map(fn ($role) => \App\Enums\RoleName::from($role->name)->label())->join(', ') }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->buildings_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$user->active ? 'green' : 'zinc'" size="sm">{{ $user->active ? __('Active') : __('Inactive') }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($users->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
