<?php

namespace App\Console\Commands;

use App\Services\MlbbDraft\DraftData;
use App\Services\MlbbDraft\Ranks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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

        ['done' => $done, 'failed' => $failed] = $data->refreshAll($days);

        $rows = [];
        foreach ($done as $name => $count) {
            $rows[] = [$name, 'updated', $count];
        }
        foreach ($failed as $name => $error) {
            $rows[] = [$name, 'FAILED — kept previous data', mb_strimwidth($error, 0, 90, '…')];
        }
        $this->table(['Dataset', 'Status', 'Items / error'], $rows);

        if ($failed) {
            $this->warn(count($failed).' dataset(s) failed; the rest were refreshed. Run the command again later to retry them.');

            return self::FAILURE;
        }

        $this->info("MLBB data refreshed ({$days}-day window).");

        return self::SUCCESS;
    }
}
