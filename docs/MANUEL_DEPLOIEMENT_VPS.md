# ERP EMUCI — Manuel de déploiement sur le serveur VPS

**Branche** : vps-mysql (dépôt RUTHAXELLE/stockapp)
**Serveur** : erp.digimmat.ci → 169.58.104.110, Ubuntu 24.04 LTS
**Date** : 09/10/2026
**Auteur** : GAYE Emmanuel — gayerodrigue@gmail.com — +225 01 03 55 96 52

> Procédure jouée de bout en bout le 09/10/2026 sur un Ubuntu 24.04 identique
> au serveur cible (Nginx 1.24, Apache 2.4.58, PHP 8.3.6, MySQL 8.0.46), avec
> un compte MySQL limité à sa base, comme en production. Le serveur réel
> n'a pas encore pu être vérifié, faute d'accès SSH à ce jour (voir § 15).

---

Ce manuel décrit l'installation complète de l'ERP sur un serveur Ubuntu neuf.
Il part d'une **base de données vide** : aucune donnée de la recette (comptes
de test, bobines, imports, journal) n'est reprise. Seuls les référentiels
nécessaires au fonctionnement sont installés (voir § 6).

Les essais ont couvert : l'installation du schéma et la mise à vide, la
création du premier compte, la connexion, les principaux écrans et l'export
Excel, le blocage des adresses sensibles, les tâches planifiées, la
sauvegarde et sa restauration, et une mise à jour par `deploy.sh`
(détail en annexe C).

## Sommaire

1. Prérequis
2. Préparer le serveur
3. Créer la base et le compte MySQL
4. Récupérer le code
5. Configurer l'application et PHP
6. Installer la base vide et le premier compte
7. Configurer le serveur web
8. Activer HTTPS
9. Tâches planifiées et sauvegardes
10. Contrôles avant ouverture
11. Premiers paramétrages dans l'application
12. Mettre à jour l'application
13. Sauvegarde et restauration
14. Retour arrière
15. Limites connues
- Annexe A — Check-list à transmettre à l'hébergeur
- Annexe B — Fichiers fournis
- Annexe C — Résultats des essais

---

## 1. Prérequis

### 1.1 Serveur

