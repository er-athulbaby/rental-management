<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New maintenance request') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model.live="form.building_id" :label="__('Building')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($buildings as $b)<option value="{{ $b->id }}">{{ $b->code }} — {{ $b->name }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="form.unit_id" :label="__('Unit')">
                <option value="">{{ __('Common area') }}</option>
                @foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->code }}</option>@endforeach
            </flux:select>
        </div>

        <flux:field>
            <flux:label>{{ __('Tenant') }}</flux:label>
            @if ($tenant)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium">{{ $tenant->name_en }}</span>
                    <span class="text-sm text-zinc-500">· {{ $tenant->mobile }}</span>
                    <flux:link href="#" wire:click.prevent="$set('choosingTenant', true)" class="text-sm">{{ __('Change') }}</flux:link>
                    <flux:button size="xs" variant="ghost" wire:click="clearTenant">{{ __('Remove') }}</flux:button>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <flux:text class="text-sm">{{ __('No tenant on a current agreement') }}</flux:text>
                    <flux:link href="#" wire:click.prevent="$set('choosingTenant', true)" class="text-sm">{{ __('Choose a tenant') }}</flux:link>
                </div>
            @endif
            <flux:error name="form.customer_id" />
        </flux:field>
        @if ($choosing)
            <x-customer-picker :results="$customerResults" :term="$term" action="pickTenant" :label="__('Search tenants')" />
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.reported_by_name" :label="__('Reported by')" />
            <flux:select wire:model="form.channel" :label="__('How it was reported')">
                @foreach ($channels as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
            </flux:select>
            <flux:select wire:model="form.category" :label="__('Category')">
                @foreach ($categories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
            </flux:select>
            <flux:select wire:model="form.priority" :label="__('Priority')">
                @foreach ($priorities as $p)<option value="{{ $p->value }}">{{ $p->label() }}</option>@endforeach
            </flux:select>
        </div>
        <flux:textarea wire:model="form.description" :label="__('Problem')" rows="4" />
        <flux:switch wire:model="form.entry_permitted" :label="__('The vendor may enter when the tenant is out')" />

        <flux:button variant="primary" type="submit">{{ __('Log request') }}</flux:button>
    </form>
</section>
