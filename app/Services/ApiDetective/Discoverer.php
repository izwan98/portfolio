<?php

namespace App\Services\ApiDetective;

use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use Throwable;

/**
 * Multi-tier endpoint discovery. Each tier only runs if the previous ones came up empty:
 *
 *   1. Direct spec       — the URL itself is an OpenAPI/Swagger document
 *   2. Linked spec       — the page (or its Swagger UI initializer) links to one
 *   3. Well-known paths  — probe /openapi.json, /v3/api-docs, /swagger.json…
 *   4. Docs scraping     — endpoint mentions + curl examples in the page text
 *   5. Live probing      — a JSON API root: hypermedia links + an inferred schema
 */
class Discoverer
{
    public const WELL_KNOWN = [
        '/openapi.json', '/openapi.yaml', '/swagger.json', '/swagger.yaml',
        '/v3/api-docs', '/v2/api-docs', '/api-docs', '/api-docs.json',
        '/swagger/v1/swagger.json', '/openapi/v1.json', '/api/openapi.json',
        '/api/swagger.json', '/docs/openapi.json', '/.well-known/openapi.json',
    ];

    private array $log = [];

    public function __construct(
        private SafeHttp $http,
        private SpecParser $parser,
        private DocScraper $scraper,
    ) {}

    /** @return array{api: array, log: list<array>} */
    public function fromText(string $text): array
    {
        $doc = SpecParser::decode($text);
        if (! SpecParser::looksLikeSpec($doc)) {
            $this->note('Parse pasted spec', 'miss', $doc === null ? 'Could not read it as JSON or YAML.' : 'Valid JSON/YAML, but not an OpenAPI or Swagger document.');
            throw new DiscoveryFailed('That doesn\'t look like an OpenAPI or Swagger document.', $this->log);
        }

        $api = $this->parser->parse($doc);
        $this->note('Parse pasted spec', 'hit', sprintf('%s %s · %d endpoints', $api['source']['kind'] === 'swagger' ? 'Swagger' : 'OpenAPI', $api['source']['specVersion'], count($api['endpoints'])));

        return ['api' => $api, 'log' => $this->log];
    }

