{{-- The Akord wordmark for the sidebar and header: white text in dark mode, black in light mode (PNGs: see welcome.blade.php).
     In a collapsed sidebar only the green tick shows, and it gives way to the expand button on hover (as Flux's brand does). --}}
<a {{ $attributes->class([
    'flex h-10 items-center px-2',
    'in-data-flux-sidebar-collapsed-desktop:w-10 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:px-0',
    'in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:absolute in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-0',
]) }} aria-label="{{ config('app.name', 'Akord') }}" data-test="app-logo">
    <img src="{{ asset('images/akord-logo-white.png') }}" alt="" width="574" height="100" class="hidden h-5 w-auto dark:block in-data-flux-sidebar-collapsed-desktop:hidden!">
    <img src="{{ asset('images/akord-logo-black.png') }}" alt="" width="574" height="100" class="block h-5 w-auto dark:hidden in-data-flux-sidebar-collapsed-desktop:hidden!">
    <x-app-logo-icon class="hidden size-6 in-data-flux-sidebar-collapsed-desktop:block" />
</a>
