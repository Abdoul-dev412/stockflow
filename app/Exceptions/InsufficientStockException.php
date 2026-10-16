<?php

namespace App\Exceptions;
use Exception;

class InsufficientStockException extends StockMovementException
{
    public function __construct(
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Stock insuffisant : {$available} disponible(s), {$requested} demandé(s)."
        );
    }
}