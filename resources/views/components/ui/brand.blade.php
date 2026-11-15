@props(['name', 'logo' => null, 'href' => null])
{{-- Design pass §4/§8: the company's logo (Company settings) and name; without a logo, the name's first letter on a gold tile. --}}
@php
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class('flex min-w-0 items-center gap-3') }} data-ui-brand>
    @if ($logo)
        <img src="{{ $logo }}" alt="" class="size-9 shrink-0 rounded-md bg-white object-contain p-0.5">
    @else
        <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-md bg-brand-400 text-base font-bold text-charcoal">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
    @endif
    <span class="truncate text-sm font-semibold">{{ $name }}</span>
</{{ $tag }}>
