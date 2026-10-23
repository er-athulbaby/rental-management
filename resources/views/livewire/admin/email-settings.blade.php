<section class="w-full max-w-2xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Email settings') }}</flux:heading>
    <flux:text>{{ __('The mail server the system sends tenant emails through. While this is off, no email is sent. Changes apply to the next email, with no restart.') }}</flux:text>
    <flux:error name="form" />

    <form wire:submit="save" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Mail server') }}</flux:legend>
            <div class="space-y-4">
                <flux:switch wire:model="form.enabled" :label="__('Send email through this server')" />
                <flux:input wire:model="form.host" :label="__('Host')" placeholder="smtp.example.com" />
                <flux:input wire:model="form.port" type="number" min="1" max="65535" :label="__('Port')" />
                <flux:select wire:model="form.encryption" :label="__('Encryption')">
                    @foreach ($encryptions as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="form.username" :label="__('Username')" autocomplete="off" />
                <flux:input wire:model="form.password" type="password" :label="__('Password')" autocomplete="new-password"
                    :description="$hasPassword ? __('Saved — leave blank to keep') : null" />
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Sender') }}</flux:legend>
            <div class="space-y-4">
                <flux:input wire:model="form.from_address" type="email" :label="__('From address')" />
                <flux:input wire:model="form.from_name" :label="__('From name')" />
            </div>
        </flux:fieldset>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>

    <flux:fieldset>
        <flux:legend>{{ __('Send test email') }}</flux:legend>
        <div class="space-y-4">
            <flux:text>{{ __('Sends a short English and Arabic message using the values above, saved or not.') }}</flux:text>
            <flux:input wire:model="testTo" type="email" :label="__('Send to')" />
            <flux:button wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">{{ __('Send test email') }}</flux:button>
            @if ($testMessage !== '')
                <flux:callout :variant="$testOk ? 'success' : 'danger'" :icon="$testOk ? 'check-circle' : 'exclamation-triangle'" :heading="$testMessage" />
                @if ($testOk && ! data_get($form, 'enabled'))
                    <flux:text>{{ __("The test worked. Switch on 'Send email through this server' and save to start sending.") }}</flux:text>
                @endif
            @endif
        </div>
    </flux:fieldset>
</section>
