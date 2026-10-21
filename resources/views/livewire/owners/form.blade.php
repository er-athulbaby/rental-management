<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $owner?->name_en ?? __('New owner') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:select wire:model="form.type" :label="__('Owner type')">
                @foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
            <flux:input wire:model="form.name_ar" :label="__('Name (Arabic)')" dir="rtl" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.id_type" :label="__('ID type')">
                    @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                <x-nationality-select wire:model="form.nationality" :current="$owner?->nationality" />
                <flux:input wire:model="form.phone" :label="__('Phone')" type="tel" />
            </div>
            <flux:input wire:model="form.email" :label="__('Email')" type="email" />
            <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" />
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>

        @if ($canViewBank)
            <flux:fieldset>
                <flux:legend>{{ __('Bank details') }}</flux:legend>
                @if ($owner?->bankChangedRecently())
                    <flux:callout variant="warning" icon="exclamation-triangle"
                        :heading="__('Bank details changed :date by :user', ['date' => $owner->bank_changed_at->timezone('Asia/Bahrain')->format('d/m/Y'), 'user' => $owner->bankChanger?->name])" />
                @endif
                <fieldset @disabled(! $canEditBank) class="mt-4 space-y-4">
                    <x-bank-select wire:model="form.bank_name" :current="$owner?->bank_name" />
                    <flux:input wire:model="form.iban" :label="__('IBAN')" />
                    <flux:input wire:model="form.account_name" :label="__('Account name')" />
                </fieldset>
            </flux:fieldset>
        @endif

        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($portal)
        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ __('Owner portal') }}</flux:heading>
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
                <flux:text>{{ __('Also used by tenant :name', ['name' => $portal['other']->name_en]) }}</flux:text>
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
                    <flux:button variant="ghost" wire:click="disablePortal" wire:confirm="{{ __('Disable this owner\'s portal access?') }}">{{ __('Disable') }}</flux:button>
                @elseif ($portal['account'])
                    <flux:button variant="ghost" wire:click="enablePortal">{{ __('Enable') }}</flux:button>
                @endif
            </div>
            <flux:error name="portal" />
        </flux:card>
    @endif


    @if ($owner)
        <livewire:documents.panel :documentable="$owner" :key="'docs-owner-'.$owner->id" />
    @endif
</section>
