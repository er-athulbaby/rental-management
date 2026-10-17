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
