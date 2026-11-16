@props(['name', 'logo' => null])
{{-- Design pass §8: a centred card; on desktop a charcoal brand panel beside the form, on phones a brand strip above it. --}}
<div {{ $attributes->class('grid w-full max-w-3xl overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm md:grid-cols-[2fr_3fr] dark:border-zinc-800 dark:bg-zinc-900') }} data-ui-auth-card>
    <div class="dark flex flex-col justify-between gap-6 bg-charcoal p-6 text-white md:p-8">
        <x-ui.brand :name="$name" :logo="$logo" />
        <div aria-hidden="true" class="hidden h-1 w-16 rounded-full bg-brand-400 md:block"></div>
    </div>
    <div class="p-6 sm:p-8">{{ $slot }}</div>
</div>
