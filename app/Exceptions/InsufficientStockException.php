<?php

namespace App\Exceptions;

class InsufficientStockException extends StockMovementException
{
    public function __construct(
        public readonly int $available,
        public readonly int $requested,
        ?string $detail = null,
    ) {
        parent::__construct(
            "Stock insuffisant : {$available} disponible(s), {$requested} demandé(s)"
            .($detail ? " ({$detail})" : '').'.'
        );
    }
}