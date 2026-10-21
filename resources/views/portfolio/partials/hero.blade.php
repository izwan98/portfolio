<section id="home" data-spy class="relative flex min-h-[100svh] flex-col items-center justify-center px-5 text-center md:px-8">
    <p class="font-display text-[15px] tracking-[0.3em] text-zinc-400" data-reveal>{{ $p['role'] }}</p>

    <h1 class="mt-5 font-display text-[64px] leading-[0.95] tracking-[0.01em] text-white sm:text-[88px] md:text-[104px]" data-reveal style="--reveal-delay: 80ms">
        {{ $p['name'] }}
    </h1>

    <p class="mt-10 max-w-3xl font-display text-[26px] leading-[1.45] text-zinc-400 italic sm:text-[30px] md:text-[34px]" data-reveal style="--reveal-delay: 160ms">
        {{ $p['tagline'] }}
    </p>

    <div
        class="mt-12 inline-flex h-12 min-w-[240px] items-center justify-center gap-3 rounded-full border border-white/10 bg-white/[0.04] px-6 text-[15px] font-medium text-zinc-100 shadow-[0_8px_30px_rgba(0,0,0,0.3)]"
        x-data="typewriter(@js($p['typewriter']))"
        data-reveal
        style="--reveal-delay: 240ms"
    >
        <span><span x-text="text">{{ $p['typewriter'][0] }}</span><span class="caret" aria-hidden="true"></span></span>
        <svg class="size-3.5 text-violet" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0c.4 3.9 2.1 5.6 6 6-3.9.4-5.6 2.1-6 6-.4-3.9-2.1-5.6-6-6 3.9-.4 5.6-2.1 6-6z" transform="translate(1 2) scale(.85)"/></svg>
    </div>

    <a href="#about" @click.prevent="go('about')" class="absolute bottom-8 flex flex-col items-center gap-2 text-zinc-400 transition-colors hover:text-white">
        <span class="text-[11px] tracking-[0.3em] uppercase">Explore</span>
        <svg class="animate-nudge size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
    </a>
</section>
