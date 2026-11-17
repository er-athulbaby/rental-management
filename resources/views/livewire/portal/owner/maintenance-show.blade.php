<section class="space-y-6">
    <x-ui.page-header :title="$request->number" :back="route('portal.owner.maintenance')" :back-label="__('All requests')">
        <x-slot:meta>
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge :status="$request->status" />
                <flux:text>{{ $request->place() }} · {{ $request->category->label() }}</flux:text>
            </div>
        </x-slot:meta>
    </x-ui.page-header>

    <x-ui.section :title="__('Details')">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-zinc-500">{{ __('Sent') }}</dt><dd class="tabular-nums">{{ $request->created_at->format('d/m/Y') }}</dd></div>
            @if ($cost > 0)
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Cost charged to you') }}</dt>
                    <dd class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($cost)]) }}</dd>
                </div>
            @endif
        </dl>
    </x-ui.section>

    <x-ui.section :title="__('Progress')">
        <ol class="space-y-3 border-s-2 border-zinc-200 ps-4 dark:border-zinc-700">
            @foreach ($timeline as $i => $step)
                <li wire:key="step-{{ $i }}" class="text-sm">
                    <time class="text-zinc-500 tabular-nums" datetime="{{ $step['at']->toIso8601String() }}">{{ $step['at']->format('d/m/Y H:i') }}</time>
                    · {{ $step['label'] }}
                </li>
            @endforeach
        </ol>
    </x-ui.section>
</section>
