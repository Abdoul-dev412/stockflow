<?php

namespace App\Console\Commands;

use App\Services\AnomalyDetector;
use Illuminate\Console\Command;

class DetectAnomalies extends Command
{
    protected $signature = 'anomalies:detect {--days=2 : Fenêtre de rattrapage, en jours}';

    protected $description = 'Réévalue les mouvements récents et la part des pertes pour détecter les anomalies';

    public function handle(AnomalyDetector $detector): int
    {
        $days = max(1, (int) $this->option('days'));

        $created = $detector->scan($days);

        $this->info("Détection terminée : {$created} nouvelle(s) anomalie(s).");

        return self::SUCCESS;
    }
}