<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Credit note on :n', ['n' => $target->label()])" :hint="__('Enter the amount (including tax) to credit on each line. Management approves; anything already paid on a credited line goes back to the tenant as credit.')" :back="route('invoices.index')" />

    <form wire:submit="submit" class="space-y-6">
        <x-ui.section :title="__('Lines')" flush>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($target->lines as $line)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6" wire:key="cl-{{ $line->id }}">
                        <div class="min-w-0 text-sm"><span class="font-medium text-zinc-900 dark:text-white">{{ $line->description }}</span> <span class="tabular-nums text-zinc-500 dark:text-zinc-400">· {{ __('total :t, credited :c, paid :p', ['t' => $line->total, 'c' => $line->credited, 'p' => $line->allocated]) }}</span></div>
                        <div>
                            <flux:input wire:model="amounts.{{ $line->id }}" inputmode="decimal" :placeholder="__('BHD')" class="max-w-32 text-end tabular-nums" />
                            <flux:error name="amounts.{{ $line->id }}" />
                        </div>
                    </div>
                @endforeach
            </div>
            <flux:error name="lines" class="px-4 py-2 sm:px-6" />
        </x-ui.section>

        <x-ui.section :title="__('Reason')">
            <flux:textarea wire:model="reason" :label="__('Reason')" label:sr-only="true" rows="2" />
            <flux:error name="reason" />
        </x-ui.section>

        <div class="flex flex-wrap justify-end gap-2">
            <flux:button wire:click="saveDraft">{{ __('Save draft') }}</flux:button>
            <flux:button variant="primary" type="submit">{{ __('Send for approval') }}</flux:button>
        </div>
    </form>
</section>
