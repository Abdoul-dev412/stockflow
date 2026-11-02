<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\IntegrityCheck;
use App\Models\Product;
use App\Support\IntegrityReport;
use Illuminate\Support\Facades\DB;

class IntegrityVerifier
{
    private const STORED_ISSUES = 200;

    public function __construct(private AuditLogger $audit)
    {
    }

    /**
     * Contrôle la cohérence du stock. Tout s'exécute dans une transaction de lecture :
     * toutes les requêtes voient le même instantané de la base, même si l'application écrit pendant ce temps.
     */
    public function verifyStock(): IntegrityReport
    {
        return DB::transaction(function () {
            $issues = [];

            // 1. La quantité du produit est celle du dernier mouvement appliqué (0 sans mouvement).
            foreach (DB::select(<<<'SQL'
                SELECT p.id AS product_id, p.sku, p.quantity, COALESCE(m.quantity_after, 0) AS expected
                FROM products p
                LEFT JOIN stock_movements m
                  ON m.id = (SELECT MAX(id) FROM stock_movements WHERE product_id = p.id AND status = 'applied')
                WHERE p.quantity <> COALESCE(m.quantity_after, 0)
                SQL) as $row) {
                $issues[] = $this->issue('quantity_mismatch', "product:{$row->product_id}", sprintf(
                    'Produit %s : quantité %d différente du dernier « stock après » (%d).',
                    $row->sku, $row->quantity, $row->expected,
                ));
            }

            // 2. Chaque mouvement part exactement de l'arrivée du précédent (le premier part de 0).
            foreach (DB::select(<<<'SQL'
                SELECT t.id, p.sku, t.quantity_before, COALESCE(t.prev_after, 0) AS expected
                FROM (
                    SELECT id, product_id, quantity_before, quantity_after,
                           LAG(quantity_after) OVER (PARTITION BY product_id ORDER BY id) AS prev_after
                    FROM stock_movements
                    WHERE status = 'applied'
                ) t
                JOIN products p ON p.id = t.product_id
                WHERE t.quantity_before <> COALESCE(t.prev_after, 0)
                SQL) as $row) {
                $issues[] = $this->issue('chain_break', "movement:{$row->id}", sprintf(
                    'Mouvement n° %d (produit %s) : stock avant %d, alors que le mouvement précédent laisse %d.',
                    $row->id, $row->sku, $row->quantity_before, $row->expected,
                ));
            }

            // 3. L'écart avant/après correspond au type et à la quantité du mouvement.
            foreach (DB::select(<<<'SQL'
                SELECT m.id, p.sku, m.type, m.quantity, m.quantity_before, m.quantity_after
                FROM stock_movements m
                JOIN products p ON p.id = m.product_id
                WHERE m.status = 'applied' AND (
                    m.quantity_before IS NULL OR m.quantity_after IS NULL OR NOT (
                        (m.type = 'entry'
                            AND CAST(m.quantity_after AS SIGNED) = CAST(m.quantity_before AS SIGNED) + CAST(m.quantity AS SIGNED))
                        OR (m.type = 'exit'
                            AND CAST(m.quantity_after AS SIGNED) = CAST(m.quantity_before AS SIGNED) - CAST(m.quantity AS SIGNED))
                        OR (m.type IN ('adjustment', 'correction')
                            AND ABS(CAST(m.quantity_after AS SIGNED) - CAST(m.quantity_before AS SIGNED)) = m.quantity)
                    )
                )
                SQL) as $row) {
                $issues[] = $this->issue('delta_mismatch', "movement:{$row->id}", sprintf(
                    'Mouvement n° %d (produit %s, %s de %d) : stock avant %s et après %s incohérents avec la quantité.',
                    $row->id, $row->sku, $row->type, $row->quantity, $row->quantity_before ?? '—', $row->quantity_after ?? '—',
                ));
            }

            // 4. Un produit suivi par lots a un stock égal à la somme de ses lots.
            foreach (DB::select(<<<'SQL'
                SELECT p.id AS product_id, p.sku, p.quantity, COALESCE(SUM(l.quantity_remaining), 0) AS lots_sum
                FROM products p
                LEFT JOIN stock_lots l ON l.product_id = p.id
                WHERE p.tracks_lots = 1
                GROUP BY p.id, p.sku, p.quantity
                HAVING p.quantity <> COALESCE(SUM(l.quantity_remaining), 0)
                SQL) as $row) {
                $issues[] = $this->issue('lot_sum_mismatch', "product:{$row->product_id}", sprintf(
                    'Produit %s : quantité %d différente de la somme de ses lots (%d).',
                    $row->sku, $row->quantity, $row->lots_sum,
                ));
            }

            // 5. Un produit non suivi par lots n'a pas de lots.
            foreach (DB::select(<<<'SQL'
                SELECT p.id AS product_id, p.sku, COUNT(*) AS lots
                FROM stock_lots l
                JOIN products p ON p.id = l.product_id
                WHERE p.tracks_lots = 0
                GROUP BY p.id, p.sku
                SQL) as $row) {
                $issues[] = $this->issue('lots_on_untracked_product', "product:{$row->product_id}", sprintf(
                    'Produit %s : non suivi par lots, mais possède %d lot(s).',
                    $row->sku, $row->lots,
                ));
            }

            // 6. Un lot contient exactement la somme de ses répartitions.
            foreach (DB::select(<<<'SQL'
                SELECT l.id, l.lot_number, p.sku, l.quantity_remaining, COALESCE(SUM(a.quantity), 0) AS allocated
                FROM stock_lots l
                JOIN products p ON p.id = l.product_id
                LEFT JOIN movement_lot_allocations a ON a.lot_id = l.id
                GROUP BY l.id, l.lot_number, p.sku, l.quantity_remaining
                HAVING l.quantity_remaining <> COALESCE(SUM(a.quantity), 0)
                SQL) as $row) {
                $issues[] = $this->issue('lot_allocation_mismatch', "lot:{$row->id}", sprintf(
                    'Lot %s du produit %s : %d restant(s), mais %d dans les répartitions.',
                    $row->lot_number, $row->sku, $row->quantity_remaining, $row->allocated,
                ));
            }

            // 7. Pour un produit à lots, la répartition d'un mouvement égale sa variation de stock.
            foreach (DB::select(<<<'SQL'
                SELECT m.id, p.sku,
                       CAST(m.quantity_after AS SIGNED) - CAST(m.quantity_before AS SIGNED) AS delta,
                       COALESCE(SUM(a.quantity), 0) AS allocated
                FROM stock_movements m
                JOIN products p ON p.id = m.product_id AND p.tracks_lots = 1
                LEFT JOIN movement_lot_allocations a ON a.movement_id = m.id
                WHERE m.status = 'applied'
                GROUP BY m.id, p.sku, m.quantity_before, m.quantity_after
                HAVING delta <> allocated
                SQL) as $row) {
                $issues[] = $this->issue('movement_allocation_mismatch', "movement:{$row->id}", sprintf(
                    'Mouvement n° %d (produit %s) : variation de %d, mais %d réparti sur les lots.',
                    $row->id, $row->sku, $row->delta, $row->allocated,
                ));
            }

            // 8. Une correction est l'inverse exact d'un autre mouvement appliqué du même produit.
            foreach (DB::select(<<<'SQL'
                SELECT c.id, c.corrects_movement_id AS original_id
                FROM stock_movements c
                LEFT JOIN stock_movements o ON o.id = c.corrects_movement_id
                WHERE c.type = 'correction' AND c.status = 'applied' AND (
                    o.id IS NULL
                    OR o.product_id <> c.product_id
                    OR o.status <> 'applied'
                    OR o.type = 'correction'
                    OR (CAST(c.quantity_after AS SIGNED) - CAST(c.quantity_before AS SIGNED))
                       <> -(CAST(o.quantity_after AS SIGNED) - CAST(o.quantity_before AS SIGNED))
                )
                SQL) as $row) {
                $issues[] = $this->issue('correction_mismatch', "movement:{$row->id}", sprintf(
                    "Correction n° %d : ne correspond pas à l'inverse du mouvement n° %s.",
                    $row->id, $row->original_id ?? '—',
                ));
            }

            return new IntegrityReport('stock', [
                'products' => Product::withTrashed()->count(),
                'movements' => (int) DB::table('stock_movements')->where('status', 'applied')->count(),
            ], $issues);
        });
    }

