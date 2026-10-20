<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Maintenance requests') }}</flux:heading>
        @can('create', \App\Models\MaintenanceRequest::class)
            <flux:button variant="primary" :href="route('maintenance.requests.create')" wire:navigate>{{ __('New request') }}</flux:button>
        @endcan
    </div>

    <div class="border-b border-zinc-200 dark:border-zinc-700">
        <div role="tablist" aria-label="{{ __('Maintenance requests') }}" class="-mb-px flex max-w-full gap-1 overflow-x-auto">
            @foreach ($tabLabels as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $currentTab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'flex shrink-0 items-center gap-2 rounded-t-md border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-inset',
                        'border-zinc-800 text-zinc-900 dark:border-white dark:text-white' => $currentTab === $key,
                        'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $currentTab !== $key,
                    ])>
                    {{ $label }}
                    <flux:badge size="sm">{{ $counts[$key] }}</flux:badge>
                </button>
            @endforeach
        </div>
    </div>

    <div class="flex flex-col flex-wrap gap-3 sm:flex-row">
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
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number, unit code, tenant name or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$requests">
            <flux:table.columns>
                <flux:table.column>{{ __('Request') }}</flux:table.column>
                <flux:table.column>{{ __('Building / unit') }}</flux:table.column>
                <flux:table.column>{{ __('Category') }}</flux:table.column>
                <flux:table.column>{{ __('Priority') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Logged') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($requests as $r)
                    <flux:table.row :key="$r->id">
                        <flux:table.cell>
                            <flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link>
                            @if ($r->channel === \App\Enums\MaintenanceChannel::Portal)
                                <flux:badge size="sm" color="sky" class="ms-1">{{ __('Portal') }}</flux:badge>
                            @endif
                            <div class="text-xs text-zinc-500">{{ $r->summary() }}</div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $r->building->code }} / {{ $r->unit?->code ?? __('Common area') }}</flux:table.cell>
                        <flux:table.cell>{{ $r->category->label() }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$r->priority->color()">{{ $r->priority->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $r->customer?->name_en ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $r->created_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$r->status" /></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="7">{{ __('No requests found.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
