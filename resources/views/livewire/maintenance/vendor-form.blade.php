<section class="w-full max-w-2xl space-y-6">
    <x-ui.page-header :title="$vendor?->name ?? __('New vendor')" :back="route('maintenance.vendors.index')" />

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-6">
            <x-ui.section :title="__('Details')" :hint="__('Who the vendor is and how to reach them.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.name" :label="__('Name')" required />
                    <flux:select wire:model="form.type" :label="__('Type')" aria-required="true">
                        @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.mobile" :label="__('Mobile')" type="tel" required />
                    <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                    <flux:input wire:model="form.cr_number" :label="__('CR number')" />
                    <flux:input wire:model="form.vat_number" :label="__('VAT number')" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Trades')" :hint="__('The kinds of work this vendor does, and whether they can get new work.')">
                <flux:checkbox.group wire:model="form.trades" :label="__('Trades')" label:sr-only="true" class="grid gap-2 sm:grid-cols-2">
                    @foreach ($trades as $t)<flux:checkbox :value="$t->value" :label="$t->label()" />@endforeach
                </flux:checkbox.group>
                <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
                <flux:switch wire:model="form.active" :label="__('Active: can get new work orders')" />
            </x-ui.section>
        </fieldset>
        @if ($canEdit)
            <div class="flex justify-end">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        @endif
    </form>

    @if ($vendor)
        <x-ui.section :title="__('Work orders')" :hint="__('Jobs this vendor has been given.')">
            <flux:text class="text-sm font-semibold tabular-nums">{{ __('Total cost: :amount BHD', ['amount' => $totalCost]) }}</flux:text>
            <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($workOrders as $wo)
                    <li wire:key="vwo-{{ $wo->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
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
        </x-ui.section>
    @endif
</section>
