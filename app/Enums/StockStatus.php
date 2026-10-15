<?php

namespace App\Enums;

enum StockStatus: string
{
    case Normal = 'normal';
    case Low = 'low';
    case OutOfStock = 'out_of_stock';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Stock normal',
            self::Low => 'Stock faible',
            self::OutOfStock => 'Rupture',
        };
    }
}