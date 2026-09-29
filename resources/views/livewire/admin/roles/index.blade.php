<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Roles') }}</flux:heading>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Users') }}</flux:table.column>
                <flux:table.column>{{ __('Permissions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($roles as $role)
                    <flux:table.row :key="$role->id">
                        <flux:table.cell>
                            <flux:link :href="route('admin.roles.edit', $role)" wire:navigate>{{ \App\Enums\RoleName::from($role->name)->label() }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ $role->users_count }}</flux:table.cell>
                        <flux:table.cell>{{ $role->permissions_count }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
