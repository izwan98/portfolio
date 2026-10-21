import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { download, slug, toJson, toMarkdown } from './export.js';
import { escapeHtml, example, highlightJson, renderSchema, requestExample, typeLabel, validate } from './schema.js';

Alpine.plugin(collapse);

const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
const BODY_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

const SAMPLES = [
    { label: 'Petstore', hint: 'OpenAPI 3 spec URL', mode: 'url', input: 'https://petstore3.swagger.io/api/v3/openapi.json' },
    { label: 'Swagger UI page', hint: 'finds the spec behind a docs page', mode: 'url', input: 'https://petstore.swagger.io/' },
    { label: 'JSONPlaceholder guide', hint: 'scrapes endpoints from docs text', mode: 'url', input: 'https://jsonplaceholder.typicode.com/guide/' },
    { label: 'GitHub API root', hint: 'live discovery via hypermedia', mode: 'url', input: 'https://api.github.com' },
];

const STEPS = ['Fetching the URL', 'Looking for a linked spec', 'Probing well-known spec paths', 'Scraping the docs page', 'Probing the live API'];

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function postJson(url, payload) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(payload),
    });
    let data = null;
    try {
        data = await res.json();
    } catch {
        // non-JSON error page
    }
    return { ok: res.ok, status: res.status, data };
}

function store(key, value) {
    try {
        if (value === undefined) return localStorage.getItem('api-detective:' + key);
        localStorage.setItem('api-detective:' + key, value);
    } catch {
        return null;
    }
}

