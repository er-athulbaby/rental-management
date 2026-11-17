@props(['items', 'fileRoute' => 'documents.view', 'inline' => false])
{{-- Spec 2026-10-21 custom fields §4–§5: read-only "More details", escaped; long text keeps its line breaks, never HTML (plan ruling 12). `inline` drops the card when the block already sits inside one. --}}
@if ($items !== [])
    @if ($inline)
        <div class="space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('More details') }}</p>
            @include('components.custom-fields.display-list')
        </div>
    @else
        <x-ui.section :title="__('More details')">
            @include('components.custom-fields.display-list')
        </x-ui.section>
    @endif
@endif
