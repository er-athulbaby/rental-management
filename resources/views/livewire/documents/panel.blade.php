<div class="space-y-4">
    <flux:heading size="lg">{{ __('Documents') }}</flux:heading>

    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @forelse ($documents as $document)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <flux:link :href="route('documents.download', $document)">{{ $document->original_name }}</flux:link>
                <div class="flex items-center gap-2">
                    <flux:badge size="sm">{{ str($document->category->value)->headline() }}</flux:badge>
                    @can('delete', $document)
                        <flux:button size="sm" variant="ghost" wire:click="delete({{ $document->id }})" wire:confirm="{{ __('Delete this document?') }}">{{ __('Delete') }}</flux:button>
                    @endcan
                </div>
            </li>
        @empty
            <li class="py-2"><flux:text>{{ __('No documents yet.') }}</flux:text></li>
        @endforelse
    </ul>

    @if ($canUpload)
        <form wire:submit="save" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:field class="flex-1">
                <flux:label>{{ __('File (PDF, image, Word or Excel, max 10 MB)') }}</flux:label>
                <input type="file" wire:model="upload" class="block w-full text-sm" />
                <flux:error name="upload" />
            </flux:field>
            <flux:select wire:model="category" :label="__('Category')" class="sm:max-w-48">
                @foreach ($categories as $category)
                    <option value="{{ $category->value }}">{{ str($category->value)->headline() }}</option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary">{{ __('Upload') }}</flux:button>
        </form>
    @endif
</div>
