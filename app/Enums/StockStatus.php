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
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Normal => 'bg-green-100 text-green-800',
            self::Low => 'bg-amber-100 text-amber-800',
            self::OutOfStock => 'bg-red-100 text-red-800',
        };
    }
}