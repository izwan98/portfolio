<article class="sheet">
    <p class="stamp">Case summary</p>
    <h1 class="sheet-title" x-text="api.title"></h1>
    <p class="overview-text" x-text="api.overview"></p>

    <div class="stat-row">
        <template x-for="m in methods" :key="m">
            <button type="button" class="stat" @click="methodFilter = [m]">
                <span class="method" :class="'m-' + m.toLowerCase()" x-text="m"></span>
                <b x-text="methodCounts[m]"></b>
            </button>
        </template>
    </div>

    <div class="facts">
        <section>
            <h3>Base URL<span x-show="api.servers.length > 1">s</span></h3>
            <template x-if="!api.servers.length"><p class="muted">None documented. Set one in “Try it”.</p></template>
            <ul class="plain">
                <template x-for="s in api.servers" :key="s.url">
                    <li><code x-text="s.url"></code> <span class="muted small" x-text="s.description"></span></li>
                </template>
            </ul>
        </section>

        <section>
            <h3>Authentication</h3>
            <p x-text="api.authNotes"></p>
            <ul class="plain" x-show="api.auth.length">
                <template x-for="a in api.auth" :key="a.name">
                    <li><code x-text="a.name"></code> <span class="muted small" x-text="a.type + (a.scheme ? ' · ' + a.scheme : '') + (a.in ? ' · ' + a.in + ' ' + a.paramName : '')"></span></li>
                </template>
            </ul>
        </section>

        <section>
            <h3>Found via</h3>
            <p x-text="{ openapi: 'An OpenAPI document.', swagger: 'A Swagger 2.0 document.', scraped: 'Scraping a documentation page — treat parameters and bodies as leads, not facts.', live: 'Calling the API and following the links in its response.' }[api.source.kind]"></p>
            <a x-show="api.source.url" :href="api.source.url" target="_blank" rel="noopener noreferrer" class="small link" x-text="api.source.url"></a>
        </section>
    </div>

    <template x-if="api.description && api.source.kind !== 'scraped' && api.source.kind !== 'live'">
        <details class="spec-desc">
            <summary>Description from the spec</summary>
            <p x-text="api.description"></p>
        </details>
    </template>

    <h2 class="sheet-h2">Areas</h2>
    <div class="area-grid">
        <template x-for="g in groups" :key="g.name">
            <button type="button" class="area" @click="select(g.endpoints[0].id)">
                <b x-text="g.name"></b>
                <span class="muted small" x-text="tagDescription(g.name) || (g.endpoints.length + ' endpoint' + (g.endpoints.length === 1 ? '' : 's'))"></span>
                <span class="area-methods">
                    <template x-for="e in g.endpoints.slice(0, 8)" :key="e.id">
                        <i :class="'m-dot m-' + e.method.toLowerCase()" :title="e.id"></i>
                    </template>
                </span>
            </button>
        </template>
    </div>
</article>
