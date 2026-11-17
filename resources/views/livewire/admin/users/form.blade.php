<section class="w-full max-w-2xl space-y-6">
    <x-ui.page-header :title="$subject ? __('Edit user') : __('New user')" :back="route('admin.users.index')" />

    @if ($subject?->isVendorSupport())
        <flux:callout variant="warning" icon="lock-closed" :heading="__('The Vendor Support account is managed on the server. You can only deactivate it.')" />
    @else
        <form wire:submit="save" class="space-y-6">
            <x-ui.section :title="__('Identity')" :hint="$subject ? null : __('The new user receives an email with a link to set their own password.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="name" :label="__('Name')" required />
                    <flux:input wire:model="email" :label="__('Email')" type="email" required />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Roles')" :hint="__('What this user is allowed to do.')">
                <flux:checkbox.group wire:model="roles" :label="__('Roles')" label:sr-only>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($roleOptions as $role)
                            <flux:checkbox :value="$role->value" :label="$role->label()" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
                <flux:error name="roles" />
            </x-ui.section>

            <x-ui.section :title="__('Assigned buildings')" :hint="__('Only matters for users without access to all buildings.')">
                <flux:checkbox.group wire:model="buildingIds" :label="__('Assigned buildings')" label:sr-only>
                    @forelse ($buildings as $building)
                        <flux:checkbox :value="$building->id" :label="$building->code.' — '.$building->name" />
                    @empty
                        <flux:text>{{ __('No buildings yet.') }}</flux:text>
                    @endforelse
                </flux:checkbox.group>
            </x-ui.section>

            <x-ui.form-actions :cancel="route('admin.users.index')">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </x-ui.form-actions>
        </form>
    @endif

    @if ($subject && (auth()->user()->can('deactivate', $subject) || auth()->user()->can('reactivate', $subject) || auth()->user()->can('resetTwoFactor', $subject)))
        <x-ui.section :title="__('Account')" :hint="auth()->user()->can('reactivate', $subject) && ! auth()->user()->can('deactivate', $subject) && ! auth()->user()->can('resetTwoFactor', $subject) ? null : __('Deactivating or resetting two-factor signs the user out everywhere.')" tone="danger">
            <div class="flex flex-wrap gap-3">
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
        </x-ui.section>
    @endif
</section>
