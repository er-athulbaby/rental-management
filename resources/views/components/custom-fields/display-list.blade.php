<dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
    @foreach ($items as $item)
        <div class="min-w-0">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ $item['label'] }}</dt>
            <dd class="break-words whitespace-pre-line">@if ($item['document'])<flux:link :href="route($fileRoute, $item['document'])">{{ $item['text'] }}</flux:link>@else{{ $item['text'] }}@endif</dd>
        </div>
    @endforeach
</dl>
