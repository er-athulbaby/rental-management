<section class="space-y-6">
    <x-ui.page-header :title="__('Documents')" />
    <ul class="space-y-3">
        @forelse ($documents as $document)
            <li wire:key="doc-{{ $document->id }}" class="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:badge size="sm">{{ $document->category->label() }}</flux:badge>
                    <span class="text-sm text-zinc-500 tabular-nums">{{ $document->created_at->format('d/m/Y') }}</span>
                </div>
                <flux:link :href="route('portal.documents.download', $document)" class="inline-flex min-h-11 max-w-full items-center break-all font-medium">{{ $document->original_name }}</flux:link>
            </li>
        @empty
            <li class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><x-ui.empty :title="__('No documents have been shared with you yet.')" icon="document" /></li>
        @endforelse
    </ul>
</section>