Alpine.data('detective', () => ({
    // investigation
    mode: 'url',
    url: store('url') || '',
    specText: '',
    loading: false,
    step: 0,
    error: null,
    log: [],
    samples: SAMPLES,
    steps: STEPS,

    // case
    api: null,
    query: '',
    methodFilter: [],
    collapsed: {},
    selectedId: null,
    tab: 'docs',
    showLog: false,
    showExport: false,
    sidebarOpen: false,

    // try it, per endpoint
    tests: {},
    auth: { scheme: '', value: '' },
    copied: null,

    init() {
        const params = new URLSearchParams(location.search);
        if (params.get('url')) {
            this.url = params.get('url');
            this.investigate();
        }
    },

    // ---------------------------------------------------------- discovery

    async investigate() {
        const input = this.mode === 'url' ? this.url.trim() : this.specText;
        if (!input) {
            this.error = this.mode === 'url' ? 'Enter a URL to investigate.' : 'Paste an OpenAPI or Swagger document.';
            return;
        }
        if (this.mode === 'url' && !/^https?:\/\//i.test(input)) {
            this.url = 'https://' + input;
        }

        this.loading = true;
        this.error = null;
        this.log = [];
        this.step = 0;
        const ticker = this.mode === 'url' ? setInterval(() => (this.step = Math.min(this.step + 1, STEPS.length - 1)), 1400) : null;

        const { ok, status, data } = await postJson('/api-detective/discover', {
            mode: this.mode,
            input: this.mode === 'url' ? this.url.trim() : this.specText,
        }).catch(() => ({ ok: false, status: 0, data: null }));

        clearInterval(ticker);
        this.loading = false;
        this.log = data?.log ?? [];

        if (!ok) {
            this.error = data?.message || (status === 429 ? 'Too many investigations — wait a minute and try again.' : status === 419 ? 'Your session expired — refresh the page.' : 'Something went wrong while investigating.');
            return;
        }

        if (this.mode === 'url') store('url', this.url.trim());
        this.openCase(data.api);
    },

    async loadDemo() {
        const res = await fetch('/api-detective/demo-spec');
        this.specText = await res.text();
        this.mode = 'paste';
        this.investigate();
    },

    trySample(s) {
        this.mode = s.mode;
        this.url = s.input;
        this.investigate();
    },

    async loadFile(event) {
        const file = event.target.files?.[0];
        if (!file) return;
        this.specText = await file.text();
        this.mode = 'paste';
        event.target.value = '';
    },

    openCase(api) {
        api.refs = api.refs && !Array.isArray(api.refs) ? api.refs : {};
        this.api = api;
        this.tests = {};
        this.selectedId = null;
        this.query = '';
        this.methodFilter = [];
        this.collapsed = {};
        this.tab = 'docs';
        this.auth = { scheme: api.auth?.[0]?.name ?? '', value: '' };
        window.scrollTo({ top: 0 });
    },

    newCase() {
        this.api = null;
        this.error = null;
        this.log = [];
        this.selectedId = null;
    },

    // ------------------------------------------------------------- browse

    get methods() {
        const present = new Set(this.api?.endpoints.map((e) => e.method));
        return METHODS.filter((m) => present.has(m));
    },

    get methodCounts() {
        const counts = {};
        for (const e of this.api?.endpoints ?? []) counts[e.method] = (counts[e.method] || 0) + 1;
        return counts;
    },

    toggleMethod(m) {
        this.methodFilter = this.methodFilter.includes(m) ? this.methodFilter.filter((x) => x !== m) : [...this.methodFilter, m];
    },

    get filtered() {
        const q = this.query.trim().toLowerCase();
        return (this.api?.endpoints ?? []).filter((e) => {
            if (this.methodFilter.length && !this.methodFilter.includes(e.method)) return false;
            if (!q) return true;
            return [e.path, e.method, e.summary, e.doc?.summary, e.operationId, ...(e.tags || [])].some((v) => String(v || '').toLowerCase().includes(q));
        });
    },

    get groups() {
        const order = (this.api?.tags ?? []).map((t) => t.name);
        const map = new Map();
        for (const e of this.filtered) {
            const tag = e.tags?.[0] || 'other';
            if (!map.has(tag)) map.set(tag, []);
            map.get(tag).push(e);
        }
        return [...map.entries()]
            .sort(([a], [b]) => {
                const ia = order.indexOf(a);
                const ib = order.indexOf(b);
                return (ia < 0 ? 999 : ia) - (ib < 0 ? 999 : ib) || a.localeCompare(b);
            })
            .map(([name, endpoints]) => ({ name, endpoints }));
    },

    get selected() {
        return this.api?.endpoints.find((e) => e.id === this.selectedId) ?? null;
    },

    select(id, tab = null) {
        this.selectedId = id;
        if (tab) this.tab = tab;
        this.sidebarOpen = false;
        this.ensureTest(this.selected);
        this.$nextTick(() => document.getElementById('case-main')?.scrollTo({ top: 0 }));
    },

    tagDescription(name) {
        return this.api?.tags?.find((t) => t.name === name)?.description ?? '';
    },

    originLabel(e) {
        return { spec: 'From spec', scraped: 'Scraped from text', 'scraped:curl': 'Scraped from curl', 'scraped:code': 'Scraped from code', live: 'Live response', 'live:link': 'Hypermedia link' }[e.origin] ?? e.origin;
    },

    pathHtml(path) {
        return escapeHtml(path).replace(/\{([^}]+)\}/g, '<span class="path-param">{$1}</span>');
    },

    // --------------------------------------------------------------- docs

    type(schema) {
        return typeLabel(schema, this.api.refs);
    },

    schemaHtml(schema) {
        return renderSchema(schema, this.api.refs);
    },

    exampleHtml(value) {
        return highlightJson(JSON.stringify(value, null, 2));
    },

    responseExample(r) {
        if (r.example !== null && r.example !== undefined) return r.example;
        return r.schema ? example(r.schema, this.api.refs) : null;
    },

    statusClass(status) {
        const s = String(status);
        return s.startsWith('2') ? 's-ok' : s.startsWith('3') ? 's-redirect' : s.startsWith('4') ? 's-client' : s.startsWith('5') ? 's-server' : 's-other';
    },

    // ------------------------------------------------------------- try it

    ensureTest(e) {
        if (!e || this.tests[e.id]) return;

        const refs = this.api.refs;
        const valueFor = (p) => {
            if (p.example !== null && p.example !== undefined) return String(p.example);
            const v = example(p.schema || { type: 'string' }, refs, p.name);
            return v === null || v === undefined ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v);
        };

        const path = {};
        const query = [];
        const headers = [];
        for (const p of e.parameters || []) {
            if (p.in === 'path') path[p.name] = valueFor(p);
            if (p.in === 'query') query.push({ on: p.required, name: p.name, value: p.required ? valueFor(p) : valueFor(p), required: p.required, hint: p.description });
            if (p.in === 'header') headers.push({ on: p.required, name: p.name, value: valueFor(p), required: p.required });
        }

        const hasBody = !!e.requestBody || BODY_METHODS.includes(e.method) && e.origin !== 'spec';
        const contentType = e.requestBody?.contentType || 'application/json';
        if (hasBody) headers.unshift({ on: true, name: 'Content-Type', value: contentType });
        headers.unshift({ on: true, name: 'Accept', value: 'application/json' });

        this.tests[e.id] = {
            server: this.api.servers?.[0]?.url ?? '',
            path,
            query,
            headers,
            body: hasBody ? this.bodyExample(e) : '',
            hasBody,
            sending: false,
            response: null,
            validation: null,
            view: 'body',
        };
    },

    bodyValue(e) {
        const rb = e.requestBody;
        if (!rb) return null;
        return rb.example ?? (rb.schema ? requestExample(rb.schema, this.api.refs) : null);
    },

    bodyExample(e) {
        const rb = e.requestBody;
        if (!rb) return '{\n  \n}';
        const value = rb.example ?? (rb.schema ? requestExample(rb.schema, this.api.refs) : {});
        if (rb.contentType?.includes('x-www-form-urlencoded') && value && typeof value === 'object') {
            return new URLSearchParams(Object.entries(value).map(([k, v]) => [k, typeof v === 'object' ? JSON.stringify(v) : String(v)])).toString();
        }
        return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
    },

    get test() {
        return this.selected ? this.tests[this.selected.id] : null;
    },

    regenerate() {
        this.test.body = this.bodyExample(this.selected);
    },

    formatBody() {
        try {
            this.test.body = JSON.stringify(JSON.parse(this.test.body), null, 2);
        } catch {
            // not JSON — leave as is
        }
    },

    addRow(kind) {
        this.test[kind].push({ on: true, name: '', value: '' });
    },

    authHeaders() {
        const scheme = this.api.auth?.find((a) => a.name === this.auth.scheme);
        const v = this.auth.value.trim();
        if (!scheme || !v) return { headers: {}, query: {} };

        if (scheme.type === 'apiKey') {
            return scheme.in === 'query' ? { headers: {}, query: { [scheme.paramName]: v } } : scheme.in === 'header' ? { headers: { [scheme.paramName]: v }, query: {} } : { headers: { Cookie: `${scheme.paramName}=${v}` }, query: {} };
        }
        if ((scheme.type === 'http' && scheme.scheme === 'basic') || scheme.type === 'basic') {
            return { headers: { Authorization: 'Basic ' + (v.includes(':') ? btoa(v) : v) }, query: {} };
        }
        return { headers: { Authorization: /^bearer /i.test(v) ? v : 'Bearer ' + v }, query: {} };
    },

    requestUrl() {
        const e = this.selected;
        const t = this.test;
        if (!e || !t) return '';

        let path = e.path.replace(/\{([^}]+)\}/g, (m, name) => encodeURIComponent(t.path[name] ?? m));
        const qs = new URLSearchParams();
        for (const q of t.query) if (q.on && q.name) qs.append(q.name, q.value);
        for (const [k, v] of Object.entries(this.authHeaders().query)) qs.append(k, v);
        const query = qs.toString();

        return t.server.replace(/\/+$/, '') + path + (query ? '?' + query : '');
    },

    requestHeaders() {
        const out = {};
        for (const h of this.test.headers) if (h.on && h.name.trim()) out[h.name.trim()] = h.value;
        return { ...out, ...this.authHeaders().headers };
    },

    curl() {
        const e = this.selected;
        const parts = [`curl -X ${e.method} '${this.requestUrl().replace(/'/g, "'\\''")}'`];
        for (const [k, v] of Object.entries(this.requestHeaders())) parts.push(`-H '${`${k}: ${v}`.replace(/'/g, "'\\''")}'`);
        if (this.test.hasBody && this.test.body.trim()) parts.push(`--data '${this.test.body.replace(/'/g, "'\\''")}'`);
        return parts.join(' \\\n  ');
    },

    async copy(text, key) {
        try {
            await navigator.clipboard.writeText(text);
            this.copied = key;
            setTimeout(() => (this.copied = null), 1500);
        } catch {
            // clipboard blocked — nothing to do
        }
    },

    async send() {
        const e = this.selected;
        const t = this.test;
        if (!t.server) {
            t.response = { error: 'Set a base URL first.' };
            return;
        }

        t.sending = true;
        t.response = null;
        t.validation = null;

        const { ok, status, data } = await postJson('/api-detective/send', {
            method: e.method,
            url: this.requestUrl(),
            headers: this.requestHeaders(),
            body: t.hasBody && t.body.trim() ? t.body : null,
        }).catch(() => ({ ok: false, status: 0, data: null }));

        t.sending = false;

        if (!ok) {
            t.response = { error: data?.error || data?.message || (status === 429 ? 'Too many requests — slow down a little.' : 'The request could not be sent.') };
            return;
        }

        let json;
        try {
            json = data.body ? JSON.parse(data.body) : undefined;
        } catch {
            json = undefined;
        }

        t.response = { ...data, json, pretty: json !== undefined ? JSON.stringify(json, null, 2) : data.body };
        t.view = 'body';
        t.validation = this.check(e, data.status, json);
    },

    /** Compare the live response with what the spec documents for that status. */
    check(e, status, json) {
        if (!e.responses?.length) return null;

        const s = String(status);
        const doc = e.responses.find((r) => r.status === s) || e.responses.find((r) => r.status.toUpperCase() === s[0] + 'XX') || e.responses.find((r) => r.status === 'default');

        if (!doc) {
            return { ok: false, documented: false, issues: [{ path: 'status', message: `${s} isn't a documented status (documented: ${e.responses.map((r) => r.status).join(', ')})` }] };
        }
        if (!doc.schema) return { ok: true, documented: true, status: doc.status, noSchema: true, issues: [] };
        if (json === undefined) return { ok: false, documented: true, status: doc.status, issues: [{ path: '$', message: 'expected a JSON body' }] };

        const issues = validate(json, doc.schema, this.api.refs);
        return { ok: issues.length === 0, documented: true, status: doc.status, issues };
    },

    responseHtml() {
        const r = this.test?.response;
        if (!r) return '';
        if (r.binary) return '<span class="muted">Binary response body (not shown).</span>';
        if (!r.pretty) return '<span class="muted">Empty body.</span>';
        return r.json !== undefined ? highlightJson(r.pretty.length > 400000 ? r.pretty.slice(0, 400000) + '\n…' : r.pretty) : escapeHtml(r.pretty.slice(0, 400000));
    },

    bytes(n) {
        return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : n >= 1024 ? Math.round(n / 1024) + ' KB' : n + ' B';
    },

    // ------------------------------------------------------------- export

    exportMarkdown() {
        download(`${slug(this.api.title)}.md`, toMarkdown(this.api, this.tests), 'text/markdown');
        this.showExport = false;
    },

    exportJson() {
        download(`${slug(this.api.title)}.api-detective.json`, toJson(this.api, this.tests, this.log), 'application/json');
        this.showExport = false;
    },
}));

window.Alpine = Alpine;
Alpine.start();
