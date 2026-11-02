<?php

namespace App\Console\Commands;

use App\Services\IntegrityVerifier;
use Illuminate\Console\Command;

class AuditVerify extends Command
{
    protected $signature = 'audit:verify {--anchor= : Ancre « id:empreinte » conservée hors de la base}';

    protected $description = "Vérifie la chaîne de hachage du journal d'audit (lignes modifiées, supprimées ou ajoutées)";

    public function handle(IntegrityVerifier $verifier): int
    {
        $anchor = $this->option('anchor');

        if ($anchor !== null && ! preg_match('/^\d+:[0-9a-f]{64}$/', $anchor)) {
            $this->error("L'ancre doit avoir le format « id:empreinte » (empreinte SHA-256 de 64 caractères hexadécimaux).");

            return self::INVALID;
        }

        $report = $verifier->verifyAuditChain($anchor);
        $verifier->record($report);

        if ($report->ok()) {
            $this->info("Journal d'audit intact : {$report->scanned['audit_rows']} entrée(s) vérifiée(s).");
        } else {
            $this->error("{$report->count()} anomalie(s) détectée(s) :");

            foreach (array_slice($report->messages(), 0, 50) as $message) {
                $this->line(" - {$message}");
            }

            if ($report->count() > 50) {
                $this->line(' … et '.($report->count() - 50).' autre(s).');
            }
        }

        if ($report->meta['anchor'] !== null) {
            $this->line("Ancre actuelle (à conserver hors de la base) : {$report->meta['anchor']}");
        }

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }
}