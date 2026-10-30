<?php

namespace App\Enums;

enum CoverageLevel: string
{
    case Critical = 'critical';
    case Attention = 'attention';
    case Comfortable = 'comfortable';
    case Undetermined = 'undetermined';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critique',
            self::Attention => 'Attention',
            self::Comfortable => 'Confortable',
            self::Undetermined => 'Indéterminée',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Critical => 'bg-red-100 text-red-800',
            self::Attention => 'bg-amber-100 text-amber-800',
            self::Comfortable => 'bg-green-100 text-green-800',
            self::Undetermined => 'bg-gray-100 text-gray-700',
        };
    }
}