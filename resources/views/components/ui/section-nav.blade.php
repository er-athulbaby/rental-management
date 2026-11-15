@props(['items'])
{{-- Design pass §7: in-page links for a long record page. $items: list of [id, label]; each id is a section on the page. --}}
<nav aria-label="{{ __('On this page') }}" {{ $attributes->class('sticky top-0 z-20 -mx-2 overflow-x-auto bg-white/95 px-2 py-2 backdrop-blur dark:bg-zinc-950/95') }} data-ui-section-nav>
    <ul class="flex gap-1">
        @foreach ($items as [$id, $label])
            <li class="shrink-0">
                <a href="#{{ $id }}" class="block rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white">{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
