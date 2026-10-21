<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0b0f">
    <meta name="description" content="Projects by Izwan Salim: portfolio, API Detective, MLBB Draft and Tung Tung Tung Sahur.">
    <title>Izwan Salim · Projects</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[#0b0b0f] font-sans text-zinc-100 antialiased">
    <main class="mx-auto flex min-h-screen max-w-4xl flex-col px-5 py-16 sm:py-24">
        <header>
            <p class="text-xs font-semibold tracking-[0.3em] text-zinc-500 uppercase">Izwan Salim</p>
            <h1 class="mt-3 text-4xl font-semibold tracking-tight sm:text-5xl">Things I've built</h1>
            <p class="mt-4 max-w-xl text-lg text-zinc-400">Pick one to open it.</p>
        </header>

        <ul class="mt-12 grid gap-4 sm:grid-cols-2">
            @foreach ($projects as $project)
                <li>
                    <a href="{{ route($project['route']) }}"
                       class="group flex h-full flex-col rounded-2xl border border-white/10 bg-white/[0.03] p-6 transition hover:-translate-y-0.5 hover:border-white/25 hover:bg-white/[0.06] focus-visible:outline-2 focus-visible:outline-offset-2"
                       style="--accent: {{ $project['accent'] }}; outline-color: var(--accent)">
                        <span class="flex items-center gap-2 text-xs font-semibold tracking-wider text-zinc-400 uppercase">
                            <span class="size-2 rounded-full" style="background: var(--accent)"></span>
                            {{ $project['tag'] }}
                        </span>
                        <span class="mt-3 text-xl font-semibold">{{ $project['title'] }}</span>
                        <span class="mt-2 flex-1 text-[15px] leading-relaxed text-zinc-400">{{ $project['description'] }}</span>
                        <span class="mt-5 inline-flex items-center gap-1.5 font-mono text-sm text-zinc-500 transition group-hover:text-[var(--accent)]">
                            {{ parse_url(route($project['route']), PHP_URL_PATH) }}
                            <svg class="size-4 transition group-hover:translate-x-0.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5"/></svg>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        <footer class="mt-auto pt-16 text-sm text-zinc-600">© {{ now()->year }} Izwan Salim</footer>
    </main>
</body>
</html>
