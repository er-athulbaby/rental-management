@props(['items', 'fileRoute' => 'documents.view'])
{{-- Spec 2026-10-21 custom fields §4–§5: read-only "More details", escaped; long text keeps its line breaks, never HTML (plan ruling 12). --}}
@if ($items !== [])
    <flux:card class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('More details') }}</flux:heading>
        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            @foreach ($items as $item)
                <div class="min-w-0">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $item['label'] }}</dt>
                    <dd class="break-words whitespace-pre-line">@if ($item['document'])<flux:link :href="route($fileRoute, $item['document'])">{{ $item['text'] }}</flux:link>@else{{ $item['text'] }}@endif</dd>
                </div>
            @endforeach
        </dl>
    </flux:card>
@endif
