<article class="sheet endpoint">
    <p class="crumbs">
        <button type="button" class="link" @click="selectedId = null">Overview</button>
        <span>/</span>
        <span x-text="selected.tags[0] || 'other'"></span>
    </p>

    <h1 class="ep-title">
        <span class="method method-lg" :class="'m-' + selected.method.toLowerCase()" x-text="selected.method"></span>
        <code class="ep-title-path" x-html="pathHtml(selected.path)"></code>
    </h1>

    <div class="badges">
        <span class="pill" :class="selected.origin.startsWith('spec') ? 'pill-spec' : 'pill-lead'" x-text="originLabel(selected)"></span>
        <span class="pill pill-warn" x-show="selected.deprecated">Deprecated</span>
        <span class="pill" x-show="selected.operationId" x-text="selected.operationId"></span>
        <template x-for="s in selected.security" :key="s"><span class="pill pill-lock" x-text="'🔒 ' + s"></span></template>
    </div>

    <p class="ep-summary" x-text="selected.doc.summary"></p>
    <p class="ep-details" x-show="selected.doc.details" x-text="selected.doc.details"></p>

    <ul class="tips" x-show="selected.doc.tips.length">
        <template x-for="tip in selected.doc.tips" :key="tip"><li x-text="tip"></li></template>
    </ul>

    <blockquote class="evidence" x-show="selected.evidence">
        <span class="stamp">Evidence</span>
        <span x-text="selected.evidence"></span>
    </blockquote>

    <div class="tabs" role="tablist">
        <button type="button" role="tab" :aria-selected="tab === 'docs'" :class="tab === 'docs' && 'on'" @click="tab = 'docs'">Docs</button>
        <button type="button" role="tab" :aria-selected="tab === 'try'" :class="tab === 'try' && 'on'" @click="tab = 'try'">Try it</button>
    </div>

    {{-- ---------------------------------------------------------------- docs --}}
    <section x-show="tab === 'docs'" class="docs">
        <h2 class="sheet-h2">Parameters</h2>
        <template x-if="!selected.parameters.length"><p class="muted">No parameters.</p></template>
        <div class="table-wrap" x-show="selected.parameters.length">
            <table class="params">
                <thead><tr><th>Name</th><th>In</th><th>Type</th><th>Description</th></tr></thead>
                <tbody>
                    <template x-for="p in selected.parameters" :key="p.in + p.name">
                        <tr>
                            <td><code class="name" x-text="p.name"></code><span class="req" x-show="p.required" title="required">*</span></td>
                            <td><span class="in" x-text="p.in"></span></td>
                            <td><code class="type" x-text="type(p.schema)"></code></td>
                            <td>
                                <span x-text="p.description"></span>
                                <template x-if="p.schema?.enum"><span class="enum"><template x-for="v in p.schema.enum.slice(0, 10)" :key="String(v)"><code x-text="JSON.stringify(v)"></code></template></span></template>
                                <template x-if="p.example !== null && p.example !== undefined"><span class="muted small"> e.g. <code x-text="String(p.example)"></code></span></template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <template x-if="selected.requestBody">
            <div>
                <h2 class="sheet-h2">Request body
                    <small class="muted" x-text="(selected.requestBody.contentType || '') + (selected.requestBody.required ? ' · required' : '')"></small>
                </h2>
                <p class="muted" x-show="selected.requestBody.description" x-text="selected.requestBody.description"></p>
                <div class="split">
                    <div class="schema-box" x-html="schemaHtml(selected.requestBody.schema)"></div>
                    <pre class="code" x-show="selected.requestBody.schema || selected.requestBody.example"><code x-html="exampleHtml(bodyValue(selected))"></code></pre>
                </div>
            </div>
        </template>

        <h2 class="sheet-h2">Responses</h2>
        <template x-if="!selected.responses.length"><p class="muted">No responses documented. Use “Try it” to see a real one.</p></template>
        <div class="responses">
            <template x-for="(r, i) in selected.responses" :key="r.status">
                <details class="response" :open="i === 0">
                    <summary>
                        <span class="status" :class="statusClass(r.status)" x-text="r.status"></span>
                        <span x-text="r.description"></span>
                        <code class="type" x-show="r.schema" x-text="type(r.schema)"></code>
                    </summary>
                    <div class="split" x-show="r.schema || r.example">
                        <div class="schema-box" x-html="schemaHtml(r.schema)"></div>
                        <pre class="code" x-show="responseExample(r) !== null"><code x-html="exampleHtml(responseExample(r))"></code></pre>
                    </div>
                </details>
            </template>
        </div>
    </section>

    {{-- ------------------------------------------------------------- try it --}}
    <section x-show="tab === 'try'" class="try">
        <div class="try-grid">
            <div class="try-request">
                <label class="field">
                    <span>Base URL</span>
                    <input type="text" x-model="test.server" list="servers" placeholder="https://api.example.com" spellcheck="false">
                    <datalist id="servers"><template x-for="s in api.servers" :key="s.url"><option :value="s.url"></option></template></datalist>
                </label>

                <template x-if="Object.keys(test.path).length">
                    <fieldset class="fields">
                        <legend>Path parameters</legend>
                        <template x-for="name in Object.keys(test.path)" :key="name">
                            <label class="field field-inline">
                                <code x-text="name"></code>
                                <input type="text" x-model="test.path[name]" spellcheck="false">
                            </label>
                        </template>
                    </fieldset>
                </template>

                <fieldset class="fields">
                    <legend>Query <button type="button" class="link small" @click="addRow('query')">+ add</button></legend>
                    <template x-if="!test.query.length"><p class="muted small">No query parameters.</p></template>
                    <template x-for="(q, i) in test.query" :key="i">
                        <div class="kv" :class="!q.on && 'off'">
                            <input type="checkbox" x-model="q.on" :aria-label="'Send ' + q.name">
                            <input type="text" x-model="q.name" placeholder="name" spellcheck="false" :readonly="q.required">
                            <input type="text" x-model="q.value" placeholder="value" spellcheck="false" :title="q.hint">
                        </div>
                    </template>
                </fieldset>

                <fieldset class="fields">
                    <legend>Headers <button type="button" class="link small" @click="addRow('headers')">+ add</button></legend>
                    <template x-for="(h, i) in test.headers" :key="i">
                        <div class="kv" :class="!h.on && 'off'">
                            <input type="checkbox" x-model="h.on" :aria-label="'Send ' + h.name">
                            <input type="text" x-model="h.name" placeholder="Header" spellcheck="false">
                            <input type="text" x-model="h.value" placeholder="value" spellcheck="false">
                        </div>
                    </template>
                </fieldset>

                <template x-if="api.auth.length">
                    <fieldset class="fields">
                        <legend>Auth</legend>
                        <div class="auth-row">
                            <select x-model="auth.scheme" aria-label="Auth scheme">
                                <template x-for="a in api.auth" :key="a.name"><option :value="a.name" x-text="a.name + ' (' + a.type + (a.scheme ? ' ' + a.scheme : '') + ')'"></option></template>
                            </select>
                            <input type="password" x-model="auth.value" placeholder="key, token, or user:pass" autocomplete="off">
                        </div>
                        <p class="muted small">Sent with this request only; never stored.</p>
                    </fieldset>
                </template>

                <template x-if="test.hasBody">
                    <fieldset class="fields">
                        <legend>Body
                            <span class="legend-actions">
                                <button type="button" class="link small" @click="regenerate()">Regenerate from schema</button>
                                <button type="button" class="link small" @click="formatBody()">Format</button>
                            </span>
                        </legend>
                        <textarea class="body-editor" x-model="test.body" spellcheck="false" rows="10"></textarea>
                    </fieldset>
                </template>

                <div class="url-preview">
                    <span class="method" :class="'m-' + selected.method.toLowerCase()" x-text="selected.method"></span>
                    <code x-text="requestUrl()"></code>
                </div>

                <div class="send-row">
                    <button type="button" class="btn btn-primary btn-send" @click="send()" :disabled="test.sending">
                        <span x-show="!test.sending">Send request</span>
                        <span x-show="test.sending" class="spinner"></span>
                    </button>
                    <button type="button" class="btn btn-ghost" @click="copy(curl(), 'curl')" x-text="copied === 'curl' ? 'Copied!' : 'Copy as curl'"></button>
                </div>
            </div>

            <div class="try-response">
                <template x-if="!test.response && !test.sending">
                    <div class="awaiting">
                        <span class="brand-mark" aria-hidden="true">@include('api-detective.partials.lens')</span>
                        <p>Send the request to see what the API actually says.</p>
                    </div>
                </template>

                <template x-if="test.sending">
                    <div class="awaiting"><span class="spinner spinner-lg"></span><p>Waiting for the API…</p></div>
                </template>

                <template x-if="test.response?.error">
                    <div class="response-error">
                        <p class="stamp stamp-red">Request failed</p>
                        <p x-text="test.response.error"></p>
                    </div>
                </template>

                <template x-if="test.response && !test.response.error">
                    <div class="response-view">
                        <div class="response-meta">
                            <span class="status status-lg" :class="statusClass(test.response.status)" x-text="test.response.status + ' ' + (test.response.reason || '')"></span>
                            <span class="muted" x-text="test.response.timeMs + ' ms'"></span>
                            <span class="muted" x-text="bytes(test.response.size)"></span>
                        </div>

                        <template x-if="test.validation">
                            <div class="check" :class="test.validation.ok ? 'check-ok' : 'check-bad'">
                                <template x-if="test.validation.ok">
                                    <p><b>✓ Matches the spec</b> <span x-text="test.validation.noSchema ? '— status ' + test.validation.status + ' is documented (no body schema to compare).' : '— body fits the documented ' + test.validation.status + ' schema.'"></span></p>
                                </template>
                                <template x-if="!test.validation.ok">
                                    <div>
                                        <p><b x-text="'✗ ' + test.validation.issues.length + ' difference' + (test.validation.issues.length === 1 ? '' : 's') + ' from the spec'"></b></p>
                                        <ul>
                                            <template x-for="(iss, i) in test.validation.issues" :key="i">
                                                <li><code x-text="iss.path"></code> <span x-text="iss.message"></span></li>
                                            </template>
                                        </ul>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div class="tabs tabs-sm">
                            <button type="button" :class="test.view === 'body' && 'on'" @click="test.view = 'body'">Body</button>
                            <button type="button" :class="test.view === 'headers' && 'on'" @click="test.view = 'headers'">Headers <small x-text="Object.keys(test.response.headers).length"></small></button>
                            <button type="button" class="link small push" x-show="test.view === 'body' && test.response.pretty" @click="copy(test.response.pretty, 'body')" x-text="copied === 'body' ? 'Copied!' : 'Copy'"></button>
                        </div>

                        <pre class="code code-response" x-show="test.view === 'body'"><code x-html="responseHtml()"></code></pre>

                        <dl class="headers" x-show="test.view === 'headers'">
                            <template x-for="[k, v] in Object.entries(test.response.headers)" :key="k">
                                <div><dt x-text="k"></dt><dd x-text="v"></dd></div>
                            </template>
                        </dl>
                    </div>
                </template>
            </div>
        </div>
    </section>
</article>
