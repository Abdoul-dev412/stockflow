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

    'replenishment' => [
        // Délai retenu pour un produit sans fournisseur.
        'default_lead_time_days' => 7,
        // Sans consommation connue : on vise ce multiple du seuil minimal.
        'fallback_threshold_multiplier' => 2,
    ],
];