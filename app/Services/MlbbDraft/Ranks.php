<?php

namespace App\Services\MlbbDraft;

/**
 * In-game ranks → the stats bucket Rone Arena publishes, and the ranked draft rules.
 *
 * Ban counts per team follow the 2026 ranked rules (Epic 3, Legend 4, Mythic+ 5);
 * below Epic there is no draft, so the tool runs in "picks only" mode.
 */
class Ranks
{
    /** Stats buckets the API accepts for `rank`. */
    public const API_RANKS = ['all', 'epic', 'legend', 'mythic', 'honor', 'glory'];

    /** Rolling windows (days) the API accepts. */
    public const WINDOWS = [1, 3, 7, 15, 30];

    public const DEFAULT_WINDOW = 7;

    public static function all(): array
    {
        return [
            ['key' => 'warrior', 'label' => 'Warrior', 'api' => 'all', 'bans' => 0, 'template' => 'ranked-none'],
            ['key' => 'elite', 'label' => 'Elite', 'api' => 'all', 'bans' => 0, 'template' => 'ranked-none'],
            ['key' => 'master', 'label' => 'Master', 'api' => 'all', 'bans' => 0, 'template' => 'ranked-none'],
            ['key' => 'grandmaster', 'label' => 'Grandmaster', 'api' => 'all', 'bans' => 0, 'template' => 'ranked-none'],
            ['key' => 'epic', 'label' => 'Epic', 'api' => 'epic', 'bans' => 3, 'template' => 'ranked-6'],
            ['key' => 'legend', 'label' => 'Legend', 'api' => 'legend', 'bans' => 4, 'template' => 'ranked-8'],
            ['key' => 'mythic', 'label' => 'Mythic', 'api' => 'mythic', 'bans' => 5, 'template' => 'tournament'],
            ['key' => 'honor', 'label' => 'Mythical Honor', 'api' => 'honor', 'bans' => 5, 'template' => 'tournament'],
            ['key' => 'glory', 'label' => 'Mythical Glory', 'api' => 'glory', 'bans' => 5, 'template' => 'tournament'],
            ['key' => 'immortal', 'label' => 'Mythical Immortal', 'api' => 'glory', 'bans' => 5, 'template' => 'tournament'],
        ];
    }
}
