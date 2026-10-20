<section class="space-y-6">
    <div class="space-y-1">
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="xl" level="1">{{ $request->number }}</flux:heading>
            <x-status-badge :status="$request->status" />
        </div>
        <flux:text>{{ $request->place() }} · {{ $request->category->label() }}</flux:text>
    </div>

    <dl class="grid gap-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Sent') }}</dt><dd>{{ $request->created_at->format('d/m/Y') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Entry permitted') }}</dt><dd>{{ $request->entry_permitted ? __('Yes') : __('No') }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Problem') }}</dt><dd class="whitespace-pre-line">{{ $request->description }}</dd></div>
    </dl>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Progress') }}</flux:heading>
        <ol class="space-y-2 border-s-2 border-zinc-200 ps-4 dark:border-zinc-700">
            @foreach ($timeline as $i => $step)
                <li wire:key="step-{{ $i }}" class="text-sm">
                    <time class="text-zinc-500" datetime="{{ $step['at']->toIso8601String() }}">{{ $step['at']->format('d/m/Y H:i') }}</time>
                    · {{ $step['label'] }}
                </li>
            @endforeach
        </ol>
    </div>

    <flux:link :href="route('portal.maintenance')">{{ __('All requests') }}</flux:link>
</section>
