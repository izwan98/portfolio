<?php

namespace App\Services\ApiDetective;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Normalizes Swagger 2.0 and OpenAPI 3.x documents into one shape the UI understands.
 *
 * Schema `$ref`s are left in place and shipped separately in `refs` (keyed by the
 * ref string), so very large specs don't explode when the same schema is reused.
 * Everything else (parameters, request bodies, responses) is resolved here.
 */
class SpecParser
{
    public const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    private array $doc = [];

    /** Decode JSON or YAML text. Returns null if it is neither. */
    public static function decode(string $text): ?array
    {
        $text = ltrim($text, "\xEF\xBB\xBF \t\r\n");
        if ($text === '') {
            return null;
        }

        if ($text[0] === '{' || $text[0] === '[') {
            $data = json_decode($text, true, 512);

            return is_array($data) ? $data : null;
        }

        // Don't try to YAML-parse an HTML page.
        if ($text[0] === '<') {
            return null;
        }

        try {
            $data = Yaml::parse($text);
        } catch (ParseException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public static function looksLikeSpec(?array $doc): bool
    {
        return is_array($doc)
            && (isset($doc['openapi']) || isset($doc['swagger']))
            && (isset($doc['paths']) || isset($doc['webhooks']) || isset($doc['components']));
    }

    /**
     * @return array<string, mixed> normalized API
     */
    public function parse(array $doc, ?string $sourceUrl = null): array
    {
        if (! self::looksLikeSpec($doc)) {
            throw new InvalidArgumentException('This is not an OpenAPI or Swagger document (no "openapi"/"swagger" version and "paths").');
        }

        $this->doc = $doc;
        $isSwagger2 = isset($doc['swagger']);

        $endpoints = [];
        foreach ((array) ($doc['paths'] ?? []) as $path => $item) {
            $item = $this->deref($item);
            if (! is_array($item)) {
                continue;
            }

            $shared = (array) ($item['parameters'] ?? []);

            foreach (self::METHODS as $method) {
                if (! isset($item[$method]) || ! is_array($item[$method])) {
                    continue;
                }

                $endpoints[] = $isSwagger2
                    ? $this->swaggerOperation($method, (string) $path, $item[$method], $shared)
                    : $this->openApiOperation($method, (string) $path, $item[$method], $shared);
            }
        }

        $info = (array) ($doc['info'] ?? []);

        return [
            'title' => (string) ($info['title'] ?? 'Untitled API'),
            'version' => (string) ($info['version'] ?? ''),
            'description' => (string) ($info['description'] ?? ''),
            'source' => [
                'kind' => $isSwagger2 ? 'swagger' : 'openapi',
                'specVersion' => (string) ($doc['openapi'] ?? $doc['swagger']),
                'url' => $sourceUrl,
            ],
            'servers' => $isSwagger2 ? $this->swaggerServers($sourceUrl) : $this->openApiServers($sourceUrl),
            'auth' => $this->securitySchemes($isSwagger2),
            'tags' => array_values(array_map(
                fn ($t) => ['name' => (string) ($t['name'] ?? ''), 'description' => (string) ($t['description'] ?? '')],
                array_filter((array) ($doc['tags'] ?? []), 'is_array'),
            )),
            'endpoints' => $endpoints,
            'refs' => $this->schemaRefs($isSwagger2),
        ];
    }

    // ---------------------------------------------------------------- OpenAPI 3

    private function openApiOperation(string $method, string $path, array $op, array $shared): array
    {
        $body = null;
        if (isset($op['requestBody'])) {
            $rb = $this->deref($op['requestBody']);
            [$type, $media] = $this->pickMedia((array) ($rb['content'] ?? []));
            $body = [
                'required' => (bool) ($rb['required'] ?? false),
                'description' => (string) ($rb['description'] ?? ''),
                'contentType' => $type,
                'contentTypes' => array_keys((array) ($rb['content'] ?? [])),
                'schema' => $media['schema'] ?? null,
                'example' => $media['example'] ?? $this->firstExample($media['examples'] ?? null),
            ];
        }

        $responses = [];
        foreach ((array) ($op['responses'] ?? []) as $status => $response) {
            $response = $this->deref($response);
            [$type, $media] = $this->pickMedia((array) ($response['content'] ?? []));
            $responses[] = [
                'status' => (string) $status,
                'description' => (string) ($response['description'] ?? ''),
                'contentType' => $type,
                'schema' => $media['schema'] ?? null,
                'example' => $media['example'] ?? $this->firstExample($media['examples'] ?? null),
            ];
        }

        return $this->endpoint($method, $path, $op, $this->parameters($shared, (array) ($op['parameters'] ?? [])), $body, $responses);
    }

    private function openApiServers(?string $sourceUrl): array
    {
        $servers = [];
        foreach ((array) ($this->doc['servers'] ?? []) as $server) {
            if (! is_array($server) || empty($server['url'])) {
                continue;
            }

            // Fill server variables with their defaults: https://{region}.api.com
            $url = preg_replace_callback('/\{([^}]+)\}/', fn ($m) => (string) ($server['variables'][$m[1]]['default'] ?? $m[0]), (string) $server['url']);

            if ($sourceUrl && ! preg_match('#^https?://#i', $url)) {
                $url = SafeHttp::resolveUrl($sourceUrl, $url === '' ? '/' : $url);
            }

            $servers[] = ['url' => rtrim($url, '/'), 'description' => (string) ($server['description'] ?? '')];
        }

        if ($servers === [] && $sourceUrl) {
            $p = parse_url($sourceUrl);
            $servers[] = ['url' => $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : ''), 'description' => 'Inferred from the spec location'];
        }

        return $servers;
    }

    // ---------------------------------------------------------------- Swagger 2

    private function swaggerOperation(string $method, string $path, array $op, array $shared): array
    {
        $params = $this->parameters($shared, (array) ($op['parameters'] ?? []), keepBodyParams: true);
        $consumes = (array) ($op['consumes'] ?? $this->doc['consumes'] ?? ['application/json']);
        $produces = (array) ($op['produces'] ?? $this->doc['produces'] ?? ['application/json']);

        $body = null;
        $formFields = [];
        $plain = [];
        foreach ($params as $p) {
            if ($p['in'] === 'body') {
                $body = [
                    'required' => $p['required'],
                    'description' => $p['description'],
                    'contentType' => in_array('application/json', $consumes, true) ? 'application/json' : ($consumes[0] ?? 'application/json'),
                    'contentTypes' => $consumes,
                    'schema' => $p['schema'],
                    'example' => $p['example'],
                ];
            } elseif ($p['in'] === 'formData') {
                $formFields[$p['name']] = $p;
            } else {
                $plain[] = $p;
            }
        }

        if ($formFields && ! $body) {
            $body = [
                'required' => (bool) array_filter($formFields, fn ($f) => $f['required']),
                'description' => 'Form fields',
                'contentType' => in_array('multipart/form-data', $consumes, true) ? 'multipart/form-data' : 'application/x-www-form-urlencoded',
                'contentTypes' => $consumes,
                'schema' => [
                    'type' => 'object',
                    'properties' => array_map(fn ($f) => ($f['schema'] ?? []) + ['description' => $f['description']], $formFields),
                    'required' => array_keys(array_filter($formFields, fn ($f) => $f['required'])),
                ],
                'example' => null,
            ];
        }

        $responses = [];
        foreach ((array) ($op['responses'] ?? []) as $status => $response) {
            $response = $this->deref($response);
            $responses[] = [
                'status' => (string) $status,
                'description' => (string) ($response['description'] ?? ''),
                'contentType' => isset($response['schema']) ? ($produces[0] ?? 'application/json') : null,
                'schema' => $response['schema'] ?? null,
                'example' => isset($response['examples']) && is_array($response['examples']) ? reset($response['examples']) : null,
            ];
        }

        return $this->endpoint($method, $path, $op, $plain, $body, $responses);
    }

    private function swaggerServers(?string $sourceUrl): array
    {
        $host = $this->doc['host'] ?? ($sourceUrl ? parse_url($sourceUrl, PHP_URL_HOST).(parse_url($sourceUrl, PHP_URL_PORT) ? ':'.parse_url($sourceUrl, PHP_URL_PORT) : '') : null);
        if (! $host) {
            return [];
        }

        $schemes = (array) ($this->doc['schemes'] ?? [$sourceUrl ? parse_url($sourceUrl, PHP_URL_SCHEME) : 'https']);
        usort($schemes, fn ($a, $b) => ($b === 'https') <=> ($a === 'https'));
        $base = rtrim((string) ($this->doc['basePath'] ?? ''), '/');

        return array_map(fn ($s) => ['url' => "{$s}://{$host}{$base}", 'description' => ''], array_values(array_unique($schemes)));
    }

    // ------------------------------------------------------------------ shared

    private function endpoint(string $method, string $path, array $op, array $parameters, ?array $body, array $responses): array
    {
        return [
            'id' => strtoupper($method).' '.$path,
            'method' => strtoupper($method),
            'path' => $path,
            'operationId' => (string) ($op['operationId'] ?? ''),
            'summary' => (string) ($op['summary'] ?? ''),
            'description' => (string) ($op['description'] ?? ''),
            'tags' => array_values(array_map('strval', (array) ($op['tags'] ?? []))),
            'deprecated' => (bool) ($op['deprecated'] ?? false),
            'parameters' => $parameters,
            'requestBody' => $body,
            'responses' => $responses,
            'security' => array_values(array_unique(array_merge(...array_map(fn ($req) => array_keys((array) $req), (array) ($op['security'] ?? $this->doc['security'] ?? []))) ?: [])),
            'origin' => 'spec',
        ];
    }

    /** Merge path-level and operation-level parameters (operation wins on name+in). */
    private function parameters(array $shared, array $own, bool $keepBodyParams = false): array
    {
        $merged = [];
        foreach ([...$shared, ...$own] as $param) {
            $param = $this->deref($param);
            if (! is_array($param) || ! isset($param['name'], $param['in'])) {
                continue;
            }

            if (! $keepBodyParams && in_array($param['in'], ['body', 'formData'], true)) {
                continue;
            }

            // Swagger 2 puts type/format/enum on the parameter itself.
            $schema = $param['schema'] ?? array_filter([
                'type' => $param['type'] ?? null,
                'format' => $param['format'] ?? null,
                'enum' => $param['enum'] ?? null,
                'items' => $param['items'] ?? null,
                'default' => $param['default'] ?? null,
                'minimum' => $param['minimum'] ?? null,
                'maximum' => $param['maximum'] ?? null,
            ], fn ($v) => $v !== null);

            $merged[$param['in'].':'.$param['name']] = [
                'name' => (string) $param['name'],
                'in' => (string) $param['in'],
                'required' => (bool) ($param['required'] ?? $param['in'] === 'path'),
                'description' => (string) ($param['description'] ?? ''),
                'schema' => $schema ?: null,
                'example' => $param['example'] ?? $this->firstExample($param['examples'] ?? null) ?? ($param['x-example'] ?? null),
                'deprecated' => (bool) ($param['deprecated'] ?? false),
            ];
        }

        return array_values($merged);
    }

    /** Prefer JSON media types. */
    private function pickMedia(array $content): array
    {
        if ($content === []) {
            return [null, []];
        }

        foreach ($content as $type => $media) {
            if (preg_match('#[/+]json\b#i', (string) $type)) {
                return [(string) $type, (array) $this->deref($media)];
            }
        }

        $type = array_key_first($content);

        return [(string) $type, (array) $this->deref($content[$type])];
    }

    private function firstExample(mixed $examples): mixed
    {
        if (! is_array($examples) || $examples === []) {
            return null;
        }

        $first = $this->deref(reset($examples));

        return is_array($first) && array_key_exists('value', $first) ? $first['value'] : null;
    }

    private function securitySchemes(bool $isSwagger2): array
    {
        $schemes = $isSwagger2
            ? (array) ($this->doc['securityDefinitions'] ?? [])
            : (array) ($this->doc['components']['securitySchemes'] ?? []);

        $out = [];
        foreach ($schemes as $name => $scheme) {
            $scheme = $this->deref($scheme);
            if (! is_array($scheme)) {
                continue;
            }

            $type = (string) ($scheme['type'] ?? '');
            $out[] = [
                'name' => (string) $name,
                'type' => $type,
                // apiKey: header/query/cookie name
                'in' => (string) ($scheme['in'] ?? ''),
                'paramName' => (string) ($scheme['name'] ?? ''),
                // http: bearer/basic ; swagger 2 "basic"
                'scheme' => strtolower((string) ($scheme['scheme'] ?? ($type === 'basic' ? 'basic' : ''))),
                'bearerFormat' => (string) ($scheme['bearerFormat'] ?? ''),
                'description' => (string) ($scheme['description'] ?? ''),
            ];
        }

        return $out;
    }

    /** All reusable schemas keyed by the exact `$ref` string the spec uses. */
    private function schemaRefs(bool $isSwagger2): array|\stdClass
    {
        $refs = [];
        $groups = $isSwagger2
            ? ['#/definitions/' => (array) ($this->doc['definitions'] ?? [])]
            : ['#/components/schemas/' => (array) ($this->doc['components']['schemas'] ?? [])];

        foreach ($groups as $prefix => $schemas) {
            foreach ($schemas as $name => $schema) {
                $refs[$prefix.self::escapePointer((string) $name)] = $schema;
            }
        }

        return $refs ?: new \stdClass;
    }

    /** Follow local `$ref`s (non-schema objects) to their target. */
    private function deref(mixed $node, int $depth = 0): mixed
    {
        while (is_array($node) && isset($node['$ref']) && is_string($node['$ref']) && $depth++ < 10) {
            $target = $this->pointer($node['$ref']);
            if ($target === null) {
                return $node;
            }
            $node = $target;
        }

        return $node;
    }

    private function pointer(string $ref): mixed
    {
        if (! str_starts_with($ref, '#/')) {
            return null; // external refs aren't followed
        }

        $node = $this->doc;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    private static function escapePointer(string $name): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $name);
    }
}
