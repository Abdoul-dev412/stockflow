<?php

namespace App\Services;

use App\Enums\AnomalyStatus;
use App\Enums\CoverageLevel;
use App\Models\Anomaly;
use App\Models\IntegrityCheck;
use App\Models\StockLot;
use App\Models\User;
use App\Support\DailyAction;
use Illuminate\Support\Collection;

class DailyActionsBuilder
{
    public const EXPIRING_SOON_DAYS = 7;
    private const MAX_ITEMS = 3;

    public function __construct(private ReplenishmentAdvisor $advisor)
    {
    }

    /**
     * Les actions à mener aujourd'hui, les plus graves d'abord.
     *
     * @return Collection<int, DailyAction>
     */
    public function forUser(User $user): Collection
    {
        $expiry = StockLot::expiryCounts(self::EXPIRING_SOON_DAYS);
        $admin = $user->isAdmin();

        return collect([
            $admin ? $this->integrity() : null,
            $this->expiredLots($expiry),
            $this->replenishment(),
            $admin ? $this->anomalies() : null,
            $this->expiringSoon($expiry),
        ])
            ->filter()
            ->sortBy(fn (DailyAction $action) => $action->rank())   // tri stable : l'ordre ci-dessus départage
            ->values();
    }

    private function integrity(): ?DailyAction
    {
        $failed = collect(['stock', 'audit'])
            ->map(fn (string $type) => IntegrityCheck::lastOf($type))
            ->filter(fn (?IntegrityCheck $check) => $check?->status === 'failed');

        if ($failed->isEmpty()) {
            return null;
        }

        $count = (int) $failed->sum('issues_count');

        return new DailyAction(
            'integrity', 'critical', "Écarts d'intégrité détectés", $count,
            'Le stock ou le journal d\'audit présente des incohérences : à vérifier avant toute autre chose.',
            route('audit.index'), 'Voir le détail',
            $failed
                ->flatMap(fn (IntegrityCheck $check) => $check->details['examples'] ?? [])
                ->take(self::MAX_ITEMS)
                ->map(fn (string $message) => ['label' => $message, 'url' => null])
                ->values()
                ->all(),
        );
    }

    /** @param array{expired: int, expired_units: int, soon: int} $expiry */
    private function expiredLots(array $expiry): ?DailyAction
    {
        if ($expiry['expired'] === 0) {
            return null;
        }

        $lots = StockLot::query()
            ->inStock()
            ->whereNotNull('expires_at')
            ->ofActiveProducts()
            ->where('expires_at', '<', today()->toDateString())
            ->with('product:id,sku,name,unit')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::MAX_ITEMS)
            ->get();

        return new DailyAction(
            'expired_lots', 'critical', 'Lots expirés à retirer du stock', $expiry['expired'],
            "{$expiry['expired_units']} unité(s) ne peuvent plus être vendues.",
            route('expiries.index'), 'Retirer les lots',
            $this->lotItems($lots),
        );
    }

    private function replenishment(): ?DailyAction
    {
        $suggestions = $this->advisor->suggestions();

        if ($suggestions->isEmpty()) {
            return null;
        }

        $urgent = $suggestions->contains(fn ($suggestion) => $suggestion->coverage->usableQuantity === 0
            || $suggestion->coverage->level === CoverageLevel::Critical);

        $items = $suggestions->take(self::MAX_ITEMS)->map(function ($suggestion) {
            $product = $suggestion->product;
            $coverage = $suggestion->coverage;

            $state = match (true) {
                $coverage->usableQuantity === 0 => 'en rupture',
                $coverage->isDetermined() => "{$coverage->daysLabel()} de stock",
                default => "stock bas ({$product->quantity}/{$product->minimum_stock})",
            };

            return [
                'label' => "{$product->name} : {$state}, {$suggestion->quantity} {$product->unit} à commander",
                'url' => route('products.show', $product),
            ];
        })->values()->all();

        return new DailyAction(
            'replenishment', $urgent ? 'critical' : 'warning', 'Produits à commander', $suggestions->count(),
            $urgent ? 'Certains produits sont en rupture ou vont l\'être très bientôt.' : 'Ces produits passent sous leur seuil ou leur couverture cible.',
            route('replenishment.index'), 'Préparer les commandes', $items,
        );
    }

    private function anomalies(): ?DailyAction
    {
        $count = Anomaly::query()->where('status', AnomalyStatus::New->value)->count();

        if ($count === 0) {
            return null;
        }

        $latest = Anomaly::query()
            ->where('status', AnomalyStatus::New->value)
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ITEMS)
            ->get();

        return new DailyAction(
            'anomalies', 'warning', 'Anomalies à examiner', $count,
            'Des mouvements sortent de l\'ordinaire : à examiner ou à ignorer avec un commentaire.',
            route('anomalies.index'), 'Examiner',
            $latest->map(fn (Anomaly $anomaly) => ['label' => $anomaly->message, 'url' => null])->all(),
        );
    }

    /** @param array{expired: int, expired_units: int, soon: int} $expiry */
    private function expiringSoon(array $expiry): ?DailyAction
    {
        if ($expiry['soon'] === 0) {
            return null;
        }

        $lots = StockLot::query()
            ->inStock()
            ->whereNotNull('expires_at')
            ->ofActiveProducts()
            ->whereBetween('expires_at', [today()->toDateString(), today()->addDays(self::EXPIRING_SOON_DAYS)->toDateString()])
            ->with('product:id,sku,name,unit')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::MAX_ITEMS)
            ->get();

        return new DailyAction(
            'expiring_soon', 'warning', 'Lots qui expirent cette semaine', $expiry['soon'],
            'À écouler ou à surveiller en priorité (règle FEFO).',
            route('expiries.index', ['days' => self::EXPIRING_SOON_DAYS]), 'Voir les péremptions',
            $this->lotItems($lots),
        );
    }

    /** @return array<int, array{label: string, url: ?string}> */
    private function lotItems(Collection $lots): array
    {
        return $lots->map(fn (StockLot $lot) => [
            'label' => "{$lot->product->name} : lot {$lot->lot_number}, {$lot->quantity_remaining} {$lot->product->unit}, {$lot->expiryLabel()}",
            'url' => route('products.show', $lot->product),
        ])->values()->all();
    }
}