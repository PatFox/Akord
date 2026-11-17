{{-- The Akord wordmark for the sidebar and header: white text in dark mode, black in light mode (PNGs: see welcome.blade.php). --}}
<a {{ $attributes->class('flex h-10 items-center px-2') }} aria-label="{{ config('app.name', 'Akord') }}" data-test="app-logo">
    <img src="{{ asset('images/akord-logo-white.png') }}" alt="" width="574" height="100" class="hidden h-5 w-auto dark:block">
    <img src="{{ asset('images/akord-logo-black.png') }}" alt="" width="574" height="100" class="block h-5 w-auto dark:hidden">
</a>
