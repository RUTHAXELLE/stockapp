> **Obsolète pour le serveur VPS** — suivre [docs/MANUEL_DEPLOIEMENT_VPS.md](docs/MANUEL_DEPLOIEMENT_VPS.md).
> Les prérequis ci-dessous (PHP 7.4, MySQL 5.7) ne sont plus valables : PHP 8.2 minimum, MySQL 8.0.

# StockApp — Guide d'installation complet

## Prérequis
- PHP 7.4+ (recommandé : PHP 8.1+)
- MySQL 5.7+ ou MariaDB 10.3+
- Apache / Nginx
- Composer

## Installation rapide

### 1. Copier et installer
```bash
cp -r stockapp/ /var/www/html/stockapp/
cd /var/www/html/stockapp/
composer require phpmailer/phpmailer phpoffice/phpspreadsheet
```

### 2. Base de données
```bash
mysql -u root -p -e "CREATE DATABASE stockapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p stockapp < database.sql
```

### 3. Configuration — includes/db.php
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'stockapp');
define('DB_USER', 'votre_user');
define('DB_PASS', 'votre_mdp');
define('APP_URL',  'https://votre-domaine.ci/stockapp');
define('APP_TIMEZONE', 'Africa/Abidjan');
```

### 4. SMTP — includes/notifications.php
```php
define('MAIL_HOST',     'smtp.votre-domaine.ci');
define('MAIL_PORT',     587);
define('MAIL_USERNAME', 'noreply@votre-domaine.ci');
define('MAIL_PASSWORD', 'votre_mdp_smtp');
```

### 5. Cron quotidien (alertes automatiques)
```
0 8 * * * php /var/www/html/stockapp/cron/check_alerts.php
```

## Première connexion
URL : https://votre-domaine.ci/stockapp/
Email : admin@stockapp.local
Mot de passe : Admin@2024

CHANGEZ LE MOT DE PASSE IMMÉDIATEMENT après connexion.

## Modules livrés
- Auth + Sessions + BDD
- Dashboard avec graphiques
- Équipements (CRUD, N° série auto, fin de cycle)
- Nomenclatures + composition de postes
- Sites + Calculateur de capacité
- Affectations + Mouvements
- Consommables + Livraisons + Ajustements
- Rapports & Analyses
- Gestion Utilisateurs (Admin)
- Permissions granulaires par rôle
- Journal d'audit complet
- Export Excel (tous modules)
- Notifications email (fin cycle, stock bas)
