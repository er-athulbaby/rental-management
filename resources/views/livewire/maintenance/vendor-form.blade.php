<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $vendor?->name ?? __('New vendor') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:input wire:model="form.name" :label="__('Name')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.type" :label="__('Type')">
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.mobile" :label="__('Mobile')" type="tel" required />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.cr_number" :label="__('CR number')" />
                <flux:input wire:model="form.vat_number" :label="__('VAT number')" />
            </div>
            <flux:checkbox.group wire:model="form.trades" :label="__('Trades')" class="grid gap-2 sm:grid-cols-2">
                @foreach ($trades as $t)<flux:checkbox :value="$t->value" :label="$t->label()" />@endforeach
            </flux:checkbox.group>
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
            <flux:switch wire:model="form.active" :label="__('Active: can get new work orders')" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($vendor)
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Work orders') }}</flux:heading>
            <flux:text class="text-sm font-medium">{{ __('Total cost: :amount BHD', ['amount' => $totalCost]) }}</flux:text>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($workOrders as $wo)
                    <li wire:key="vwo-{{ $wo->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span>
                            <flux:link :href="route('maintenance.requests.show', $wo->maintenance_request_id)" wire:navigate>{{ $wo->number }}</flux:link>
                            · {{ $wo->maintenanceRequest->number }} · {{ $wo->maintenanceRequest->building->code }} / {{ $wo->maintenanceRequest->unit?->code ?? __('Common area') }}
                        </span>
                        <x-status-badge :status="$wo->status" />
                    </li>
                @empty
                    <li class="py-2"><flux:text>{{ __('No work orders yet.') }}</flux:text></li>
                @endforelse
            </ul>
        </div>
    @endif
</section>
