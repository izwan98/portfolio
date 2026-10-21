<?php

namespace App\Services\ApiDetective;

use Illuminate\Support\Str;

/**
 * Pulls API clues out of an HTML documentation page:
 *  - links to OpenAPI/Swagger documents (incl. Swagger UI / Redoc config)
 *  - endpoint mentions in prose ("GET /users/{id}") and in curl examples.
 */
class DocScraper
{
    private const VERBS = 'GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS';

    /**
     * Candidate spec URLs, most likely first.
     *
     * @return list<string>
     */
    public function specLinks(string $html, string $baseUrl): array
    {
        $found = [];

        $patterns = [
            // Swagger UI: SwaggerUIBundle({ url: "..." }) / urls: [{ url: "..." }]
            '/\burl\s*:\s*["\']([^"\']+)["\']/i',
            // Redoc: <redoc spec-url="..."> / Redoc.init("...")
            '/spec-url\s*=\s*["\']([^"\']+)["\']/i',
            '/Redoc\.init\(\s*["\']([^"\']+)["\']/i',
            // Stoplight Elements / RapiDoc
            '/\b(?:apiDescriptionUrl|spec-url)\s*=\s*["\']([^"\']+)["\']/i',
            // Any href/src pointing at a spec-ish file
            '/\b(?:href|src|data-url|data-spec)\s*=\s*["\']([^"\']*(?:openapi|swagger|api-docs)[^"\']*)["\']/i',
            // Spec URLs held in variables / config strings anywhere in the text
            '#(https?://[^\s"\'`,<>]+?(?:openapi|swagger|api-docs)[^\s"\'`,<>]*?\.(?:json|ya?ml))(?=[\s"\'`,<>]|$)#i',
            '#["\'`]((?:\.{0,2}/)?[^\s"\'`,<>:]*(?:openapi|swagger|api-docs)[^\s"\'`,<>]*\.(?:json|ya?ml))["\'`]#i',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $m);
            foreach ($m[1] as $candidate) {
                $candidate = html_entity_decode(trim($candidate));
                if ($candidate === '' || str_starts_with($candidate, '#') || str_starts_with($candidate, 'javascript:')) {
                    continue;
                }

                // Skip Swagger UI's own assets
                if (preg_match('#swagger-ui(-bundle|-standalone-preset)?(\.min)?\.(js|css)|favicon|\.png$|\.svg$#i', $candidate)) {
                    continue;
                }

                if (! preg_match('#(openapi|swagger|api-docs|\.json|\.ya?ml)(\?|$|/)#i', $candidate)) {
                    continue;
                }

                $found[] = SafeHttp::resolveUrl($baseUrl, $candidate);
            }
        }

        $found = array_values(array_unique($found));

        // Same host first, then JSON/YAML files, then the rest
        $host = parse_url($baseUrl, PHP_URL_HOST);
        usort($found, fn ($a, $b) => self::score($b, $host) <=> self::score($a, $host));

