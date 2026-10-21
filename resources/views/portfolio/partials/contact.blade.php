@php
    $icons = [
        'linkedin' => '<path d="M6.5 9v9M6.5 6v.01M10.5 18v-5.2c0-2.1 1.3-3.3 3-3.3s2.8 1.2 2.8 3.3V18M10.5 9.5V18" />',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m4 7 8 6 8-6"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1"/>',
    ];
@endphp

<section id="contact" data-spy class="mx-auto flex min-h-[90svh] max-w-6xl flex-col items-center justify-center px-5 py-24 text-center md:px-8">
    <div data-reveal>
        <p class="eyebrow">Contact</p>
        <h2 class="mt-2 font-display text-[40px] leading-none text-white md:text-[44px]">{{ $p['contact']['heading'] }}</h2>
        <p class="mt-6 text-[17px] text-zinc-400">{{ $p['contact']['blurb'] }}</p>
    </div>

    <ul class="mt-14 flex flex-wrap justify-center gap-x-10 gap-y-8 [perspective:800px]" data-reveal style="--reveal-delay: 100ms">
        @foreach ($p['contact']['links'] as $link)
            <li>
                <a
                    href="{{ $link['href'] }}"
                    @if (str_starts_with($link['href'], 'http')) target="_blank" rel="noopener" @endif
                    class="iso-key-wrap group flex w-24 flex-col items-center gap-5 outline-none"
                >
                    <span class="iso-key grid place-items-center">
                        <svg class="size-7 text-zinc-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$link['icon']] !!}</svg>
                    </span>
                    <span class="text-[11px] font-medium tracking-[0.24em] text-zinc-400 uppercase transition-colors group-hover:text-white">{{ $link['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    {{-- A small PHP "card" in lieu of a sign-off --}}
    <pre class="mt-16 w-full max-w-md overflow-x-auto rounded-xl border border-white/[0.07] bg-panel/80 p-5 text-left font-mono text-[13px] leading-[1.85] text-zinc-300" data-reveal style="--reveal-delay: 180ms"><code><span class="text-sky-400">$izwan</span> <span class="text-zinc-500">=</span> [
    <span class="text-emerald-400/90">'building'</span>  <span class="text-zinc-500">=&gt;</span> <span class="text-emerald-400/90">'Laravel web products'</span>,
    <span class="text-emerald-400/90">'exploring'</span> <span class="text-zinc-500">=&gt;</span> [<span class="text-emerald-400/90">'AI-assisted dev'</span>, <span class="text-emerald-400/90">'Figma MCP'</span>],
    <span class="text-emerald-400/90">'based_in'</span>  <span class="text-zinc-500">=&gt;</span> <span class="text-emerald-400/90">'Kuala Lumpur'</span>,
    <span class="text-emerald-400/90">'status'</span>    <span class="text-zinc-500">=&gt;</span> <span class="text-emerald-400/90">'Open to opportunities'</span>,
];</code></pre>

    <p class="mt-20 font-mono text-[11px] tracking-[0.1em] text-zinc-600">
        © {{ now()->year }} {{ $p['full_name'] }} · Built with Laravel
    </p>
</section>
