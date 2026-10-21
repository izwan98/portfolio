<?php

namespace App\Console\Commands;

use App\Services\MlbbDraft\DraftData;
use App\Services\MlbbDraft\Ranks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('mlbb:refresh {--days=7 : Stats window in days (1, 3, 7, 15 or 30)}')]
#[Description('Pull fresh MLBB hero win/pick/ban rates for every rank from Rone Arena')]
class RefreshMlbbData extends Command
{
    public function handle(DraftData $data): int
    {
        $days = (int) $this->option('days');
        if (! in_array($days, Ranks::WINDOWS, true)) {
            $this->error('--days must be one of: '.implode(', ', Ranks::WINDOWS));

            return self::INVALID;
        }

        try {
            $done = $data->refreshAll($days);
        } catch (Throwable $e) {
            $this->error('Refresh failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Dataset', 'Items'], collect($done)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info("MLBB data refreshed ({$days}-day window).");

        return self::SUCCESS;
    }
}
