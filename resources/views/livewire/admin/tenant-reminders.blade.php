<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Tenant reminders')" :hint="__('Emails sent to tenants every morning at 08:00: one email per tenant, in English and Arabic. Each type stays off until you switch it on. Staff can still send a reminder by hand from an invoice, cheque or agreement.')" />
    @unless ($mailReady)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Email is not set up, so no reminders are sent. Ask your administrator to configure mail.')" />
    @endunless
    <flux:error name="settings" />

    <form wire:submit="save" class="space-y-6">
        @foreach ($types as $type => [$label, $daysLabel])
            <x-ui.section :title="$label" wire:key="reminder-{{ $type }}">
                <flux:fieldset>
                    <flux:legend class="sr-only">{{ $label }}</flux:legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex items-center sm:col-span-2">
                            <flux:switch wire:model="settings.{{ $type }}.enabled" :label="__('Send automatically')" />
                        </div>
                        <div class="sm:col-span-2"><flux:input wire:model="settings.{{ $type }}.days" :label="$daysLabel" :placeholder="__('e.g. 1, 7, 14')" aria-required="true" /></div>
                        <flux:textarea wire:model="settings.{{ $type }}.intro_en" :label="__('Intro line (English)')" rows="3" aria-required="true" />
                        <flux:textarea wire:model="settings.{{ $type }}.intro_ar" :label="__('Intro line (Arabic)')" rows="3" dir="rtl" aria-required="true" />
                        <flux:error name="settings.{{ $type }}" class="sm:col-span-2" />
                    </div>
                </flux:fieldset>
            </x-ui.section>
        @endforeach
        <x-ui.form-actions>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </x-ui.form-actions>
    </form>
</section>
