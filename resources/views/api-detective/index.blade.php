<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d0f12">
    <meta name="description" content="API Detective discovers, documents and tests REST APIs from OpenAPI specs, documentation pages and live endpoints.">

    <title>API Detective</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='13' cy='13' r='8' fill='none' stroke='%23f5b544' stroke-width='3.5'/%3E%3Cpath d='M19 19l8 8' stroke='%23f5b544' stroke-width='4' stroke-linecap='round'/%3E%3C/svg%3E">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">

    @vite(['resources/css/api-detective.css', 'resources/js/api-detective/main.js'])
</head>
<body x-data="detective" :class="api ? 'has-case' : ''">

{{-- ================================================================ landing --}}
<template x-if="!api">
    <main class="landing">
        <header class="brand">
            <span class="brand-mark" aria-hidden="true">@include('api-detective.partials.lens')</span>
            <span class="brand-name">API Detective</span>
        </header>

        <section class="hero">
            <p class="stamp">Case file · REST APIs</p>
            <h1>Every API leaves clues.</h1>
            <p class="lede">Point it at an OpenAPI spec, a documentation page, or a live endpoint. It finds the endpoints, explains what they do, and lets you test them right here.</p>

            <form class="search-card" @submit.prevent="investigate()">
                <div class="mode-tabs" role="tablist">
                    <button type="button" role="tab" :aria-selected="mode === 'url'" :class="mode === 'url' && 'on'" @click="mode = 'url'">URL</button>
                    <button type="button" role="tab" :aria-selected="mode === 'paste'" :class="mode === 'paste' && 'on'" @click="mode = 'paste'">Paste spec</button>
                </div>

                <div x-show="mode === 'url'" class="url-row">
                    <input type="text" x-model="url" placeholder="https://api.example.com/openapi.json — or a docs page, or an API root" autocomplete="off" spellcheck="false" aria-label="URL to investigate" :disabled="loading">
                    <button type="submit" class="btn btn-primary" :disabled="loading">
                        <span x-show="!loading">Investigate</span>
                        <span x-show="loading" x-cloak class="spinner" aria-label="Investigating"></span>
                    </button>
                </div>

                <div x-show="mode === 'paste'" x-cloak class="paste-box">
                    <textarea x-model="specText" placeholder="Paste OpenAPI 3.x or Swagger 2.0 — JSON or YAML" spellcheck="false" aria-label="Spec text" :disabled="loading"></textarea>
                    <div class="paste-actions">
                        <label class="btn btn-ghost">
                            <input type="file" accept=".json,.yaml,.yml,application/json,text/yaml" class="sr-only" @change="loadFile($event)">
                            Open file…
                        </label>
                        <button type="submit" class="btn btn-primary" :disabled="loading">
                            <span x-show="!loading">Investigate</span>
                            <span x-show="loading" x-cloak class="spinner"></span>
                        </button>
                    </div>
                </div>
            </form>

            {{-- Progress / failure --}}
            <div class="status-area" aria-live="polite">
                <template x-if="loading && mode === 'url'">
                    <ol class="tiers live">
                        <template x-for="(s, i) in steps" :key="i">
                            <li :class="i < step ? 'done' : i === step ? 'now' : ''"><span class="dot"></span><span x-text="s"></span></li>
                        </template>
                    </ol>
                </template>

                <template x-if="error && !loading">
                    <div class="case-closed">
                        <p class="stamp stamp-red">Case unsolved</p>
                        <p class="error-text" x-text="error"></p>
                        <template x-if="log.length">
                            @include('api-detective.partials.log')
                        </template>
                    </div>
                </template>
            </div>

            <div class="samples" x-show="!loading">
                <span class="samples-label">Try a case:</span>
                <template x-for="s in samples" :key="s.input">
                    <button type="button" class="chip" @click="trySample(s)" :title="s.hint">
                        <span x-text="s.label"></span><small x-text="s.hint"></small>
                    </button>
                </template>
                <button type="button" class="chip chip-amber" @click="loadDemo()" title="A bundled spec — works offline">
                    <span>Demo spec</span><small>bundled · works offline</small>
                </button>
            </div>
        </section>

        <section class="how" aria-label="How it works">
            <div class="method-col">
                <h2><span class="num">01</span> Discover</h2>
                <p>Five tiers, stopping at the first that works:</p>
                <ol class="tiers">
                    <li><b>Direct spec</b> — the URL is an OpenAPI/Swagger document</li>
                    <li><b>Linked spec</b> — found in the page or its Swagger UI / Redoc config</li>
                    <li><b>Well-known paths</b> — <code>/openapi.json</code>, <code>/v3/api-docs</code>, …</li>
                    <li><b>Docs scraping</b> — <code>GET /users/{id}</code>, curl &amp; fetch examples</li>
                    <li><b>Live probing</b> — JSON roots and their hypermedia links</li>
                </ol>
            </div>
            <div class="method-col">
                <h2><span class="num">02</span> Understand</h2>
                <p>Every endpoint gets a plain-language summary, the inputs that matter, what comes back, and practical tips: pagination, auth, idempotency, enum values, deprecations.</p>
            </div>
            <div class="method-col">
                <h2><span class="num">03</span> Test &amp; export</h2>
                <p>Requests are generated from the schemas. Send them, compare the response with the documented schema, copy as curl, and export everything to Markdown or JSON.</p>
            </div>
        </section>

        <footer class="foot">Requests are sent from this server, and private network addresses are blocked. Nothing you enter is stored.</footer>
    </main>