        return array_slice($found, 0, 8);
    }

    /**
     * Swagger UI 4+ keeps its config in a separate initializer script.
     *
     * @return list<string>
     */
    public function initializerScripts(string $html, string $baseUrl): array
    {
        preg_match_all('/<script[^>]+src\s*=\s*["\']([^"\']*(?:swagger-initializer|swagger-ui-init|swagger-config|redoc-config|api-docs-config)[^"\']*\.js[^"\']*)["\']/i', $html, $m);

        return array_values(array_unique(array_map(fn ($src) => SafeHttp::resolveUrl($baseUrl, html_entity_decode($src)), $m[1])));
    }

    /**
     * Endpoints mentioned in the page text or code samples.
     *
     * @return array{endpoints: list<array>, servers: list<string>}
     */
    public function endpoints(string $html, string $baseUrl): array
    {
        $html = preg_replace('#<(script|style|svg|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        // Keep line structure so evidence snippets read naturally
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h\d|/pre|/code)\b[^>]*>#i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? '';

        $hits = [];
        $origins = [];

        // 1) "GET /v1/users/{id}" or "POST https://api.x.com/v1/things"
        $verbs = self::VERBS;
        preg_match_all("#\\b({$verbs})\\s+(https?://[^\\s\"'<>`]+|/[A-Za-z0-9_\\-./{}:~%]*)#", $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $match) {
            $hits[] = [$match[1][0], $match[2][0], $match[0][1], 'mention'];
        }

        // 2) curl examples
        preg_match_all('~\bcurl\b[^\n]*(?:\\\\\n[^\n]*)*~i', $text, $curls, PREG_OFFSET_CAPTURE);
        foreach ($curls[0] as [$cmd, $offset]) {
            if (! preg_match('~(https?://[^\s"\'\\\\]+)~', $cmd, $u)) {
                continue;
            }
            $method = preg_match("~(?:-X|--request)\\s*['\"]?({$verbs})~i", $cmd, $x)
                ? strtoupper($x[1])
                : (preg_match('~\s(-d|--data|--data-raw|--json|-F|--form)\b~', $cmd) ? 'POST' : 'GET');
            $hits[] = [$method, $u[1], $offset, 'curl'];
        }

        // 3) JS examples: fetch('https://…', { method: 'POST' }) and axios.get('https://…')
        preg_match_all('~\bfetch\(\s*[\'"`](https?://[^\'"`\s]+)[\'"`]\s*(?:,\s*\{(.{0,400}?)\})?~is', $text, $fetches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($fetches as $f) {
            $method = isset($f[2]) && preg_match("~method\\s*:\\s*['\"`]({$verbs})~i", $f[2][0], $x) ? strtoupper($x[1]) : 'GET';
            $hits[] = [$method, $f[1][0], $f[0][1], 'code'];
        }
        preg_match_all('~\baxios\.(get|post|put|patch|delete|head|options)\(\s*[\'"`](https?://[^\'"`\s]+)~i', $text, $axios, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($axios as $a) {
            $hits[] = [strtoupper($a[1][0]), $a[2][0], $a[0][1], 'code'];
        }

        $endpoints = [];
        foreach ($hits as [$method, $target, $offset, $kind]) {
            if (str_starts_with($target, 'http')) {
                $parts = parse_url($target);
                if (empty($parts['host'])) {
                    continue;
                }
                $origins[] = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
                $path = $parts['path'] ?? '/';
            } else {
                $path = $target;
            }

            $path = self::normalizePath($path);
            if ($path === null) {
                continue;
            }
            [$path, $examples] = self::templatize($path);

            $id = strtoupper($method).' '.$path;
            if (isset($endpoints[$id])) {
                $endpoints[$id]['mentions']++;

                // A code sample is better evidence than a bare mention.
                if ($kind !== 'mention' && $endpoints[$id]['origin'] === 'scraped') {
                    $endpoints[$id]['origin'] = 'scraped:'.$kind;
                    $endpoints[$id]['evidence'] = self::snippet($text, $offset);
                }

                continue;
            }

            $endpoints[$id] = [
                'id' => $id,
                'method' => strtoupper($method),
                'path' => $path,
                'operationId' => '',
                'summary' => '',
                'description' => '',
                'tags' => [self::tagFor($path)],
                'deprecated' => false,
                'parameters' => self::pathParameters($path, $examples),
                'requestBody' => in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)
                    ? ['required' => false, 'description' => '', 'contentType' => 'application/json', 'contentTypes' => ['application/json'], 'schema' => null, 'example' => null]
                    : null,
                'responses' => [],
                'security' => [],
                'origin' => $kind === 'mention' ? 'scraped' : 'scraped:'.$kind,
                'evidence' => self::snippet($text, $offset),
                'mentions' => 1,
            ];
        }

        $origins = array_count_values($origins);
        arsort($origins);

        return [
            'endpoints' => array_values($endpoints),
            'servers' => array_keys($origins),
        ];
    }

    /** "/users/:id?x=1" → "/users/{id}"; rejects things that clearly aren't API paths. */
    public static function normalizePath(string $path): ?string
    {
        $path = preg_replace('/[?#].*$/', '', $path) ?? '';
        $path = rtrim($path, '.,;:)]\'"`');
        $path = preg_replace('#/:([A-Za-z_][A-Za-z0-9_]*)#', '/{$1}', $path) ?? '';
        $path = preg_replace('#/+#', '/', $path) ?? '';

        if ($path === '' || $path === '/' || strlen($path) > 160) {
            return $path === '/' ? '/' : null;
        }

        // Static assets / pages are not endpoints
        if (preg_match('#\.(html?|css|js|png|jpe?g|gif|svg|ico|md|pdf|zip)$#i', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * Concrete IDs in examples become parameters: /posts/1/comments → /posts/{postId}/comments
     * (keeping "1" as the example value).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function templatize(string $path): array
    {
        $segments = explode('/', $path);
        $examples = [];

        foreach ($segments as $i => $seg) {
            if ($i === 0 || ! preg_match('/^(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{24})$/i', $seg)) {
                continue;
            }

            $owner = $segments[$i - 1] ?? '';
            $name = $owner !== '' && ! str_starts_with($owner, '{')
                ? Str::camel(Str::singular($owner)).'Id'
                : 'id';
            while (isset($examples[$name])) {
                $name .= '2';
            }

            $examples[$name] = $seg;
            $segments[$i] = '{'.$name.'}';
        }

        return [implode('/', $segments), $examples];
    }

    private static function pathParameters(string $path, array $examples = []): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $m);

        return array_map(fn ($name) => [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'description' => isset($examples[$name]) ? 'Inferred from an example in the docs' : '',
            'schema' => ['type' => isset($examples[$name]) && ctype_digit($examples[$name]) ? 'integer' : 'string'],
            'example' => $examples[$name] ?? null,
            'deprecated' => false,
        ], array_values(array_unique($m[1])));
    }

    private static function tagFor(string $path): string
    {
        foreach (explode('/', trim($path, '/')) as $seg) {
            if ($seg !== '' && ! str_starts_with($seg, '{') && ! preg_match('/^(v\d+|api|rest)$/i', $seg)) {
                return $seg;
            }
        }

        return 'root';
    }

    private static function snippet(string $text, int $offset): string
    {
        $start = max(0, $offset - 80);
        $snippet = substr($text, $start, 260);
        $snippet = preg_replace('/\s+/', ' ', $snippet) ?? '';

        return trim(mb_convert_encoding($snippet, 'UTF-8', 'UTF-8'));
    }

    private static function score(string $url, ?string $host = null): int
    {
        return ($host && parse_url($url, PHP_URL_HOST) === $host ? 4 : 0)
            + (preg_match('#\.(json|ya?ml)(\?|$)#i', $url) ? 2 : 0)
            + (preg_match('#openapi|swagger#i', $url) ? 1 : 0);
    }
}
