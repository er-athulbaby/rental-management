<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Tenant reminders') }}</flux:heading>
    <flux:text>{{ __('Emails sent to tenants every morning at 08:00: one email per tenant, in English and Arabic. Each type stays off until you switch it on. Staff can still send a reminder by hand from an invoice, cheque or agreement.') }}</flux:text>
    @unless ($mailReady)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Email is not set up, so no reminders are sent. Ask your administrator to configure mail.')" />
    @endunless
    <flux:error name="settings" />

    <form wire:submit="save" class="space-y-4">
        @foreach ($types as $type => [$label, $daysLabel])
            <flux:fieldset wire:key="reminder-{{ $type }}" class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:legend>{{ $label }}</flux:legend>
                <div class="space-y-4">
                    <flux:switch wire:model="settings.{{ $type }}.enabled" :label="__('Send automatically')" />
                    <flux:input wire:model="settings.{{ $type }}.days" :label="$daysLabel" :placeholder="__('e.g. 1, 7, 14')" />
                    <flux:textarea wire:model="settings.{{ $type }}.intro_en" :label="__('Intro line (English)')" rows="2" />
                    <flux:textarea wire:model="settings.{{ $type }}.intro_ar" :label="__('Intro line (Arabic)')" rows="2" dir="rtl" />
                    <flux:error name="settings.{{ $type }}" />
                </div>
            </flux:fieldset>
        @endforeach
        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
