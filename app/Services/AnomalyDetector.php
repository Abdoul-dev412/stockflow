<?php

namespace App\Services;

use App\Enums\AnomalyRule;
use App\Enums\AnomalyStatus;
use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Anomaly;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

class AnomalyDetector
{
    /** Motifs de sortie qui constituent une perte (R3). */
    private const LOSS_REASONS = [
        MovementReason::Loss, MovementReason::Breakage, MovementReason::Expiry, MovementReason::Theft,
    ];

    /** Sorties jugées par R4 : les retraits de pertes et de péremptions sur un produit dormant sont attendus. */
    private const DORMANT_CHECKED_REASONS = [
        MovementReason::Sale, MovementReason::InternalUse, MovementReason::Theft, MovementReason::Other,
    ];

    /** Seules ces sorties comptent comme activité d'un produit (elles remettent le compteur de dormance à zéro). */
    private const CONSUMPTION_REASONS = [MovementReason::Sale, MovementReason::InternalUse];

    /**
     * Évalue les règles déclenchées par un mouvement appliqué.
     *
     * @return int nombre d'anomalies créées
     */
    public function evaluate(StockMovement $movement): int
    {
        if ($movement->status !== MovementStatus::Applied) {
            return 0;
        }

        $movement->loadMissing(['product', 'user']);
        $product = $movement->product;

        if ($product === null) {
            return 0;
        }

        $created = 0;

        if ($movement->type === MovementType::Exit) {
            $created += (int) $this->ruleSpike($movement, $product);
            $created += (int) $this->ruleDormant($movement, $product);

            if (in_array($movement->reason_code, self::LOSS_REASONS, true)) {
                $created += (int) $this->ruleLosses($product, $movement->occurred_at, $movement);
            }
        }

        if ($movement->type === MovementType::Adjustment && $movement->signedQuantity() < 0) {
            $created += (int) $this->ruleNegativeAdjustments($movement, $product);
        }

        return $created;
    }

    /**
     * Rattrapage : réévalue les mouvements enregistrés depuis $days jours, puis la part des pertes
     * de tous les produits concernés. Sans danger à répéter (idempotent).
     *
     * @return int nombre d'anomalies créées
     */
    public function scan(int $days = 2): int
    {
        $created = 0;

        StockMovement::query()
            ->where('status', MovementStatus::Applied->value)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->chunkById(500, function ($movements) use (&$created) {
                foreach ($movements as $movement) {
                    $created += $this->evaluate($movement);
                }
            });

        $window = (int) config('stockflow.anomalies.window_days');

        $productIds = StockMovement::query()
            ->where('type', MovementType::Exit->value)
            ->where('status', MovementStatus::Applied->value)
            ->whereIn('reason_code', array_map(fn (MovementReason $reason) => $reason->value, self::LOSS_REASONS))
            ->where('occurred_at', '>=', now()->subDays($window))
            ->distinct()
            ->pluck('product_id');

        foreach (Product::query()->whereIn('id', $productIds)->get() as $product) {
            $created += (int) $this->ruleLosses($product, now());
        }

        return $created;
    }

    // ------------------------------------------------------------------ R1

    private function ruleSpike(StockMovement $movement, Product $product): bool
    {
        if ($movement->reason_code === MovementReason::Expiry) {
            return false;
        }

        $window = (int) config('stockflow.anomalies.window_days');
        $at = $movement->occurred_at;

        $sample = $this->appliedExits($product->id)
            ->where('id', '<>', $movement->id)
            ->where('reason_code', '<>', MovementReason::Expiry->value)
            ->whereBetween('occurred_at', [$at->copy()->subDays($window), $at])
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->pluck('quantity')
            ->map(fn ($quantity) => (int) $quantity)
            ->sort()
            ->values();

        if ($sample->count() < (int) config('stockflow.anomalies.r1_min_sample')) {
            return false;
        }

        $median = $this->median($sample);
        $multiplier = (float) config('stockflow.anomalies.r1_multiplier');

        if ($movement->quantity <= $multiplier * $median) {
            return false;
        }

        return $this->flag(AnomalyRule::Spike, "R1:movement:{$movement->id}", [
            'product_id' => $product->id,
            'user_id' => $movement->user_id,
            'movement_id' => $movement->id,
            'occurred_at' => $at,
            'message' => sprintf(
                'Sortie de %d %s sur %s : plus de %s fois la sortie médiane (%s) des %d derniers jours.',
                $movement->quantity, $product->unit, $product->sku,
                $this->number($multiplier), $this->number($median), $window,
            ),
            'details' => [
                'quantity' => $movement->quantity,
                'median' => $median,
                'multiplier' => $multiplier,
                'sample' => $sample->count(),
                'window_days' => $window,
            ],
        ]);
    }

