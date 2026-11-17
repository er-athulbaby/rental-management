<section class="w-full max-w-4xl space-y-6">
    <x-ui.page-header :title="__('Pending approvals')" />

    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Waiting')" :value="$rows->count()" :tone="$rows->isNotEmpty() ? 'warning' : null" />
        <x-ui.stat :label="__('For you to decide')" :value="$rows->where('mine', false)->count()" />
    </x-ui.stats>

    <flux:error name="approval" />

    @forelse ($rows as $row)
        @php($approval = $row['approval'])
        <x-ui.section wire:key="approval-{{ $approval->id }}" :tone="$row['mine'] ? null : 'accent'" flush>
            <div class="space-y-4 p-4 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 space-y-2">
                        <flux:badge size="sm" color="amber">{{ $approval->action->label() }}</flux:badge>
                        <p class="text-base font-medium text-zinc-900 dark:text-white">{{ $row['summary'] }}</p>
                    </div>
                    @if ($row['url'])
                        <flux:button size="sm" variant="ghost" icon:trailing="arrow-up-right" :href="$row['url']" wire:navigate>{{ __('Open') }}</flux:button>
                    @endif
                </div>

                <div class="grid gap-x-6 gap-y-3 rounded-lg bg-zinc-50 p-3 text-sm sm:grid-cols-2 dark:bg-zinc-800/50">
                    <div class="min-w-0 sm:col-span-2">
                        <p class="text-zinc-700 dark:text-zinc-300">{{ __('Requested by :name on :date', ['name' => $approval->requester->name, 'date' => $approval->requested_at->timezone('Asia/Bahrain')->format('d/m/Y H:i')]) }}</p>
                    </div>
                    @if ($approval->reason)
                        <div class="min-w-0 whitespace-pre-line text-zinc-700 sm:col-span-2 dark:text-zinc-300">{{ __('Reason: :reason', ['reason' => $approval->reason]) }}</div>
                    @endif
                </div>

                @if ($row['mine'])
                    <flux:callout icon="information-circle" :heading="__('Another approver must decide this: you requested it or created the record.')" />
                @elseif ($rejecting === $approval->id)
                    <form wire:submit="reject" class="space-y-3 rounded-lg border border-red-300 p-4 dark:border-red-500/40">
                        <flux:textarea wire:model="comment" :label="__('Reason for rejecting')" rows="2" />
                        <div class="flex flex-wrap justify-end gap-2">
                            <flux:button wire:click="$set('rejecting', null)">{{ __('Cancel') }}</flux:button>
                            <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
                        </div>
                    </form>
                @else
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:button wire:click="startRejecting({{ $approval->id }})">{{ __('Reject') }}</flux:button>
                        <flux:button variant="primary" wire:click="approve({{ $approval->id }})" wire:confirm="{{ __('Approve this request?') }}">{{ __('Approve') }}</flux:button>
                    </div>
                @endif
            </div>
        </x-ui.section>
    @empty
        <x-ui.section><x-ui.empty :title="__('Nothing is waiting for approval.')" icon="check-badge" /></x-ui.section>
    @endforelse
</section>
