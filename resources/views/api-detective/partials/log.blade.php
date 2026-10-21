<ol class="log">
    <template x-for="(entry, i) in log" :key="i">
        <li :class="'log-' + entry.status">
            <span class="log-badge" x-text="{ hit: 'found', miss: 'nothing', skip: 'skipped', error: 'error' }[entry.status] ?? entry.status"></span>
            <span class="log-step" x-text="entry.step"></span>
            <span class="log-detail" x-text="entry.detail"></span>
            <a class="log-url" x-show="entry.url" :href="entry.url" target="_blank" rel="noopener noreferrer" x-text="entry.url"></a>
        </li>
    </template>
</ol>
