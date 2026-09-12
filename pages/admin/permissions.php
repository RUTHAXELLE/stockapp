<?php
// ============================================================
//  pages/admin/permissions.php  —  Gestion des permissions
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_auth();
require_permission('users', 'can_update');

$user        = current_user();
$page_title  = 'Permissions & Rôles';
$active_page = 'permissions';

// Source unique des modules gérés par cet écran (icône + libellé) —
// utilisée à la fois pour générer le tableau et pour savoir quels
// modules traiter à la sauvegarde/création de rôle (array_keys), au
// lieu de listes recopiées à la main qui finissent par diverger (trouvé
// avec inventaire/inventaire_rivets/inventaire_pmma/inventaire_equipements,
// présents dans le tableau mais absents de la liste JS de sauvegarde —
// remis silencieusement à zéro à chaque clic sur Sauvegarder, 2026-08-29).
// Groupes d'onglets (n° 2.7 réunion PDG — la matrice de 41 modules dans un
// seul tableau était illisible). Les clés reprennent les groupes de
// navigation de includes/groupes_config.php plutôt que la liste du CR, qui
// n'en cite que 6 et laisse 11 modules sans rattachement (écarts,
// inventaires, demandes, annuaire). Même découpage partout = un seul
// modèle mental pour l'administrateur.
$module_groupes = [
    // Le groupe DASHBOARD n'apparaissait pas ici tant qu'aucun module ne
    // s'y rattachait : dashboard.php et pdg_overview.php ne passent pas
    // par require_permission(). Sans cette entrée, le Dashboard KPI serait
    // devenu invisible sur cet écran — donc impossible à ouvrir à un rôle —
    // en rejoignant le groupe : l'onglet ne s'affiche que si $module_groupes
    // le déclare.
    'DASHBOARD'      => ['<i class="ph ph-squares-four" aria-hidden="true"></i>', 'Dashboard'],
    'STOCK'          => ['<i class="ph ph-package" aria-hidden="true"></i>', 'Stock'],
    'BOBINES'        => ['<i class="ph ph-film-strip" aria-hidden="true"></i>', 'Bobines'],
    'INVENTAIRE'     => ['<i class="ph ph-clipboard-text" aria-hidden="true"></i>', 'Inventaire'],
    'OPERATIONS'     => ['<i class="ph ph-lightning" aria-hidden="true"></i>', 'Opérations'],
    'INFORMATIQUE'   => ['<i class="ph ph-desktop-tower" aria-hidden="true"></i>', 'Informatique'],
    'RAPPORTS'       => ['<i class="ph ph-chart-bar" aria-hidden="true"></i>', 'Rapports'],
    'DEMANDES'       => ['<i class="ph ph-file-text" aria-hidden="true"></i>', 'Demandes internes'],
    // Ajouté 2026-09-10 : le module Achats (4 droits) existe en base depuis
    // sql/migration_achats_03_permissions.sql (2026-08) mais n'avait jamais
    // été exposé ici — impossible d'ajuster ces droits sans repasser par une
    // migration SQL. Même regroupement que includes/groupes_config.php.
    'ACHATS'         => ['<i class="ph ph-shopping-cart" aria-hidden="true"></i>', 'Achats'],
    'ADMINISTRATION' => ['<i class="ph ph-shield-check" aria-hidden="true"></i>', 'Administration'],
];

