<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Work orders') }}</flux:heading>

    <div class="flex flex-col flex-wrap gap-3 sm:flex-row">
        <flux:select wire:model.live="status" class="sm:max-w-40">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <x-building-filter :buildings="$buildings" label="" />
        <flux:select wire:model.live="priority" class="sm:max-w-40">
            <option value="">{{ __('All priorities') }}</option>
            @foreach ($priorities as $p)<option value="{{ $p->value }}">{{ $p->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="category" class="sm:max-w-48">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($categories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="vendor" class="sm:max-w-48">
            <option value="">{{ __('All vendors') }}</option>
            @foreach ($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Work order or request number')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$workOrders">
            <flux:table.columns>
                <flux:table.column>{{ __('Work order') }}</flux:table.column>
                <flux:table.column>{{ __('Request') }}</flux:table.column>
                <flux:table.column>{{ __('Building / unit') }}</flux:table.column>
                <flux:table.column>{{ __('Vendor') }}</flux:table.column>
                <flux:table.column>{{ __('Scheduled') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Estimate') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($workOrders as $wo)
                    <flux:table.row :key="$wo->id">
                        <flux:table.cell><flux:link :href="route('maintenance.requests.show', $wo->maintenance_request_id)" wire:navigate>{{ $wo->number }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $wo->maintenanceRequest->number }}</flux:table.cell>
                        <flux:table.cell>{{ $wo->maintenanceRequest->building->code }} / {{ $wo->maintenanceRequest->unit?->code ?? __('Common area') }}</flux:table.cell>
                        <flux:table.cell>{{ $wo->vendor->name }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            {{ $wo->scheduled_on?->format('d/m/Y') ?? '—' }}
                            @if ($wo->isOverdue())
                                <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$wo->status" /></flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $wo->estimate ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="7">{{ __('No work orders found.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
