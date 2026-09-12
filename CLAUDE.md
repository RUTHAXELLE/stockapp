# ERP EMUCI — Contexte projet pour Claude Code

## Présentation

Application web de gestion de stock industriel (bobines, équipements, opérations terrain) pour EMU-CI dans le cadre du projet NSIIV (Nouveau Système d'Immatriculation des Véhicules).

**Nom interne :** ERP EMUCI / StockApp
**GitHub :** GayeGuy/ERP-EMUCI-V2, branche `main` (transféré depuis RUTHAXELLE/stockapp — conservé sous ce nom pour la branche `vps-mysql`)
**Production :** https://stockapp-p8us.onrender.com (auto-déployé depuis GitHub `main` via Render + Docker, région Frankfurt)

---

## Stack technique

| Couche | Technologie |
|---|---|
| Langage | PHP 8.2 (pas de framework) |
| Base de données | PostgreSQL (Neon, PDO `pdo_pgsql`, `sslmode=require`) — migré depuis MySQL le 2026-07-21 |
| Hébergement | Render (Docker, `php -S 0.0.0.0:$PORT`) |
| Dev local | Variables d'environnement (`includes/db.php` → `env()`), valeurs par défaut PostgreSQL local si absentes |
| Export Excel | `phpoffice/phpspreadsheet ^1.29` |
| Export PDF | `dompdf/dompdf ^3.1` |
| Export PPTX | ZipArchive natif PHP (Open XML manuel) |
| Icons | Phosphor Icons (CDN) |

---

## Structure des fichiers

```
stockapp/
├── index.php                         # Entrée principale (routeur simple)
├── login.php / logout.php
├── Dockerfile                        # php:8.2-cli + pdo_pgsql + php -S (Render)
├── render.yaml                       # Render Blueprint
├── composer.json
├── includes/
│   ├── db.php                        # PDO, constantes DB, helpers SQL
│   ├── session.php                   # Auth, permissions, current_user()
│   ├── helpers.php                   # h(), fmt_date(), pagination(), etc.
│   └── groupes_config.php            # Groupes de menus par rôle
├── pages/
│   ├── admin/
│   │   ├── users.php                 # Gestion utilisateurs
│   │   ├── permissions.php           # Matrice droits rôle × module
│   │   └── audit.php                 # Journal d'audit
│   ├── operations/
│   │   └── point_journalier.php      # Points journaliers coordinateur + corrections GSB
│   ├── stock_bobines_vue.php         # Vue stock bobines par site (+ export XLSX/PPTX)
│   ├── bobines.php                   # Liste bobines avec filtres AJAX (sans rechargement)
│   ├── inventaire_bobines.php
│   ├── validation_stock_matin.php    # Validation stock matin GSB + demande corrections
│   ├── rapports_gsb.php              # Rapports & exports GSB (Excel 3 feuilles + PDF)
│   ├── commandes_bobines.php
│   ├── dashboard.php
│   ├── pdg_overview.php              # Vue PDG / supervision globale
│   ├── equipements.php
│   ├── interventions.php
│   └── ...
├── templates/
│   ├── header.php                    # Nav principale avec groupes de menus + CSS variables
│   ├── footer.php
│   └── 403.php
└── sql/
    ├── stockapp_pg.sql               # Schéma + données initiales PostgreSQL
    └── migration_fix_permissions_regressions.sql  # Migration permissions (2026-07-30)
```

---

## Fonctions clés — includes/db.php

```php
get_db(): PDO                        // singleton PDO
db_query(sql, params): PDOStatement
db_fetch_all(sql, params): array
db_fetch_one(sql, params): ?array
db_fetch_value(sql, params): mixed   // première colonne, première ligne
db_last_id(): string
db_begin() / db_commit() / db_rollback()
```

---

## Rôles utilisateurs

| Slug | Description |
|---|---|
| `admin` / `superadmin` | Accès total |
| `gsb` | Gestionnaire stock bobines — validation matin, rapports, corrections |
| `coordinateur_site` | Coordinateur terrain — points journaliers, réponse corrections |
| `superviseur_operation` | Supervision opérations |
| `pdg` | Vue globale lecture seule (pdg_overview.php) |

---

## Système de permissions

Les droits sont stockés en base dans une table `permissions` (rôle × module × can_create/can_read/can_update/can_delete).

**Point critique :** Toute modification de permissions peut créer des régressions silencieuses (403 ou blocs dashboard disparus). Toujours auditer l'impact sur tous les rôles concernés avant de pousser.

La migration `sql/migration_fix_permissions_regressions.sql` (2026-07-30) corrige :
- 403 sur `validation_stock_matin.php` pour 3 rôles
- Blocs dashboard disparus pour 4 rôles

---

## Conventions de code

- Toujours utiliser les helpers `db_*` de `includes/db.php`, jamais PDO directement
- Échapper les sorties HTML avec `h()` (défini dans `helpers.php`)
- Les `use PhpOffice\...` doivent être **en tête de fichier** (avant tout code exécutable) — PHP interdit les alias `use` dans des blocs `if` ou des fonctions
- Les notifications applicatives utilisent le type `'info'` (contrainte enum sur `notifications.type` : `'fin_cycle'`, `'stock_bas'`, `'alerte_conso'`, `'info'`)

---

## CSS — conventions visuelles

Variables CSS globales (définies dans `templates/header.php`) :

```css
--navy: #06033A
--blue: #1B75BC
--border: #e2e8f0
--muted: #94a3b8
```

**Piège CSS connu :** `.vue-table tbody tr:nth-child(even) td` a une spécificité plus haute que `.vue-table tr.total-row td`. Pour forcer le style sur la ligne total, utiliser `!important`.

---

## Notes techniques importantes

### Export PPTX
- Génération via `ZipArchive::addFromString()` (pas de répertoire temporaire)
- Ne jamais mettre `<p:ph type="body"/>` dans un slide avec layout "blank"
- Les text boxes utilisent `<p:cNvSpPr txBox="1"/>` sans `<p:ph>`
- Fond de slide via `<p:bg><p:bgPr>`, pas via une forme rectangle
- Toujours `tempnam(sys_get_temp_dir(), 'pptx_')` pour le fichier temp + `unlink()` après `readfile()`

### Export XLSX
- Alias `XlsxWriter` (pas `Xlsx`) pour éviter le conflit avec la variable `$export === 'xlsx'`

### bobines.php — filtres AJAX
- Les filtres ne rechargent pas la page : échange AJAX du tableau uniquement
- Suppression du flash de rechargement (ancien `@view-transition` CSS remplacé par AJAX pur)

### point_journalier.php — bugs corrigés
- Brouillon reset à zéro : `editPoint` JS restaure tous les champs (véhicules, bobines, rivets)
- Double déduction stock : le chemin UPDATE restaure `films_restants` avant DELETE + re-INSERT
- Point vide soumis : `soumettre_point` et `valider_point_coord` vérifient `COUNT(*) FROM op_films_utilises`

### validation_stock_matin.php
- Vue GSB : 2 onglets (Validation en cours / Historique 30 jours)
- Bouton "Demander modif" visible uniquement pour GSB (`$can_valider`), masqué pour coordinateur
- Statuts : `Conforme` (vert) / `Avec écart` (orange) / `Réajusté` (bleu) / `Bloqué` (rouge)

### pdg_overview.php
- Fix département N+1 appliqué (eager loading des départements)

### users.php (admin)
- Validation stricte nom/email/téléphone/mot de passe à la création
- Surbrillance rouge au blur sur les champs invalides

---

## État du projet (2026-07-30)

**Code :** À jour, tout est poussé sur `main`. Render redéploie automatiquement.

### Terminé ✅
- Migration MySQL → PostgreSQL/Neon + déploiement Render (2026-07-21)
- Flux correction bobines GSB → Coordinateur (table `corrections_bobines`)
- Page Rapports & Exports GSB (`pages/rapports_gsb.php`)
- validation_stock_matin.php : onglets, filtres, légende, masquage bouton coordinateur
- point_journalier.php : restauration brouillon, double déduction stock, point vide bloqué
- Recherche + filtres réactifs sur la page Utilisateurs (`pages/admin/users.php`)
- Scroll après N lignes sur les tableaux (sites, EMUCI inconnus, bobines)
- Fix département N+1 dans pdg_overview.php
- Validation stricte + surbrillance rouge sur création utilisateur
- Redesign boutons Retour/PDF sur fiche de demande
- Colonne Consommé avant Restants + scroll sur bobines.php
- Fix flash rechargement : filtres bobines.php passés en AJAX pur
- Audit permissions + correction 3 régressions (403 + blocs dashboard disparus) → `sql/migration_fix_permissions_regressions.sql`

### Backlog ⏳
- [ ] Supprimer `fix_columns.php` à la racine (migration one-shot terminée)
- [ ] Vérifier permissions `coordinateur_site` sur `inventaire_bobines` dans Admin → Permissions
- [ ] Déploiement VPS : Nginx/Apache + PHP-FPM + MySQL + Certbot (branche `vps-mysql`, guide `DEPLOY-VPS-MYSQL.md`)
- [ ] Révoquer l'ancien mot de passe MySQL Railway exposé dans l'historique Git
- [ ] Changer le mot de passe admin par défaut `Admin@2024`

---

## Commandes utiles

```bash
# Déployer (push suffit — Render auto-déploie depuis GitHub main)
git push origin main

# Voir les logs Render
# Dashboard Render → service stockapp → Logs

# Charger le schéma PostgreSQL sur Neon
psql "<connection string Neon>" < sql/stockapp_pg.sql
```
