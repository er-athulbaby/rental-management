<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 flex-1">
            <flux:input wire:model.blur="name" :placeholder="__('Untitled package')" :aria-label="__('Package name')" aria-describedby="package-name-error" :aria-invalid="$errors->has('name') ? 'true' : null" class="max-w-md" :disabled="! $canEdit" />
            @error('name')<flux:text id="package-name-error" class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canEdit)
                <flux:button variant="primary" wire:click="save">{{ __('Save') }}</flux:button>
            @endif
            @if ($packageId)
                <flux:button icon="arrow-down-tray" wire:click="download">{{ __('Download now') }}</flux:button>
                @if ($canEdit)
                    <flux:button :icon="$shared ? 'users' : 'lock-closed'" wire:click="share({{ $shared ? 'false' : 'true' }})" aria-pressed="{{ $shared ? 'true' : 'false' }}">{{ $shared ? __('Shared') : __('Only me') }}</flux:button>
                    <flux:modal.trigger name="schedule-email"><flux:button icon="envelope">{{ __('Schedule email') }}</flux:button></flux:modal.trigger>
                    <flux:button variant="ghost" icon="trash" wire:click="deletePackage" wire:confirm="{{ __('Delete this package?') }}" :aria-label="__('Delete')" />
                @endif
            @endif
            <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
        </div>
    </div>

    @if ($packageId && $canEdit)
        @include('livewire.reports.partials.schedule-modal', ['intro' => __('Each person gets the package with only the data they are allowed to see.'), 'fixedDates' => false])
    @endif

    <flux:text>{{ __('One Excel workbook, one sheet per report, in this order. Periods are worked out again on every run.') }}</flux:text>
    @error('items')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror

    <ol class="space-y-3">
        @forelse ($rows as $n => $row)
            <li class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="item-{{ $n }}-{{ $row['index'] }}-{{ $row['title'] }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $n + 1 }}. {{ $row['title'] }}</span>
                        <span class="block text-sm text-zinc-500">{{ $row['kind'] }}</span>
                    </div>
                    @if ($canEdit)
                        <div class="flex shrink-0 gap-1">
                            <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="moveItem({{ $n }}, -1)" :aria-label="__('Move up')" />
                            <flux:button size="sm" variant="ghost" icon="arrow-down" wire:click="moveItem({{ $n }}, 1)" :aria-label="__('Move down')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItem({{ $n }})" :aria-label="__('Remove')" />
                        </div>
                    @endif
                </div>
                @if ($row['form'] !== null)
                    <div class="mt-3 grid gap-3 sm:grid-cols-4">
                        @if ($row['form']['building'])
                            <x-building-filter :buildings="$buildings" :model="'items.'.$row['index'].'.settings.building'" :label="__('Building').' ('.$row['title'].')'" :disabled="! $canEdit" />
                        @endif
                        @if ($row['form']['dates'] !== 'none')
                            <flux:select wire:model="items.{{ $row['index'] }}.settings.period" :label="__('Period').' ('.$row['title'].')'" :disabled="! $canEdit">
                                @foreach ($presets as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </flux:select>
                        @endif
                        @foreach ($row['form']['options'] as $property => $choices)
                            <flux:select wire:model="items.{{ $row['index'] }}.settings.{{ $property }}" :label="__(str($property)->headline()->toString()).' ('.$row['title'].')'" :disabled="! $canEdit">
                                @foreach ($choices as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </flux:select>
                        @endforeach
                    </div>
                @elseif ($row['locked'])
                    <flux:text class="mt-2 text-sm">{{ __('You do not have access to this report.') }}</flux:text>
                @endif
                @php($itemErrors = [...$errors->get('items.'.$row['index']), ...collect($errors->get('items.'.$row['index'].'.*'))->flatten()->all()])
                @foreach ($itemErrors as $message)
                    <flux:text class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</flux:text>
                @endforeach
            </li>
        @empty
            <li><flux:text>{{ __('No reports yet. Add the first one below.') }}</flux:text></li>
        @endforelse
    </ol>

    @if ($canEdit)
        <div class="flex flex-wrap items-end gap-2">
            <flux:select wire:model="add" :label="__('Add a report')" class="sm:max-w-md">
                <option value="">{{ __('Choose a report') }}</option>
                @if ($saved->isNotEmpty())
                    <optgroup label="{{ __('Saved reports') }}">
                        @foreach ($saved as $r)<option value="saved:{{ $r->id }}">{{ $r->name }}</option>@endforeach
                    </optgroup>
                @endif
                <optgroup label="{{ __('Ready-made reports') }}">
                    @foreach ($ready as $key => $form)<option value="ready:{{ $key }}">{{ $form['title'] }}</option>@endforeach
                </optgroup>
            </flux:select>
            <flux:button icon="plus" wire:click="addItem">{{ __('Add') }}</flux:button>
        </div>
        @error('add')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror
    @endif
</div>
