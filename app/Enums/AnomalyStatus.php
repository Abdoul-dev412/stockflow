<?php

namespace App\Enums;

enum AnomalyStatus: string
{
    case New = 'new';
    case Reviewed = 'reviewed';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nouvelle',
            self::Reviewed => 'Examinée',
            self::Ignored => 'Ignorée',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::New => 'bg-amber-100 text-amber-800',
            self::Reviewed => 'bg-green-100 text-green-800',
            self::Ignored => 'bg-gray-200 text-gray-700',
        };
    }
}