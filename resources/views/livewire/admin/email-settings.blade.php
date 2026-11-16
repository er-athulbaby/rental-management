<section class="w-full max-w-2xl space-y-8">
    <x-ui.page-header :title="__('Email settings')" :hint="__('The mail server the system sends tenant emails through. While this is off, no email is sent. Changes apply to the next email, with no restart.')" />
    <flux:error name="form" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.section :title="__('Mail server')">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:switch wire:model="form.enabled" :label="__('Send email through this server')" class="sm:col-span-2" />
                <flux:input wire:model="form.host" :label="__('Host')" placeholder="smtp.example.com" />
                <flux:input wire:model="form.port" type="number" min="1" max="65535" :label="__('Port')" aria-required="true" />
                <flux:select wire:model="form.encryption" :label="__('Encryption')" aria-required="true">
                    @foreach ($encryptions as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="form.username" :label="__('Username')" autocomplete="off" />
                <flux:input wire:model="form.password" type="password" :label="__('Password')" autocomplete="new-password"
                    :description="$hasPassword ? __('Saved — leave blank to keep') : null" />
            </div>
        </x-ui.section>

        <x-ui.section :title="__('Sender')">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.from_address" type="email" :label="__('From address')" />
                <flux:input wire:model="form.from_name" :label="__('From name')" />
            </div>
        </x-ui.section>

        <x-ui.form-actions>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </x-ui.form-actions>
    </form>

    <x-ui.section :title="__('Send test email')">
        <flux:text>{{ __('Sends a short English and Arabic message using the values above, saved or not.') }}</flux:text>
        <flux:input wire:model="testTo" type="email" :label="__('Send to')" />
        <flux:button wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">{{ __('Send test email') }}</flux:button>
        @if ($testMessage !== '')
            <flux:callout :variant="$testOk ? 'success' : 'danger'" :icon="$testOk ? 'check-circle' : 'exclamation-triangle'" :heading="$testMessage" />
            @if ($testOk && ! data_get($form, 'enabled'))
                <flux:text>{{ __("The test worked. Switch on 'Send email through this server' and save to start sending.") }}</flux:text>
            @endif
        @endif
    </x-ui.section>
</section>
