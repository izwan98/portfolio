@php
    // Extension → [file tint, glyph tint, glyph]
    $types = [
        'blade' => ['#f05340', '#f05340', '◆'],
        'php' => ['#777bb3', '#8b8fd1', '◇'],
        'md' => ['#6b7280', '#9ca3af', '#'],
        'json' => ['#ca8a04', '#eab308', '{}'],
    ];

    $typeOf = fn (string $file) => match (true) {
        str_ends_with($file, '.blade.php') => 'blade',
        str_ends_with($file, '.php') => 'php',
        str_ends_with($file, '.md') => 'md',
        default => 'json',
    };

    $tree = [
        ['file' => 'home.blade.php', 'target' => 'home'],
        ['file' => 'about.md', 'target' => 'about'],
        ['folder' => 'skills', 'children' => [
            ['file' => 'toolkit.php', 'target' => 'toolkit'],
        ]],
        ['folder' => 'projects', 'children' => collect($p['projects'])
            ->map(fn ($x) => ['file' => $x['file'], 'target' => 'project-'.$x['slug']])->all()],
        ['folder' => 'experience', 'children' => collect($p['experience'])
            ->map(fn ($x) => ['file' => $x['file'], 'target' => 'exp-'.$x['slug']])->all()],
        ['file' => 'contact.json', 'target' => 'contact'],
    ];
@endphp

{{-- Identity --}}
<a href="#home" @click.prevent="go('home')" class="flex items-center gap-3 px-1 py-4">
    <span class="grid size-11 place-items-center rounded-full border-2 border-white/90 font-display text-lg tracking-wider text-white">
        {{ $p['initials'] }}
    </span>
    <span class="leading-tight">
        <span class="block text-sm font-semibold text-white">{{ $p['first_name'] }}</span>
        <span class="block font-mono text-[11px] tracking-[0.2em] text-zinc-400 uppercase">Portfolio</span>
    </span>
</a>

{{-- Explorer panel --}}
<nav class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-white/[0.07] bg-panel" aria-label="Sections">
    <div class="flex items-center gap-2 border-b border-white/[0.06] px-3 py-3">
        <span class="size-2.5 rounded-full bg-[#ff5f57]"></span>
        <span class="size-2.5 rounded-full bg-[#febc2e]"></span>
        <span class="size-2.5 rounded-full bg-[#28c840]"></span>
        <span class="ml-3 font-mono text-[12px] text-zinc-400">explorer</span>
    </div>

    <ul class="flex-1 space-y-0.5 overflow-y-auto px-2 py-3 font-mono text-[13px]">
        @foreach ($tree as $node)
            @if (isset($node['folder']))
                <li x-data="{ open: true }">
                    <button type="button" class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-zinc-200 hover:bg-white/[0.04]" @click="open = !open" :aria-expanded="open">
                        <svg class="size-3 text-zinc-400 transition-transform" :class="!open && '-rotate-90'" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m3 4.5 3 3 3-3"/></svg>
                        <svg class="size-4 text-[#7c83db]" viewBox="0 0 16 16" fill="currentColor"><path d="M1.5 3.5A1.5 1.5 0 0 1 3 2h3.4l1.6 1.6H13A1.5 1.5 0 0 1 14.5 5v7A1.5 1.5 0 0 1 13 13.5H3A1.5 1.5 0 0 1 1.5 12z"/></svg>
                        {{ $node['folder'] }}
                    </button>
                    <ul x-show="open" x-collapse.duration.200ms class="ml-[15px] space-y-0.5 border-l border-white/15 pl-2">
                        @foreach ($node['children'] as $child)
                            @include('portfolio.partials.sidebar-file', ['item' => $child, 'type' => $types[$typeOf($child['file'])]])
                        @endforeach
                    </ul>
                </li>
            @else
                @include('portfolio.partials.sidebar-file', ['item' => $node, 'type' => $types[$typeOf($node['file'])]])
            @endif
        @endforeach
    </ul>

    <div class="border-t border-white/[0.06] px-3 py-2.5 font-mono text-[11px] text-zinc-500">
        <span class="text-emerald-400/80">●</span> {{ $p['location'] }}
    </div>
</nav>
