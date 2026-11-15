@props(['title' => null, 'hint' => null])
{{-- Design pass §3/§6: a titled card for a group of form fields or a record panel. Pass id="…" for the in-page nav. --}}
<section {{ $attributes->class('scroll-mt-20 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900') }} data-ui-section>
    @if (filled($title))
        <header class="flex flex-wrap items-start justify-between gap-2 border-b border-zinc-200 px-4 py-3 sm:px-6 dark:border-zinc-700">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                @if (filled($hint))
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $hint }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div class="space-y-4 p-4 sm:p-6">{{ $slot }}</div>
</section>
