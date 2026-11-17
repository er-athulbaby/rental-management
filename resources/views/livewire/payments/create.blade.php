<section class="w-full max-w-5xl space-y-6">
    <x-ui.page-header :title="__('Record payment — :name', ['name' => $customer->name_en])" :back="route('customers.edit', $customer)" />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
        <form wire:submit="save" class="space-y-6">
            <x-ui.section :title="__('Details')" :hint="__('How and when the money came in.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="form.method" :label="__('Method')" aria-required="true">
                        @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                        <option value="split">{{ __('Split — several methods, one receipt') }}</option>
                    </flux:select>
                    <x-date-input wire:model="form.received_on" :label="__('Received on')" aria-required="true" />
                    @unless ($isSplit)
                        <flux:input wire:model="form.amount" inputmode="decimal" :label="__('Amount (BHD)')" aria-required="true" />
                        <flux:input wire:model="form.reference" :label="__('Reference')" />
                    @endunless
                </div>
                <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
                <flux:error name="form.amount" />
                <flux:error name="form.method" />
            </x-ui.section>

            @if ($isSplit)
                <x-ui.section :title="__('Paid by')" :hint="__('One line per method, e.g. card 300 and cash 200. The payment is their total, on one receipt.')">
                    <div class="space-y-2">
                        @foreach ($tenders as $i => $t)
                            <div class="flex flex-wrap items-start gap-2" wire:key="tender-{{ $i }}">
                                <flux:select wire:model="tenders.{{ $i }}.method" :aria-label="__('Method')" class="max-w-40">
                                    @foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                                </flux:select>
                                <flux:input wire:model.live.debounce.400ms="tenders.{{ $i }}.amount" inputmode="decimal" :placeholder="__('BHD')" :aria-label="__('Amount (BHD)')" class="max-w-32" />
                                <flux:input wire:model="tenders.{{ $i }}.reference" :placeholder="__('Reference, e.g. card slip no.')" :aria-label="__('Reference')" class="min-w-0 flex-1" />
                                @if (count($tenders) > 2)
                                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeTender({{ $i }})" :aria-label="__('Remove')" />
                                @endif
                            </div>
                            <flux:error name="tenders.{{ $i }}.method" /><flux:error name="tenders.{{ $i }}.amount" /><flux:error name="tenders.{{ $i }}.reference" />
                        @endforeach
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <flux:button size="sm" wire:click="addTender" icon="plus">{{ __('Add a method') }}</flux:button>
                        <flux:text class="font-medium tabular-nums">{{ __('Total: :t BHD', ['t' => $tendersTotal ?? '—']) }}</flux:text>
                    </div>
                    <flux:error name="tenders" />
                </x-ui.section>
            @endif

            <x-ui.section :title="__('Allocate to')" :hint="__('Leave every amount blank to pay the oldest invoices first. Anything not allocated stays as tenant credit.')">
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($open as $invoice)
                        <div class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="open-{{ $invoice->id }}">
                            <div class="text-sm"><span class="font-medium text-zinc-900 dark:text-white">{{ $invoice->number }}</span> <span class="tabular-nums text-zinc-500 dark:text-zinc-400">· {{ __('due :d', ['d' => $invoice->due_date->format('d/m/Y')]) }} · {{ __('balance :b', ['b' => $invoice->balance]) }}</span></div>
                            <flux:input wire:model="split.{{ $invoice->id }}" inputmode="decimal" :placeholder="__('BHD')" class="max-w-32 text-end tabular-nums" />
                        </div>
                    @empty
                        <flux:text>{{ __('Nothing is owed: the whole payment will be credit.') }}</flux:text>
                    @endforelse
                </div>
                <flux:error name="allocations" />
            </x-ui.section>

            <x-ui.form-actions :cancel="route('customers.edit', $customer)">
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Record payment') }}</flux:button>
            </x-ui.form-actions>
        </form>

        {{-- Display only (plan ruling 9): integer fils from the same $wire state the inputs bind; a value that is not up to 3 decimals shows a dash. The server alone validates and allocates. --}}
        <aside class="lg:sticky lg:top-6" aria-label="{{ __('Running total') }}"
            x-data="{
                fils(v) { const s = String(v ?? '').trim(); if (s === '') return 0; const m = /^(\d{1,9})(?:\.(\d{1,3}))?$/.exec(s); return m ? Number(m[1]) * 1000 + Number((m[2] ?? '').padEnd(3, '0')) : null },
                sum(list) { let t = 0; for (const v of list) { const f = this.fils(v); if (f === null) return null; t += f } return t },
                show(f) { return f === null ? '—' : Math.floor(f / 1000) + '.' + String(f % 1000).padStart(3, '0') },
                received() { return $wire.form?.method === 'split' ? this.sum(Object.values($wire.tenders ?? {}).map(r => r?.amount)) : this.sum([$wire.form?.amount]) },
                allocated() { return this.sum(Object.values($wire.split ?? {})) },
            }">
            <x-ui.section :title="__('Running total')">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Owed now') }}</dt><dd class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => $owed]) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Open invoices') }}</dt><dd class="font-semibold tabular-nums">{{ $open->count() }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('This payment') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(received())"></span> {{ __('BHD') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Allocated by hand') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(allocated())"></span> {{ __('BHD') }}</dd></div>
                </dl>
            </x-ui.section>
        </aside>
    </div>
</section>
