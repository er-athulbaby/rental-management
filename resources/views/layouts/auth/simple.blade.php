@php
    $brand = \App\Models\CompanySetting::brand();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-100 antialiased dark:bg-zinc-950">
        <div class="flex min-h-svh items-center justify-center p-4 sm:p-6 md:p-10">
            <x-ui.auth-card :name="$brand['name']" :logo="$brand['logo']">
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </x-ui.auth-card>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
