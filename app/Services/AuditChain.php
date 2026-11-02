<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class AuditChain
{
    /** Empreinte « précédente » de la toute première entrée. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Version de l'algorithme : fait partie du contenu haché. Ne jamais la modifier sans migration. */
    public const VERSION = 1;

    /**
     * Ajoute une entrée à la chaîne. La tête de chaîne est verrouillée jusqu'à la fin de la transaction
     * englobante : les écritures d'audit sont donc strictement sérialisées.
     *
     * Règle : cet appel doit être la DERNIÈRE opération verrouillante d'une transaction.
     */
    public function append(?int $userId, string $action, string $entityType, ?int $entityId, ?array $details): AuditLog
    {
        return DB::transaction(function () use ($userId, $action, $entityType, $entityId, $details) {
            $head = $this->lockHead();
            $previous = $head->last_hash ?? self::GENESIS;

            $createdAt = now()->startOfSecond();

            // On hache exactement ce que la base conservera après un aller-retour JSON
            // (énumérations, dates et nombres normalisés).
            $details = $details === null
                ? null
                : json_decode(json_encode($details, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

            $hash = self::hashFor([
                'v' => self::VERSION,
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
            ], $previous);

            $log = new AuditLog;
            $log->forceFill([
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details,
                'previous_hash' => $previous,
                'hash' => $hash,
                'created_at' => $createdAt,
            ])->save();

            DB::table('audit_chain_head')->where('id', 1)->update([
                'last_id' => $log->id,
                'last_hash' => $hash,
                'updated_at' => $createdAt,
            ]);

            return $log;
        });
    }

    /** @param array<string, mixed> $payload */
    public static function hashFor(array $payload, string $previousHash): string
    {
        return hash('sha256', $previousHash."\n".self::canonicalJson($payload));
    }

    /** Contenu canonique d'une ligne lue en base (objet de `DB::table`). */
    public static function payloadFromRow(object $row): array
    {
        return [
            'v' => self::VERSION,
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
            'action' => (string) $row->action,
            'entity_type' => (string) $row->entity_type,
            'entity_id' => $row->entity_id === null ? null : (int) $row->entity_id,
            'details' => $row->details === null ? null : json_decode($row->details, true),
            'created_at' => (string) $row->created_at,
        ];
    }

    /**
     * Chaîne les entrées existantes qui n'ont pas encore d'empreinte (ou recommence toute la chaîne).
     *
     * @return int nombre d'entrées chaînées
     */
    public function backfill(): int
    {
        $previous = self::GENESIS;
        $count = 0;
        $lastId = null;

        DB::transaction(function () use (&$previous, &$count, &$lastId) {
            DB::table('audit_logs')->chunkById(500, function ($rows) use (&$previous, &$count, &$lastId) {
                foreach ($rows as $row) {
                    $hash = self::hashFor(self::payloadFromRow($row), $previous);

                    DB::table('audit_logs')->where('id', $row->id)->update([
                        'previous_hash' => $previous,
                        'hash' => $hash,
                    ]);

                    $previous = $hash;
                    $lastId = $row->id;
                    $count++;
                }
            });

            DB::table('audit_chain_head')->updateOrInsert(['id' => 1], [
                'last_id' => $lastId,
                'last_hash' => $count > 0 ? $previous : null,
                'updated_at' => now(),
            ]);
        });

        return $count;
    }

    /**
     * Crée la tête si elle manque, puis la verrouille. L'`upsert` vient AVANT la lecture verrouillante :
     * un `SELECT ... FOR UPDATE` sur une ligne absente, suivi d'un `INSERT`, provoque des interblocages
     * quand plusieurs transactions démarrent ensemble.
     */
    private function lockHead(): object
    {
        DB::table('audit_chain_head')->upsert(
            [['id' => 1, 'last_id' => null, 'last_hash' => null, 'updated_at' => now()]],
            ['id'],
            ['id'],
        );

        return DB::table('audit_chain_head')->where('id', 1)->lockForUpdate()->first();
    }

    private static function canonicalJson(array $payload): string
    {
        return json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /** Trie les clés des tableaux associatifs, récursivement ; l'ordre des listes est conservé. */
    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => self::canonicalize($item), $value);
    }
}