    /** @return array{api: array, log: list<array>} */
    public function fromUrl(string $url): array
    {
        // Tier 1 — direct
        try {
            $page = $this->http->get($url);
        } catch (BlockedUrlException $e) {
            $this->note('Fetch URL', 'error', $e->getMessage(), $url);
            throw new DiscoveryFailed($e->getMessage(), $this->log);
        } catch (ConnectionException $e) {
            $this->note('Fetch URL', 'error', 'Could not connect: '.self::short($e->getMessage()), $url);
            throw new DiscoveryFailed('Could not reach that URL.', $this->log);
        }

        $finalUrl = $page['url'];
        $this->note('Fetch URL', $page['status'] < 400 ? 'hit' : 'miss', "HTTP {$page['status']} · ".self::contentType($page).' · '.self::bytes($page['size']), $finalUrl);

        if ($api = $this->trySpec($page, $finalUrl, 'Tier 1 · Direct spec')) {
            return $this->done($api);
        }

        $isHtml = str_contains(strtolower($page['headers']['content-type'] ?? ''), 'html') || preg_match('/^\s*</', $page['body']);

        // Tier 2 — spec linked from the docs page
        if ($isHtml) {
            $links = $this->scraper->specLinks($page['body'], $finalUrl);

            foreach ($this->scraper->initializerScripts($page['body'], $finalUrl) as $script) {
                try {
                    $js = $this->http->get($script, [], 8);
                    $fromScript = $this->scraper->specLinks($js['body'], $finalUrl);
                    $this->note('Tier 2 · Read Swagger UI config', $fromScript ? 'hit' : 'miss', $fromScript ? 'Found '.count($fromScript).' spec link(s)' : 'No spec URL inside', $script);
                    $links = array_values(array_unique([...$fromScript, ...$links]));
                } catch (Throwable $e) {
                    $this->note('Tier 2 · Read Swagger UI config', 'error', self::short($e->getMessage()), $script);
                }
            }

            if (! $links) {
                $this->note('Tier 2 · Linked spec', 'miss', 'No OpenAPI/Swagger links on the page');
            }

            foreach (array_slice($links, 0, 5) as $link) {
                if ($api = $this->fetchSpec($link, 'Tier 2 · Linked spec')) {
                    return $this->done($api);
                }
            }
        } else {
            $this->note('Tier 2 · Linked spec', 'skip', 'Not an HTML page');
        }

        // Tier 3 — well-known locations (origin, then the URL's own directory)
        $p = parse_url($finalUrl);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        $dir = rtrim(preg_replace('#/[^/]*\.[a-z0-9]+$#i', '', $p['path'] ?? ''), '/');
        $bases = array_values(array_unique(array_filter([$origin.$dir, $origin])));

        $candidates = [];
        foreach ($bases as $base) {
            foreach (self::WELL_KNOWN as $suffix) {
                $candidates[] = $base.$suffix;
            }
        }
        $candidates = array_slice(array_values(array_unique($candidates)), 0, 28);

        // Probed concurrently; the first hit in priority order wins.
        foreach ($this->http->getMany($candidates) as $candidate => $res) {
            if ($res && $res['status'] < 300 && ($api = $this->trySpec($res, $candidate, 'Tier 3 · Well-known path', quietMiss: true))) {
                return $this->done($api);
            }
        }
        $this->note('Tier 3 · Well-known paths', 'miss', 'Probed '.count($candidates).' common spec locations — nothing found');

        // Tier 4 — scrape the documentation text
        if ($isHtml) {
            $scraped = $this->scraper->endpoints($page['body'], $finalUrl);
            if ($scraped['endpoints']) {
                $this->note('Tier 4 · Scrape docs page', 'hit', sprintf('%d endpoint(s) in the text and code examples', count($scraped['endpoints'])), $finalUrl);

                return $this->done($this->scrapedApi($page, $finalUrl, $scraped));
            }
            $this->note('Tier 4 · Scrape docs page', 'miss', 'No endpoint mentions found in the text');
        } else {
            $this->note('Tier 4 · Scrape docs page', 'skip', 'Not an HTML page');
        }

        // Tier 5 — treat the URL as a live API
        $json = json_decode($page['body'], true);
        if (is_array($json) && $page['status'] < 400) {
            $api = $this->liveApi($page, $finalUrl, $json);
            $this->note('Tier 5 · Live probe', 'hit', sprintf('JSON response · %d endpoint(s) incl. %d hypermedia link(s)', count($api['endpoints']), count($api['endpoints']) - 1), $finalUrl);

            return $this->done($api);
        }
        $this->note('Tier 5 · Live probe', 'miss', 'The URL did not return JSON');

        throw new DiscoveryFailed('No API found at that URL. Try a spec URL, a docs page, or paste the spec.', $this->log);
    }

    // ------------------------------------------------------------------ tiers

    private function fetchSpec(string $url, string $tier, bool $quietMiss = false): ?array
    {
        try {
            $res = $this->http->get($url, [], 8);
        } catch (Throwable $e) {
            if (! $quietMiss) {
                $this->note($tier, 'error', self::short($e->getMessage()), $url);
            }

            return null;
        }

        if ($res['status'] >= 400) {
            if (! $quietMiss) {
                $this->note($tier, 'miss', "HTTP {$res['status']}", $url);
            }

            return null;
        }

        return $this->trySpec($res, $res['url'], $tier, $quietMiss);
    }

    private function trySpec(array $res, string $url, string $tier, bool $quietMiss = false): ?array
    {
        $doc = SpecParser::decode($res['body']);
        if (! SpecParser::looksLikeSpec($doc)) {
            if (! $quietMiss) {
                $this->note($tier, 'miss', 'Not an OpenAPI/Swagger document', $url);
            }

            return null;
        }

        try {
            $api = $this->parser->parse($doc, $url);
        } catch (InvalidArgumentException $e) {
            $this->note($tier, 'error', $e->getMessage(), $url);

            return null;
        }

        $this->note($tier, 'hit', sprintf('%s %s · %d endpoints', $api['source']['kind'] === 'swagger' ? 'Swagger' : 'OpenAPI', $api['source']['specVersion'], count($api['endpoints'])), $url);

        return $api;
    }

    private function scrapedApi(array $page, string $url, array $scraped): array
    {
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $page['body'], $m) ? trim(html_entity_decode(strip_tags($m[1]))) : parse_url($url, PHP_URL_HOST);
        $servers = $scraped['servers'] ?: [preg_replace('#^(https?://[^/]+).*$#', '$1', $url)];

