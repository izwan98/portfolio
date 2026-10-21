@php
    $orbSizes = ['lg' => 'size-[72px]', 'md' => 'size-[46px]', 'sm' => 'size-[36px]'];
    $nodes = $p['about']['nodes'];
@endphp

<section id="about" data-spy class="mx-auto max-w-6xl px-5 py-24 md:px-8 md:py-32">
    <div data-reveal>
        <p class="eyebrow">About</p>
        <h2 class="mt-2 font-display text-[40px] leading-none text-white md:text-[44px]">{{ $p['about']['heading'] }}</h2>
    </div>

    <div class="mt-12 grid items-center gap-12 lg:grid-cols-[1.1fr_1fr] lg:gap-8">
        <div class="max-w-[480px] space-y-6 text-[17px] leading-[1.8] text-zinc-400" data-reveal>
            @foreach ($p['about']['paragraphs'] as $paragraph)
                <p>{!! $paragraph !!}</p>
            @endforeach
        </div>

        {{-- Drifting orb graph; positions are percentages of the square --}}
        <div
            class="relative mx-auto aspect-square w-full max-w-[420px] select-none"
            x-data="orbGraph(@js($nodes))"
            data-reveal
            style="--reveal-delay: 120ms"
            aria-hidden="true"
        >
            <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none">
                @foreach (array_slice($nodes, 1) as $n)
                    <line data-line x1="{{ $nodes[0]['x'] }}" y1="{{ $nodes[0]['y'] }}" x2="{{ $n['x'] }}" y2="{{ $n['y'] }}" stroke="rgb(199 203 232 / 0.35)" stroke-width="1.2" vector-effect="non-scaling-stroke"/>
                @endforeach
            </svg>

            @foreach ($nodes as $n)
                <div
                    data-orb
                    class="orb-node absolute flex -translate-x-1/2 -translate-y-1/2 flex-col items-center"
                    style="left: {{ $n['x'] }}%; top: {{ $n['y'] }}%"
                >
                    <div class="orb {{ $orbSizes[$n['size']] }}"></div>
                    <span class="orb-label absolute top-full mt-3 whitespace-nowrap {{ $n['size'] === 'lg' ? 'text-[15px] !text-white/90' : '' }}">{{ $n['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <figure class="mt-24 text-center" data-reveal>
        <svg class="mx-auto size-7 text-zinc-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 21v-6.6c0-3.4 1.6-6.6 4.8-9.4l1.9 1.6C8 8.3 7.1 10 7 12h4v9zm10 0v-6.6c0-3.4 1.6-6.6 4.8-9.4l1.9 1.6C18 8.3 17.1 10 17 12h4v9z"/></svg>
        <blockquote class="mt-5 font-display text-[28px] leading-tight text-zinc-300 italic md:text-[32px]">
            “<span class="text-violet">{{ $p['about']['quote']['highlight'] }}</span>{{ $p['about']['quote']['rest'] }}”
        </blockquote>
    </figure>
</section>
