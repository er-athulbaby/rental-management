<section class="w-full max-w-5xl space-y-6">
    <x-ui.page-header :title="$label" :back="route('admin.roles.index')" />

    @if ($readOnly)
        <flux:callout variant="warning" icon="lock-closed" :heading="__('This role is read-only for you: it is either a role you hold or the Vendor Support role.')" />
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Permissions')" :hint="__('Tick what this role can do, area by area.')">
            <div class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($groups as $group => $cases)
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <flux:checkbox.group wire:model="permissions" :label="$group" :disabled="$readOnly">
                            @foreach ($cases as $permission)
                                <flux:checkbox :value="$permission->value" :label="$permission->value" />
                            @endforeach
                        </flux:checkbox.group>
                    </div>
                @endforeach
            </div>
            <flux:error name="permissions" />
        </x-ui.section>

        @unless ($readOnly)
            <x-ui.form-actions :cancel="route('admin.roles.index')">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </x-ui.form-actions>
        @endunless
    </form>
</section>
