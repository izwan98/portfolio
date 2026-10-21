<?php

namespace App\Services\MlbbDraft;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Pick & ban templates: the order of every ban and pick turn, per team and player.
 *
 * Built-in templates cover the ranked formats and a tournament draft. Anyone can add
 * community templates, which are shared with every visitor; only the creator (holding
 * the secret key returned on creation) can edit or delete theirs.
 *
 * Stored as one JSON file on the local disk — no database needed.
 */
class DraftTemplates
{
    public const MAX_COMMUNITY = 500;

    public const MAX_STEPS = 30;

    private const FILE = 'mlbb/templates.json';

    /** @return list<array> */
    public static function builtIns(): array
    {
        // `newRound` marks a ban that opens a new ban round (e.g. ranked round 2, after round 1 is revealed).
        $ban = fn (string $team, int $player, bool $newRound = false) => ['action' => 'ban', 'team' => $team, 'player' => $player] + ($newRound ? ['newRound' => true] : []);
        $pick = fn (string $team, int $player) => ['action' => 'pick', 'team' => $team, 'player' => $player];

        // Ranked picks: 1-2-2-2-2-1 snake.
        $snakePicks = [
            $pick('first', 1), $pick('second', 1), $pick('second', 2), $pick('first', 2), $pick('first', 3),
            $pick('second', 3), $pick('second', 4), $pick('first', 4), $pick('first', 5), $pick('second', 5),
        ];
        // Ranked round-1 bans (players 1–3), snaking from the first-pick team.
        $round1 = [$ban('first', 1), $ban('second', 1), $ban('second', 2), $ban('first', 2), $ban('first', 3), $ban('second', 3)];

        $make = fn (string $id, string $name, string $description, array $steps, bool $blind) => [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'author' => 'MLBB Draft',
            'blindBans' => $blind,
            'steps' => $steps,
            'builtIn' => true,
        ];

        return [
            $make('ranked-none', 'Ranked · No bans', 'Warrior to Grandmaster: picks only, 1-2-2-2-2-1.', $snakePicks, false),
            $make('ranked-6', 'Ranked · 6 bans (Epic)', 'Players 1–3 of each team ban, then 1-2-2-2-2-1 picks.', [...$round1, ...$snakePicks], true),
            // Legend: two blind ban rounds of 2 per team (round 2 after round 1 is revealed), then snake picks.
            $make('ranked-8', 'Ranked · 8 bans (Legend)', 'Round 1: each team blind-bans 2 (players 1–2). Round 2: each team blind-bans 2 more (players 3–4). Then 1-2-2-2-2-1 picks.', [
                $ban('first', 1), $ban('first', 2), $ban('second', 1), $ban('second', 2),
                $ban('first', 3, true), $ban('first', 4), $ban('second', 3), $ban('second', 4),
                ...$snakePicks,
            ], true),
            // Mythic and above use the MPL draft: bans and picks interleave, and bans are open.
            $make('tournament', 'Mythic+ · MPL style (10 bans)', 'Mythic and above, like MPL: 3 bans each, 3 picks each, 2 more bans each (second team starts), then the last 2 picks each. Bans are open.', [
                $ban('first', 1), $ban('second', 1), $ban('first', 2), $ban('second', 2), $ban('first', 3), $ban('second', 3),
                $pick('first', 1), $pick('second', 1), $pick('second', 2), $pick('first', 2), $pick('first', 3), $pick('second', 3),
                $ban('second', 4), $ban('first', 4), $ban('second', 5), $ban('first', 5),
                $pick('second', 4), $pick('first', 4), $pick('first', 5), $pick('second', 5),
            ], false),
        ];
    }

    /** Built-ins first, then community templates (newest first). Keys are never included. */
    public function all(): array
    {
        $community = array_map(fn ($t) => self::public($t), array_reverse($this->read()));

        return [...self::builtIns(), ...$community];
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }

