<section class="w-full max-w-4xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Pending approvals') }}</flux:heading>
    <flux:error name="approval" />

    @forelse ($rows as $row)
        @php($approval = $row['approval'])
        <flux:card class="space-y-3" wire:key="approval-{{ $approval->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="space-y-1">
                    <flux:heading>{{ $approval->action->label() }}</flux:heading>
                    <flux:text>{{ $row['summary'] }}</flux:text>
                    <flux:text size="sm">{{ __('Requested by :name on :date', ['name' => $approval->requester->name, 'date' => $approval->requested_at->timezone('Asia/Bahrain')->format('d/m/Y H:i')]) }}</flux:text>
                    @if ($approval->reason)
                        <flux:text size="sm">{{ __('Reason: :reason', ['reason' => $approval->reason]) }}</flux:text>
                    @endif
                </div>
                @if ($row['url'])
                    <flux:link :href="$row['url']" wire:navigate>{{ __('Open') }}</flux:link>
                @endif
            </div>

            @if ($row['mine'])
                <flux:text size="sm">{{ __('Another approver must decide this: you requested it or created the record.') }}</flux:text>
            @elseif ($rejecting === $approval->id)
                <form wire:submit="reject" class="space-y-2">
                    <flux:textarea wire:model="comment" :label="__('Reason for rejecting')" rows="2" />
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
                        <flux:button wire:click="$set('rejecting', null)">{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            @else
                <div class="flex gap-2">
                    <flux:button variant="primary" wire:click="approve({{ $approval->id }})" wire:confirm="{{ __('Approve this request?') }}">{{ __('Approve') }}</flux:button>
                    <flux:button wire:click="startRejecting({{ $approval->id }})">{{ __('Reject') }}</flux:button>
                </div>
            @endif
        </flux:card>
    @empty
        <flux:text>{{ __('Nothing is waiting for approval.') }}</flux:text>
    @endforelse
</section>