    /**
     * Parcourt toute la chaîne d'audit : empreinte de chaque entrée, chaînage, tête de chaîne
     * et, si elle est fournie, ancre externe au format « id:empreinte ».
     */
    public function verifyAuditChain(?string $anchor = null): IntegrityReport
    {
        return DB::transaction(function () use ($anchor) {
            $issues = [];
            $previous = AuditChain::GENESIS;
            $count = 0;
            $lastId = null;
            $lastHash = null;
            $anchorFound = null;
            [$anchorId, $anchorHash] = $anchor !== null ? explode(':', $anchor, 2) : [null, null];

            DB::table('audit_logs')->chunkById(1000, function ($rows) use (
                &$issues, &$previous, &$count, &$lastId, &$lastHash, &$anchorFound, $anchorId
            ) {
                foreach ($rows as $row) {
                    $count++;

                    if ($row->hash === null || $row->previous_hash === null) {
                        $issues[] = $this->issue('missing_hash', "audit:{$row->id}",
                            "Entrée d'audit n° {$row->id} : empreinte absente.");
                    } else {
                        if ($row->previous_hash !== $previous) {
                            $issues[] = $this->issue('chain_break', "audit:{$row->id}",
                                "Entrée d'audit n° {$row->id} : chaînage rompu (une entrée précédente a été supprimée, insérée ou modifiée).");
                        }

                        $expected = AuditChain::hashFor(AuditChain::payloadFromRow($row), $row->previous_hash);

                        if (! hash_equals($expected, $row->hash)) {
                            $issues[] = $this->issue('hash_mismatch', "audit:{$row->id}",
                                "Entrée d'audit n° {$row->id} : le contenu ne correspond plus à son empreinte (ligne modifiée).");
                        }
                    }

                    $previous = $row->hash ?? $previous;
                    $lastId = (int) $row->id;
                    $lastHash = $row->hash;

                    if ($anchorId !== null && (int) $row->id === (int) $anchorId) {
                        $anchorFound = $row->hash;
                    }
                }
            });

            $head = DB::table('audit_chain_head')->where('id', 1)->first();

            if ($count === 0) {
                if ($head !== null && $head->last_id !== null) {
                    $issues[] = $this->issue('head_mismatch', 'audit:head',
                        "Le journal est vide alors que la tête de chaîne indique l'entrée n° {$head->last_id} : des entrées ont été supprimées.");
                }
            } elseif ($head === null || (int) $head->last_id !== $lastId || $head->last_hash !== $lastHash) {
                $issues[] = $this->issue('head_mismatch', 'audit:head',
                    'La tête de chaîne ne correspond pas à la dernière entrée du journal (entrées finales supprimées ou ajoutées hors application).');
            }

            if ($anchorId !== null && $anchorFound !== $anchorHash) {
                $issues[] = $this->issue('anchor_mismatch', "audit:anchor:{$anchorId}",
                    "L'ancre de l'entrée n° {$anchorId} ne correspond pas : l'entrée a été modifiée ou supprimée.");
            }

            return new IntegrityReport('audit', ['audit_rows' => $count], $issues, [
                'anchor' => $lastId !== null ? "{$lastId}:{$lastHash}" : null,
            ]);
        });
    }