    // ------------------------------------------------------------------ R2

    private function ruleNegativeAdjustments(StockMovement $movement, Product $product): bool
    {
        $days = (int) config('stockflow.anomalies.r2_days');
        $threshold = (int) config('stockflow.anomalies.r2_count');
        $at = $movement->occurred_at;
        $from = $at->copy()->subDays($days);

        $count = StockMovement::query()
            ->where('user_id', $movement->user_id)
            ->where('type', MovementType::Adjustment->value)
            ->where('status', MovementStatus::Applied->value)
            ->whereDoesntHave('correction')
            ->whereColumn('quantity_after', '<', 'quantity_before')
            ->whereBetween('occurred_at', [$from, $at])
            ->count();

        if ($count < $threshold) {
            return false;
        }

        // Une seule anomalie par série : tant qu'une anomalie récente existe pour cet utilisateur, on ne répète pas.
        $alreadyFlagged = Anomaly::query()
            ->where('rule_code', AnomalyRule::RepeatedNegativeAdjustments->value)
            ->where('user_id', $movement->user_id)
            ->where('occurred_at', '>=', $from)
            ->exists();

        if ($alreadyFlagged) {
            return false;
        }

        return $this->flag(AnomalyRule::RepeatedNegativeAdjustments, "R2:movement:{$movement->id}", [
            'product_id' => $product->id,
            'user_id' => $movement->user_id,
            'movement_id' => $movement->id,
            'occurred_at' => $at,
            'message' => sprintf(
                '%s a enregistré %d ajustements négatifs en %d jours (dernier : produit %s).',
                $movement->user->name, $count, $days, $product->sku,
            ),
            'details' => ['count' => $count, 'days' => $days],
        ]);
    }

    // ------------------------------------------------------------------ R3

    /** Part des pertes dans les sorties d'un produit, observée à la date $asOf. */
    public function ruleLosses(Product $product, CarbonInterface $asOf, ?StockMovement $trigger = null): bool
    {
        $window = (int) config('stockflow.anomalies.window_days');
        $percent = (float) config('stockflow.anomalies.r3_percent');
        $minExits = (int) config('stockflow.anomalies.r3_min_exits');

        $lossValues = array_map(fn (MovementReason $reason) => $reason->value, self::LOSS_REASONS);
        $placeholders = implode(',', array_fill(0, count($lossValues), '?'));

        $row = $this->appliedExits($product->id)
            ->whereBetween('occurred_at', [$asOf->copy()->subDays($window), $asOf])
            ->selectRaw(
                "COUNT(*) as exits,
                 COALESCE(SUM(quantity), 0) as total,
                 COALESCE(SUM(CASE WHEN reason_code IN ({$placeholders}) THEN quantity ELSE 0 END), 0) as lost",
                $lossValues,
            )
            ->toBase()
            ->first();

        $exits = (int) $row->exits;
        $total = (int) $row->total;
        $lost = (int) $row->lost;

        if ($exits < $minExits || $total === 0) {
            return false;
        }

        $share = $lost / $total * 100;

        if ($share <= $percent) {
            return false;
        }

        // Une seule anomalie par produit et par fenêtre d'observation.
        $alreadyFlagged = Anomaly::query()
            ->where('rule_code', AnomalyRule::LossShare->value)
            ->where('product_id', $product->id)
            ->where('occurred_at', '>=', $asOf->copy()->subDays($window))
            ->exists();

        if ($alreadyFlagged) {
            return false;
        }

        return $this->flag(AnomalyRule::LossShare, "R3:product:{$product->id}:{$asOf->format('Y-m-d')}", [
            'product_id' => $product->id,
            'user_id' => null,
            'movement_id' => $trigger?->id,
            'occurred_at' => $asOf,
            'message' => sprintf(
                'Produit %s : %s %% des sorties des %d derniers jours sont des pertes, casses, vols ou péremptions (%d sur %d %s).',
                $product->sku, $this->number($share, 1), $window, $lost, $total, $product->unit,
            ),
            'details' => [
                'percent' => round($share, 1),
                'lost' => $lost,
                'total' => $total,
                'exits' => $exits,
                'window_days' => $window,
            ],
        ]);
    }

