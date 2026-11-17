<section class="space-y-6">
    <x-ui.page-header :title="__('Invoices')" :hint="__('Amounts are in BHD.')" />

    <div role="tablist" aria-label="{{ __('Invoices') }}" class="flex gap-2 overflow-x-auto pb-1">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $currentTab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                @class([
                    'inline-flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-medium',
                    'bg-accent text-accent-foreground' => $currentTab === $key,
                    'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800' => $currentTab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="space-y-3">
        @forelse ($invoices as $i)
            <li wire:key="inv-{{ $i->id }}" class="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $i->number }}</span>
                    <x-status-badge :status="$i->displayLabel()" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Issued :date', ['date' => $i->issue_date->format('d/m/Y')]) }} · {{ __('Due :date', ['date' => $i->due_date->format('d/m/Y')]) }}
                </div>
                <dl class="grid grid-cols-3 gap-2 rounded-lg bg-zinc-50 p-3 text-sm tabular-nums dark:bg-zinc-800/60">
                    <div><dt class="text-zinc-500">{{ __('Total') }}</dt><dd>{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->total)) }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Paid') }}</dt><dd>{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->total) - App\Support\Fils::fromDecimal($i->balance)) }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Balance') }}</dt><dd class="font-semibold">{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->balance)) }}</dd></div>
                </dl>
                <flux:link :href="route('portal.invoices.pdf', $i)" class="inline-flex min-h-11 items-center text-sm">{{ __('Invoice PDF') }}</flux:link>
            </li>
        @empty
            <li class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><x-ui.empty :title="__('No invoices here.')" /></li>
        @endforelse
    </ul>
</section>
