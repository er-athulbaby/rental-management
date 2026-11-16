<section class="w-full max-w-2xl space-y-6">
    <x-ui.page-header :title="$subject ? __('Edit user') : __('New user')" :back="route('admin.users.index')" />

    @if ($subject?->isVendorSupport())
        <flux:callout variant="warning" icon="lock-closed" :heading="__('The Vendor Support account is managed on the server. You can only deactivate it.')" />
    @else
        <form wire:submit="save" class="space-y-6">
            <x-ui.section>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="name" :label="__('Name')" required />
                    <flux:input wire:model="email" :label="__('Email')" type="email" required />

                    <flux:checkbox.group wire:model="roles" :label="__('Roles')" class="sm:col-span-2">
                        @foreach ($roleOptions as $role)
                            <flux:checkbox :value="$role->value" :label="$role->label()" />
                        @endforeach
                    </flux:checkbox.group>
                    <flux:error name="roles" class="sm:col-span-2" />

                    <flux:checkbox.group wire:model="buildingIds" :label="__('Assigned buildings')" :description="__('Only matters for users without access to all buildings.')" class="sm:col-span-2">
                        @forelse ($buildings as $building)
                            <flux:checkbox :value="$building->id" :label="$building->code.' — '.$building->name" />
                        @empty
                            <flux:text>{{ __('No buildings yet.') }}</flux:text>
                        @endforelse
                    </flux:checkbox.group>
                </div>

                @unless ($subject)
                    <flux:text>{{ __('The new user receives an email with a link to set their own password.') }}</flux:text>
                @endunless
            </x-ui.section>

            <div class="flex justify-end">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    @endif

    @if ($subject)
        <div class="flex flex-wrap gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            @can('deactivate', $subject)
                <flux:button variant="danger" wire:click="deactivate" wire:confirm="{{ __('Deactivate this user and sign them out everywhere?') }}">{{ __('Deactivate') }}</flux:button>
            @endcan
            @can('reactivate', $subject)
                <flux:button wire:click="reactivate">{{ __('Reactivate') }}</flux:button>
            @endcan
            @can('resetTwoFactor', $subject)
                <flux:button wire:click="resetTwoFactor" wire:confirm="{{ __('Remove this user\'s two-factor setup and sign them out?') }}">{{ __('Reset 2FA') }}</flux:button>
            @endcan
        </div>
    @endif
</section>
