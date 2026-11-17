@php
    $nav = $owner ? array_values(array_filter([
        ['details', __('Details')],
        $canViewBank ? ['bank', __('Bank details')] : null,
        $portal ? ['portal', __('Owner portal')] : null,
        ['documents', __('Documents')],
    ])) : [];
@endphp
<section class="w-full max-w-2xl space-y-6">
    @if ($owner)
        <x-ui.summary :title="$owner->name_en" :back="route('owners.index')">
            <x-slot:facts>
                <x-ui.fact :label="__('Phone')">{{ $owner->phone ?: '—' }}</x-ui.fact>
                <x-ui.fact :label="__('Email')"><span class="break-words">{{ $owner->email ?: '—' }}</span></x-ui.fact>
            </x-slot:facts>
        </x-ui.summary>
        <x-ui.section-nav :items="$nav" />
    @else
        <x-ui.page-header :title="__('New owner')" :back="route('owners.index')" />
    @endif

    <form wire:submit="save" id="details" class="scroll-mt-20 space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-6">
            <x-ui.section :title="__('Identity')" :hint="__('Who the owner is, as shown on contracts and statements.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <flux:select wire:model="form.type" :label="__('Owner type')" aria-required="true">
                            @foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>@endforeach
                        </flux:select>
                    </div>
                    <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
                    <flux:input wire:model="form.name_ar" :label="__('Name (Arabic)')" dir="rtl" />
                    <flux:select wire:model="form.id_type" :label="__('ID type')" aria-required="true">
                        @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                    <x-nationality-select wire:model="form.nationality" :current="$owner?->nationality" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Contact')" :hint="__('How to reach the owner.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.phone" :label="__('Phone')" type="tel" />
                    <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" />
                    </div>
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
                    </div>
                </div>
            </x-ui.section>
        </fieldset>

        @if ($canViewBank)
            <x-ui.section :title="__('Bank details')" id="bank" :hint="__('Where payments to the owner are sent.')">
                @if ($owner?->bankChangedRecently())
                    <flux:callout variant="warning" icon="exclamation-triangle"
                        :heading="__('Bank details changed :date by :user', ['date' => $owner->bank_changed_at->timezone('Asia/Bahrain')->format('d/m/Y'), 'user' => $owner->bankChanger?->name])" />
                @endif
                <fieldset @disabled(! $canEditBank) class="grid gap-4 sm:grid-cols-2">
                    <x-bank-select wire:model="form.bank_name" :current="$owner?->bank_name" />
                    <div class="sm:col-span-2">
                        <flux:input wire:model="form.iban" :label="__('IBAN')" />
                    </div>
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
        <x-ui.section :title="__('Owner portal')" id="portal" :hint="__('Sign-in access to the owner portal.')">
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
        <div id="documents" class="scroll-mt-20">
            <livewire:documents.panel :documentable="$owner" :key="'docs-owner-'.$owner->id" />
        </div>
    @endif
</section>
