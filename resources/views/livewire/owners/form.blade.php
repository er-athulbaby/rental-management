<section class="w-full max-w-2xl space-y-6">
    <x-ui.page-header :title="$owner?->name_en ?? __('New owner')" :back="route('owners.index')" />

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-6">
            <x-ui.section :title="__('Identity')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="form.type" :label="__('Owner type')" aria-required="true" class="sm:col-span-2">
                        @foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
                    <flux:input wire:model="form.name_ar" :label="__('Name (Arabic)')" dir="rtl" />
                    <flux:select wire:model="form.id_type" :label="__('ID type')" aria-required="true">
                        @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                    <x-nationality-select wire:model="form.nationality" :current="$owner?->nationality" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Contact')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.phone" :label="__('Phone')" type="tel" />
                    <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                    <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" class="sm:col-span-2" />
                    <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" class="sm:col-span-2" />
                </div>
            </x-ui.section>
        </fieldset>

        @if ($canViewBank)
            <x-ui.section :title="__('Bank details')">
                @if ($owner?->bankChangedRecently())
                    <flux:callout variant="warning" icon="exclamation-triangle"
                        :heading="__('Bank details changed :date by :user', ['date' => $owner->bank_changed_at->timezone('Asia/Bahrain')->format('d/m/Y'), 'user' => $owner->bankChanger?->name])" />
                @endif
                <fieldset @disabled(! $canEditBank) class="space-y-4">
                    <x-bank-select wire:model="form.bank_name" :current="$owner?->bank_name" />
                    <flux:input wire:model="form.iban" :label="__('IBAN')" />
                    <flux:input wire:model="form.account_name" :label="__('Account name')" />
                </fieldset>
            </x-ui.section>
        @endif

        @if ($canEdit)
            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <x-ui.form-actions :cancel="route('owners.index')">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </x-ui.form-actions>
        @endif
    </form>
    @unless ($canEdit)
        <x-custom-fields.display :items="$customDetails" />
    @endunless

    @if ($portal)
        <x-ui.section :title="__('Owner portal')">
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
                <flux:text>{{ $portal['otherSeen'] ? __('Also used by tenant :name', ['name' => $portal['other']->name_en]) : __('Also used by another tenant') }}</flux:text>
            @endif
            @if (! $portal['mailReady'])
                <flux:text>{{ __('Portal email is not set up.') }}</flux:text>
            @endif
            <div class="flex flex-wrap gap-2">
                @if ($portal['mailReady'] && filled($owner?->email))
                    <flux:button variant="primary" wire:click="invitePortal">
                        {{ $portal['account'] && ! $portal['account']->disabled_at ? __('Re-send invite') : __('Invite to portal') }}
                    </flux:button>
                @endif
                @if ($portal['account'] && ! $portal['account']->disabled_at)
                    <flux:button variant="ghost" wire:click="disablePortal" wire:confirm="{{ __('Disable this owner\'s portal access?').($portal['other'] ? ' '.__('This also ends their access as tenant.') : '') }}">{{ __('Disable') }}</flux:button>
                @elseif ($portal['account'])
                    <flux:button variant="ghost" wire:click="enablePortal">{{ __('Enable') }}</flux:button>
                @endif
            </div>
            <flux:error name="portal" />
        </x-ui.section>
    @endif

    @if ($owner)
        <livewire:documents.panel :documentable="$owner" :key="'docs-owner-'.$owner->id" />
    @endif
</section>
