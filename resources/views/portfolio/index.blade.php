<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#18181b">
    <meta name="description" content="Portfolio of {{ $p['full_name'] }}, {{ $p['role'] }} based in {{ $p['location'] }}.">
    <meta property="og:title" content="{{ $p['name'] }} · {{ $p['role'] }}">
    <meta property="og:description" content="{{ $p['tagline'] }}">

    <title>{{ $p['name'] }} · {{ $p['role'] }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/me-favicon.svg') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Afacad:ital,wght@0,400..700;1,400..700&family=Bebas+Neue&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/portfolio.css', 'resources/js/portfolio.js'])
</head>
<body class="font-sans" x-data="portfolio" :class="drawer && 'overflow-hidden lg:overflow-auto'">

    {{-- Desktop explorer --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-[352px] flex-col bg-ink-deep px-4 pb-4 lg:flex">
        @include('portfolio.partials.sidebar')
    </aside>

    {{-- Mobile toggle + drawer --}}
    <button
        type="button"
        class="fixed top-3 right-4 z-50 grid size-11 place-items-center rounded-full border border-white/10 bg-zinc-200 text-zinc-800 shadow-lg lg:hidden"
        @click="drawer = !drawer"
        :aria-expanded="drawer"
        aria-label="Toggle navigation"
    >
        <svg x-show="!drawer" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 7h14M5 12h14M5 17h14"/></svg>
        <svg x-show="drawer" x-cloak class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>

    <div
        x-show="drawer"
        x-cloak
        x-transition.opacity
        class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden"
        @click="drawer = false"
    ></div>

    <aside
        x-show="drawer"
        x-cloak
        x-transition:enter="transition duration-300 ease-out"
        x-transition:enter-start="-translate-x-full"
        x-transition:leave="transition duration-200 ease-in"
        x-transition:leave-end="-translate-x-full"
        class="fixed inset-y-0 left-0 z-50 flex w-[86vw] max-w-[352px] flex-col bg-ink-deep px-4 pb-4 lg:hidden"
        @keydown.escape.window="drawer = false"
    >
        @include('portfolio.partials.sidebar')
    </aside>

    <main class="bg-grid min-h-screen lg:ml-[352px]">
        @include('portfolio.partials.hero')
        @include('portfolio.partials.about')
        @include('portfolio.partials.toolkit')
        @include('portfolio.partials.projects')
        @include('portfolio.partials.experience')
        @include('portfolio.partials.contact')
    </main>

</body>
</html>
