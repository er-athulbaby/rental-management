@php
    $account = auth('tenant')->user();
    $brand = \App\Models\CompanySetting::brand();
    $rtl = app()->getLocale() === 'ar';
    // Owner portal plan ruling 4: the side is the route's; a shared account gets the Tenant / Owner switch.
    $ownerSide = request()->routeIs('portal.owner.*');
    $both = $account instanceof \App\Models\PortalAccount && $account->hasTenant() && $account->hasOwner();
    // [route, label]; each page's task adds its line (plan File Structure).
    $nav = $ownerSide ? [
        ['portal.owner.home', __('Home')],
        ['portal.owner.buildings', __('Buildings')],
        ['portal.owner.maintenance', __('Maintenance')],
        ['portal.owner.contracts', __('Contracts')],
        ['portal.owner.documents', __('Documents')],
        ['portal.owner.statements', __('Statements')],
        ['portal.owner.payments', __('Payments')],
        ['portal.owner.expenses', __('Expenses')],
    ] : [
        ['portal.home', __('Home')],
        ['portal.agreements', __('Agreements')],
        ['portal.invoices', __('Invoices')],
        ['portal.payments', __('Payments')],
        ['portal.cheques', __('Cheques')],
        ['portal.statement', __('Statement')],
        ['portal.documents', __('Documents')],
        ['portal.maintenance', __('Maintenance')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        {{-- Spec 2026-10-18 tenant portal §4: mobile first; the 16px gutter is px-4; only the menu scrolls sideways. --}}
        <header class="dark border-b border-charcoal-line bg-charcoal text-white">
            <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                <x-ui.brand :name="$brand['name']" :logo="$brand['logo']" :href="route($account ? ($ownerSide ? 'portal.owner.home' : 'portal.home') : 'portal.login')" />
                <div class="flex items-center gap-1">
                    @if ($both)
                        <nav aria-label="{{ __('Portal') }}" class="flex gap-1">
                            @foreach ([['portal.home', __('Tenant'), ! $ownerSide], ['portal.owner.home', __('Owner'), $ownerSide]] as [$route, $label, $current])
                                <a href="{{ route($route) }}" @if ($current) aria-current="page" @endif
                                    @class([
                                        'inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium',
                                        'bg-brand-400 text-charcoal' => $current,
                                        'text-zinc-300 hover:bg-charcoal-raised hover:text-white' => ! $current,
                                    ])>{{ $label }}</a>
                            @endforeach
                        </nav>
                    @endif
                    {{-- Each language is named in its own language (plan ruling 16). --}}
                    <form method="POST" action="{{ route('portal.locale') }}">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $rtl ? 'en' : 'ar' }}">
                        <flux:button type="submit" size="sm" variant="ghost">{{ $rtl ? 'English' : 'العربية' }}</flux:button>
                    </form>
                    @if ($account)
                        <form method="POST" action="{{ route('portal.logout') }}">
                            @csrf
                            <flux:button type="submit" size="sm" variant="ghost">{{ __('Sign out') }}</flux:button>
                        </form>
                    @endif
                </div>
            </div>
            @if ($account)
                <nav aria-label="{{ $ownerSide ? __('Owner portal') : __('Tenant portal') }}" class="mx-auto max-w-3xl overflow-x-auto px-4">
                    <ul class="flex gap-1 pb-2">
                        @foreach ($nav as [$route, $label])
                            <li class="shrink-0">
                                <a href="{{ route($route) }}" @if (request()->routeIs($route.'*')) aria-current="page" @endif
                                    @class([
                                        'inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium whitespace-nowrap',
                                        'bg-brand-400 text-charcoal' => request()->routeIs($route.'*'),
                                        'text-zinc-300 hover:bg-charcoal-raised hover:text-white' => ! request()->routeIs($route.'*'),
                                    ])>{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6">
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>
