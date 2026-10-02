<div class="space-y-4">
    <flux:heading size="lg">{{ __('Documents') }}</flux:heading>

    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @forelse ($documents as $document)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <flux:link :href="route('documents.download', $document)">{{ $document->original_name }}</flux:link>
                <div class="flex items-center gap-2">
                    <flux:badge size="sm">{{ $document->category->label() }}</flux:badge>
                    @if ($document->expires_on)
                        <flux:badge size="sm" :color="$document->expires_on->isPast() ? 'red' : 'zinc'">{{ __('Expires :date', ['date' => $document->expires_on->format('d/m/Y')]) }}</flux:badge>
                    @endif
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
            <flux:select wire:model.live="category" :label="__('Category')" class="sm:max-w-48">
                @foreach ($categories as $cat)
                    <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                @endforeach
            </flux:select>
            @if (in_array($category, ['id_copy', 'cr_copy'], true))
                <flux:input wire:model="expiresOn" type="date" :label="__('Expires on')" class="sm:max-w-44" />
            @endif
            <flux:button type="submit" variant="primary">{{ __('Upload') }}</flux:button>
        </form>
    @endif
</div>
