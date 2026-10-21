<?php

namespace App\Services\MlbbDraft;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Daily snapshots of Rone Arena data, stored as JSON on the local disk.
 *
 * A snapshot older than MAX_AGE_HOURS is refetched on the next request (and the
 * scheduled `mlbb:refresh` command refreshes them every morning anyway). If the API
 * is down, the last good snapshot is served rather than failing the draft.
 */
class DraftData
{
    public const MAX_AGE_HOURS = 24;

    private const DIR = 'mlbb';

    public function __construct(private RoneArena $api) {}

    public function heroes(bool $force = false): array
    {
        return $this->remember('heroes.json', $force, fn () => $this->api->heroes());
    }

    public function stats(string $rank, int $days, bool $force = false): array
    {
        return $this->remember("stats/{$rank}-{$days}.json", $force, fn () => $this->api->rankStats($rank, $days));
    }

    /**
     * Matchups for the given heroes; only missing or stale ones hit the API (in one batch).
     *
     * @param  list<int>  $ids
     */
    public function matchups(array $ids, string $rank, int $days): array
    {
        $out = [];
        $missing = [];

        foreach ($ids as $id) {
            $snap = $this->read($this->matchupPath($id, $rank, $days));
            if ($snap) {
                $out[$id] = $snap['data'];
            }
            if (! $snap || $this->isStale($snap)) {
                $missing[] = $id;
            }
        }

        if ($missing) {
            try {
                foreach ($this->api->matchups($missing, $rank, $days) as $id => $data) {
                    $this->write($this->matchupPath($id, $rank, $days), $data);
                    $out[$id] = $data;
                }
            } catch (Throwable $e) {
                Log::warning('mlbb-draft: matchup refresh failed', ['error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /** When the stats for this rank/window were last pulled from the API. */
    public function statsUpdatedAt(string $rank, int $days): ?string
    {
        return $this->read("stats/{$rank}-{$days}.json")['fetchedAt'] ?? null;
    }

    /**
     * Refresh everything the draft board needs up front. Called daily by the scheduler.
     *
     * Each dataset is fetched on its own: if one fails (e.g. the API answers 503 for a rank),
     * the others still refresh and the failed one keeps its last good snapshot.
     *
     * @return array{done: array<string, int>, failed: array<string, string>}
     */
    public function refreshAll(int $days = Ranks::DEFAULT_WINDOW): array
    {
        $jobs = ['heroes' => fn () => $this->api->heroes()] + collect(Ranks::API_RANKS)
            ->mapWithKeys(fn ($rank) => ["stats:{$rank}" => fn () => $this->api->rankStats($rank, $days)])
            ->all();

        $done = [];
        $failed = [];
        foreach ($jobs as $name => $fetch) {
            try {
                $data = $fetch();
                $this->write($name === 'heroes' ? 'heroes.json' : 'stats/'.substr($name, 6)."-{$days}.json", $data);
                $done[$name] = count($data);
            } catch (Throwable $e) {
                $failed[$name] = $e->getMessage();
                Log::warning("mlbb-draft: refresh of {$name} failed", ['error' => $e->getMessage()]);
            }
        }

        // Matchups refresh lazily (they're per hero); drop yesterday's so nothing stale lingers.
        foreach ($this->disk()->allFiles(self::DIR.'/matchups') as $file) {
            if ($this->disk()->lastModified($file) < now()->subHours(self::MAX_AGE_HOURS)->getTimestamp()) {
                $this->disk()->delete($file);
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    // ---------------------------------------------------------------- storage

    private function remember(string $path, bool $force, callable $fetch): array
    {
        $snap = $this->read($path);

        if (! $force && $snap && ! $this->isStale($snap)) {
            return $snap['data'];
        }

        try {
            $data = $fetch();
            $this->write($path, $data);

            return $data;
        } catch (Throwable $e) {
            if ($snap) {
                Log::warning("mlbb-draft: serving stale {$path}", ['error' => $e->getMessage()]);

                return $snap['data'];
            }

            throw $e;
        }
    }

    private function isStale(array $snap): bool
    {
        return Carbon::parse($snap['fetchedAt'])->lt(now()->subHours(self::MAX_AGE_HOURS));
    }

    private function read(string $path): ?array
    {
        $full = self::DIR.'/'.$path;
        if (! $this->disk()->exists($full)) {
            return null;
        }

        $snap = json_decode((string) $this->disk()->get($full), true);

        return is_array($snap) && isset($snap['fetchedAt'], $snap['data']) ? $snap : null;
    }

    private function write(string $path, array $data): void
    {
        $this->disk()->put(self::DIR.'/'.$path, json_encode(['fetchedAt' => now()->toIso8601String(), 'data' => $data], JSON_UNESCAPED_SLASHES));
    }

    private function matchupPath(int $id, string $rank, int $days): string
    {
        return "matchups/{$rank}-{$days}/{$id}.json";
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
