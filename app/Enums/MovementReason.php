<?php

namespace App\Enums;

enum MovementReason: string
{
    case Reception = 'reception';
    case Sale = 'sale';
    case InternalUse = 'internal_use';
    case Loss = 'loss';
    case Breakage = 'breakage';
    case Expiry = 'expiry';
    case Theft = 'theft';
    case Inventory = 'inventory';
    case Correction = 'correction';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Reception => 'Réception de marchandises',
            self::Sale => 'Vente',
            self::InternalUse => 'Consommation interne',
            self::Loss => 'Perte',
            self::Breakage => 'Casse',
            self::Expiry => 'Péremption',
            self::Theft => 'Vol constaté',
            self::Inventory => 'Inventaire',
            self::Correction => 'Correction',
            self::Other => 'Autre (à justifier)',
        };
    }
}