| Élément | Exigé | Remarque |
|---|---|---|
| Système | **Ubuntu 24.04 LTS**, 64 bits | Constaté sur 169.58.104.110 (bannière SSH `OpenSSH_9.6p1 Ubuntu-3ubuntu13.19`) |
| Processeur / mémoire | 2 vCPU, 4 Go de RAM conseillés | Recommandation : MySQL et PHP sur la même machine, exports Excel jusqu'à 256 Mo par requête |
| Disque | 40 Go conseillés | Base, sauvegardes de 14 jours, fichiers déposés (imports EMUCI jusqu'à 50 Mo) |
| Fuseau horaire | `Africa/Abidjan` | Réglé au § 2 |

### 1.2 Logiciels

Tous viennent des dépôts officiels d'Ubuntu 24.04 ; aucune source externe
n'est nécessaire.

| Logiciel | Version minimale | Version testée | Paquets |
|---|---|---|---|
| Serveur web | Nginx 1.18 **ou** Apache 2.4 | Nginx 1.24 / Apache 2.4.58 | `nginx` (ou `apache2 libapache2-mod-php8.3`) |
| PHP | **8.2** (exigé par PhpSpreadsheet) | 8.3.6 | `php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-zip php8.3-mbstring php8.3-xml php8.3-curl` |
| MySQL | 8.0 | 8.0.46 | `mysql-server` |
| Outils | — | — | `git curl unzip certbot python3-certbot-nginx logrotate cron ufw` |

Les huit paquets PHP couvrent les 15 extensions utilisées par l'application :
ctype, dom, fileinfo, filter, gd, iconv, libxml, mbstring, pdo_mysql,
simplexml, xml, xmlreader, xmlwriter, zip, zlib. Les bibliothèques PHP
(`vendor/`) sont livrées dans le dépôt : **Composer n'est pas nécessaire**.

> Si PHP 8.4 est déjà installé sur le serveur, il convient aussi : remplacer
> `8.3` par `8.4` dans les noms de paquets, les chemins `/etc/php/8.3/…` et le
> chemin du socket `php8.3-fpm.sock`.

### 1.3 Réseau

| Flux | Sens | Statut au 09/10/2026 |
|---|---|---|
| 22 (SSH) | entrant, depuis les postes d'administration | Ouvert ; identifiants transmis refusés |
| 80 (HTTP) | entrant, depuis Internet | **Fermé** — à ouvrir |
| 443 (HTTPS) | entrant, depuis Internet | **Fermé** — à ouvrir |
| 3306 (MySQL) | — | Fermé — **doit le rester** : MySQL n'écoute que sur le serveur |
| HTTPS sortant vers `github.com` et Let's Encrypt | sortant | À confirmer |

Le nom `erp.digimmat.ci` pointe déjà vers 169.58.104.110 (enregistrement DNS A
vérifié). Let's Encrypt en a besoin pour délivrer le certificat.

### 1.4 Accès à obtenir

- Un **compte SSH avec droits `sudo`** sur le serveur (clé SSH de préférence).
- Un accès en lecture au dépôt `RUTHAXELLE/stockapp`. S'il est privé, une
  *deploy key* en lecture seule est la solution la plus propre.
- L'adresse email du futur **super-administrateur** de l'application.

---

## 2. Préparer le serveur

Toutes les commandes se lancent en SSH sur le serveur.

```bash
sudo apt update && sudo apt upgrade -y
sudo timedatectl set-timezone Africa/Abidjan
```

Installer les logiciels (variante Nginx, conseillée) :

```bash
sudo apt install -y nginx mysql-server git curl unzip logrotate cron ufw php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-zip php8.3-mbstring php8.3-xml php8.3-curl certbot python3-certbot-nginx
```

Variante Apache : remplacer `nginx` par `apache2 libapache2-mod-php8.3`, et
`python3-certbot-nginx` par `python3-certbot-apache`.

Pare-feu : n'ouvrir que SSH et le web.

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

(Variante Apache : `sudo ufw allow 'Apache Full'`.)

Vérifier que MySQL n'écoute que localement. La valeur attendue est `127.0.0.1`,
valeur par défaut d'Ubuntu :

```bash
grep -E '^\s*bind-address' /etc/mysql/mysql.conf.d/mysqld.cnf
```

---

## 3. Créer la base et le compte MySQL

**Les étapes 3 à 5 se font dans la même session SSH** : le mot de passe MySQL
est généré dans une variable, écrit directement dans les fichiers de
configuration, et n'est jamais affiché ni saisi à la main.

```bash
MDP=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32)
```

```bash
sudo mysql <<SQL
CREATE DATABASE stockapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'stockapp'@'localhost' IDENTIFIED BY '$MDP';
CREATE USER 'stockapp'@'127.0.0.1' IDENTIFIED BY '$MDP';
GRANT ALL PRIVILEGES ON stockapp.* TO 'stockapp'@'localhost', 'stockapp'@'127.0.0.1';
SQL
```

Le compte n'a de droits que sur la base `stockapp`. Les deux variantes
(`localhost`, `127.0.0.1`) couvrent les deux modes de connexion :
l'application passe par le socket local, les scripts par TCP.

Fichier d'identifiants pour les sauvegardes et les commandes d'administration
(lisible par root uniquement) :

```bash
sudo install -m 600 /dev/null /etc/mysql/stockapp-client.cnf
printf '[client]\nuser=stockapp\npassword=%s\n' "$MDP" | sudo tee /etc/mysql/stockapp-client.cnf > /dev/null
```

---

## 4. Récupérer le code

```bash
sudo git clone -b vps-mysql https://github.com/RUTHAXELLE/stockapp.git /var/www/stockapp
cd /var/www/stockapp
```

Le code est publié tel quel dans `/var/www/stockapp`. Les fichiers qui ne
doivent pas être servis (`.env`, `.git`, `sql/`, `tools/`, `docs/`…) sont
bloqués par la configuration du serveur web (§ 7). **Ne pas utiliser
d'autre configuration que celle fournie.**

---

## 5. Configurer l'application et PHP

### 5.1 Fichier `.env`

```bash
sudo cp .env.example .env
sudo sed -i "s|^DB_PASS=.*|DB_PASS=$MDP|; s|^APP_URL=.*|APP_URL=https://erp.digimmat.ci|" .env
unset MDP
```

Contenu attendu (le mot de passe est déjà renseigné) :

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=stockapp
DB_USER=stockapp
DB_PASS=••••••••
APP_URL=https://erp.digimmat.ci
APP_TIMEZONE=Africa/Abidjan
```

`deploy.sh` le passe ensuite en droits 640, propriétaire `www-data`.

### 5.2 Réglages PHP

Le fichier fourni règle la taille des imports (50 Mo), la mémoire (256 Mo),
la durée maximale des exports (300 s), le fuseau horaire, et masque les
erreurs à l'écran.

```bash
for d in fpm cli; do sudo cp docs/deploiement/php-stockapp.ini /etc/php/8.3/$d/conf.d/90-stockapp.ini; done
sudo systemctl restart php8.3-fpm
```

Variante Apache avec `mod_php` : copier aussi dans `/etc/php/8.3/apache2/conf.d/`.

---

## 6. Installer la base vide et le premier compte

```bash
cd /var/www/stockapp && sudo ./deploy.sh
```

Lors de la première installation, le script :

1. sauvegarde la base (vide à ce stade) dans `/var/backups/stockapp/` ;
2. lance `sql/installer_schema_mysql.sh`, qui joue `sql/stockapp.sql` puis
   les 25 migrations `*_mysql.sql` dans l'ordre et vérifie le résultat
   (≥ 116 tables, colonnes et tables récentes présentes) ;
3. **vide toutes les tables**, sauf les 19 référentiels ci-dessous, puis
   vérifie qu'il ne reste ni utilisateur, ni bobine, ni ligne de journal ;
4. applique le schéma des Demandes internes (sans effet s'il est déjà là) ;
5. donne les fichiers à `www-data` et recharge le serveur web ;
6. **demande les informations du super-administrateur** : email, prénom,
   nom et mot de passe. Le mot de passe est saisi deux fois, masqué. Il doit
   compter au moins 8 caractères, dont une majuscule, un chiffre et un
   caractère spécial.

**Référentiels conservés** (tout le reste repart vide) :

| Domaine | Tables | Contenu |
|---|---|---|
| Droits | `roles`, `permissions`, `defauts_affichage` | 17 rôles, matrice des droits, affichage par rôle |
| Opérations | `op_types_bobines`, `op_types_vehicule`, `op_types_pmma` | Types A001…, VP/Camion/Semi/Moto, types de PMMA |
| Organisation | `sites`, `nomenclatures`, `configurations_site` | 21 sites (sans responsable), 9 familles d'équipements, dotation type par site |
| Demandes internes | `di_types`, `di_etapes`, `di_roles`, `di_plateformes` | Circuit de validation |
| Achats | `achat_types`, `achat_paliers`, `achat_parametres`, `departements`, `familles_achat`, `lignes_budgetaires` | Paramétrage des achats |

Si le compte n'a pas été créé à l'étape 6 (session sans terminal, saisie
refusée…) :

```bash
sudo -u www-data php tools/creer_superadmin.php
```

Ce script refuse de s'exécuter s'il existe déjà un super-administrateur
actif. Les comptes suivants se créent dans l'application.

---

## 7. Configurer le serveur web

### 7.1 Nginx (conseillé)

```bash
sudo cp docs/deploiement/nginx-stockapp.conf /etc/nginx/sites-available/stockapp
sudo ln -s /etc/nginx/sites-available/stockapp /etc/nginx/sites-enabled/stockapp
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 7.2 Apache (variante)

```bash
sudo cp docs/deploiement/apache-stockapp.conf /etc/apache2/sites-available/stockapp.conf
sudo a2enmod rewrite && sudo a2ensite stockapp && sudo a2dissite 000-default
sudo apache2ctl configtest && sudo systemctl reload apache2
```

### 7.3 Pourquoi ces fichiers et pas ceux de l'ancien guide

Avec la configuration Apache de l'ancien `DEPLOY-VPS-MYSQL.md`, les adresses
suivantes étaient **téléchargeables depuis Internet** (vérifié) : `/.env`
(mot de passe MySQL en clair), `/sql/stockapp.sql`, `/deploy.sh`,
`/composer.json`, `/Dockerfile`, ainsi que les sauvegardes, que l'ancien
`deploy.sh` rangeait dans le dossier publié. Les fichiers fournis bloquent :

- les fichiers cachés (`.env`, `.git/`…) ;
- les dossiers `includes`, `templates`, `cron`, `vendor`, `sql`, `tools`,
  `docs`, `docker`, `backups`, `git` ;
- les extensions `.md .sh .yml .yaml .json .lock .sql .gz .log`, plus
  `Dockerfile` ;
- `migrate_site_id.php`, un script ponctuel protégé par un secret écrit en
  clair dans le code ;
- tout script exécutable déposé dans `uploads/`.

Ils limitent aussi la taille des envois à 55 Mo et laissent 300 s aux exports.

---

## 8. Activer HTTPS

Les ports 80 et 443 doivent être ouverts et `erp.digimmat.ci` pointer vers
le serveur.

```bash
sudo certbot --nginx -d erp.digimmat.ci
```

(Variante Apache : `sudo certbot --apache -d erp.digimmat.ci`.)

Certbot ajoute la partie HTTPS au fichier de configuration et met en place
le renouvellement automatique. Pour vérifier le renouvellement :

```bash
sudo certbot renew --dry-run
```

---

## 9. Tâches planifiées et sauvegardes

```bash
sudo mkdir -p /var/log/stockapp && sudo chown www-data: /var/log/stockapp
sudo install -m 644 docs/deploiement/cron-stockapp /etc/cron.d/stockapp
sudo install -m 644 docs/deploiement/logrotate-stockapp /etc/logrotate.d/stockapp
```

| Tâche | Fréquence | Compte | Effet |
|---|---|---|---|
| `cron/check_alerts.php bobines` | toutes les 5 min | www-data | Passe en « épuisée » toute bobine à zéro film et zéro stock système |
| Sauvegarde MySQL | chaque nuit à 2 h 30 | root | `/var/backups/stockapp/quotidien_AAAA-MM-JJ.sql.gz`, conservée 14 jours |
| Alertes quotidiennes | — | — | **Désactivée** : le script s'arrête en erreur (voir § 15) |

Le journal des tâches est `/var/log/stockapp/cron.log`, avec une rotation
hebdomadaire sur 8 semaines.

**Copier les sauvegardes hors du serveur.** Une sauvegarde qui reste sur
le serveur disparaît avec lui. Prévoir une copie régulière vers un autre
emplacement (autre serveur, stockage de l'hébergeur).

---

## 10. Contrôles avant ouverture

### 10.1 Adresses sensibles

Chaque adresse doit répondre **404** (Nginx) ou **403** (Apache) :

```bash
for u in /.env /.git/config /sql/stockapp.sql /deploy.sh /composer.json /vendor/autoload.php /includes/db.php /docs/MANUEL_DEPLOIEMENT_VPS.md /tools/creer_superadmin.php /migrate_site_id.php /Dockerfile; do printf '%-36s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' https://erp.digimmat.ci$u)"; done
```

Un seul **200** dans cette liste est bloquant : ne pas ouvrir l'application
avant de l'avoir corrigé.

### 10.2 Application

- `https://erp.digimmat.ci/login.php` affiche la page « Connexion — ERP EMUCI ».
- La connexion du super-administrateur aboutit à l'accueil.
- Ouvrir le Tableau de bord, le Dashboard KPI, le Point EMUCI, les
  Équipements et Administration → Utilisateurs : aucune page d'erreur.
- Lancer un export Excel (Vue stock bobines → Excel) : un fichier `.xlsx`
  se télécharge.
- `sudo tail /var/log/stockapp/cron.log` : une ligne `[OK]` toutes les
  5 minutes.
- `sudo ls -l /var/backups/stockapp/` le lendemain : une sauvegarde
  `quotidien_…` présente.

---

## 11. Premiers paramétrages dans l'application

La base étant vide, le super-administrateur doit, dans l'ordre :

1. créer les comptes utilisateurs (Administration → Utilisateurs) et leur
   attribuer rôle et site ;
2. désigner le responsable de chaque site : les 21 sites conservés n'en ont
   plus, puisque les comptes de recette ont été supprimés ;
3. vérifier la matrice des droits (Administration → Permissions) ;
4. saisir les stocks de départ (bobines, rivets, PMMA, consommables) et
   enregistrer les équipements ;
5. renseigner les catalogues d'achat (articles, consommables), vides au
   départ.

---

## 12. Mettre à jour l'application

```bash
cd /var/www/stockapp && sudo ./deploy.sh
```

Le script récupère la dernière version de la branche `vps-mysql`, sauvegarde
la base, applique le schéma des Demandes internes et recharge le serveur web.
**Il ne recharge jamais le schéma de base sur une base existante** : les
données sont protégées.

**Nouvelles migrations.** Si la mise à jour apporte de nouveaux fichiers
`sql/*_mysql.sql`, le script les liste après le `git pull` :

```
/!\ Nouvelles migrations SQL à jouer APRÈS lecture (voir docs/MANUEL_DEPLOIEMENT_VPS.md) :
      sql/migration_xxx_mysql.sql
```

Elles ne sont pas jouées automatiquement. Pour chacune, lire le fichier puis :

```bash
sudo mysql --defaults-extra-file=/etc/mysql/stockapp-client.cnf stockapp < sql/migration_xxx_mysql.sql
```

S'il y en a plusieurs, les jouer dans l'ordre de la liste. Une sauvegarde
vient d'être faite par `deploy.sh` (§ 13 pour revenir en arrière).

Options du script : `SKIP_BACKUP=1` (pas de sauvegarde), `SKIP_PULL=1` (pas
de `git pull`), `RELOAD_WEB=0` (pas de rechargement), `APP_DIR=…`,
`BACKUP_DIR=…`. Exemple : `sudo SKIP_PULL=1 ./deploy.sh`.

---

## 13. Sauvegarde et restauration

**Sauvegarde manuelle :**

```bash
sudo sh -c 'mysqldump --defaults-extra-file=/etc/mysql/stockapp-client.cnf --no-tablespaces --single-transaction stockapp | gzip > /var/backups/stockapp/manuel_$(date +%F_%H%M).sql.gz'
```

**Restauration** (remplace tout le contenu de la base par celui de la
sauvegarde ; prévenir les utilisateurs avant) :

```bash
sudo sh -c 'zcat /var/backups/stockapp/FICHIER.sql.gz | mysql --defaults-extra-file=/etc/mysql/stockapp-client.cnf stockapp'
```

Une restauration a été testée le 09/10/2026 : les 116 tables et leurs
données ont été rechargées. Il est conseillé d'en refaire une à blanc chaque
mois, dans une base de test.

---

## 14. Retour arrière

Si une mise à jour pose problème :

```bash
cd /var/www/stockapp
sudo git log --oneline -5
sudo git checkout <commit_précédent>
```

Restaurer ensuite la sauvegarde que `deploy.sh` a faite juste avant la mise
à jour (§ 13) : c'est la plus récente `stockapp_AAAAMMJJ_HHMMSS.sql.gz` de
`/var/backups/stockapp/`. Recharger le serveur web
(`sudo systemctl reload nginx php8.3-fpm`).

Pour revenir ensuite à la branche : `sudo git checkout vps-mysql`.

---

## 15. Limites connues

| Point | Effet | Que faire |
|---|---|---|
| **Envoi d'emails non fonctionnel** | La bibliothèque d'envoi (PHPMailer) n'est pas livrée et aucun serveur SMTP n'est configuré. « Mot de passe oublié » échoue. | Les mots de passe se réinitialisent par un administrateur, dans Administration → Utilisateurs. |
| **Alertes quotidiennes** (fins de cycle, stocks bas) | `cron/check_alerts.php` appelle deux fonctions qui n'existent dans aucune version du code : la tâche s'arrête en erreur. | Laissée désactivée dans `cron-stockapp`. À développer avant activation. |
| Vues `v_cout_hebdo_site`, `v_cout_mensuel_site` | Héritées de l'ancien schéma, inutilisables avec le mode SQL par défaut de MySQL 8. | Aucune page ne les utilise : sans effet. |
| Serveur réel non encore vérifié | Les identifiants SSH transmis sont refusés ; les ports 80 et 443 sont fermés. | Voir l'annexe A ; reprendre les § 2 à 10 dès l'accès obtenu. |
| `/assets/` répond 403 | Les listes de fichiers sont désactivées. | Normal : les feuilles de style et images restent servies. |

---

## Annexe A — Check-list à transmettre à l'hébergeur

- ☐ Nom d'utilisateur SSH et méthode de connexion (clé ou mot de passe) pour 169.58.104.110
- ☐ Ce compte dispose de `sudo`
- ☐ Restrictions d'accès SSH éventuelles (adresses IP autorisées)
- ☐ Ouverture des ports **80** et **443** en entrée
- ☐ Port **3306** non exposé
- ☐ Accès sortant HTTPS vers `github.com` et Let's Encrypt
- ☐ Ubuntu 24.04 LTS confirmé ; logiciels déjà installés (serveur web, PHP, MySQL) et leurs versions
- ☐ Ressources : vCPU, RAM, disque
- ☐ Solution de copie des sauvegardes hors du serveur

## Annexe B — Fichiers fournis

| Fichier | Rôle |
|---|---|
| `deploy.sh` | Installation et mises à jour (sauvegarde, schéma, droits, rechargement) |
| `sql/installer_schema_mysql.sh` | Schéma complet sur base vide + suppression des données de démonstration |
| `tools/creer_superadmin.php` | Création du premier compte (ligne de commande uniquement) |
| `docs/deploiement/nginx-stockapp.conf` | Site Nginx |
| `docs/deploiement/apache-stockapp.conf` | Site Apache (variante) |
| `docs/deploiement/php-stockapp.ini` | Réglages PHP |
| `docs/deploiement/cron-stockapp` | Tâches planifiées → `/etc/cron.d/stockapp` |
| `docs/deploiement/logrotate-stockapp` | Rotation des journaux → `/etc/logrotate.d/stockapp` |

## Annexe C — Résultats des essais (09/10/2026)

Environnement : Ubuntu 24.04, Nginx 1.24 / Apache 2.4.58, PHP 8.3.6,
MySQL 8.0.46 local, compte MySQL limité à sa base.

| Essai | Résultat |
|---|---|
| Première installation par `deploy.sh` | 116 tables, 5 contrôles de schéma OK, base vide (19 référentiels seulement) |
| Création du super-administrateur | Compte créé (bcrypt coût 12) ; second lancement, mot de passe faible et confirmation différente refusés |
| Connexion + Accueil, Tableau de bord, Dashboard KPI, Point EMUCI, Équipements, Utilisateurs, Vue stock | 200, aucune erreur PHP ni SQL |
| Export Excel | Fichier `.xlsx` valide |
| Adresses sensibles (`.env`, `.git`, `sql/`, sauvegardes, `deploy.sh`, `composer.json`, `vendor/`, `includes/`, `docs/`, `tools/`, `migrate_site_id.php`, `Dockerfile`, script dans `uploads/`) | Nginx : toutes 404 — Apache : toutes 403 |
| `tools/creer_superadmin.php` depuis le web | 404 |
| Réglages PHP vus par PHP-FPM | 50M / 55M / 256M / 300 s / Africa/Abidjan / erreurs masquées |
| Tâche des 5 minutes (www-data) et sauvegarde nocturne (root) | OK ; sauvegarde complète (114 tables + 2 vues) |
| Restauration d'une sauvegarde par le compte de l'application | 116 tables rechargées |
| Mise à jour par `deploy.sh` (dépôt appartenant à www-data) | `git pull` OK, nouvelle migration signalée, base préservée |

Deux défauts trouvés pendant ces essais et corrigés dans cette version :
l'installation échouait avec un compte MySQL limité (vues déclarées
`DEFINER=root`), et la sauvegarde signalait une erreur de droits
(`--no-tablespaces` manquant). Avec l'ancien `deploy.sh`, la deuxième mise
à jour aurait aussi échoué (Git refuse en root un dossier appartenant à
`www-data`) : c'est corrigé.
