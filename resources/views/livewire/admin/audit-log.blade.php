<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Audit log') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <flux:input wire:model.live.debounce.300ms="event" :label="__('Event')" />
        <flux:select wire:model.live="causerId" :label="__('User')">
            <option value="">{{ __('Anyone') }}</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="subjectType" :label="__('Record type')">
            <option value="">{{ __('Any') }}</option>
            @foreach ($subjectTypes as $type)
                <option value="{{ $type }}">{{ class_basename($type) }}</option>
            @endforeach
        </flux:select>
        <x-date-input wire:model.live="from" :label="__('From')" />
        <x-date-input wire:model.live="to" :label="__('To')" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$entries">
            <flux:table.columns>
                <flux:table.column>{{ __('Time') }}</flux:table.column>
                <flux:table.column>{{ __('User') }}</flux:table.column>
                <flux:table.column>{{ __('Event') }}</flux:table.column>
                <flux:table.column>{{ __('Record') }}</flux:table.column>
                <flux:table.column>{{ __('IP') }}</flux:table.column>
                <flux:table.column>{{ __('Changes') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($entries as $entry)
                    <flux:table.row :key="$entry->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $entry->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i:s') }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->causer?->name ?? __('System') }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->event }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->subject_type ? class_basename($entry->subject_type).' #'.$entry->subject_id : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->ip ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($entry->attribute_changes?->isNotEmpty() || $entry->properties?->isNotEmpty())
                                <details>
                                    <summary class="cursor-pointer text-sm">{{ __('Show') }}</summary>
                                    <pre class="mt-2 max-w-md overflow-x-auto text-xs">{{ json_encode(['changes' => $entry->attribute_changes, 'properties' => $entry->properties], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
