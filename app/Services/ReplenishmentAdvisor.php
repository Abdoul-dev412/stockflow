<?php

namespace App\Services;

use App\Enums\CoverageLevel;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Coverage;
use App\Support\ReplenishmentSuggestion;
use Illuminate\Support\Collection;

class ReplenishmentAdvisor
{
    public function __construct(private CoverageCalculator $coverage)
    {
    }

    /**
     * Les produits actifs à réapprovisionner, les plus urgents d'abord :
     * ruptures, puis couverture croissante, puis produits sans estimation.
     *
     * @return Collection<int, ReplenishmentSuggestion>
     */
    public function suggestions(): Collection
    {
        $products = Product::query()->active()->with('supplier')->get();
        $coverages = $this->coverage->forProducts($products);

        return $products
            ->map(fn (Product $product) => $this->suggest($product, $coverages->get($product->id)))
            ->filter()
            ->sort(fn (ReplenishmentSuggestion $a, ReplenishmentSuggestion $b) => [
                $a->coverage->usableQuantity > 0, $a->coverage->days ?? INF, $a->product->name,
            ] <=> [
                $b->coverage->usableQuantity > 0, $b->coverage->days ?? INF, $b->product->name,
            ])
            ->values();
    }

    public function suggest(Product $product, Coverage $coverage): ?ReplenishmentSuggestion
    {
        $leadTime = $product->supplier?->lead_time_days
            ?? (int) config('stockflow.replenishment.default_lead_time_days');

        $classicAlert = $product->quantity <= $product->minimum_stock;
        $coverageAlert = in_array($coverage->level, [CoverageLevel::Critical, CoverageLevel::Attention], true);

        if (! $classicAlert && ! $coverageAlert) {
            return null;
        }

        if ($coverage->isDetermined()) {
            $basis = 'consumption';
            $needed = $coverage->dailyConsumption * ($leadTime + $product->target_cover_days) - $coverage->usableQuantity;
        } else {
            $basis = 'threshold';
            $needed = $product->minimum_stock * (int) config('stockflow.replenishment.fallback_threshold_multiplier')
                - $coverage->usableQuantity;
        }

        // L'arrondi à 6 décimales évite qu'une erreur de virgule flottante ajoute une unité.
        $quantity = max(0, (int) ceil(round($needed, 6)));

        return $quantity > 0
            ? new ReplenishmentSuggestion($product, $coverage, $quantity, $basis, $leadTime)
            : null;
    }

    /** @param Collection<int, array{product: Product, quantity: int}> $items */
    public function message(Supplier $supplier, Collection $items, User $user): string
    {
        $greeting = filled($supplier->contact_name) ? "Bonjour {$supplier->contact_name}," : 'Bonjour,';

        $lines = $items
            ->map(fn (array $item) => "- {$item['product']->name} ({$item['product']->sku}) : {$item['quantity']} {$item['product']->unit}")
            ->values()
            ->all();

        return implode("\n", [
            $greeting,
            '',
            'Nous souhaitons passer la commande suivante :',
            ...$lines,
            '',
            'Merci de nous confirmer la disponibilité et le délai de livraison.',
            'Cordialement,',
            $user->name,
        ]);
    }

    /** Lien WhatsApp si le numéro est au format international, sinon null. */
    public function whatsappUrl(Supplier $supplier, string $message): ?string
    {
        if (! preg_match('/^\+[1-9]\d{7,14}$/', (string) $supplier->phone)) {
            return null;
        }

        return 'https://wa.me/'.ltrim($supplier->phone, '+').'?text='.rawurlencode($message);
    }

    public function mailtoUrl(Supplier $supplier, string $subject, string $message): ?string
    {
        if (blank($supplier->email) || ! filter_var($supplier->email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return 'mailto:'.str_replace('%40', '@', rawurlencode($supplier->email))
            .'?subject='.rawurlencode($subject).'&body='.rawurlencode($message);
    }
}