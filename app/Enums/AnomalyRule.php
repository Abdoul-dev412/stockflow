<?php

namespace App\Enums;

enum AnomalyRule: string
{
    case Spike = 'R1';
    case RepeatedNegativeAdjustments = 'R2';
    case LossShare = 'R3';
    case DormantProduct = 'R4';

    public function label(): string
    {
        return match ($this) {
            self::Spike => 'Sortie exceptionnelle',
            self::RepeatedNegativeAdjustments => 'Ajustements négatifs répétés',
            self::LossShare => 'Pertes anormales',
            self::DormantProduct => 'Sortie sur produit dormant',
        };
    }

    public function description(): string
    {
        $c = config('stockflow.anomalies');

        return match ($this) {
            self::Spike => "Sortie supérieure à {$c['r1_multiplier']} fois la sortie médiane du produit sur {$c['window_days']} jours.",
            self::RepeatedNegativeAdjustments => "{$c['r2_count']} ajustements négatifs ou plus par le même utilisateur en {$c['r2_days']} jours.",
            self::LossShare => "Pertes, casses, vols et péremptions supérieurs à {$c['r3_percent']} % des sorties du produit sur {$c['window_days']} jours.",
            self::DormantProduct => 'Sortie sur un produit sans vente ni consommation depuis '.config('stockflow.dormancy.days').' jours.',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Spike => 'bg-orange-100 text-orange-800',
            self::RepeatedNegativeAdjustments => 'bg-blue-100 text-blue-800',
            self::LossShare => 'bg-red-100 text-red-800',
            self::DormantProduct => 'bg-purple-100 text-purple-800',
        };
    }
}