<section id="projects" class="mx-auto max-w-6xl px-5 py-24 md:px-8 md:py-32" x-data="{ open: null }">
    <div data-reveal>
        <p class="eyebrow">Projects</p>
        <h2 class="mt-2 font-display text-[52px] leading-none text-white italic md:text-[64px]">Selected Work</h2>
        <p class="mt-5 max-w-2xl text-[17px] leading-[1.75] text-zinc-400">
            Products I've built across Laravel platforms, payment systems, CRMs, HR tooling, and AI-powered experiences.
        </p>
    </div>

    <ol class="mt-14 max-w-3xl border-t border-white/15">
        @foreach ($p['projects'] as $project)
            <li id="project-{{ $project['slug'] }}" data-spy class="scroll-mt-24 border-b border-white/15" data-reveal>
                <button
                    type="button"
                    class="group flex w-full items-end justify-between gap-6 py-10 text-left"
                    @click="open = open === {{ $loop->index }} ? null : {{ $loop->index }}"
                    :aria-expanded="open === {{ $loop->index }}"
                >
                    <span>
                        <span class="eyebrow block !text-[10px] !tracking-[0.3em] text-zinc-500">{{ $project['category'] }}</span>
                        <span
                            class="mt-2 block font-display text-[38px] leading-none transition-colors duration-300 md:text-[44px]"
                            :class="open === {{ $loop->index }} ? 'text-white' : 'text-zinc-500 group-hover:text-zinc-200'"
                        >{{ $project['title'] }}</span>
                    </span>

                    <span class="flex items-center gap-4 pb-1">
                        <span class="hidden font-mono text-xs text-zinc-600 sm:inline">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="grid size-9 place-items-center rounded-full border border-white/15 text-zinc-400 transition duration-300 group-hover:border-white/40 group-hover:text-white" :class="open === {{ $loop->index }} && 'rotate-45 !border-violet !text-white bg-violet/20'">
                            <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M8 3v10M3 8h10"/></svg>
                        </span>
                    </span>
                </button>

                <div class="collapse-grid" :class="open === {{ $loop->index }} && 'open'">
                    <div>
                        <div class="pb-10">
                            <p class="text-[18px] leading-relaxed text-zinc-200">{{ $project['summary'] }}</p>
                            <ul class="mt-5 space-y-3">
                                @foreach ($project['points'] as $point)
                                    <li class="flex gap-3 text-[16px] leading-[1.7] text-zinc-400">
                                        <span class="mt-[11px] size-1.5 shrink-0 rounded-full bg-violet"></span>
                                        {{ $point }}
                                    </li>
                                @endforeach
                            </ul>
                            <div class="mt-6 flex flex-wrap gap-2">
                                @foreach ($project['tags'] as $tag)
                                    <span class="rounded-full border border-white/10 bg-white/[0.04] px-3 py-1 font-mono text-[11px] text-zinc-300">{{ $tag }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </li>
        @endforeach
    </ol>
</section>
