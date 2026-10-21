<?php

namespace App\Services\ApiDetective;

use Illuminate\Support\Str;

/**
 * Writes human-friendly docs for a discovered API — no external services.
 *
 * Summaries come from the spec where it has them, and are otherwise derived
 * from REST conventions (method + path shape), parameters, bodies and responses.
 */
class Summarizer
{
    private const PAGINATION = '/^(page|per_page|perPage|limit|offset|cursor|page_size|pageSize|page_token|pageToken|after|before|_page|_limit|_start)$/';

    /** Adds `overview`, `authNotes` and a `doc` block to every endpoint. */
    public function annotate(array $api): array
    {
        $refs = (array) ($api['refs'] ?? []);

        foreach ($api['endpoints'] as $i => $endpoint) {
            $api['endpoints'][$i]['doc'] = $this->describe($endpoint, $refs);
        }

        $api['overview'] = $this->overview($api);
        $api['authNotes'] = $this->authNotes($api['auth'] ?? []);

        return $api;
    }

    public function overview(array $api): string
    {
        $endpoints = $api['endpoints'] ?? [];
        $count = count($endpoints);

        $byMethod = array_count_values(array_column($endpoints, 'method'));
        arsort($byMethod);
        $methods = implode(', ', array_map(fn ($m, $n) => "{$n} {$m}", array_keys($byMethod), $byMethod));

        $groups = array_count_values(array_merge(...array_map(fn ($e) => $e['tags'] ?: ['other'], $endpoints)) ?: []);
        arsort($groups);
        $top = array_slice(array_keys($groups), 0, 5);

        $desc = trim(Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($api['description'] ?? '')))), 320));

        $sentences = array_filter([
            $desc,
            sprintf('%s exposes %d endpoint%s%s.', $api['title'] ?? 'This API', $count, $count === 1 ? '' : 's', $methods ? " ({$methods})" : ''),
            $top ? 'Main areas: '.implode(', ', $top).(count($groups) > 5 ? ', and more' : '').'.' : null,
            ($deprecated = count(array_filter($endpoints, fn ($e) => $e['deprecated'] ?? false))) ? "{$deprecated} endpoint".($deprecated === 1 ? ' is' : 's are').' deprecated.' : null,
        ]);

        return implode(' ', $sentences);
    }

    public function authNotes(array $auth): string
    {
        if (! $auth) {
            return 'No authentication is documented.';
        }

        return 'Authenticate with '.implode(' or ', array_map(fn ($a) => match ($a['type']) {
            'apiKey' => "an API key in the {$a['in']} \"{$a['paramName']}\"",
            'http' => $a['scheme'] === 'basic' ? 'HTTP Basic auth' : 'a Bearer token in the Authorization header',
            'basic' => 'HTTP Basic auth',
            'oauth2' => 'OAuth 2.0 (Bearer access token)',
            'openIdConnect' => 'OpenID Connect (Bearer token)',
            default => $a['name'],
        }, $auth)).'.';
    }

    /** @return array{summary: string, details: string, tips: list<string>} */
    public function describe(array $e, array $refs = []): array
    {
        $segments = array_values(array_filter(
            explode('/', trim($e['path'], '/')),
            fn ($s) => $s !== '' && ! preg_match('/^(v\d+(\.\d+)?|api|rest)$/i', $s),
        ));
        $last = end($segments) ?: 'root';
        $isItem = str_starts_with($last, '{');
        $resource = $isItem ? ($segments[count($segments) - 2] ?? 'resource') : $last;
        $resource = str_replace(['-', '_'], ' ', $resource);
        $singular = Str::singular($resource);
        $param = $isItem ? trim($last, '{}') : null;
        $parent = $this->parent($segments, $isItem);

        $summary = trim((string) $e['summary']) ?: match ($e['method']) {
            'GET' => match (true) {
                $resource === 'root' => 'Returns the API root.',
                $isItem => "Retrieves a single {$singular} by {$param}.",
                default => 'Lists '.$resource.($parent ? " for a {$parent}" : '').'.',
            },
            'POST' => ($isItem ? "Performs an action on {$singular} {$param}" : "Creates a new {$singular}".($parent ? " under a {$parent}" : '')).'.',
            'PUT' => "Replaces {$singular}".($param ? " {$param}" : '').'.',
            'PATCH' => "Updates fields of {$singular}".($param ? " {$param}" : '').'.',
            'DELETE' => "Deletes {$singular}".($param ? " {$param}" : '').'.',
            'HEAD' => "Checks whether {$singular} exists (headers only).",
            'OPTIONS' => "Describes the allowed methods for {$e['path']}.",
            default => "{$e['method']} request to {$e['path']}.",
        };

        $params = $e['parameters'] ?? [];
        $required = array_column(array_filter($params, fn ($p) => $p['required']), 'name');
        $optional = array_column(array_filter($params, fn ($p) => ! $p['required']), 'name');
        $body = $e['requestBody'] ?? null;
        $ok = collect($e['responses'] ?? [])->first(fn ($r) => str_starts_with($r['status'], '2'));
        $errors = array_values(array_filter(array_column($e['responses'] ?? [], 'status'), fn ($s) => preg_match('/^[45]/', $s)));

        $description = trim(Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $e['description']))), 260));

        $details = array_filter([
            $description !== '' && $description !== $summary ? $description : null,
            $required ? 'Requires '.self::list($required).'.' : null,
            $optional ? 'Optional: '.self::list(array_slice($optional, 0, 6)).(count($optional) > 6 ? ' and '.(count($optional) - 6).' more' : '').'.' : null,
            $body ? 'Takes '.self::bodyKind($body['contentType'] ?? null).' body'.(($f = self::fieldNames($body['schema'] ?? null, $refs)) ? " ({$f})" : '').'.' : null,
            $ok ? "Returns {$ok['status']}".(($f = self::fieldNames($ok['schema'] ?? null, $refs)) ? " with {$f}" : (($ok['description'] ?? '') !== '' ? ': '.Str::lower(rtrim($ok['description'], '.')) : '')).'.' : null,
        ]);

        $enums = array_filter($params, fn ($p) => ! empty($p['schema']['enum']));

        $tips = array_values(array_filter([
            ($e['deprecated'] ?? false) ? 'Deprecated — avoid it in new integrations.' : null,
            ($e['security'] ?? []) ? 'Needs auth: '.implode(', ', $e['security']).'.' : null,
            collect($params)->contains(fn ($p) => preg_match(self::PAGINATION, $p['name'])) ? 'Paginated — loop over pages to get everything.' : null,
            $enums ? implode(' ', array_map(fn ($p) => "\"{$p['name']}\" accepts: ".implode(', ', array_slice((array) $p['schema']['enum'], 0, 6)).'.', array_slice($enums, 0, 2))) : null,
            in_array($e['method'], ['PUT', 'DELETE'], true) ? 'Idempotent — safe to retry.' : null,
            $e['method'] === 'POST' && ! $isItem ? 'Not idempotent — retrying may create duplicates.' : null,
            $errors ? 'Documented errors: '.implode(', ', array_slice($errors, 0, 6)).'.' : null,
            str_starts_with((string) ($e['origin'] ?? ''), 'scraped') ? 'Reconstructed from docs text — confirm it with "Try it".' : null,
            str_starts_with((string) ($e['origin'] ?? ''), 'live') && $e['method'] === 'GET' && $isItem ? 'Found via a hypermedia link — fill in the path parameters to call it.' : null,
        ]));

        return ['summary' => $summary, 'details' => implode(' ', $details), 'tips' => array_slice($tips, 0, 4)];
    }

    /** Top-level property names of a schema ("list of {a, b}" for arrays), following $refs. */
    public static function fieldNames(mixed $schema, array $refs, int $depth = 0): ?string
    {
        if (! is_array($schema) || $depth > 4) {
            return null;
        }

        if (isset($schema['$ref'])) {
            return self::fieldNames($refs[$schema['$ref']] ?? null, $refs, $depth + 1);
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combo) {
            if (isset($schema[$combo]) && is_array($schema[$combo])) {
                $names = array_filter(array_map(fn ($s) => self::fieldNames($s, $refs, $depth + 1), $schema[$combo]));

                return $names ? implode($combo === 'allOf' ? ', ' : ' | ', array_unique($names)) : null;
            }
        }

        if (($schema['type'] ?? null) === 'array' || isset($schema['items'])) {
            $inner = self::fieldNames($schema['items'] ?? null, $refs, $depth + 1);

            return $inner ? "a list of {{$inner}}" : 'a list';
        }

        if (isset($schema['properties']) && is_array($schema['properties']) && $schema['properties']) {
            $keys = array_keys($schema['properties']);

            return implode(', ', array_slice($keys, 0, 8)).(count($keys) > 8 ? ', …' : '');
        }

        return null;
    }

    /** "posts/{id}/comments" → "post" (the owning resource of a nested collection). */
    private function parent(array $segments, bool $isItem): ?string
    {
        $owner = array_slice($segments, 0, $isItem ? -2 : -1);
        foreach (array_reverse($owner) as $seg) {
            if (! str_starts_with($seg, '{')) {
                return Str::singular(str_replace(['-', '_'], ' ', $seg));
            }
        }

        return null;
    }

    private static function bodyKind(?string $type): string
    {
        return match (true) {
            $type === null || str_contains($type, 'json') => 'a JSON',
            str_contains($type, 'multipart') => 'a multipart form',
            str_contains($type, 'x-www-form-urlencoded') => 'a form-encoded',
            str_contains($type, 'xml') => 'an XML',
            default => "a {$type}",
        };
    }

    private static function list(array $names): string
    {
        return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' and '.end($names) : (string) ($names[0] ?? '');
    }
}
