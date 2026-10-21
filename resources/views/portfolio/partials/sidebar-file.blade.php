<li>
    <a
        href="#{{ $item['target'] }}"
        @click.prevent="go('{{ $item['target'] }}')"
        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors"
        :class="active === '{{ $item['target'] }}' ? 'bg-white/[0.07] text-white' : 'text-zinc-400 hover:bg-white/[0.04] hover:text-zinc-200'"
    >
        <span class="w-3 text-center text-[9px]" style="color: {{ $type[1] }}">{{ $type[2] }}</span>
        <svg class="size-4 shrink-0" viewBox="0 0 16 16" style="color: {{ $type[0] }}" fill="currentColor"><path d="M3.5 1.5h6l3 3v10h-9z" opacity=".9"/><path d="M9.5 1.5v3h3" fill="#000" opacity=".25"/></svg>
        <span class="truncate">{{ $item['file'] }}</span>
    </a>
</li>
