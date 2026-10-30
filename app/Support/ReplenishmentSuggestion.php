<?php

namespace App\Support;

use App\Models\Product;

final class ReplenishmentSuggestion
{
    public function __construct(
        public readonly Product $product,
        public readonly Coverage $coverage,
        public readonly int $quantity,
        public readonly string $basis,
        public readonly int $leadTimeDays,
    ) {
    }

    public function basisLabel(): string
    {
        return $this->basis === 'consumption' ? 'Basé sur la consommation' : 'Basé sur le seuil minimal';
    }
}