</template>

{{-- ================================================================== case --}}
<template x-if="api">
    <div class="case">
        <header class="case-bar">
            <button type="button" class="brand brand-sm" @click="newCase()" title="New case">
                <span class="brand-mark" aria-hidden="true">@include('api-detective.partials.lens')</span>
                <span class="brand-name">API Detective</span>
            </button>

            <button type="button" class="icon-btn only-mobile" @click="sidebarOpen = !sidebarOpen" aria-label="Endpoints">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
            </button>

            <div class="case-title">
                <span class="case-name" x-text="api.title"></span>
                <span class="pill" x-show="api.version" x-text="'v' + api.version"></span>
                <span class="pill pill-source" x-text="api.source.kind + (api.source.specVersion ? ' ' + api.source.specVersion : '')"></span>
            </div>

            <div class="case-actions">
                <button type="button" class="btn btn-ghost" @click="showLog = !showLog" :class="showLog && 'on'">Case notes</button>
                <div class="menu" @click.outside="showExport = false">
                    <button type="button" class="btn btn-ghost" @click="showExport = !showExport" :aria-expanded="showExport">Export ▾</button>
                    <div class="menu-list" x-show="showExport" x-transition.opacity x-cloak>
                        <button type="button" @click="exportMarkdown()"><b>Markdown</b><small>Readable docs (.md)</small></button>
                        <button type="button" @click="exportJson()"><b>JSON</b><small>Endpoints, docs, examples, test results</small></button>
                    </div>
                </div>
                <button type="button" class="btn btn-primary" @click="newCase()">New case</button>
            </div>
        </header>

        <div class="case-log" x-show="showLog" x-collapse x-cloak>
            @include('api-detective.partials.log')
        </div>

        <div class="case-body">
            <div class="scrim" x-show="sidebarOpen" x-transition.opacity x-cloak @click="sidebarOpen = false"></div>

            {{-- Sidebar: endpoint index --}}
            <aside class="index" :class="sidebarOpen && 'open'">
                <div class="index-tools">
                    <input type="search" x-model="query" placeholder="Search endpoints…" aria-label="Search endpoints">
                    <div class="method-filter">
                        <template x-for="m in methods" :key="m">
                            <button type="button" class="m-chip" :class="['m-' + m.toLowerCase(), methodFilter.includes(m) && 'on']" @click="toggleMethod(m)">
                                <span x-text="m"></span><small x-text="methodCounts[m]"></small>
                            </button>
                        </template>
                    </div>
                </div>

                <nav class="index-list" aria-label="Endpoints">
                    <button type="button" class="overview-link" :class="!selectedId && 'active'" @click="selectedId = null; sidebarOpen = false">
                        Overview <small x-text="api.endpoints.length + ' endpoints'"></small>
                    </button>

                    <template x-for="g in groups" :key="g.name">
                        <div class="group">
                            <button type="button" class="group-head" @click="collapsed[g.name] = !collapsed[g.name]" :aria-expanded="!collapsed[g.name]">
                                <svg class="caret" :class="collapsed[g.name] && 'shut'" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m3 4.5 3 3 3-3"/></svg>
                                <span x-text="g.name"></span>
                                <small x-text="g.endpoints.length"></small>
                            </button>
                            <ul x-show="!collapsed[g.name]">
                                <template x-for="e in g.endpoints" :key="e.id">
                                    <li>
                                        <button type="button" class="ep" :class="[selectedId === e.id && 'active', e.deprecated && 'deprecated']" @click="select(e.id)" :title="e.doc?.summary">
                                            <span class="method" :class="'m-' + e.method.toLowerCase()" x-text="e.method"></span>
                                            <span class="ep-path" x-html="pathHtml(e.path)"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <p class="empty" x-show="!filtered.length">No endpoints match.</p>
                </nav>
            </aside>

            <main id="case-main" class="detail">
                <template x-if="!selected">
                    @include('api-detective.partials.overview')
                </template>
                <template x-if="selected">
                    @include('api-detective.partials.endpoint')
                </template>
            </main>
        </div>
    </div>
</template>

</body>
</html>
