<?php

namespace App\Services\ApiDetective;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Outbound HTTP for discovery and the request tester.
 *
 * Every request is SSRF-guarded: only http(s), the host must resolve to public
 * addresses only, the connection is pinned to the address we checked (so DNS
 * can't be swapped between check and connect), redirects are re-validated hop
 * by hop, and response bodies are capped.
 */
class SafeHttp
{
    public const MAX_BODY_BYTES = 8 * 1024 * 1024;

    private const BLOCKED_HEADERS = ['host', 'content-length', 'connection', 'transfer-encoding', 'te', 'upgrade', 'proxy-authorization'];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    public function __construct(?Closure $resolver = null, private ?bool $allowPrivate = null)
    {
        $this->resolver = $resolver ?? self::dnsResolver(...);
        $this->allowPrivate ??= (bool) config('services.api_detective.allow_private_hosts', false);
    }

    /**
     * Send a request.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, reason: string, headers: array<string, string>, body: string, timeMs: int, size: int, url: string, redirects: list<string>}
     *
     * @throws BlockedUrlException|ConnectionException
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $maxRedirects = 0,
        float $timeout = 15,
        int $maxBytes = self::MAX_BODY_BYTES,
    ): array {
        $redirects = [];
        $started = hrtime(true);

        for ($hop = 0; ; $hop++) {
            $response = $this->once($method, $url, $headers, $body, $timeout, $maxBytes);

            $location = $response->header('Location');
            if ($hop < $maxRedirects && $response->status() >= 300 && $response->status() < 400 && $location !== '') {
                $redirects[] = $url;
                $url = self::resolveUrl($url, $location);
                if (in_array($response->status(), [301, 302, 303], true)) {
                    $method = 'GET';
                    $body = null;
                }

                continue;
            }

            $raw = $response->body();

            return [
                'status' => $response->status(),
                'reason' => $response->reason(),
                'headers' => self::flattenHeaders($response->headers()),
                'body' => $raw,
                'timeMs' => (int) round((hrtime(true) - $started) / 1e6),
                'size' => strlen($raw),
                'url' => $url,
                'redirects' => $redirects,
            ];
        }
    }

    /** Convenience GET that follows a few (re-validated) redirects. */
    public function get(string $url, array $headers = [], float $timeout = 12): array
    {
        return $this->send('GET', $url, $headers + ['Accept' => 'application/json, application/yaml, text/yaml, text/html;q=0.9, */*;q=0.8'], null, 4, $timeout);
    }

    /**
     * Concurrent GETs (no redirects) for probing many candidate URLs at once.
     * Blocked or failed URLs come back as null.
     *
     * @param  list<string>  $urls
     * @return array<string, array{status: int, headers: array<string, string>, body: string, size: int, url: string}|null>
     */
    public function getMany(array $urls, float $timeout = 6): array
    {
        $targets = [];
        foreach ($urls as $url) {
            try {
                $targets[$url] = $this->assertAllowed($url);
            } catch (BlockedUrlException) {
                $targets[$url] = null;
            }
        }

        $allowed = array_filter($targets);
        $responses = $allowed === [] ? [] : Http::pool(function (Pool $pool) use ($allowed, $timeout) {
            foreach ($allowed as $url => $t) {
                $this->configure($pool->as($url), $t, ['Accept' => 'application/json, application/yaml, */*;q=0.5'], $timeout, self::MAX_BODY_BYTES)->get($url);
            }
        });

        $out = [];
        foreach ($targets as $url => $t) {
            $res = $responses[$url] ?? null;
            $out[$url] = $res instanceof Response ? [
                'status' => $res->status(),
                'headers' => self::flattenHeaders($res->headers()),
                'body' => $res->body(),
                'size' => strlen($res->body()),
                'url' => $url,
            ] : null;
        }

        return $out;
    }

    /**
     * @throws BlockedUrlException
     */
    public function assertAllowed(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new BlockedUrlException('Only absolute http(s) URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedUrlException('Credentials in the URL are not allowed. Use an Authorization header instead.');
        }

        $host = trim($host, '[]');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($ips === []) {
            throw new BlockedUrlException("Could not resolve host \"{$host}\".");
        }

        if (! $this->allowPrivate) {
            foreach ($ips as $ip) {
                if (! self::isPublicIp($ip)) {
                    throw new BlockedUrlException("\"{$host}\" points to a private or reserved address ({$ip}), which is blocked.");
                }
            }
        }

        return ['host' => $host, 'port' => (int) $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) — judge the embedded IPv4
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Ranges PHP's flags don't cover: CGNAT, benchmarking, IPv6 unique/link-local
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            foreach ([['100.64.0.0', 10], ['198.18.0.0', 15], ['192.0.0.0', 24]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        return ! preg_match('/^(fc|fd|fe8|fe9|fea|feb)/i', $ip);
    }

    /** Lower-cased header names, multi-values joined. */
    public static function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', (array) $values);
        }

        return $out;
    }

    public static function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('#^https?://#i', $relative)) {
            return $relative;
        }

        $p = parse_url($base);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');

        if (str_starts_with($relative, '//')) {
            return $p['scheme'].':'.$relative;
        }

        if (str_starts_with($relative, '/')) {
            return $origin.$relative;
        }

        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
        $path = $dir.$relative;

        // Collapse ./ and ../
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }

        return $origin.implode('/', $segments);
    }

    /** @return list<string> */
    private static function dnsResolver(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private function once(string $method, string $url, array $headers, ?string $body, float $timeout, int $maxBytes): Response
    {
        $target = $this->assertAllowed($url);

        $headers = array_filter(
            $headers,
            fn ($v, $k) => ! in_array(strtolower((string) $k), self::BLOCKED_HEADERS, true) && $v !== null && $v !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        $request = $this->configure(Http::createPendingRequest(), $target, $headers, $timeout, $maxBytes);

        if ($body !== null && $body !== '') {
            $request = $request->withBody($body, $headers['Content-Type'] ?? $headers['content-type'] ?? 'application/json');
        }

        return $request->send(strtoupper($method), $url);
    }

    private function configure(PendingRequest $request, array $target, array $headers, float $timeout, int $maxBytes): PendingRequest
    {
        $ipForCurl = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];

        return $request
            ->withHeaders($headers + ['User-Agent' => 'API-Detective/1.0'])
            ->connectTimeout(min(5, $timeout))
            ->timeout($timeout)
            ->withoutRedirecting()
            ->withOptions([
                'http_errors' => false,
                // Pin the connection to the address we just validated.
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ipForCurl}"]],
                'progress' => function ($downloadTotal, $downloaded) use ($maxBytes) {
                    if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                        throw new BlockedUrlException('Response is larger than '.intdiv($maxBytes, 1024 * 1024).' MB.');
                    }
                },
            ]);
    }
}
