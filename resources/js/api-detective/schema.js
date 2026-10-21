/**
 * JSON Schema helpers: $ref resolution, example generation (for "Try it"),
 * response validation, and a readable HTML tree for the docs view.
 *
 * `refs` maps the exact `$ref` strings used by the spec to their schemas.
 */

const MAX_DEPTH = 6;

export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

export function refName(ref) {
    return decodeURIComponent(String(ref).split('/').pop()).replace(/~1/g, '/').replace(/~0/g, '~');
}

/** Follow `$ref`s. Returns { schema, name } where name is the last ref followed. */
export function resolve(schema, refs, seen = new Set()) {
    let name = null;
    let s = schema;

    while (s && typeof s === 'object' && typeof s.$ref === 'string') {
        if (seen.has(s.$ref)) {
            return { schema: { description: `(circular: ${refName(s.$ref)})` }, name: refName(s.$ref), circular: true };
        }
        seen.add(s.$ref);
        name = refName(s.$ref);
        s = refs?.[s.$ref] ?? lookupPointer(s.$ref, refs);
        if (!s) {
            return { schema: { description: `(unresolved ${name})` }, name, missing: true };
        }
    }

    return { schema: s ?? {}, name };
}

// "#/components/schemas/Pet/properties/tags" → walk into the registered Pet schema
function lookupPointer(ref, refs) {
    if (!refs) return null;
    const base = Object.keys(refs)
        .filter((k) => ref.startsWith(k + '/'))
        .sort((a, b) => b.length - a.length)[0];
    if (!base) return null;

    let node = refs[base];
    for (const part of ref.slice(base.length + 1).split('/')) {
        node = node?.[decodeURIComponent(part).replace(/~1/g, '/').replace(/~0/g, '~')];
    }
    return node ?? null;
}

/** Flatten allOf into one object schema (best effort). */
function mergeAllOf(schema, refs, seen) {
    const out = { ...schema, properties: { ...(schema.properties || {}) }, required: [...(schema.required || [])] };
    delete out.allOf;

    for (const part of schema.allOf) {
        const { schema: s } = resolve(part, refs, new Set(seen));
        const merged = s.allOf ? mergeAllOf(s, refs, seen) : s;
        Object.assign(out.properties, merged.properties || {});
        out.required.push(...(merged.required || []));
        if (!out.type && merged.type) out.type = merged.type;
        if (!out.description && merged.description) out.description = merged.description;
    }

    return out;
}

function primaryType(schema) {
    const t = schema.type;
    if (Array.isArray(t)) return t.find((x) => x !== 'null') ?? 'null';
    if (t) return t;
    if (schema.properties || schema.additionalProperties) return 'object';
    if (schema.items) return 'array';
    if (schema.enum) return typeof schema.enum[0];
    return undefined;
}

// ------------------------------------------------------------ examples

const STRING_FORMATS = {
    'date-time': () => new Date(Date.UTC(2026, 0, 15, 9, 30)).toISOString(),
    date: () => '2026-01-15',
    time: () => '09:30:00',
    email: () => 'detective@example.com',
    uuid: () => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
    uri: () => 'https://example.com',
    url: () => 'https://example.com',
    hostname: () => 'example.com',
    ipv4: () => '203.0.113.7',
    ipv6: () => '2001:db8::7',
    password: () => 'correct-horse-battery',
    byte: () => 'ZXhhbXBsZQ==',
    binary: () => '<binary>',
};

function stringFor(name = '', schema = {}) {
    const n = name.toLowerCase();
    if (schema.format && STRING_FORMATS[schema.format]) return STRING_FORMATS[schema.format]();
    if (schema.pattern === '^[0-9]+$') return '123';
    if (n.includes('email')) return STRING_FORMATS.email();
    if (n.endsWith('url') || n.endsWith('uri') || n === 'website' || n === 'homepage') return 'https://example.com';
    if (n === 'id' || n.endsWith('id')) return 'abc123';
    if (n.includes('phone')) return '+60 12-345 6789';
    if (n.includes('name')) return n.includes('user') ? 'sherlock' : 'Sherlock Holmes';
    if (n.includes('date') || n.endsWith('at')) return STRING_FORMATS['date-time']();
    if (n.includes('status')) return 'active';
    if (n.includes('token')) return 'tok_example';
    if (n.includes('description') || n === 'body' || n.includes('message')) return 'Elementary, my dear Watson.';
    if (n.includes('title')) return 'A Study in Scarlet';
    if (n.includes('country')) return 'MY';
    if (n.includes('city')) return 'Kuala Lumpur';
    if (n.includes('currency')) return 'MYR';
    return 'string';
}

