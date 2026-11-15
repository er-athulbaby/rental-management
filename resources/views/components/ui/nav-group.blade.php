@props(['heading', 'key', 'current' => false])
{{--
    Design pass §4 (plan ruling 6): a collapsible sidebar group. The group holding the current page is always open; the
    others open or stay closed as this browser last left them (localStorage "rms.nav.<key>", read in try/catch, so a
    blocked storage only means "closed").
--}}
<details data-nav-group="{{ $key }}" {{ $current ? 'data-current open' : '' }}
    x-data
    x-init="const k = 'rms.nav.{{ $key }}';
        if (! {{ $current ? 'true' : 'false' }}) { try { if (localStorage.getItem(k) === 'open') $el.open = true } catch (e) {} }
        $el.addEventListener('toggle', () => { try { localStorage.setItem(k, $el.open ? 'open' : 'closed') } catch (e) {} })"
    {{ $attributes->class('group/nav') }}>
    <summary class="my-px flex h-8 cursor-pointer list-none items-center gap-2 rounded-lg px-3 text-sm font-medium text-zinc-400 select-none hover:bg-charcoal-raised hover:text-white [&::-webkit-details-marker]:hidden">
        <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 group-open/nav:hidden rtl:rotate-180" />
        <flux:icon.chevron-down variant="micro" class="hidden size-4 shrink-0 group-open/nav:block" />
        <span class="truncate">{{ $heading }}</span>
    </summary>
    <div class="grid ps-2">{{ $slot }}</div>
</details>
