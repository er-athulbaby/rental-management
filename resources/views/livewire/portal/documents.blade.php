<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Documents') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($documents as $document)
            <li wire:key="doc-{{ $document->id }}" class="flex flex-wrap items-center justify-between gap-2 p-4">
                <flux:link :href="route('portal.documents.download', $document)" class="min-w-0 truncate">{{ $document->original_name }}</flux:link>
                <span class="flex items-center gap-2 text-sm text-zinc-500">
                    <flux:badge size="sm">{{ $document->category->label() }}</flux:badge>
                    {{ $document->created_at->format('d/m/Y') }}
                </span>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No documents have been shared with you yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
