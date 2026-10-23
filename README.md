# StockFlow

Application web de gestion de stocks et de mouvements de marchandises, pensée pour les petites entreprises : produits, entrées, sorties, ajustements, alertes, historique, audit et exports.

![Tests](https://github.com/Abdoul-dev412/stockflow/actions/workflows/tests.yml/badge.svg)

> Projet de portfolio Full Stack. **Version actuelle : v0.1 (cœur).** Les modules d'aide à la décision (lots et FEFO, couverture en jours, réapprovisionnement) arrivent en v0.2, le mode hors ligne en v0.3.

## Fonctionnalités de la v0.1

- **Produits, catégories, fournisseurs** : création, modification, désactivation, recherche, filtres combinables, tri, pagination côté serveur.
- **Mouvements de stock** : entrées, sorties, ajustements d'inventaire (par quantité constatée) et corrections documentées. Chaque mouvement est tracé (avant, après, motif, utilisateur, date).
- **Fiabilité** : stock mis à jour et mouvement enregistré dans une seule transaction, verrou de ligne contre les opérations simultanées, stock jamais négatif, mouvements immuables.
- **Alertes** : stock faible et rupture, triés par urgence, avec accès direct à l'entrée de stock.
- **Tableau de bord** : indicateurs calculés depuis la base, mouvements récents, produits à réapprovisionner.
- **Journal d'audit** en lecture seule et **exports CSV** (produits, niveaux de stock, historique) avec neutralisation des formules.
- **Utilisateurs et rôles** (administrateur, gestionnaire) avec contrôle des droits côté serveur, mots de passe temporaires à changer à la première connexion.

## Pile technique

PHP 8.2 · Laravel 12 · Blade · Tailwind CSS · Alpine.js · MySQL 8 / MariaDB 10.4 · Pest · GitHub Actions

## Installation locale (Windows et XAMPP)

Prérequis : PHP 8.2 ou plus, Composer, Node.js 20 ou plus, Git, MySQL ou MariaDB (XAMPP convient).

```bash
git clone https://github.com/Abdoul-dev412/stockflow.git
cd stockflow
composer install
npm install
copy .env.example .env
php artisan key:generate
```

1. Démarre MySQL (panneau de contrôle XAMPP).
2. Crée deux bases en `utf8mb4_unicode_ci` : `stockflow` et `stockflow_test`.
3. Vérifie la section `DB_*` du fichier `.env`.

```bash
php artisan migrate --seed
npm run dev        # dans un premier terminal
php artisan serve  # dans un second terminal
```

Ouvre <http://127.0.0.1:8000>.

### Comptes de démonstration

Mot de passe de tous les comptes : `password`.

| Compte | Rôle |
|---|---|
| admin@stockflow.test | Administrateur |
| gestionnaire@stockflow.test | Gestionnaire |
| superviseur@stockflow.test | Gestionnaire |

> Le seeder de démonstration refuse de s'exécuter en production.

## Tests

```bash
php artisan test                        # tout
php artisan test --testsuite=Unit,Feature   # sans la suite de concurrence (plus rapide)
```

- Les tests utilisent la base `stockflow_test` : elle est vidée à chaque exécution. Ne la fais jamais pointer sur `stockflow`.
- La suite `Concurrency` lance de vrais processus PHP simultanés pour prouver qu'on ne peut pas consommer plus de stock qu'il n'en reste. Elle prend une vingtaine de secondes.
- La CI exécute l'ensemble sur MySQL 8 ; en local, le développement a été fait sur MariaDB 10.4.

## Architecture

| Élément | Rôle |
|---|---|
| `app/Services/StockMovementService.php` | Seul point d'entrée qui modifie le stock : transaction, verrou, règles métier |
| `app/Services/AuditLogger.php` | Seul point d'entrée du journal d'audit |
| `app/Services/UserManager.php` | Gestion des comptes et invariants (dernier administrateur, auto-protection) |
| `app/Policies` | Droits d'accès, vérifiés côté serveur |
| `app/Http/Requests` | Validation des données |
| `app/Support/CsvExport.php` | Génération des CSV et neutralisation des formules |

## Règles métier essentielles

- Un produit est créé avec un stock de 0 ; son stock initial est une entrée de stock.
- Une sortie ne peut jamais rendre le stock négatif.
- Un mouvement appliqué n'est ni modifié ni supprimé : toute erreur se corrige par une correction justifiée, réservée à l'administrateur, une seule fois par mouvement.
- Un ajustement se saisit par la quantité constatée ; l'écart est calculé par le système.
- Au moins un administrateur actif existe toujours.

## Sécurité

Autorisations côté serveur (Policies) · validation de toutes les entrées · protection CSRF · échappement des sorties · mots de passe hachés · limitation des tentatives de connexion · en-têtes de sécurité · exports réservés à l'administrateur avec neutralisation des formules · journal d'audit sans secret · `.env` exclu du dépôt.

## Mise en production

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan stockflow:create-admin   # crée le premier administrateur
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

- `APP_ENV=production`, `APP_DEBUG=false`, une `APP_KEY` propre, `SESSION_SECURE_COOKIE=true`, HTTPS obligatoire.
- Ne lance **jamais** le seeder de démonstration en production et utilise un mot de passe fort pour la base.
- Sauvegarde régulière : `mysqldump -u <utilisateur> -p stockflow > sauvegarde.sql`.

## Limites connues de la v0.1

- Les mouvements sont immuables au niveau applicatif ; une requête SQL directe pourrait les modifier. La commande de vérification d'intégrité de la v0.2 la détectera.
- Les textes fournis par Breeze (connexion, profil) sont en anglais ; la traduction complète est prévue en v1.0.
- Le « mot de passe oublié » nécessite un envoi d'emails non configuré ; l'administrateur réinitialise les mots de passe.
- Pas encore de lots et dates de péremption, de couverture en jours ni de mode hors ligne.

## Feuille de route

- **v0.2** : lots et FEFO, couverture en jours, réapprovisionnement en un clic, intégrité vérifiable, anomalies, classification ABC.
- **v0.3** : mode hors ligne (PWA), synchronisation idempotente, gestion des conflits, scan par caméra.
- **v1.0** : déploiement, Docker, démonstration, traduction complète.




<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>


## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
