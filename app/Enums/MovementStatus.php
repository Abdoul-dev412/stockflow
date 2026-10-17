<?php

namespace App\Enums;

enum MovementStatus: string
{
    case Applied = 'applied';
    case Conflict = 'conflict';
    case PendingValidation = 'pending_validation';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Appliqué',
            self::Conflict => 'En conflit',
            self::PendingValidation => 'En attente de validation',
            self::Rejected => 'Rejeté',
        };
    }
}
