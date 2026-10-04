<div class="space-y-4" x-data="{ src: '', image: false, name: '' }">
    <flux:heading size="lg">{{ __('Documents') }}</flux:heading>

    <flux:modal name="doc-preview-{{ $this->getId() }}" class="w-full max-w-5xl" x-on:close="src = ''">
        <div class="space-y-3">
            <flux:heading size="lg" class="truncate pe-8" x-text="name"></flux:heading>
            <template x-if="src && image"><img :src="src" :alt="name" class="mx-auto max-h-[75vh] rounded" /></template>
            <template x-if="src && ! image"><iframe :src="src" :title="name" class="h-[75vh] w-full rounded border border-zinc-200 dark:border-zinc-700"></iframe></template>
        </div>
    </flux:modal>

    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @forelse ($documents as $document)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <div class="flex min-w-0 items-center gap-2">
                    @if ($type = $document->previewType())
                        <button type="button" class="truncate text-start text-sm font-medium underline decoration-zinc-800/20 underline-offset-[6px] hover:decoration-current dark:decoration-white/20"
                            x-on:click="src = @js(route('documents.view', $document)); image = @js(str_starts_with($type, 'image/')); name = @js($document->original_name); $flux.modal('doc-preview-{{ $this->getId() }}').show()">{{ $document->original_name }}</button>
                    @else
                        <flux:link :href="route('documents.download', $document)">{{ $document->original_name }}</flux:link>
                    @endif
                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('documents.download', $document)" :tooltip="__('Download')" :aria-label="__('Download')" />
                </div>
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
                <x-file-button wire:model="upload" />
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
