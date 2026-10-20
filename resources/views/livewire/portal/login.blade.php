<section class="mx-auto w-full max-w-sm space-y-6">
    <flux:heading size="xl" level="1">{{ __('Sign in to the tenant portal') }}</flux:heading>

    @if (session('status'))
        <flux:text>{{ session('status') }}</flux:text>
    @endif

    @if (! $mailReady)
        <flux:text>{{ __('Portal email is not set up. Please contact the office.') }}</flux:text>
    @elseif (! $codeSent)
        <form wire:submit="sendCode" class="space-y-4">
            <flux:input wire:model="email" type="email" :label="__('Email address')" autocomplete="email" required autofocus />
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Send code') }}</flux:button>
        </form>
    @else
        <flux:text>{{ __('If this email has an account, we\'ve sent a code.') }} {{ __('It works once, for 10 minutes.') }}</flux:text>
        <form wire:submit="signIn" class="space-y-4">
            <flux:input wire:model="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
            <flux:checkbox wire:model="remember" :label="__('Remember this device for 30 days')" />
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Sign in') }}</flux:button>
        </form>
        <flux:button variant="ghost" size="sm" wire:click="startAgain">{{ __('Use another email or get a new code') }}</flux:button>
    @endif
</section>