// Source unique des modules gérés par cet écran (icône + libellé + groupe) —
// utilisée à la fois pour générer le tableau et pour savoir quels
// modules traiter à la sauvegarde/création de rôle (array_keys), au
// lieu de listes recopiées à la main qui finissent par diverger (trouvé
// avec inventaire/inventaire_rivets/inventaire_pmma/inventaire_equipements,
// présents dans le tableau mais absents de la liste JS de sauvegarde —
// remis silencieusement à zéro à chaque clic sur Sauvegarder, 2026-08-29).
//
// 3e élément = groupe d'onglet. Les déstructurations à 2 éléments
// (foreach ... as [$mico,$mlbl]) restent valides : PHP ignore le surplus.
$modules = [
    // Module Achats — cf. note sur $module_groupes ci-dessus (2026-09-10).
    'achats'             => ['<i class="ph ph-list-checks" aria-hidden="true"></i>', 'Achats (FEB)', 'ACHATS'],
    'achats_dashboard'   => ['<i class="ph ph-gauge" aria-hidden="true"></i>', 'Dashboard Achats', 'ACHATS'],
    'achats_param'       => ['<i class="ph ph-sliders-horizontal" aria-hidden="true"></i>', 'Paramétrage achats', 'ACHATS'],
    'achats_suivi'       => ['<i class="ph ph-truck" aria-hidden="true"></i>', 'Suivi achats (DA/BC)', 'ACHATS'],
    // Ordre + regroupement alignés sur le menu Informatique
    // (includes/groupes_config.php) : Interventions, Rapport journalier,
    // Affectations IT, Transfert équipement. 'affectations' gouverne aussi
    // "Historique des mouvements" dans le menu Stock (can_read) : un seul
    // onglet ne peut pas représenter les deux à la fois, priorité donnée
    // ici à Informatique sur demande explicite.
    'interventions'      => ['<i class="ph ph-wrench" aria-hidden="true"></i>', 'Interventions', 'INFORMATIQUE'],
    'rapport_journalier' => ['<i class="ph ph-file-text" aria-hidden="true"></i>', 'Rapport journalier', 'INFORMATIQUE'],
    'affectations_it'    => ['<i class="ph ph-headset" aria-hidden="true"></i>', 'Affectations IT', 'INFORMATIQUE'],
    'affectations'       => ['<i class="ph ph-link" aria-hidden="true"></i>', 'Transfert équipement', 'INFORMATIQUE'],
    'bobines'            => ['<i class="ph ph-film-strip" aria-hidden="true"></i>', 'Bobines', 'STOCK'],
    'commandes'          => ['<i class="ph ph-storefront" aria-hidden="true"></i>', 'Commandes', 'STOCK'],
    'commandes_bobines'  => ['<i class="ph ph-shopping-cart" aria-hidden="true"></i>', 'Commande bobines', 'BOBINES'],
    'consommables'       => ['<i class="ph ph-flask" aria-hidden="true"></i>', 'Consommables', 'STOCK'],
    'delegations'        => ['<i class="ph ph-handshake" aria-hidden="true"></i>', 'Délégations', 'ADMINISTRATION'],
    // Scission par écran (2026-09) : "demandes" ne gouverne plus que "Mes
    // demandes" — cf. sql/migration_split_demandes_par_ecran.sql. "Types &
    // circuits" et "Circuits avancés" restent réservés admin/superadmin en
    // dur, hors table permissions : pas de module pour ces deux écrans.
    'demandes'           => ['<i class="ph ph-note-pencil" aria-hidden="true"></i>', 'Mes demandes', 'DEMANDES'],
    'demandes_new'       => ['<i class="ph ph-plus-circle" aria-hidden="true"></i>', 'Nouvelle demande', 'DEMANDES'],
    'demandes_valider'   => ['<i class="ph ph-seal-check" aria-hidden="true"></i>', 'À valider', 'DEMANDES'],
    'demandes_it'        => ['<i class="ph ph-wrench" aria-hidden="true"></i>', 'Traitements IT', 'DEMANDES'],
    'agents'             => ['<i class="ph ph-users" aria-hidden="true"></i>', 'Annuaire agents', 'DEMANDES'],
    'departements'       => ['<i class="ph ph-buildings" aria-hidden="true"></i>', 'Départements', 'ADMINISTRATION'],
    'ecarts_bobines'     => ['<i class="ph ph-warning-diamond" aria-hidden="true"></i>', 'Écarts bobines', 'INVENTAIRE'],
    'ecarts_rivets'      => ['<i class="ph ph-warning-diamond" aria-hidden="true"></i>', 'Écarts rivets', 'INVENTAIRE'],
    'ecarts_pmma'        => ['<i class="ph ph-warning-diamond" aria-hidden="true"></i>', 'Écarts PMMA', 'INVENTAIRE'],
    'ecarts_equipements' => ['<i class="ph ph-warning-diamond" aria-hidden="true"></i>', 'Écarts équipements', 'INVENTAIRE'],
    // Scission Informatique/Opérationnel (2026-09) : deux modules distincts
    // au lieu d'un seul 'equipements' couvrant les deux catégories — cf.
    // sql/migration_split_equipements_operationnel_vignette.sql.
    'equipements'        => ['<i class="ph ph-desktop" aria-hidden="true"></i>', 'Équipements Informatique', 'STOCK'],
    'equipements_operationnel' => ['<i class="ph ph-hard-hat" aria-hidden="true"></i>', 'Équipements Opérationnel', 'STOCK'],
    // Ordre aligné sur le menu Opérations (includes/groupes_config.php) :
    // Point journalier, Demande d'intervention, Suivi des observations,
    // Point EMUCI, Import EMUCI.
    'operations'         => ['<i class="ph ph-truck" aria-hidden="true"></i>', 'Points journaliers', 'OPERATIONS'],
    'observations'       => ['<i class="ph ph-chat-dots" aria-hidden="true"></i>', 'Suivi des observations', 'OPERATIONS'],
    'point_emuci'        => ['<i class="ph ph-magnifying-glass" aria-hidden="true"></i>', 'Point EMUCI', 'OPERATIONS'],
    'import_emuci'       => ['<i class="ph ph-download-simple" aria-hidden="true"></i>', 'Import EMUCI', 'OPERATIONS'],
    'inventaire'         => ['<i class="ph ph-clipboard-text" aria-hidden="true"></i>', 'Inventaire (accès module)', 'INVENTAIRE'],
    'inventaire_bobines' => ['<i class="ph ph-chart-bar" aria-hidden="true"></i>', 'Inventaire bobines', 'INVENTAIRE'],
    'inventaire_rivets'  => ['<i class="ph ph-chart-bar" aria-hidden="true"></i>', 'Inventaire rivets', 'INVENTAIRE'],
    'inventaire_pmma'    => ['<i class="ph ph-chart-bar" aria-hidden="true"></i>', 'Inventaire PMMA', 'INVENTAIRE'],
    'inventaire_equipements' => ['<i class="ph ph-chart-bar" aria-hidden="true"></i>', 'Inventaire équipements', 'INVENTAIRE'],
    'audit'              => ['<i class="ph ph-clipboard-text" aria-hidden="true"></i>', 'Journal d\'audit', 'ADMINISTRATION'],
    'kpi_dashboard'      => ['<i class="ph ph-gauge" aria-hidden="true"></i>', 'Dashboard KPI', 'DASHBOARD'],
    'nomenclatures'      => ['<i class="ph ph-tag" aria-hidden="true"></i>', 'Nomenclatures', 'ADMINISTRATION'],
    'pmma'               => ['<i class="ph ph-printer" aria-hidden="true"></i>', 'PMMA', 'STOCK'],
    'rapports'           => ['<i class="ph ph-chart-bar" aria-hidden="true"></i>', 'Rapports & Analyses', 'RAPPORTS'],
    'rapports_gsb'       => ['<i class="ph ph-clipboard-text" aria-hidden="true"></i>', 'Rapports & Exports', 'BOBINES'],
    'receptions'         => ['<i class="ph ph-package" aria-hidden="true"></i>', 'Réceptions site', 'STOCK'],
    'resume_superviseur' => ['<i class="ph ph-chart-line-up" aria-hidden="true"></i>', 'Résumé superviseur', 'RAPPORTS'],
    'rivets'             => ['<i class="ph ph-wrench" aria-hidden="true"></i>', 'Rivets', 'STOCK'],
    'simulation_stocks'  => ['<i class="ph ph-trend-up" aria-hidden="true"></i>', 'Simulation & projection', 'RAPPORTS'],
    'sites'              => ['<i class="ph ph-buildings" aria-hidden="true"></i>', 'Sites', 'ADMINISTRATION'],
    'tracabilite_endommagements' => ['<i class="ph ph-first-aid-kit" aria-hidden="true"></i>', 'Traçabilité endommagements', 'BOBINES'],
    'referentiels_operations' => ['<i class="ph ph-sliders-horizontal" aria-hidden="true"></i>', 'Référentiels & capacités', 'ADMINISTRATION'],
    'users'              => ['<i class="ph ph-users" aria-hidden="true"></i>', 'Utilisateurs', 'ADMINISTRATION'],
    'validation_stock'   => ['<i class="ph ph-check-circle" aria-hidden="true"></i>', 'Validation stock jour', 'BOBINES'],
    'stock_bobines'      => ['<i class="ph ph-chart-line-up" aria-hidden="true"></i>', 'Vue stock par site', 'BOBINES'],
    // Scission Bobines/Vignette (2026-09) : module distinct de 'bobines' —
    // cf. sql/migration_split_equipements_operationnel_vignette.sql.
    'vignette'           => ['<i class="ph ph-sticker" aria-hidden="true"></i>', 'Vignette', 'STOCK'],
];

