<?php

namespace App\Services\MlbbDraft;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Rone Arena MLBB API, returning compact, normalized shapes.
 */
class RoneArena
{
    private const LANES = [
        'exp lane' => 'exp', 'exp' => 'exp',
        'mid lane' => 'mid', 'mid' => 'mid',
        'gold lane' => 'gold', 'gold' => 'gold',
        'jungle' => 'jungle',
        'roam' => 'roam',
    ];

    /**
     * Every hero with roles, lanes, portrait and the official strong/weak/assist relations.
     *
     * @return list<array{id: int, name: string, head: string, roles: list<string>, lanes: list<string>, strong: list<int>, weak: list<int>, assist: list<int>}>
     */
    public function heroes(): array
    {
        $responses = Http::pool(fn (Pool $pool) => [
            $this->configure($pool->as('positions'))->get('/api/heroes/positions', ['size' => 300, 'index' => 1]),
            $this->configure($pool->as('heroes'))->get('/api/heroes', ['size' => 300, 'index' => 1]),
        ]);

        $positions = $this->records($responses['positions'], 'hero positions');
        $portraits = [];
        foreach ($this->records($responses['heroes'], 'hero list') as $r) {
            $portraits[(int) ($r['data']['hero_id'] ?? 0)] = (string) ($r['data']['hero']['data']['head'] ?? '');
        }

        $heroes = [];
        foreach ($positions as $r) {
            $id = (int) ($r['data']['hero_id'] ?? 0);
            $hero = $r['data']['hero']['data'] ?? [];
            if (! $id || empty($hero['name'])) {
                continue;
            }

            $relation = $r['data']['relation'] ?? [];

            $heroes[] = [
                'id' => $id,
                'name' => (string) $hero['name'],
                'head' => $portraits[$id] ?? (string) ($hero['smallmap'] ?? ''),
                'roles' => self::titles($hero['sortid'] ?? [], 'sort_title', fn ($t) => strtolower($t)),
                'lanes' => self::titles($hero['roadsort'] ?? [], 'road_sort_title', fn ($t) => self::LANES[strtolower($t)] ?? null),
                'strong' => self::ids($relation['strong']['target_hero_id'] ?? []),
                'weak' => self::ids($relation['weak']['target_hero_id'] ?? []),
                'assist' => self::ids($relation['assist']['target_hero_id'] ?? []),
            ];
        }

        if ($heroes === []) {
            throw new RuntimeException('Rone Arena returned no heroes.');
        }

        usort($heroes, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $heroes;
    }

    /**
     * Win / pick / ban rate of every hero for a rank bucket over the last N days.
     *
     * @return array<int, array{wr: float, pr: float, br: float}>
     */
    public function rankStats(string $rank, int $days): array
    {
        $response = $this->configure(Http::createPendingRequest())->get('/api/heroes/rank', [
            'rank' => $rank,
            'days' => (string) $days,
            'size' => 300,
            'index' => 1,
            'sort_field' => 'win_rate',
            'sort_order' => 'desc',
        ]);

        $stats = [];
        foreach ($this->records($response, 'rank statistics') as $r) {
            $d = $r['data'] ?? [];
            $id = (int) ($d['main_heroid'] ?? 0);
            if ($id) {
                $stats[$id] = [
                    'wr' => round((float) ($d['main_hero_win_rate'] ?? 0), 5),
                    'pr' => round((float) ($d['main_hero_appearance_rate'] ?? 0), 5),
                    'br' => round((float) ($d['main_hero_ban_rate'] ?? 0), 5),
                ];
            }
        }

        if ($stats === []) {
            throw new RuntimeException("Rone Arena returned no rank statistics for {$rank}.");
        }

        return $stats;
    }

    /**
     * Matchup data for several heroes at once (counters + teammate compatibility).
     *
     * For each hero:
     *  - counteredBy: heroes that win MORE when facing it (good picks into it)
     *  - beats:       heroes that win LESS when facing it (avoid picking them into it)
     *  - goodWith / badWith: teammates that raise / lower its win rate
     * Each entry is [heroId, winRateDelta] with the delta in percentage points.
     *
     * @param  list<int>  $ids
     * @return array<int, array{counteredBy: list<array{0:int,1:float}>, beats: list<array{0:int,1:float}>, goodWith: list<array{0:int,1:float}>, badWith: list<array{0:int,1:float}>}>
     */
    public function matchups(array $ids, string $rank, int $days): array
    {
        $query = ['rank' => $rank, 'days' => (string) $days];

        $responses = Http::pool(function (Pool $pool) use ($ids, $query) {
            foreach ($ids as $id) {
                $this->configure($pool->as("c{$id}"))->get("/api/heroes/{$id}/counters", $query);
                $this->configure($pool->as("m{$id}"))->get("/api/heroes/{$id}/compatibility", $query);
            }
        });

        $out = [];
        foreach ($ids as $id) {
            $counters = $this->firstRecord($responses["c{$id}"] ?? null);
            $compat = $this->firstRecord($responses["m{$id}"] ?? null);

            if ($counters === null && $compat === null) {
                continue; // leave it out so the caller can retry later
            }

            $out[$id] = [
                'counteredBy' => self::deltas($counters['sub_hero'] ?? []),
                'beats' => self::deltas($counters['sub_hero_last'] ?? []),
                'goodWith' => self::deltas($compat['sub_hero'] ?? []),
                'badWith' => self::deltas($compat['sub_hero_last'] ?? []),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    private function configure(PendingRequest $request): PendingRequest
    {
        // Fall back to the public API if the setting is missing (e.g. a config cache built before it existed).
        $base = rtrim((string) (config('services.rone_arena.url') ?: 'https://arena.rone.dev'), '/');

        $request = $request
            ->baseUrl($base)
            ->acceptJson()
            ->connectTimeout(8)
            ->timeout(25)
            // The API occasionally answers 5xx or drops a connection — retry those a few times.
            ->retry(3, fn (int $attempt) => $attempt * 1500, fn (\Throwable $e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
            ->withHeaders(['User-Agent' => 'mlbb-draft/1.0']);

        if ($token = config('services.rone_arena.token')) {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function records(mixed $response, string $what): array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            // Pooled requests hand back the exception itself when the connection fails.
            $status = match (true) {
                $response instanceof Response => 'HTTP '.$response->status(),
                $response instanceof \Throwable => 'connection failed: '.$response->getMessage(),
                default => 'no response',
            };
            throw new RuntimeException("Could not load {$what} from Rone Arena ({$status}).");
        }

        $records = $response->json('data.records');

        return is_array($records) ? $records : [];
    }

    private function firstRecord(mixed $response): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $data = $response->json('data.records.0.data');

        return is_array($data) ? $data : null;
    }

    /** Pull titles out of the API's config objects, skipping the empty-string padding it sometimes adds. */
    private static function titles(array $items, string $key, callable $map): array
    {
        $out = [];
        foreach ($items as $item) {
            $title = is_array($item) ? ($item['data'][$key] ?? null) : null;
            if (is_string($title) && $title !== '' && ($value = $map($title))) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private static function ids(array $ids): array
    {
        return array_values(array_filter(array_map('intval', $ids)));
    }

    private static function deltas(array $subs): array
    {
        $out = [];
        foreach ($subs as $s) {
            if (isset($s['heroid'], $s['increase_win_rate'])) {
                $out[] = [(int) $s['heroid'], round((float) $s['increase_win_rate'] * 100, 2)];
            }
        }

        return $out;
    }
}