    /**
     * Conserve le résultat. Une anomalie n'est tracée dans l'audit qu'à son apparition (ou si son contenu change),
     * pas à chaque exécution quotidienne tant qu'elle persiste.
     */
    public function record(IntegrityReport $report): IntegrityCheck
    {
        $previous = IntegrityCheck::lastOf($report->type);
        $signature = $report->ok() ? null : $report->signature();

        $check = IntegrityCheck::create([
            'type' => $report->type,
            'status' => $report->ok() ? 'ok' : 'failed',
            'issues_count' => $report->count(),
            'signature' => $signature,
            'details' => [
                'scanned' => $report->scanned,
                'meta' => $report->meta,
                'examples' => array_slice($report->messages(), 0, 5),
                'issues' => array_slice($report->issues, 0, self::STORED_ISSUES),
            ],
            'checked_at' => now(),
        ]);

        if (! $report->ok() && ($previous === null || $previous->signature !== $signature)) {
            $this->audit->record(null, AuditAction::IntegrityCheckFailed, 'integrity', [
                'check' => $report->type,
                'issues' => $report->count(),
                'examples' => array_slice($report->messages(), 0, 5),
            ]);
        }

        return $check;
    }

    /** @return array{check: string, ref: string, message: string} */
    private function issue(string $check, string $ref, string $message): array
    {
        return ['check' => $check, 'ref' => $ref, 'message' => $message];
    }
}