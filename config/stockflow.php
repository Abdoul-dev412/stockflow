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
    ],
    'dormancy' => [
        // Un produit est dormant : du stock, et aucune vente ni consommation depuis ce nombre de jours.
        'days' => 90,
    ],

    'abc' => [
        'window_days' => 365,
        // Seuils cumulés : A jusqu'à 80 % de la valeur, B jusqu'à 95 %, C ensuite.
        'a_percent' => 80,
        'b_percent' => 95,
    ],

    'replenishment' => [
        // Délai retenu pour un produit sans fournisseur.
        'default_lead_time_days' => 7,
        // Sans consommation connue : on vise ce multiple du seuil minimal.
        'fallback_threshold_multiplier' => 2,
    ],

    'sync' => [
    // Durée de vie d'un jeton d'appareil, en jours : au-delà, reconnexion et renouvellement.
    'token_days' => 7,
    'max_devices_per_user' => 5,
    // Limite par jeton et par minute (le triple par adresse IP).
    'rate_limit_per_minute' => 120,
    // Le curseur de synchronisation recule de quelques secondes (transactions validées en retard).
    'cursor_lag_seconds' => 10,
],
];