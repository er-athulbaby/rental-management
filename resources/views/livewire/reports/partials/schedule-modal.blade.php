{{-- The "Schedule email" modal for a saved report or a package. Needs $intro, $fixedDates, $schedules, $lastDelivery, $staff, $schedule. --}}
@php
    $days = [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];
    $statusLabels = ['sending' => __('sending'), 'sent' => __('sent'), 'failed' => __('failed'), 'skipped' => __('skipped')];
    $freq = $schedule['frequency'] ?? '';
    $freqLabels = ['daily' => __('Every day'), 'weekly' => __('Every week'), 'monthly' => __('Every month')];
@endphp
    <flux:modal name="schedule-email" class="w-full max-w-xl">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Schedule email') }}</flux:heading>
            <flux:text>{{ $intro }}</flux:text>
            @if ($fixedDates)
                <flux:callout icon="information-circle" :heading="__('This report uses fixed dates, so a schedule will send the same dates every time.')" />
            @endif

            @forelse ($schedules as $s)
                <div class="flex items-start justify-between gap-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700" wire:key="schedule-{{ $s->id }}">
                    <div class="min-w-0 space-y-1">
                        <div class="font-medium">
                            {{ $freqLabels[$s->frequency] ?? $s->frequency }}
                            @if ($s->frequency === 'weekly'){{ $days[$s->day] ?? '' }}@elseif ($s->frequency === 'monthly'){{ $s->day === 0 ? __('last day') : __('day :n', ['n' => $s->day]) }}@endif
                            {{ __('at') }} {{ sprintf('%02d:00', $s->hour) }}
                        </div>
                        <div>{{ __('Next run') }}: {{ $s->next_run_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}
                            @if ($s->paused_at)<flux:badge size="sm" color="amber">{{ __('Paused') }}</flux:badge>@endif
                        </div>
                        <div class="text-zinc-500">{{ $s->recipients->pluck('name')->join(', ') }}</div>
                        @if (isset($lastDelivery[$s->id]))<div class="text-zinc-500">{{ __('Last send') }}: {{ $statusLabels[$lastDelivery[$s->id]->status] ?? $lastDelivery[$s->id]->status }} · {{ $lastDelivery[$s->id]->due_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</div>@endif
                    </div>
                    <div class="flex shrink-0 gap-1">
                        @if ($s->paused_at)<flux:button size="sm" wire:click="resumeSchedule({{ $s->id }})">{{ __('Resume') }}</flux:button>@endif
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeSchedule({{ $s->id }})" wire:confirm="{{ __('Remove this schedule?') }}" :aria-label="__('Remove schedule')" />
                    </div>
                </div>
            @empty
                <flux:text class="text-sm">{{ __('Not scheduled yet.') }}</flux:text>
            @endforelse

            <div class="grid grid-cols-3 gap-2">
                <flux:select wire:model.live="schedule.frequency" :label="__('Repeat')">
                    @foreach ($freqLabels as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                </flux:select>
                @if ($freq === 'weekly')
                    <flux:select wire:model="schedule.day" :label="__('Day')">
                        @foreach ($days as $n => $d)<option value="{{ $n }}">{{ $d }}</option>@endforeach
                    </flux:select>
                @elseif ($freq === 'monthly')
                    <flux:select wire:model="schedule.day" :label="__('Day')">
                        @for ($n = 1; $n <= 28; $n++)<option value="{{ $n }}">{{ $n }}</option>@endfor
                        <option value="0">{{ __('Last day') }}</option>
                    </flux:select>
                @else
                    <div></div>
                @endif
                <flux:select wire:model="schedule.hour" :label="__('Time')">
                    @for ($h = 0; $h < 24; $h++)<option value="{{ $h }}">{{ sprintf('%02d:00', $h) }}</option>@endfor
                </flux:select>
            </div>
            @error('schedule.frequency')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror
            @error('schedule.day')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror
            @error('schedule.hour')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror

            <fieldset class="space-y-2">
                <legend class="mb-1 text-sm font-medium">{{ __('Send to') }}</legend>
                <flux:input wire:model.live.debounce.300ms="staffSearch" :aria-label="__('Search staff')" :placeholder="__('Search staff by name or email')" icon="magnifying-glass" autocomplete="off" />
                <div class="max-h-48 space-y-1 overflow-y-auto">
                    @forelse ($staff as $u)
                        <label class="flex items-center gap-2 text-sm" wire:key="staff-{{ $u->id }}">
                            <input type="checkbox" value="{{ $u->id }}" wire:model="schedule.recipients"> {{ $u->name }} <span class="text-zinc-500">{{ $u->email }}</span>
                        </label>
                    @empty
                        <flux:text class="text-sm">{{ __('No staff found.') }}</flux:text>
                    @endforelse
                </div>
                @foreach ($errors->get('schedule.recipients*') as $messages)@foreach ($messages as $m)<flux:text class="text-sm text-red-600" role="alert">{{ $m }}</flux:text>@endforeach @endforeach
            </fieldset>
            <div class="flex justify-end"><flux:button variant="primary" wire:click="addSchedule">{{ __('Add schedule') }}</flux:button></div>
        </div>
    </flux:modal>