    // ------------------------------------------------------------------ R4

    private function ruleDormant(StockMovement $movement, Product $product): bool
    {
        if (! in_array($movement->reason_code, self::DORMANT_CHECKED_REASONS, true)) {
            return false;
        }

        $threshold = (int) config('stockflow.anomalies.r4_dormant_days');
        $at = $movement->occurred_at;

        $lastConsumption = $this->appliedExits($product->id)
            ->whereIn('reason_code', array_map(fn (MovementReason $reason) => $reason->value, self::CONSUMPTION_REASONS))
            ->where('id', '<>', $movement->id)
            ->where('occurred_at', '<=', $at)
            ->max('occurred_at');

        // Sans vente ni consommation passée, la référence est le premier mouvement du produit.
        $reference = $lastConsumption ?? StockMovement::query()
            ->where('product_id', $product->id)
            ->where('status', MovementStatus::Applied->value)
            ->where('id', '<>', $movement->id)
            ->min('occurred_at');

        if ($reference === null) {
            return false;
        }

        $idleDays = (int) floor(Carbon::parse($reference)->diffInDays($at, false));

        if ($idleDays < $threshold) {
            return false;
        }

        return $this->flag(AnomalyRule::DormantProduct, "R4:movement:{$movement->id}", [
            'product_id' => $product->id,
            'user_id' => $movement->user_id,
            'movement_id' => $movement->id,
            'occurred_at' => $at,
            'message' => sprintf(
                "Sortie de %d %s sur %s : ce produit n'avait eu aucune vente ni consommation depuis %d jours.",
                $movement->quantity, $product->unit, $product->sku, $idleDays,
            ),
            'details' => ['idle_days' => $idleDays, 'threshold_days' => $threshold],
        ]);
    }

    // ------------------------------------------------------------------ Outils

    /** Sorties appliquées d'un produit, hors mouvements annulés par une correction. */
    private function appliedExits(int $productId): Builder
    {
        return StockMovement::query()
            ->where('product_id', $productId)
            ->where('type', MovementType::Exit->value)
            ->where('status', MovementStatus::Applied->value)
            ->whereDoesntHave('correction');
    }

    /** @param Collection<int, int> $sorted valeurs triées par ordre croissant */
    private function median(Collection $sorted): float
    {
        $count = $sorted->count();
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    private function number(float|int $value, int $decimals = 2): string
    {
        $formatted = number_format((float) $value, $decimals, ',', '');

        return str_contains($formatted, ',') ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }

    /** Crée l'anomalie si elle n'existe pas déjà (clé de déduplication unique). */
    private function flag(AnomalyRule $rule, string $key, array $attributes): bool
    {
        if (Anomaly::query()->where('dedupe_key', $key)->exists()) {
            return false;
        }

        try {
            Anomaly::create($attributes + [
                'rule_code' => $rule,
                'dedupe_key' => $key,
                'status' => AnomalyStatus::New,
                'detected_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Détectée au même instant par un autre processus.
            return false;
        }

        return true;
    }
}