        return null;
    }

    /** @return array{0: array, 1: string} the template and its secret edit key */
    public function create(array $input): array
    {
        $clean = self::validate($input);
        $key = Str::random(40);

        $template = $this->withLock(function (array $all) use ($clean, $key) {
            if (count($all) >= self::MAX_COMMUNITY) {
                throw ValidationException::withMessages(['name' => 'The template library is full. Delete an old template first.']);
            }

            $template = [
                'id' => Str::slug(Str::limit($clean['name'], 40, '')).'-'.Str::lower(Str::random(6)),
                ...$clean,
                'keyHash' => hash('sha256', $key),
                'createdAt' => now()->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
            ];
            $all[] = $template;

            return [$all, $template];
        });

        return [self::public($template), $key];
    }

    public function update(string $id, array $input, ?string $key): array
    {
        $clean = self::validate($input);

        return self::public($this->withLock(function (array $all) use ($id, $clean, $key) {
            $i = $this->authorize($all, $id, $key);
            $all[$i] = [...$all[$i], ...$clean, 'updatedAt' => now()->toIso8601String()];

            return [$all, $all[$i]];
        }));
    }

    public function delete(string $id, ?string $key): void
    {
        $this->withLock(function (array $all) use ($id, $key) {
            $i = $this->authorize($all, $id, $key);
            array_splice($all, $i, 1);

            return [$all, null];
        });
    }

    /**
     * Validate and normalize a template.
     *
     * Every team must pick exactly 5 times (each player once); each player may ban at most once.
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $errors = [];

        $name = trim(strip_tags((string) ($input['name'] ?? '')));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 60) {
            $errors['name'][] = 'Give the template a name of 3–60 characters.';
        }

        $description = trim(strip_tags((string) ($input['description'] ?? '')));
        if (mb_strlen($description) > 200) {
            $errors['description'][] = 'Keep the description under 200 characters.';
        }

        $author = trim(strip_tags((string) ($input['author'] ?? '')));
        if (mb_strlen($author) > 40) {
            $errors['author'][] = 'Keep the author name under 40 characters.';
        }

        $steps = [];
        $raw = $input['steps'] ?? null;
        if (! is_array($raw) || count($raw) < 1 || count($raw) > self::MAX_STEPS) {
            $errors['steps'][] = 'A template needs between 1 and '.self::MAX_STEPS.' turns.';
            $raw = [];
        }

        foreach (array_values($raw) as $n => $step) {
            $action = $step['action'] ?? null;
            $team = $step['team'] ?? null;
            $player = filter_var($step['player'] ?? null, FILTER_VALIDATE_INT);

            if (! in_array($action, ['ban', 'pick'], true) || ! in_array($team, ['first', 'second'], true) || $player === false || $player < 1 || $player > 5) {
                $errors['steps'][] = 'Turn '.($n + 1).' is incomplete.';

                continue;
            }

            $steps[] = ['action' => $action, 'team' => $team, 'player' => $player]
                + ($action === 'ban' && filter_var($step['newRound'] ?? false, FILTER_VALIDATE_BOOL) ? ['newRound' => true] : []);
        }

        foreach (self::sequenceProblems($steps) as $problem) {
            $errors['steps'][] = $problem;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $name,
            'description' => $description,
            'author' => $author,
            'blindBans' => filter_var($input['blindBans'] ?? false, FILTER_VALIDATE_BOOL),
            'steps' => $steps,
        ];
    }

    /** Human-readable problems with a turn sequence (empty when valid). */
    public static function sequenceProblems(array $steps): array
    {
        $problems = [];

        foreach (['first' => 'First team', 'second' => 'Second team'] as $team => $label) {
            foreach (['pick', 'ban'] as $action) {
                $players = array_column(array_filter($steps, fn ($s) => $s['team'] === $team && $s['action'] === $action), 'player');
                $dupes = array_unique(array_diff_assoc($players, array_unique($players)));
                foreach ($dupes as $p) {
                    $problems[] = "{$label}: player {$p} has more than one {$action}.";
                }
                if ($action === 'pick' && count(array_unique($players)) !== 5) {
                    $problems[] = "{$label} must pick 5 times (one per player) — it has ".count($players).'.';
                }
            }
        }

        return $problems;
    }

    // ---------------------------------------------------------------- storage

    private static function public(array $t): array
    {
        unset($t['keyHash']);

        return $t + ['builtIn' => false];
    }

    private function authorize(array $all, string $id, ?string $key): int
    {
        foreach ($all as $i => $t) {
            if ($t['id'] === $id) {
                if (! $key || ! hash_equals($t['keyHash'], hash('sha256', $key))) {
                    throw new TemplateForbidden('Only the person who created this template can change it.');
                }

                return $i;
            }
        }

        throw new TemplateNotFound('That template does not exist (built-in templates cannot be changed).');
    }

    private function read(): array
    {
        $disk = Storage::disk('local');
        $data = $disk->exists(self::FILE) ? json_decode((string) $disk->get(self::FILE), true) : [];

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Read-modify-write under an exclusive file lock so concurrent saves don't clobber each other.
     *
     * @param  callable(array): array{0: array, 1: mixed}  $change
     */
    private function withLock(callable $change): mixed
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('mlbb');
        $handle = fopen($disk->path('mlbb/templates.lock'), 'c');

        if (! $handle || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the template store.');
        }

        try {
            [$all, $result] = $change($this->read());
            $disk->put(self::FILE, json_encode(array_values($all), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
