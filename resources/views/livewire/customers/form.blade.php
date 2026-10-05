<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $customer?->name_en ?? __('New tenant') }}</flux:heading>
    @if ($account)
        <flux:card class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-6 tabular-nums">
                <div><flux:text size="sm">{{ __('Outstanding') }}</flux:text><div class="font-semibold">{{ $account['outstanding'] }}</div></div>
                <div><flux:text size="sm">{{ __('Credit') }}</flux:text><div class="font-semibold">{{ $account['credit'] }}</div></div>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($account['canRecord'])
                    <flux:button variant="primary" :href="route('payments.create', ['customer' => $customer->id])" wire:navigate>{{ __('Record payment') }}</flux:button>
                @endif
                <flux:button :href="route('customers.statement', $customer)" wire:navigate>{{ __('Statement') }}</flux:button>
                @can('create', App\Models\Invoice::class)
                    <flux:button :href="route('invoices.create', ['customer' => $customer->id])" wire:navigate>{{ __('Manual invoice') }}</flux:button>
                @endcan
            </div>
        </flux:card>
    @endif

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:fieldset class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:legend>{{ __('Identity') }}</flux:legend>
                <div class="space-y-4">
                    <flux:radio.group wire:model.live="form.type" :label="__('Tenant type')" variant="segmented">
                        <flux:radio value="individual" :label="__('Individual')" />
                        <flux:radio value="company" :label="__('Company')" />
                    </flux:radio.group>
                    <flux:input wire:model="form.name_en" :label="__('Name (English)')" autocomplete="name" required />
                    <flux:input wire:model="form.name_ar" :label="__('Name (Arabic, used in contracts)')" dir="rtl" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model="form.id_type" :label="__('ID type')">
                            @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                        </flux:select>
                        <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                        <x-nationality-select wire:model="form.nationality" :current="$customer?->nationality" />
                    </div>
                </div>
            </flux:fieldset>

            <flux:fieldset class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:legend>{{ __('Contact') }}</flux:legend>
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="form.mobile" :label="__('Mobile')" type="tel" autocomplete="tel" required />
                        <flux:input wire:model="form.email" :label="__('Email')" type="email" autocomplete="email" />
                        @if (($form['type'] ?? '') === 'company')
                            <flux:input wire:model="form.contact_person" :label="__('Contact person')" />
                        @endif
                    </div>
                    <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" autocomplete="street-address" />
                </div>
            </flux:fieldset>

            <flux:fieldset class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:legend>{{ __('Emergency contact') }}</flux:legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.emergency_contact_name" :label="__('Name')" />
                    <flux:input wire:model="form.emergency_contact_phone" :label="__('Phone')" type="tel" />
                </div>
            </flux:fieldset>

            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($customer)
        <livewire:documents.panel :documentable="$customer" :key="'docs-customer-'.$customer->id" />
    @endif
</section>