// Modules indexés par groupe, pour le rendu des onglets. Construit depuis
// $modules (et non recopié) : un module ajouté plus haut apparaît
// forcément dans un onglet.
$modules_par_groupe = [];
foreach ($modules as $mk => $m) {
    $modules_par_groupe[$m[2] ?? 'ADMINISTRATION'][$mk] = $m;
}

// Onglet ouvert par défaut : le premier groupe qui porte réellement des
// modules, et non un nom écrit en dur. Le code fixait « STOCK », ce qui
// laissait l'onglet actif ailleurs qu'en tête dès qu'un groupe était
// ajouté avant lui.
$grp_defaut = (string) array_key_first(
    array_intersect_key($module_groupes, $modules_par_groupe));

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── SAUVEGARDER PERMISSIONS D'UN RÔLE
    if ($action === 'save_permissions') {
        // Seul superadmin peut modifier les permissions
        if ($user['role_slug'] !== 'superadmin')
            json_response(false, 'Seul le Super Administrateur peut modifier les permissions.');

        $role_id = (int)($_POST['role_id'] ?? 0);
        // Empêcher modification du superadmin lui-même
        $role = db_fetch_one("SELECT slug FROM roles WHERE id=?", [$role_id]);
        if ($role && $role['slug'] === 'superadmin')
            json_response(false, 'Les permissions du Super Administrateur ne peuvent pas être modifiées.');

        // array_keys() du $modules défini en haut de fichier — source
        // unique partagée avec le tableau HTML et le JS (ALL_MODULES) : une
        // case rendue à l'écran est forcément traitée ici.
        $modules_keys = array_keys($modules);
        $actions  = ['can_create','can_read','can_update','can_delete','can_export'];

        db_begin();
        try {
            foreach ($modules_keys as $module) {
                $perms = [];
                foreach ($actions as $a) {
                    $key = "perm_{$role_id}_{$module}_{$a}";
                    $perms[$a] = !empty($_POST[$key]) ? 1 : 0;
                }
                // can_read toujours au moins 0 (pas de forçage)
                db_query(
                    "INSERT INTO permissions (role_id,module,can_create,can_read,can_update,can_delete,can_export)
                     VALUES (?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       can_create=VALUES(can_create), can_read=VALUES(can_read),
                       can_update=VALUES(can_update), can_delete=VALUES(can_delete),
                       can_export=VALUES(can_export)",
                    [$role_id, $module, $perms['can_create'], $perms['can_read'],
                     $perms['can_update'], $perms['can_delete'], $perms['can_export']]
                );
            }
            audit_log($user['id'], 'UPDATE', 'users', $role_id,
                "Modification permissions rôle ID:$role_id");
            db_commit();
            json_response(true, 'Permissions sauvegardées.');
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    // ── CRÉER UN RÔLE PERSONNALISÉ
    if ($action === 'create_role') {
        if ($user['role_slug'] !== 'superadmin')
            json_response(false, 'Action réservée au Super Administrateur.');
        $nom  = trim($_POST['nom']  ?? '');
        $slug = strtolower(preg_replace('/[^a-z0-9_]/', '_', trim($_POST['slug'] ?? '')));
        $desc = trim($_POST['desc'] ?? '');
        if (!$nom || !$slug) json_response(false, 'Nom et slug obligatoires.');
        if (db_fetch_value("SELECT COUNT(*) FROM roles WHERE slug=?", [$slug]) > 0)
            json_response(false, "Le slug '$slug' existe déjà.");
        db_query("INSERT INTO roles (nom,slug,description) VALUES (?,?,?)", [$nom, $slug, $desc]);
        $id = (int)db_last_id();
        // Initialiser avec aucune permission — array_keys() du $modules
        // défini en haut de fichier, cf. save_permissions ci-dessus.
        foreach (array_keys($modules) as $m) {
            db_query("INSERT INTO permissions (role_id,module,can_read) VALUES (?,?,0)", [$id, $m]);
        }
        audit_log($user['id'], 'CREATE', 'users', $id, "Création rôle $nom");
        json_response(true, 'Rôle créé.', ['id' => $id]);
    }

    json_response(false, 'Action inconnue.');
}

// ============================================================
//  DONNÉES
// ============================================================
$roles   = db_fetch_all("SELECT * FROM roles ORDER BY id");
// $modules défini plus haut (avant le bloc AJAX) — source unique.
$actions = [
    'can_read'   => ['<i class="ph ph-eye" aria-hidden="true"></i>', 'Lire'],
    'can_create' => ['<i class="ph ph-plus" aria-hidden="true"></i>', 'Créer'],
    'can_update' => ['<i class="ph ph-pencil-simple" aria-hidden="true"></i>', 'Modifier'],
    'can_delete' => ['<i class="ph ph-trash" aria-hidden="true"></i>', 'Supprimer'],
    'can_export' => ['<i class="ph ph-download-simple" aria-hidden="true"></i>', 'Exporter'],
];

// Charger toutes les permissions
$all_perms = [];
$rows = db_fetch_all("SELECT * FROM permissions");
foreach ($rows as $p) {
    $all_perms[$p['role_id']][$p['module']] = $p;
}

// Nb users par rôle
$users_by_role = array_column(
    db_fetch_all("SELECT role_id, COUNT(*) AS n FROM users WHERE actif=1 GROUP BY role_id"),
    'n', 'role_id'
);

include __DIR__ . '/../../templates/header.php';
?>
<style>
/* Maitre-detail. minmax(0,1fr) et non 1fr : sans le minimum a zero, la
   colonne de detail refuserait de descendre sous la largeur de son tableau
   et repousserait la mise en page. */
.perm-layout{display:grid;grid-template-columns:236px minmax(0,1fr);gap:24px;align-items:start}
.perm-panes{min-width:0}

.perm-tabs{display:flex;flex-direction:column;gap:2px;
  background:var(--card,#fff);border:1px solid var(--border);border-radius:12px;padding:8px;
  max-height:calc(100vh - 210px);overflow-y:auto;position:sticky;top:88px}
.perm-tab{display:flex;align-items:baseline;gap:7px;flex-wrap:wrap;
  padding:9px 12px;font-size:13px;font-weight:500;color:var(--text,#2c3e50);
  cursor:pointer;text-align:left;width:100%;
  background:none;border:none;border-radius:8px;
  font-family:'Manrope',sans-serif;transition:background .15s,color .15s}
.perm-tab:hover{background:var(--lighter,#f0f4f8)}
.perm-tab.active{background:var(--navy,#1E2B4A);color:#fff;font-weight:700}  /* navy et non --blue-mid : depuis la refonte de palette --blue-mid vaut
     #5B76FF, ce qui ne donne que 3,83:1 avec du blanc. navy donne 14:1. */
.perm-tab.active span{opacity:.85 !important}
.perm-tabs::-webkit-scrollbar{width:8px}
.perm-tabs::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:8px}

/* Sous 980px la colonne passe au-dessus, en rangee defilante : garder une
   liste verticale y mangerait toute la hauteur utile. */
@media(max-width:980px){
  .perm-layout{grid-template-columns:minmax(0,1fr);gap:16px}
  .perm-tabs{flex-direction:row;position:static;max-height:none;overflow-x:auto;overflow-y:hidden;padding:6px;min-width:0}
  .perm-tab{width:auto;flex:0 0 auto;white-space:nowrap;flex-wrap:nowrap}
}
.perm-pane{display:none}
.perm-pane.active{display:block}

/* ── Onglets par groupe de modules (n° 2.7 réunion PDG) ──
   Second niveau d'onglets, à l'intérieur du panneau d'un rôle : la
   colonne de gauche sert déjà aux rôles. */
.grp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
.grp-tab{display:inline-flex;align-items:center;gap:6px;
  padding:7px 13px;font-size:12.5px;font-weight:600;
  font-family:'Manrope',sans-serif;
  color:var(--text,#2c3e50);background:var(--card,#fff);
  border:1px solid var(--border);border-radius:20px;cursor:pointer;
  transition:background .15s,color .15s,border-color .15s}
.grp-tab:hover{background:var(--lighter,#f0f4f8)}
.grp-tab.active{background:var(--navy,#1E2B4A);color:#fff;border-color:var(--navy,#1E2B4A)}
.grp-count{display:inline-block;min-width:18px;padding:1px 5px;border-radius:9px;
  font-size:10.5px;font-weight:700;background:var(--lighter,#f0f4f8);color:var(--muted)}
.grp-tab.active .grp-count{background:rgba(255,255,255,.22);color:#fff}

.grp-pane{display:none}
.grp-pane.active{display:block}

/* Bascule de colonne — même action pour tous les modules du groupe. */
.col-toggle{margin-left:5px;padding:0 4px;font-size:12px;line-height:1.4;
  border:1px solid var(--border);border-radius:5px;background:var(--card,#fff);
  color:var(--muted);cursor:pointer}
.col-toggle:hover{background:var(--navy,#1E2B4A);color:#fff;border-color:var(--navy,#1E2B4A)}

/* Vue globale : lecture seule, densité augmentée pour l'impression. */
.perm-global td{padding:7px 14px;font-size:13px}
.perm-global .gmark{text-align:center;font-weight:700}
.perm-global .gmark.yes{color:var(--success,#1E8449)}
.perm-global .gmark.no{color:var(--border)}
@media print{
  .perm-tabs,.grp-tabs,.role-header .btn{display:none!important}
  .grp-pane{display:none!important}
  .grp-pane[id$="-GLOBAL"]{display:block!important}
  .perm-table-wrap{max-height:none!important;overflow:visible!important}
}

/* Scroll interne au-delà de 8 lignes : hauteur = header (~46px) + 8 lignes (~50px/ligne).
   Classe plutôt qu'id : ce wrapper est répété une fois par rôle (foreach $roles). */
.perm-table-wrap{max-height:446px;overflow-y:auto}
.perm-table{width:100%;border-collapse:separate;border-spacing:0}
.perm-table thead{position:sticky;top:0;z-index:5}
.perm-table th{padding:10px 14px;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;text-align:left;background:var(--lighter,#f0f4f8);border-bottom:1px solid var(--border)}
.perm-table th.center{text-align:center}
.perm-table td{padding:12px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.perm-table tr:last-child td{border-bottom:none}
.perm-table tr:hover td{background:var(--lighter)}

.module-cell{display:flex;align-items:center;gap:10px}
.module-icon{font-size:18px;width:24px;text-align:center}
.module-name{font-size:13.5px;font-weight:500}

.perm-check-wrap{display:flex;justify-content:center}
.perm-toggle{
  width:40px;height:22px;border-radius:11px;background:var(--border);
  position:relative;cursor:pointer;transition:background .2s;border:none;
  flex-shrink:0;
}
.perm-toggle.on{background:var(--success)}
.perm-toggle.on.danger-perm{background:var(--danger)}
.perm-toggle::after{
  content:'';position:absolute;top:2px;left:2px;
  width:18px;height:18px;border-radius:50%;background:white;
  transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);
}
.perm-toggle.on::after{left:20px}
.perm-toggle:disabled{opacity:.4;cursor:not-allowed}

.role-header{display:flex;align-items:center;gap:14px;padding:20px;background:linear-gradient(135deg,var(--navy),#1a3c5e);border-radius:12px;margin-bottom:20px;color:white}
.role-avatar{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}
.role-title{font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800}
.role-sub{font-size:12px;opacity:.65;margin-top:3px}

.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(13,31,53,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;animation:mIn .25s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:700}
.mclose{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.mbody{padding:24px}
.mfoot{padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <p style="font-size:13.5px;color:var(--muted);max-width:600px">
    Configurez les droits d'accès pour chaque rôle. Les modifications sont appliquées immédiatement pour tous les utilisateurs du rôle concerné.
  </p>
  <?php if($user['role_slug']==='superadmin'): ?>
  <button class="btn btn-secondary" onclick="document.getElementById('mRole').classList.add('open')">+ Nouveau rôle</button>
  <?php endif; ?>
</div>

<!-- LISTE DES RÔLES + PANNEAU : avec 17 rôles, une rangée d'onglets
     obligeait à défiler pour savoir lesquels existent. En colonne, ils
     sont tous lisibles d'un regard. -->
<div class="perm-layout">
<nav class="perm-tabs" id="permTabs" aria-label="Rôles">
  <?php foreach($roles as $i=>$r): ?>
  <button class="perm-tab <?= $i===0?'active':'' ?>" onclick="showPerm('role-<?= $r['id'] ?>',this)">
    <?= h($r['nom']) ?>
    <span style="margin-left:6px;font-size:12px;opacity:.6">(<?= $users_by_role[$r['id']]??0 ?> user<?= ($users_by_role[$r['id']]??0)>1?'s':'' ?>)</span>
  </button>
  <?php endforeach; ?>
</nav>

<div class="perm-panes">
<?php foreach($roles as $r):
  $is_superadmin = $r['slug'] === 'superadmin';
  $can_edit      = $user['role_slug'] === 'superadmin' && !$is_superadmin;
  $role_icons    = ['superadmin'=>'<i class="ph ph-crown" aria-hidden="true"></i>','admin'=>'<i class="ph ph-shield" aria-hidden="true"></i>','lecteur'=>'<i class="ph ph-briefcase" aria-hidden="true"></i>'];
?>
<div class="perm-pane <?= $r===reset($roles)?'active':'' ?>" id="role-<?= $r['id'] ?>">

  <!-- RÔLE HEADER -->
  <div class="role-header">
    <div class="role-avatar"><?= $role_icons[$r['slug']] ?? '<i class="ph ph-user" aria-hidden="true"></i>' ?></div>
    <div style="flex:1">
      <div class="role-title"><?= h($r['nom']) ?></div>
      <div class="role-sub">
        <?= h($r['description'] ?: 'Aucune description') ?> ·
        <?= $users_by_role[$r['id']]??0 ?> utilisateur(s) actif(s)
      </div>
    </div>
    <?php if($is_superadmin): ?>
    <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:8px 14px;font-size:12px;font-weight:600">
      <i class="ph ph-lock" aria-hidden="true"></i> Accès total — non modifiable
    </div>
    <?php elseif($can_edit): ?>
    <button class="btn" style="background:var(--navy,#1E2B4A);color:white" onclick="savePerms(<?= $r['id'] ?>)">
      <i class="ph ph-floppy-disk" aria-hidden="true"></i> Sauvegarder
    </button>
    <?php else: ?>
    <div style="background:rgba(255,255,255,.1);border-radius:8px;padding:8px 14px;font-size:12px">
      Lecture seule
    </div>
    <?php endif; ?>
  </div>

  <?php if($is_superadmin): ?>
  <div class="alert alert-info">
    <span><i class="ph ph-crown" aria-hidden="true"></i></span> Le Super Administrateur a accès à toutes les fonctionnalités sans restriction. Ses permissions ne peuvent pas être modifiées.
  </div>

  <?php else: ?>
  <!-- ONGLETS PAR GROUPE DE MODULES (n° 2.7 réunion PDG)
       Tous les groupes sont rendus dans le DOM et masqués en CSS : les
       champs cachés existent donc quel que soit l'onglet affiché, et
       savePerms() (qui boucle sur ALL_MODULES) continue de tout envoyer.
       Ne jamais rendre uniquement l'onglet actif — ce serait rejouer le
       bug de remise à zéro silencieuse documenté en tête de fichier. -->
  <nav class="grp-tabs" aria-label="Groupes de modules">
    <?php foreach($module_groupes as $gk=>[$gico,$glbl]):
      if (empty($modules_par_groupe[$gk])) continue;
      $gcount = count($modules_par_groupe[$gk]);
    ?>
    <button class="grp-tab <?= $gk===$grp_defaut?'active':'' ?>"
            onclick="showGrp('<?= $r['id'] ?>','<?= $gk ?>',this)">
      <?= $gico ?> <?= h($glbl) ?><span class="grp-count"><?= $gcount ?></span>
    </button>
    <?php endforeach; ?>
    <button class="grp-tab" onclick="showGrp('<?= $r['id'] ?>','GLOBAL',this)">
      <i class="ph ph-list" aria-hidden="true"></i> Vue globale<span class="grp-count"><?= count($modules) ?></span>
    </button>
  </nav>

  <?php foreach($module_groupes as $gk=>[$gico,$glbl]):
    if (empty($modules_par_groupe[$gk])) continue;
  ?>
  <div class="card grp-pane <?= $gk===$grp_defaut?'active':'' ?>" id="grp-<?= $r['id'] ?>-<?= $gk ?>">
    <div class="perm-table-wrap">
    <table class="perm-table">
      <thead>
        <tr>
          <th style="width:200px"><?= $gico ?> <?= h($glbl) ?></th>
          <?php foreach($actions as $ak=>[$aico,$albl]): ?>
          <th class="center">
            <?= $aico ?> <?= $albl ?>
            <?php if($can_edit): ?>
            <button class="col-toggle" title="Tout activer/désactiver la colonne <?= $albl ?>"
                    onclick="toggleCol('<?= $r['id'] ?>','<?= $gk ?>','<?= $ak ?>')">⇅</button>
            <?php endif; ?>
          </th>
          <?php endforeach; ?>
          <?php if($can_edit): ?>
          <th class="center">Ligne</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach($modules_par_groupe[$gk] as $mk=>[$mico,$mlbl]):
          $perms = $all_perms[$r['id']][$mk] ?? [];
        ?>
        <tr>
          <td>
            <div class="module-cell">
              <span class="module-icon"><?= $mico ?></span>
              <span class="module-name"><?= $mlbl ?></span>
            </div>
          </td>
          <?php foreach($actions as $ak=>[$aico,$albl]):
            $val   = !empty($perms[$ak]);
            $isDel = $ak==='can_delete';
            $name  = "perm_{$r['id']}_{$mk}_{$ak}";
          ?>
          <td>
            <div class="perm-check-wrap">
              <button type="button"
                class="perm-toggle <?= $val?'on':'' ?> <?= $isDel&&$val?'danger-perm':'' ?>"
                id="<?= $name ?>"
                data-name="<?= $name ?>"
                data-val="<?= $val?1:0 ?>"
                onclick="<?= $can_edit?"togglePerm(this)":'void(0)' ?>"
                <?= !$can_edit?'disabled':'' ?>
                title="<?= $albl ?> — <?= $mlbl ?>">
              </button>
              <input type="hidden" id="h_<?= $name ?>" name="<?= $name ?>" value="<?= $val?1:0 ?>">
            </div>
          </td>
          <?php endforeach; ?>
          <?php if($can_edit): ?>
          <td style="text-align:center">
            <button class="btn btn-secondary btn-sm" onclick="toggleRow('<?= $r['id'] ?>','<?= $mk ?>')" title="Tout activer/désactiver">⇄</button>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- VUE GLOBALE — lecture seule, pour impression et export.
       Volontairement sans id ni champ caché : dupliquer les identifiants
       des bascules casserait getElementById() utilisé par toute la page. -->
  <div class="card grp-pane" id="grp-<?= $r['id'] ?>-GLOBAL">
    <div class="perm-table-wrap" style="max-height:none">
    <table class="perm-table perm-global">
      <thead>
        <tr>
          <th style="width:200px">Module</th>
          <th>Groupe</th>
          <?php foreach($actions as $ak=>[$aico,$albl]): ?>
          <th class="center"><?= $albl ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach($module_groupes as $gk=>[$gico,$glbl]):
          if (empty($modules_par_groupe[$gk])) continue;
          foreach($modules_par_groupe[$gk] as $mk=>[$mico,$mlbl]):
            $perms = $all_perms[$r['id']][$mk] ?? [];
        ?>
        <tr>
          <td>
            <div class="module-cell">
              <span class="module-icon"><?= $mico ?></span>
              <span class="module-name"><?= $mlbl ?></span>
            </div>
          </td>
          <td style="font-size:12.5px;color:var(--muted)"><?= h($glbl) ?></td>
          <?php foreach($actions as $ak=>[$aico,$albl]):
            $val = !empty($perms[$ak]);
          ?>
          <td class="center gmark <?= $val?'yes':'no' ?>"><?= $val ? '✓' : '·' ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <?php if($can_edit): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding:14px 18px;background:white;border:1px solid var(--border);border-radius:10px;flex-wrap:wrap;gap:10px">
    <div style="font-size:13px;color:var(--muted)">
      <i class="ph ph-lightbulb" aria-hidden="true"></i> Les modifications ne prennent effet qu'après avoir cliqué sur <strong>Sauvegarder</strong>.
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-secondary" onclick="allOff(<?= $r['id'] ?>)"><i class="ph ph-prohibit" aria-hidden="true"></i> Tout désactiver</button>
      <button class="btn btn-primary" onclick="savePerms(<?= $r['id'] ?>)"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Sauvegarder les permissions</button>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>
<?php endforeach; ?>
</div><!-- /perm-panes -->
</div><!-- /perm-layout -->

<!-- MODAL NOUVEAU RÔLE -->
<div class="modal-overlay" id="mRole">
  <div class="modal">
    <div class="mhdr"><h3>Nouveau rôle</h3><button class="mclose" onclick="document.getElementById('mRole').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <div id="mRAlert"></div>
      <div class="form-group"><label>Nom du rôle *</label>
        <input type="text" class="form-control" id="rNom" placeholder="Ex: Superviseur">
      </div>
      <div class="form-group"><label>Slug * <span style="font-size:12px;color:var(--muted)">(identifiant unique, lettres/chiffres)</span></label>
        <input type="text" class="form-control" id="rSlug" placeholder="superviseur" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'_')">
      </div>
      <div class="form-group"><label>Description</label>
        <textarea class="form-control" id="rDesc" rows="3" placeholder="Description du rôle…"></textarea>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mRole').classList.remove('open')">Annuler</button>
      <button class="btn btn-primary" onclick="saveRole()"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Créer le rôle</button>
    </div>
  </div>
</div>

<script>
// Dérivé du même $modules PHP qui génère les lignes du tableau (au lieu
// d'une liste JS recopiée à la main) : une case rendue à l'écran est
// forcément dans cette liste, donc toujours effectivement sauvegardée.
// Trouvé en debuggant pourquoi les droits 'inventaire'/'inventaire_rivets'/
// 'inventaire_pmma'/'inventaire_equipements' se remettaient silencieusement
// à zéro à chaque clic sur Sauvegarder — ils avaient été ajoutés au tableau
// PHP mais pas à l'ancienne liste JS recopiée, donc jamais inclus dans le
// FormData envoyé au serveur (2026-08-29).
const ALL_MODULES = <?= json_encode(array_keys($modules)) ?>;

// Modules par groupe — dérivé du même $modules PHP, pour que la bascule
// de colonne ne porte que sur les modules réellement affichés dans
// l'onglet courant.
const MODULES_PAR_GROUPE = <?= json_encode(array_map('array_keys', $modules_par_groupe)) ?>;

const PERM_ACTIONS = ['can_read','can_create','can_update','can_delete','can_export'];

function showPerm(id,btn){
  document.querySelectorAll('.perm-pane').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.perm-tab').forEach(b=>b.classList.remove('active'));
  document.getElementById(id).classList.add('active'); btn.classList.add('active');
}

// Onglets de groupe — portée limitée au panneau du rôle courant, sinon
// changer de groupe sur un rôle le changerait sur tous les autres.
function showGrp(roleId, groupe, btn){
  const pane = document.getElementById('role-'+roleId);
  if(!pane) return;
  pane.querySelectorAll('.grp-pane').forEach(p=>p.classList.remove('active'));
  pane.querySelectorAll('.grp-tab').forEach(b=>b.classList.remove('active'));
  const target = document.getElementById('grp-'+roleId+'-'+groupe);
  if(target) target.classList.add('active');
  btn.classList.add('active');
}

// Bascule d'une action pour tous les modules du groupe affiché.
function toggleCol(roleId, groupe, action){
  const mods = MODULES_PAR_GROUPE[groupe] || [];
  const allOn = mods.every(m=>{
    const b=document.getElementById(`perm_${roleId}_${m}_${action}`);
    return b && b.dataset.val==='1';
  });
  const newVal = allOn ? 0 : 1;
  mods.forEach(m=>{
    const b=document.getElementById(`perm_${roleId}_${m}_${action}`);
    if(!b) return;
    b.dataset.val=String(newVal);
    b.classList.toggle('on',newVal===1);
    b.classList.toggle('danger-perm',newVal===1&&action==='can_delete');
    document.getElementById('h_'+b.id).value=newVal;
  });
}

function togglePerm(btn){
  const newVal = btn.dataset.val==='0'?1:0;
  btn.dataset.val=String(newVal);
  btn.classList.toggle('on',newVal===1);
  const isDel=btn.id.includes('_can_delete');
  btn.classList.toggle('danger-perm',newVal===1&&isDel);
  document.getElementById('h_'+btn.id).value=newVal;
}

function toggleRow(roleId, module){
  const actions=['can_read','can_create','can_update','can_delete','can_export'];
  const allOn=actions.every(a=>{
    const b=document.getElementById(`perm_${roleId}_${module}_${a}`);
    return b&&b.dataset.val==='1';
  });
  actions.forEach(a=>{
    const b=document.getElementById(`perm_${roleId}_${module}_${a}`);
    if(b){
      const newVal=allOn?0:1;
      b.dataset.val=String(newVal);
      b.classList.toggle('on',newVal===1);
      b.classList.toggle('danger-perm',newVal===1&&a==='can_delete');
      document.getElementById('h_'+b.id).value=newVal;
    }
  });
}

function allOff(roleId){
  if(!confirm('Désactiver TOUTES les permissions de ce rôle ?'))return;
  const modules=ALL_MODULES;
  const actions=['can_read','can_create','can_update','can_delete','can_export'];
  modules.forEach(m=>actions.forEach(a=>{
    const b=document.getElementById(`perm_${roleId}_${m}_${a}`);
    if(b){b.dataset.val='0';b.classList.remove('on','danger-perm');document.getElementById('h_'+b.id).value=0;}
  }));
}

function savePerms(roleId){
  const modules=ALL_MODULES;
  const actions=['can_read','can_create','can_update','can_delete','can_export'];
  const fd=new FormData();
  fd.append('action','save_permissions');
  fd.append('role_id',roleId);
  modules.forEach(m=>actions.forEach(a=>{
    const hid=document.getElementById(`h_perm_${roleId}_${m}_${a}`);
    if(hid)fd.append(`perm_${roleId}_${m}_${a}`,hid.value);
  }));
  fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json())
    .then(d=>toast(d.message,d.success?'success':'danger'))
    .catch(()=>toast('Erreur réseau lors de la sauvegarde.','danger'));
}

function saveRole(){
  const fd=new FormData();
  fd.append('action','create_role');
  fd.append('nom',document.getElementById('rNom').value);
  fd.append('slug',document.getElementById('rSlug').value);
  fd.append('desc',document.getElementById('rDesc').value);
  fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json())
    .then(d=>{
      if(d.success){toast(d.message,'success');document.getElementById('mRole').classList.remove('open');setTimeout(()=>location.reload(),800);}
      else document.getElementById('mRAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
    });
}
document.getElementById('mRole').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
