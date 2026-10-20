<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Invoices') }}</flux:heading>

    <div role="tablist" aria-label="{{ __('Invoices') }}" class="flex gap-1 overflow-x-auto">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $currentTab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                @class([
                    'shrink-0 rounded-md px-3 py-1.5 text-sm font-medium',
                    'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $currentTab === $key,
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700' => $currentTab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($invoices as $i)
            <li wire:key="inv-{{ $i->id }}" class="space-y-2 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $i->number }}</span>
                    <x-status-badge :status="$i->displayLabel()" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Issued :date', ['date' => $i->issue_date->format('d/m/Y')]) }} · {{ __('Due :date', ['date' => $i->due_date->format('d/m/Y')]) }}
                </div>
                <dl class="grid grid-cols-3 gap-2 text-sm tabular-nums">
                    <div><dt class="text-zinc-500">{{ __('Total') }}</dt><dd>{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->total)) }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Paid') }}</dt><dd>{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->total) - App\Support\Fils::fromDecimal($i->balance)) }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Balance') }}</dt><dd class="font-semibold">{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->balance)) }}</dd></div>
                </dl>
                <flux:link :href="route('portal.invoices.pdf', $i)" class="text-sm">{{ __('Invoice PDF') }}</flux:link>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No invoices here.') }}</flux:text></li>
        @endforelse
    </ul>
    <flux:text class="text-sm">{{ __('Amounts are in BHD.') }}</flux:text>
</section>
