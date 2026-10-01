<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Credit note on :n', ['n' => $target->label()]) }}</flux:heading>
    <flux:text>{{ __('Enter the amount (including tax) to credit on each line. Management approves; anything already paid on a credited line goes back to the customer as credit.') }}</flux:text>

    <form wire:submit="submit" class="space-y-4">
        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($target->lines as $line)
                <div class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="cl-{{ $line->id }}">
                    <div class="text-sm">{{ $line->description }} · {{ __('total :t, credited :c, paid :p', ['t' => $line->total, 'c' => $line->credited, 'p' => $line->allocated]) }}</div>
                    <div>
                        <flux:input wire:model="amounts.{{ $line->id }}" inputmode="decimal" :placeholder="__('BHD')" class="max-w-32" />
                        <flux:error name="amounts.{{ $line->id }}" />
                    </div>
                </div>
            @endforeach
        </div>
        <flux:error name="lines" />
        <flux:textarea wire:model="reason" :label="__('Reason')" rows="2" />
        <flux:error name="reason" />
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="saveDraft">{{ __('Save draft') }}</flux:button>
            <flux:button variant="primary" type="submit">{{ __('Send for approval') }}</flux:button>
        </div>
    </form>
</section>
