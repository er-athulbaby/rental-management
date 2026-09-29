<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $label }}</flux:heading>

    @if ($readOnly)
        <flux:callout variant="warning" icon="lock-closed" :heading="__('This role is read-only for you: it is either a role you hold or the Vendor Support role.')" />
    @endif

    <form wire:submit="save" class="space-y-6">
        @foreach ($groups as $group => $cases)
            <flux:checkbox.group wire:model="permissions" :label="$group" :disabled="$readOnly">
                @foreach ($cases as $permission)
                    <flux:checkbox :value="$permission->value" :label="$permission->value" />
                @endforeach
            </flux:checkbox.group>
        @endforeach
        <flux:error name="permissions" />

        @unless ($readOnly)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endunless
    </form>
</section>
