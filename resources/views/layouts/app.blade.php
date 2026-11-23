<!DOCTYPE html>
{{-- scroll-pt keeps anchor jumps and scrollIntoView (step links) clear of the floating header. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark scroll-pt-36">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        {{-- A floating bar: the strip around it lets clicks through to the page scrolling underneath. --}}
        <header class="pointer-events-none sticky top-0 z-30 px-4 pt-3 sm:px-6 lg:px-8" data-test="app-header">
            <div class="pointer-events-auto mx-auto max-w-6xl rounded-xl border border-zinc-200 bg-zinc-50/90 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/90">
                <div class="flex h-14 items-center gap-2 px-2">
                    <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

                    <flux:spacer />

                    {{-- Flips Flux's appearance between light and dark (saved in localStorage as flux.appearance, overriding "System"). --}}
                    <flux:tooltip :content="__('Switch light or dark mode')" position="bottom">
                        <button type="button" x-data x-on:click="$flux.dark = ! $flux.dark" aria-label="{{ __('Switch light or dark mode') }}" data-test="appearance-toggle"
                                class="flex size-10 cursor-pointer items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-800/5 hover:text-zinc-800 dark:text-white/80 dark:hover:bg-white/10 dark:hover:text-white">
                            <flux:icon.moon class="size-5 dark:hidden" />
                            <flux:icon.sun class="hidden size-5 dark:block" />
                        </button>
                    </flux:tooltip>

                    @php($unreadNotifications = auth()->user()->unreadNotifications()->count())
                    <flux:tooltip :content="__('Notifications')" position="bottom">
                        <a href="{{ route('notifications.index') }}" wire:navigate data-test="notifications-link"
                           @if (request()->routeIs('notifications.index')) aria-current="page" @endif
                           @class([
                               'relative flex size-10 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-800/5 hover:text-zinc-800 dark:text-white/80 dark:hover:bg-white/10 dark:hover:text-white',
                               'bg-zinc-800/5 text-zinc-800 dark:bg-white/10 dark:text-white' => request()->routeIs('notifications.index'),
                           ])>
                            <flux:icon.bell class="size-5" />
                            <span class="sr-only">{{ __('Notifications') }}</span>
                            @if ($unreadNotifications > 0)
                                <span class="absolute end-1 top-1 h-4 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] font-semibold leading-4 text-white">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>
                            @endif
                        </a>
                    </flux:tooltip>

                    <flux:dropdown position="bottom" align="end">
                        <flux:profile class="max-sm:[&>span]:hidden" :name="auth()->user()->name" :initials="auth()->user()->initials()" data-test="user-menu-button" />

                        <flux:menu>
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />
                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                            <flux:menu.separator />
                            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                {{ __('Settings') }}
                            </flux:menu.item>
                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                                    {{ __('Log out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                </div>

                {{-- Pages set this with <x-slot:breadcrumbs>. Long crumbs (contract titles) shrink and truncate rather than wrap. --}}
                @isset($breadcrumbs)
                    <nav aria-label="{{ __('Breadcrumb') }}" data-test="breadcrumbs"
                         class="flex min-h-10 items-center border-t border-zinc-200 px-4 dark:border-zinc-700 [&_[data-flux-breadcrumbs-item]]:min-w-0 [&_[data-flux-breadcrumbs-item]>:first-child]:truncate [&_[data-flux-breadcrumbs]]:min-w-0">
                        {{ $breadcrumbs }}
                    </nav>
                @endisset
            </div>
        </header>

        <main class="px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto flex max-w-6xl flex-col items-center">
                {{ $slot }}
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