/** Build a plausible example value from a schema. */
export function example(schema, refs, name = '', depth = 0, seen = new Set()) {
    if (!schema || depth > MAX_DEPTH) return null;

    const r = resolve(schema, refs, new Set(seen));
    if (r.circular) return null;
    let s = r.schema;
    const nextSeen = new Set(seen);
    if (schema.$ref) nextSeen.add(schema.$ref);

    if (s.example !== undefined) return s.example;
    if (Array.isArray(s.examples) && s.examples.length) return s.examples[0];
    if (s.default !== undefined) return s.default;
    if (s.const !== undefined) return s.const;
    if (Array.isArray(s.enum) && s.enum.length) return s.enum.find((v) => v !== null) ?? s.enum[0];

    if (s.allOf) s = mergeAllOf(s, refs, nextSeen);
    if (s.oneOf || s.anyOf) return example((s.oneOf || s.anyOf)[0], refs, name, depth + 1, nextSeen);

    switch (primaryType(s)) {
        case 'object': {
            const out = {};
            const props = Object.entries(s.properties || {});
            for (const [key, prop] of props.slice(0, 30)) {
                const r2 = resolve(prop, refs, new Set(nextSeen));
                if (r2.schema.readOnly && depth === 0 && s.__forRequest) continue;
                const v = example(prop, refs, key, depth + 1, nextSeen);
                if (v !== null || props.length < 30) out[key] = v;
            }
            if (!props.length && s.additionalProperties && typeof s.additionalProperties === 'object') {
                out.key = example(s.additionalProperties, refs, 'value', depth + 1, nextSeen);
            }
            return out;
        }
        case 'array': {
            const item = example(s.items || {}, refs, name.replace(/s$/, ''), depth + 1, nextSeen);
            return item === null ? [] : [item];
        }
        case 'integer':
            return s.minimum ?? (/(^|_)(id|page)$|Id$/.test(name) ? 1 : /limit|size|count/i.test(name) ? 10 : 42);
        case 'number':
            return s.minimum ?? 9.99;
        case 'boolean':
            return true;
        case 'null':
            return null;
        case 'string':
            return stringFor(name, s);
        default:
            return s.description ? stringFor(name, s) : null;
    }
}

/** Example for a request body: skip readOnly props at the top level. */
export function requestExample(schema, refs) {
    const { schema: s } = resolve(schema, refs);
    return example({ ...s, __forRequest: true }, refs);
}

// ---------------------------------------------------------- validation

function typeOf(v) {
    if (v === null) return 'null';
    if (Array.isArray(v)) return 'array';
    if (Number.isInteger(v)) return 'integer';
    return typeof v;
}

function typeMatches(value, schema) {
    let types = schema.type;
    if (!types) return true;
    types = Array.isArray(types) ? types : [types];
    if (schema.nullable) types = [...types, 'null'];
    const actual = typeOf(value);
    return types.some((t) => t === actual || (t === 'number' && actual === 'integer'));
}

/** Check a response body against a schema. Returns a list of { path, message }. */
export function validate(value, schema, refs, path = '$', issues = [], depth = 0, seen = new Set()) {
    if (!schema || depth > 12 || issues.length >= 25) return issues;

    const r = resolve(schema, refs, new Set(seen));
    if (r.circular || r.missing) return issues;
    let s = r.schema;
    const nextSeen = new Set(seen);
    if (schema.$ref) nextSeen.add(schema.$ref);

    if (s.allOf) s = mergeAllOf(s, refs, nextSeen);

    if (s.oneOf || s.anyOf) {
        const options = s.oneOf || s.anyOf;
        const ok = options.some((opt) => validate(value, opt, refs, path, [], depth + 1, nextSeen).length === 0);
        if (!ok) issues.push({ path, message: `doesn't match any of the ${options.length} allowed shapes` });
        return issues;
    }

    if (!typeMatches(value, s)) {
        issues.push({ path, message: `expected ${[].concat(s.type).join(' | ')}${s.nullable ? ' | null' : ''}, got ${typeOf(value)}` });
        return issues;
    }

    if (Array.isArray(s.enum) && !s.enum.some((e) => JSON.stringify(e) === JSON.stringify(value))) {
        issues.push({ path, message: `${JSON.stringify(value)} is not one of ${s.enum.slice(0, 6).map((e) => JSON.stringify(e)).join(', ')}` });
    }

    if (value && typeof value === 'object' && !Array.isArray(value)) {
        for (const key of s.required || []) {
            if (!(key in value)) issues.push({ path: `${path}.${key}`, message: 'required field is missing' });
        }
        for (const [key, prop] of Object.entries(s.properties || {})) {
            if (key in value) validate(value[key], prop, refs, `${path}.${key}`, issues, depth + 1, nextSeen);
        }
    }

    if (Array.isArray(value) && s.items) {
        // Checking a handful of items is enough to spot a shape mismatch.
        value.slice(0, 5).forEach((item, i) => validate(item, s.items, refs, `${path}[${i}]`, issues, depth + 1, nextSeen));
    }

    return issues;
}

