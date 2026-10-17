@use('App\Models\ReminderSend')
@use('App\Models\ReminderSetting')
<div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="sm">{{ __('Reminders') }}</flux:heading>
        @if ($sendable)
            @if ($refusal === null)
                <flux:modal.trigger name="send-reminder">
                    <flux:button size="sm" icon="envelope">{{ __('Send reminder') }}</flux:button>
                </flux:modal.trigger>
            @else
                <flux:button size="sm" icon="envelope" disabled>{{ __('Send reminder') }}</flux:button>
            @endif
        @endif
    </div>
    @if ($sendable && $refusal !== null)
        <flux:text size="sm">{{ __('Can\'t send: :reason', ['reason' => $refusal]) }}</flux:text>
    @endif
    <flux:error name="reminder" />

    @if ($sends->isEmpty())
        <flux:text size="sm">{{ __('No reminders yet.') }}</flux:text>
    @else
        <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            @foreach ($sends as $s)
                <li class="flex flex-wrap items-center gap-2 py-2" wire:key="rs-{{ $s->id }}">
                    <span class="tabular-nums">{{ $s->sent_on->format('d/m/Y') }}</span>
                    <span>· {{ ReminderSetting::label($s->type) }}</span>
                    @if ($showItem)
                        <span>· <flux:link :href="$s->itemUrl()" wire:navigate>{{ $s->itemLabel() }}</flux:link></span>
                    @endif
                    <flux:badge size="sm" :color="$s->statusColor()">{{ $s->statusLabel() }}</flux:badge>
                    <span class="text-zinc-500">{{ $s->manual ? __('Manual · :name', ['name' => $s->sender?->name]) : __('Automatic') }}</span>
                    @if ($s->reason !== null)
                        <span class="text-zinc-500">· {{ ReminderSend::reasonText($s->reason) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($previewHtml !== null)
        <flux:modal name="send-reminder" class="w-full md:max-w-3xl">
            <div class="space-y-4">
                <flux:heading size="lg">{{ $subject }}</flux:heading>
                <flux:text>{{ __('To: :email', ['email' => $email]) }}</flux:text>
                <iframe sandbox="" srcdoc="{{ $previewHtml }}" title="{{ __('Email preview') }}"
                    class="h-[60vh] w-full rounded border border-zinc-200 bg-white dark:border-zinc-700"></iframe>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" icon="paper-airplane" wire:click="send"
                        wire:confirm="{{ __('Send this reminder to :email?', ['email' => $email]) }}">{{ __('Send') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
