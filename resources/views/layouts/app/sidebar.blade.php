<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @can('approvals.decide')
                        <flux:sidebar.item icon="check-badge" :href="route('approvals.index')" :current="request()->routeIs('approvals.*')"
                            :badge="\App\Models\Approval::pending()->count() ?: null" wire:navigate>{{ __('Approvals') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Leasing')" class="grid">
                    @can('customers.view')
                        <flux:sidebar.item icon="identification" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>{{ __('Customers') }}</flux:sidebar.item>
                    @endcan
                    @can('agreements.view')
                        <flux:sidebar.item icon="document-text" :href="route('agreements.index')" :current="request()->routeIs('agreements.*')" wire:navigate>{{ __('Agreements') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @can('finance.view')
                    <flux:sidebar.group :heading="__('Finance')" class="grid">
                        <flux:sidebar.item icon="banknotes" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>{{ __('Invoices') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="credit-card" :href="route('payments.index')" :current="request()->routeIs('payments.*')" wire:navigate>{{ __('Payments') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="banknotes" :href="route('cheques.index')" :current="request()->routeIs('cheques.*')" wire:navigate>{{ __('Cheques') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="arrow-up-right" :href="route('disbursements.index')" :current="request()->routeIs('disbursements.*')" wire:navigate>{{ __('Payments out') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="shield-check" :href="route('deposit-settlements.index')" :current="request()->routeIs('deposit-settlements.*')" wire:navigate>{{ __('Deposit settlements') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                <flux:sidebar.group :heading="__('Property')" class="grid">
                    @can('buildings.view')
                        <flux:sidebar.item icon="building-office-2" :href="route('buildings.index')" :current="request()->routeIs('buildings.*')" wire:navigate>{{ __('Buildings') }}</flux:sidebar.item>
                    @endcan
                    @can('buildings.view')
                        <flux:sidebar.item icon="home-modern" :href="route('units.index')" :current="request()->routeIs('units.*')" wire:navigate>{{ __('Units') }}</flux:sidebar.item>
                    @endcan
                    @can('owners.view')
                        <flux:sidebar.item icon="user-group" :href="route('owners.index')" :current="request()->routeIs('owners.*')" wire:navigate>{{ __('Owners') }}</flux:sidebar.item>
                    @endcan
                    @can('owners.view')
                        <flux:sidebar.item icon="document-text" :href="route('owner-contracts.index')" :current="request()->routeIs('owner-contracts.*')" wire:navigate>{{ __('Owner contracts') }}</flux:sidebar.item>
                    @endcan
                    @can('viewAny', \App\Models\Expense::class)
                        <flux:sidebar.item icon="receipt-percent" :href="route('expenses.index')" :current="request()->routeIs('expenses.*')" wire:navigate>{{ __('Expenses') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @canany(['users.manage', 'roles.manage', 'settings.manage', 'templates.manage', 'audit.view', 'import.run'])
                    <flux:sidebar.group :heading="__('Administration')" class="grid">
                        @can('users.manage')
                            <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>{{ __('Users') }}</flux:sidebar.item>
                        @endcan
                        @can('roles.manage')
                            <flux:sidebar.item icon="key" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>{{ __('Roles') }}</flux:sidebar.item>
                        @endcan
                        @can('settings.manage')
                            <flux:sidebar.item icon="building-office" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>{{ __('Company settings') }}</flux:sidebar.item>
                        @endcan
                        @can('templates.manage')
                            <flux:sidebar.item icon="document-duplicate" :href="route('admin.templates.index')" :current="request()->routeIs('admin.templates.*')" wire:navigate>{{ __('Contract templates') }}</flux:sidebar.item>
                        @endcan
                        @can('audit.view')
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('admin.audit')" :current="request()->routeIs('admin.audit')" wire:navigate>{{ __('Audit log') }}</flux:sidebar.item>
                        @endcan
                        @can('import.run')
                            <flux:sidebar.item icon="arrow-up-tray" :href="route('import.index')" :current="request()->routeIs('import.*')" wire:navigate>{{ __('Data import') }}</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:text size="sm" class="px-2">{{ config('app.version') }}</flux:text>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