// ----------------------------------------------------------- rendering

export function typeLabel(schema, refs) {
    if (!schema) return 'any';
    const r = resolve(schema, refs);
    const s = r.schema.allOf ? mergeAllOf(r.schema, refs, new Set()) : r.schema;
    if (r.name && primaryType(s) === 'object') return r.name;

    const t = primaryType(s);
    if (t === 'array') return `${typeLabel(s.items, refs)}[]`;
    if (s.oneOf || s.anyOf) return (s.oneOf || s.anyOf).map((x) => typeLabel(x, refs)).join(' | ');
    const nullable = s.nullable || (Array.isArray(s.type) && s.type.includes('null'));
    return `${t ?? 'any'}${s.format ? `<${s.format}>` : ''}${nullable ? '?' : ''}`;
}

/** Collapsible property tree as HTML (every value escaped). */
export function renderSchema(schema, refs, depth = 0, seen = new Set()) {
    if (!schema) return '<p class="muted small">No schema documented.</p>';

    const r = resolve(schema, refs, new Set(seen));
    let s = r.schema;
    const nextSeen = new Set(seen);
    if (schema.$ref) nextSeen.add(schema.$ref);
    if (s.allOf) s = mergeAllOf(s, refs, nextSeen);

    if (r.circular) return `<p class="muted small">↻ ${escapeHtml(r.name)} (circular)</p>`;

    if (primaryType(s) === 'array') {
        return `<div class="schema-array"><span class="muted small">array of</span> <code class="type">${escapeHtml(typeLabel(s.items, refs))}</code>${
            depth < MAX_DEPTH ? renderSchema(s.items, refs, depth + 1, nextSeen) : ''
        }</div>`;
    }

    if (s.oneOf || s.anyOf) {
        const opts = s.oneOf || s.anyOf;
        return `<div class="schema-oneof"><span class="muted small">one of:</span>${opts
            .map((o) => `<div class="schema-option"><code class="type">${escapeHtml(typeLabel(o, refs))}</code>${depth < MAX_DEPTH ? renderSchema(o, refs, depth + 1, nextSeen) : ''}</div>`)
            .join('')}</div>`;
    }

    const props = Object.entries(s.properties || {});
    if (!props.length) {
        return s.description ? `<p class="muted small">${escapeHtml(s.description)}</p>` : '';
    }

    const required = new Set(s.required || []);
    const rows = props
        .map(([key, prop]) => {
            const pr = resolve(prop, refs, new Set(nextSeen));
            const ps = pr.schema.allOf ? mergeAllOf(pr.schema, refs, nextSeen) : pr.schema;
            const t = primaryType(ps);
            const nested = (t === 'object' && Object.keys(ps.properties || {}).length) || (t === 'array' && ps.items && primaryType(resolve(ps.items, refs).schema) === 'object');
            const meta = [
                ps.description ? `<span class="desc">${escapeHtml(ps.description)}</span>` : '',
                ps.enum ? `<span class="enum">${ps.enum.slice(0, 10).map((e) => `<code>${escapeHtml(JSON.stringify(e))}</code>`).join(' ')}</span>` : '',
                ps.readOnly ? '<span class="flag">read-only</span>' : '',
                ps.deprecated ? '<span class="flag warn">deprecated</span>' : '',
            ].join('');

            const head = `<span class="prop"><code class="name">${escapeHtml(key)}</code>${required.has(key) ? '<span class="req" title="required">*</span>' : ''}<code class="type">${escapeHtml(typeLabel(prop, refs))}</code></span>${meta}`;

            if (nested && depth < MAX_DEPTH && !pr.circular) {
                return `<li><details${depth < 1 ? ' open' : ''}><summary>${head}</summary>${renderSchema(prop, refs, depth + 1, nextSeen)}</details></li>`;
            }
            return `<li><div class="leaf">${head}</div></li>`;
        })
        .join('');

    return `<ul class="schema-tree">${rows}</ul>`;
}

/** Syntax-highlighted JSON (escaped). */
export function highlightJson(text) {
    return escapeHtml(text).replace(
        /(&quot;(?:\\.|[^&]|&(?!quot;))*?&quot;)(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g,
        (m, str, colon, lit, num) => {
            if (str) return colon ? `<span class="j-key">${str}</span>${colon}` : `<span class="j-str">${str}</span>`;
            if (lit) return `<span class="j-lit">${lit}</span>`;
            if (num) return `<span class="j-num">${num}</span>`;
            return m;
        },
    );
}