        return [
            'title' => $title ?: 'Scraped API',
            'version' => '',
            'description' => 'Reconstructed from a documentation page. Endpoints, parameters and bodies are best guesses — verify them with "Try it".',
            'source' => ['kind' => 'scraped', 'specVersion' => '', 'url' => $url],
            'servers' => array_map(fn ($s) => ['url' => rtrim($s, '/'), 'description' => 'Seen in the docs'], $servers),
            'auth' => [],
            'tags' => [],
            'endpoints' => $scraped['endpoints'],
            'refs' => new \stdClass,
        ];
    }

    private function liveApi(array $page, string $url, array $json): array
    {
        $p = parse_url($url);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        $rootPath = $p['path'] ?? '/';

        $endpoints = [[
            'id' => 'GET '.($rootPath ?: '/'),
            'method' => 'GET',
            'path' => $rootPath ?: '/',
            'operationId' => '',
            'summary' => 'API root',
            'description' => 'The URL you investigated. Its response shape was inferred from a live call.',
            'tags' => ['root'],
            'deprecated' => false,
            'parameters' => [],
            'requestBody' => null,
            'responses' => [[
                'status' => (string) $page['status'],
                'description' => 'Observed response',
                'contentType' => $page['headers']['content-type'] ?? 'application/json',
                'schema' => SchemaInferrer::infer($json),
                'example' => $json,
            ]],
            'security' => [],
            'origin' => 'live',
        ]];

        // Hypermedia: { "user_url": "https://api.github.com/user", ... } / { "_links": { "self": { "href": … } } }
        $seen = [$endpoints[0]['id'] => true];
        array_walk_recursive($json, function ($value, $key) use (&$endpoints, &$seen, $origin) {
            if (! is_string($value) || ! str_starts_with($value, $origin) || count($endpoints) > 150) {
                return;
            }

            // Strip RFC 6570 optional parts: /user/repos{?type,page}
            $path = preg_replace('/\{[?&\/#][^}]*\}/', '', substr($value, strlen($origin))) ?: '/';
            $path = DocScraper::normalizePath($path);
            if ($path === null || isset($seen['GET '.$path])) {
                return;
            }
            $seen['GET '.$path] = true;

            preg_match_all('/\{([^}]+)\}/', $path, $m);
            $endpoints[] = [
                'id' => 'GET '.$path,
                'method' => 'GET',
                'path' => $path,
                'operationId' => '',
                'summary' => '',
                'description' => '',
                'tags' => [self::tagFromKey((string) $key, $path)],
                'deprecated' => false,
                'parameters' => array_map(fn ($n) => ['name' => $n, 'in' => 'path', 'required' => true, 'description' => '', 'schema' => ['type' => 'string'], 'example' => null, 'deprecated' => false], $m[1]),
                'requestBody' => null,
                'responses' => [],
                'security' => [],
                'origin' => 'live:link',
                'evidence' => "\"{$key}\": \"{$value}\"",
            ];
        });

        return [
            'title' => parse_url($url, PHP_URL_HOST).' (live)',
            'version' => '',
            'description' => 'Discovered by calling the API directly and following the links in its response. Only GET endpoints can be found this way.',
            'source' => ['kind' => 'live', 'specVersion' => '', 'url' => $url],
            'servers' => [['url' => $origin, 'description' => 'Live API origin']],
            'auth' => [],
            'tags' => [],
            'endpoints' => $endpoints,
            'refs' => new \stdClass,
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function done(array $api): array
    {
        return ['api' => $api, 'log' => $this->log];
    }

    private function note(string $step, string $status, string $detail, ?string $url = null): void
    {
        $this->log[] = array_filter(compact('step', 'status', 'detail', 'url'), fn ($v) => $v !== null);
    }

    private static function tagFromKey(string $key, string $path): string
    {
        $seg = explode('/', trim($path, '/'))[0] ?? '';

        return $seg !== '' && ! str_starts_with($seg, '{') ? $seg : (preg_replace('/_url$/', '', $key) ?: 'root');
    }

    private static function contentType(array $res): string
    {
        return explode(';', $res['headers']['content-type'] ?? 'unknown type')[0];
    }

    private static function bytes(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1).' MB' : ($n >= 1024 ? round($n / 1024).' KB' : "{$n} B");
    }

    private static function short(string $message): string
    {
        return mb_strimwidth(preg_replace('/\s+/', ' ', $message) ?? '', 0, 160, '…');
    }
}
