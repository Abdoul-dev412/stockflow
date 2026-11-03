<?php

return [
    'coverage' => [
        // Fenêtre de calcul de la consommation moyenne, en jours.
        'window_days' => (int) env('STOCKFLOW_COVERAGE_WINDOW_DAYS', 30),
        // Historique minimal (depuis le premier mouvement) pour estimer une couverture.
        'min_history_days' => (int) env('STOCKFLOW_COVERAGE_MIN_HISTORY_DAYS', 7),
        // Niveaux : critique si moins de X jours, attention si moins de Y jours.
        'critical_days' => (int) env('STOCKFLOW_COVERAGE_CRITICAL_DAYS', 7),
        'attention_days' => (int) env('STOCKFLOW_COVERAGE_ATTENTION_DAYS', 15),
    ],

    'anomalies' => [
        // Fenêtre d'observation (R1 et R3), en jours.
        'window_days' => 30,
        // R1 : multiple de la sortie médiane, et nombre minimal de sorties de référence.
        'r1_multiplier' => 3,
        'r1_min_sample' => 5,
        // R2 : nombre d'ajustements négatifs sur la période, en jours.
        'r2_count' => 3,
        'r2_days' => 7,
        // R3 : part des pertes dans les sorties (en %), et nombre minimal de sorties pour juger.
        'r3_percent' => 20,
        'r3_min_exits' => 3,
        // R4 : durée sans vente ni consommation pour qu'un produit soit dormant.
        'r4_dormant_days' => 90,
    ],

    'replenishment' => [
        // Délai retenu pour un produit sans fournisseur.
        'default_lead_time_days' => 7,
        // Sans consommation connue : on vise ce multiple du seuil minimal.
        'fallback_threshold_multiplier' => 2,
    ],
];