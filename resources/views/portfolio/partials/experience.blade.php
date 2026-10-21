<section id="experience" class="mx-auto max-w-6xl px-5 py-24 md:px-8 md:py-32">
    <div class="text-center" data-reveal>
        <p class="eyebrow">Experience</p>
        <h2 class="mt-2 font-display text-[40px] leading-none text-white italic md:text-[44px]">Journey So Far</h2>
        <p class="mt-5 text-[17px] text-zinc-400">From lecture halls to shipping production web platforms.</p>
    </div>

    <div class="relative mt-16" x-data="timeline">
        {{-- Rail: faint track + scroll-driven fill --}}
        <div class="absolute top-0 bottom-0 left-[11px] w-px bg-white/10 md:left-1/2 md:-translate-x-1/2"></div>
        <div
            class="absolute top-0 left-[11px] w-px origin-top bg-gradient-to-b from-white via-indigo-300 to-violet md:left-1/2 md:-translate-x-1/2"
            :style="`height: ${progress * 100}%`"
        ></div>

        <ol class="space-y-14 md:space-y-4">
            @foreach ($p['experience'] as $item)
                @php
                    $isEdu = $item['type'] === 'education';
                    $accent = $isEdu ? '#dc2626' : '#1e4fa8';
                    $right = $loop->odd === false; // even rows sit on the right
                @endphp

                <li id="exp-{{ $item['slug'] }}" data-spy class="relative scroll-mt-24 pl-10 md:grid md:grid-cols-2 md:gap-16 md:pl-0">
                    {{-- Node --}}
                    <span
                        class="absolute top-8 left-0 grid size-[23px] place-items-center rounded-full border-2 bg-white md:left-1/2 md:-translate-x-1/2"
                        style="border-color: {{ $accent }}"
                    >
                        <span class="size-[9px] rounded-full" style="background: {{ $isEdu ? $accent : '#fff' }}"></span>
                    </span>

                    <article
                        class="relative overflow-hidden rounded-2xl bg-card p-6 text-zinc-900 shadow-[0_20px_50px_rgba(0,0,0,0.35)] md:p-7 {{ $right ? 'md:col-start-2' : 'md:col-start-1' }}"
                        data-reveal
                    >
                        <span class="absolute top-5 bottom-5 w-[3px] rounded-full {{ $right ? 'left-0' : 'left-0 md:right-0 md:left-auto' }}" style="background: {{ $accent }}"></span>

                        <h3 class="font-display text-[26px] leading-none tracking-[0.01em] md:text-[28px]">{{ $item['company'] }}</h3>
                        <p class="mt-2 text-[16px] text-zinc-600">{{ $item['role'] }}</p>

                        <p class="mt-3 flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.16em] uppercase" style="color: {{ $accent }}">
                            @if ($isEdu)
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="m2 9 10-5 10 5-10 5z"/><path d="M6 11v5c3 2.5 9 2.5 12 0v-5"/></svg>
                            @else
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V4h8v3M8 7v13M16 7v13"/></svg>
                            @endif
                            {{ $item['period'] }}
                        </p>

                        <p class="mt-4 text-[16px] leading-snug font-medium text-zinc-900">{{ $item['headline'] }}</p>
                        <p class="mt-3 text-[15px] leading-[1.75] text-zinc-500">{{ $item['body'] }}</p>

                        @if ($item['tags'])
                            <div class="mt-5 flex flex-wrap gap-2">
                                @foreach ($item['tags'] as $tag)
                                    <span class="rounded-full bg-white px-3 py-1 font-mono text-[11px] text-zinc-700 shadow-sm">{{ $tag }}</span>
                                @endforeach
                            </div>
                        @endif
                    </article>
                </li>
            @endforeach
        </ol>
    </div>
</section>
