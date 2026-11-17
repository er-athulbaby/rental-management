@props(['rows'])
{{-- Totals footer for a table card (rework batch 4): rows are [label, value, strong?]. Sits inside a flush section, under the table. --}}
<dl {{ $attributes->class('space-y-1 border-t border-zinc-200 bg-zinc-50 px-4 py-3 text-sm tabular-nums sm:px-6 dark:border-zinc-700 dark:bg-zinc-800/50') }} data-ui-totals>
    @foreach ($rows as $row)
        <div @class(['ms-auto flex max-w-xs justify-between gap-8', 'pt-1 text-base font-semibold text-zinc-900 dark:text-white' => ($row[2] ?? false), 'text-zinc-600 dark:text-zinc-300' => ! ($row[2] ?? false)])>
            <dt>{{ $row[0] }}</dt>
            @if ($row[1] !== null)<dd class="text-end">{{ $row[1] }}</dd>@endif
        </div>
    @endforeach
</dl>
