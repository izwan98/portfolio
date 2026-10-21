<section id="toolkit" data-spy class="py-24 md:py-32">
    <div class="px-5 text-center md:px-8" data-reveal>
        <p class="eyebrow">Technical Skills</p>
        <h2 class="mt-2 font-display text-[40px] leading-none text-white md:text-[44px]">My Toolkit</h2>
        <p class="mt-5 text-[17px] text-zinc-400">The stack I reach for when shipping web products end-to-end.</p>
    </div>

    <div class="mt-14 space-y-6 px-4 md:px-8" data-reveal style="--reveal-delay: 120ms">
        @foreach ($p['skills'] as $row)
            <div class="marquee overflow-hidden py-1">
                {{-- Each half holds the row twice (wider than any viewport); two halves let -50% loop seamlessly --}}
                @php($half = [...$row, ...$row])
                <ul class="marquee-track {{ $loop->odd ? '' : 'reverse' }}" style="--marquee-duration: {{ 60 + $loop->index * 10 }}s">
                    @foreach ([...$half, ...$half] as $skill)
                        <li class="flex w-[112px] shrink-0 flex-col items-center gap-3" @if ($loop->index >= count($row)) aria-hidden="true" @endif>
                            <div class="grid size-[82px] place-items-center rounded-[22px] bg-white shadow-[0_10px_25px_rgba(0,0,0,0.35)] transition-transform duration-300 hover:-translate-y-1">
                                <img src="{{ asset('images/skills/'.$skill['icon'].'.svg') }}" alt="" class="size-11 object-contain" loading="lazy">
                            </div>
                            <span class="text-[12px] font-medium text-zinc-400">{{ $skill['name'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</section>
