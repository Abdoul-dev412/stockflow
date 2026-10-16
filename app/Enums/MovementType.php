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
}