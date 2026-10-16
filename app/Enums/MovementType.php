<?php

namespace App\Enums;

enum MovementType: string
{
    case Entry = 'entry';
    case Exit = 'exit';
    case Adjustment = 'adjustment';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrée',
            self::Exit => 'Sortie',
            self::Adjustment => 'Ajustement',
            self::Correction => 'Correction',
        };
    }

    /** @return array<int, MovementReason> */
    public function allowedReasons(): array
    {
        return match ($this) {
            self::Entry => [MovementReason::Reception, MovementReason::Other],
            self::Exit => [
                MovementReason::Sale, MovementReason::InternalUse, MovementReason::Loss,
                MovementReason::Breakage, MovementReason::Expiry, MovementReason::Theft,
                MovementReason::Other,
            ],
            self::Adjustment => [MovementReason::Inventory],
            self::Correction => [MovementReason::Correction],
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Entry => 'bg-green-100 text-green-800',
            self::Exit => 'bg-orange-100 text-orange-800',
            self::Adjustment => 'bg-blue-100 text-blue-800',
            self::Correction => 'bg-purple-100 text-purple-800',
        };
    }
}