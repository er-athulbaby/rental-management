{{-- Design pass §3: the card around a list's flux:table; the .ui-table rules in app.css shade and stick the header. --}}
<div {{ $attributes->class('ui-table min-w-0 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900') }} data-ui-table>
    {{ $slot }}
</div>
