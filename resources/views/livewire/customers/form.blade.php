@php
    $nav = $customer ? array_values(array_filter([
        ['details', __('Details')],
        $portal ? ['portal', __('Tenant portal')] : null,
        $maintenance ? ['maintenance', __('Maintenance')] : null,
        ['reminders', __('Reminders')],
        ['documents', __('Documents')],
    ])) : [];
@endphp
<section class="w-full max-w-4xl space-y-6">
    @if ($customer)
        <x-ui.summary :title="$customer->name_en" :back="route('customers.index')">
            <x-slot:actions>
                @if ($account)
                    @if ($account['canRecord'])
                        <flux:button variant="primary" :href="route('payments.create', ['customer' => $customer->id])" wire:navigate>{{ __('Record payment') }}</flux:button>
                    @endif
                    <flux:button :href="route('customers.statement', $customer)" wire:navigate>{{ __('Statement') }}</flux:button>
                    @can('create', App\Models\Invoice::class)
                        <flux:button :href="route('invoices.create', ['customer' => $customer->id])" wire:navigate>{{ __('Manual invoice') }}</flux:button>
                    @endcan
                @endif
            </x-slot:actions>
            <x-slot:facts>
                @if ($account)
                    <x-ui.fact :label="__('Outstanding')">{{ $account['outstanding'] }}</x-ui.fact>
                    <x-ui.fact :label="__('Credit')">{{ $account['credit'] }}</x-ui.fact>
                @endif
                <x-ui.fact :label="__('Mobile')">{{ $customer->mobile }}</x-ui.fact>
                <x-ui.fact :label="__('ID')">{{ $customer->id_type->label() }} {{ $customer->maskedId() }}</x-ui.fact>
            </x-slot:facts>
        </x-ui.summary>
        <x-ui.section-nav :items="$nav" />
    @else
        <x-ui.page-header :title="__('New tenant')" :back="route('customers.index')" />
    @endif

    <form wire:submit="save" id="details" class="scroll-mt-20 space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-6">
            <x-ui.section :title="__('Identity')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:radio.group wire:model.live="form.type" :label="__('Tenant type')" variant="segmented" aria-required="true" class="sm:col-span-2">
                        <flux:radio value="individual" :label="__('Individual')" />
                        <flux:radio value="company" :label="__('Company')" />
                    </flux:radio.group>
                    <flux:input wire:model="form.name_en" :label="__('Name (English)')" autocomplete="name" required />
                    <flux:input wire:model="form.name_ar" :label="__('Name (Arabic, used in contracts)')" dir="rtl" />
                    <flux:select wire:model="form.id_type" :label="__('ID type')" aria-required="true">
                        @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                    <x-nationality-select wire:model="form.nationality" :current="$customer?->nationality" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Contact')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.mobile" :label="__('Mobile')" type="tel" autocomplete="tel" required />
                    <flux:input wire:model="form.email" :label="__('Email')" type="email" autocomplete="email" />
                    @if (($form['type'] ?? '') === 'company')
                        <flux:input wire:model="form.contact_person" :label="__('Contact person')" />
                    @endif
                    <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" autocomplete="street-address" class="sm:col-span-2" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Emergency contact')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.emergency_contact_name" :label="__('Name')" />
                    <flux:input wire:model="form.emergency_contact_phone" :label="__('Phone')" type="tel" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Reminders and notes')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:switch wire:model="form.no_reminders" :label="__('Don\'t send reminders')"
                        :description="__('No automatic or manual reminder emails go to this tenant.')" class="sm:col-span-2" />
                    <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" class="sm:col-span-2" />
                </div>
            </x-ui.section>
        </fieldset>
        @if ($canEdit)
            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <x-ui.form-actions :cancel="route('customers.index')">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </x-ui.form-actions>
        @endif
    </form>
    @unless ($canEdit)
        <x-custom-fields.display :items="$customDetails" />
    @endunless

    @if ($portal)
        <x-ui.section :title="__('Tenant portal')" id="portal">
            @if ($portal['account'] === null)
                <flux:text>{{ __('Not invited yet.') }}</flux:text>
            @elseif ($portal['account']->disabled_at)
                <flux:text>{{ __('Portal access is disabled.') }}</flux:text>
            @else
                <flux:text>
                    {{ __('Invited :date, signs in with :email.', ['date' => $portal['account']->invited_at?->format('d/m/Y') ?? '—', 'email' => $portal['account']->email]) }}
                    @if ($portal['account']->last_login_at)
                        {{ __('Last signed in :date.', ['date' => $portal['account']->last_login_at->format('d/m/Y')]) }}
                    @endif
                </flux:text>
            @endif
            @if ($portal['other'])
                <flux:text>{{ $portal['otherSeen'] ? __('Also used by owner :name', ['name' => $portal['other']->name_en]) : __('Also used by another owner') }}</flux:text>
            @endif
            @if (! $portal['mailReady'])
                <flux:text>{{ __('Portal email is not set up.') }}</flux:text>
            @endif
            <div class="flex flex-wrap gap-2">
                @if ($portal['mailReady'] && filled($customer?->email))
                    <flux:button variant="primary" wire:click="invitePortal">
                        {{ $portal['account'] && ! $portal['account']->disabled_at ? __('Re-send invite') : __('Invite to portal') }}
                    </flux:button>
                @endif
                @if ($portal['account'] && ! $portal['account']->disabled_at)
                    <flux:button variant="ghost" wire:click="disablePortal" wire:confirm="{{ __('Disable this tenant\'s portal access?').($portal['other'] ? ' '.__('This also ends their access as owner.') : '') }}">{{ __('Disable') }}</flux:button>
                @elseif ($portal['account'])
                    <flux:button variant="ghost" wire:click="enablePortal">{{ __('Enable') }}</flux:button>
                @endif
            </div>
            <flux:error name="portal" />
        </x-ui.section>
    @endif

    @if ($customer)
        @if ($maintenance)
            <div id="maintenance" class="scroll-mt-20">
                <x-maintenance-requests :requests="$maintenance" />
            </div>
        @endif
        <div id="reminders" class="scroll-mt-20">
            <livewire:reminders.record-panel :record="$customer" :key="'reminders-customer-'.$customer->id" />
        </div>
        <div id="documents" class="scroll-mt-20">
            <livewire:documents.panel :documentable="$customer" :key="'docs-customer-'.$customer->id" />
        </div>
    @endif
</section>
