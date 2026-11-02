<?php

namespace App\Console\Commands;

use App\Services\IntegrityVerifier;
use Illuminate\Console\Command;

class StockVerify extends Command
{
    protected $signature = 'stock:verify';

    protected $description = 'Vérifie la cohérence du stock : quantités, chaîne avant/après, lots, corrections';

    public function handle(IntegrityVerifier $verifier): int
    {
        $report = $verifier->verifyStock();
        $verifier->record($report);

        if ($report->ok()) {
            $this->info("Stock cohérent : {$report->scanned['products']} produit(s) et {$report->scanned['movements']} mouvement(s) appliqué(s) vérifiés.");

            return self::SUCCESS;
        }

        $this->error("{$report->count()} anomalie(s) détectée(s) :");

        foreach (array_slice($report->messages(), 0, 50) as $message) {
            $this->line(" - {$message}");
        }

        if ($report->count() > 50) {
            $this->line(' … et '.($report->count() - 50).' autre(s).');
        }

        return self::FAILURE;
    }
}