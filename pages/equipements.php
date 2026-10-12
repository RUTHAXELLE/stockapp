<?php
// ============================================================
//  pages/equipements.php — Gestion Équipements
//  + Taux de pannes + Blocs résumé par type + Amortissement OHADA
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();

// Deux modules de permission distincts depuis la scission Informatique /
// Opérationnel (2026-09) : chaque catégorie s'octroie indépendamment dans
// Administration → Permissions. « equipements » reste le module de la
// catégorie Informatique (nom historique, pas renommé pour ne pas perdre
// les droits déjà accordés) ; « equipements_operationnel » est le nouveau
// module, seedé depuis « equipements » par sql/migration_split_
// equipements_operationnel_vignette.sql.
$f_categorie  = trim($_GET['categorie'] ?? 'informatique');
$perm_module  = $f_categorie === 'operationnel' ? 'equipements_operationnel' : 'equipements';
require_permission($perm_module, 'can_read');

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$is_coord  = ($role_slug === 'coordinateur_site');
$site_force= ($is_coord && ($user['site_id'] ?? 0)) ? (int)$user['site_id'] : 0;

$page_title  = 'Équipements';
$active_page = isset($_GET['categorie']) && $_GET['categorie']==='operationnel' ? 'equipements_op' : 'equipements_info';
$f_site      = $site_force ?: (int)($_GET['site'] ?? 0);
$f_etat      = trim($_GET['etat'] ?? '');
$f_type      = (int)($_GET['type'] ?? 0);
$f_search    = trim($_GET['q'] ?? '');
$f_statut_stock = trim($_GET['statut_stock'] ?? '');
$f_fin_cycle    = !empty($_GET['fin_cycle']);

$sites_list  = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
$nomenclatures     = db_fetch_all("SELECT id,code,libelle,categorie,duree_vie_mois FROM nomenclatures WHERE categorie=? ORDER BY libelle", [$f_categorie]);
$all_nomenclatures = db_fetch_all("SELECT id,libelle,categorie FROM nomenclatures ORDER BY categorie,libelle");
$can_create  = can($perm_module,'can_create');
$can_update  = can($perm_module,'can_update');

// ── Amortissements OHADA (durées standard en mois)
$ohada_durees = [
    'informatique'  => 36,   // Matériel informatique : 3 ans
    'mobilier'      => 120,  // Mobilier : 10 ans
    'vehicule'      => 60,   // Véhicules : 5 ans
    'operationnel'  => 60,   // Matériel et outillage : 5 ans
    'default'       => 60,
];

// ── Numéro de série interne "CODE-EMUCI-NNNN" — reprise du trigger MySQL
// trg_equipement_numero_serie (sql/migration_stockapp_complet.sql), jamais
// recréé lors de la migration vers PostgreSQL du 2026-07-21 : le champ
// restait vide à la création faute d'équivalent côté base. Séquence par
// nomenclature (comme l'original), pas globale.
function _prochain_numero_serie(int $nomenclature_id): string {
    $code = $nomenclature_id
        ? (db_fetch_value("SELECT code FROM nomenclatures WHERE id=?", [$nomenclature_id]) ?: 'EQP')
        : 'EQP';
    // MySQL : ::int -> CAST(... AS UNSIGNED), opérateur ~ -> REGEXP, et
    // CONCAT() au lieu de || (qui vaut OR en MySQL hors PIPES_AS_CONCAT).
    // Cette requête était restée en syntaxe PostgreSQL sur la branche :
    // la création d'un équipement échouait faute de numéro.
    $seq = (int)db_fetch_value(
        "SELECT COALESCE(MAX(CAST(REGEXP_REPLACE(numero_serie_interne,'.*-','') AS UNSIGNED)),0)+1
         FROM equipements
         WHERE nomenclature_id=? AND numero_serie_interne REGEXP CONCAT('^', ?, '-[A-Z]+-[0-9]+$')",
        [$nomenclature_id, $code]
    );
    return $code . '-EMUCI-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ── IMPORT EN MASSE (Excel/CSV) — bouton "Importer" à côté de "Ajouter".
// Form classique (pas AJAX) pour l'upload de fichier, comme
// pages/import_emuci.php : plus simple qu'un fetch+FormData+JSON pour un
// fichier, et permet d'afficher un rapport d'erreurs détaillé après
// rechargement plutôt qu'un toast.
require_once __DIR__ . '/../includes/import_helpers.php';

if (($_GET['modele'] ?? '') === '1') {
    require_once __DIR__ . '/../vendor/autoload.php';
    import_generer_modele(
        ['Marque','Modèle','Type','N° Série externe','Site','État','Date acquisition','Prix achat','Emplacement'],
        ['HP','LaserJet Pro', $nomenclatures[0]['libelle'] ?? '', 'SN123456', $sites_list[0]['nom'] ?? '', 'neuf', date('d/m/Y'), '150000', 'Bureau RH, 2e étage'],
        'modele_equipements_' . $f_categorie . '.xlsx'
    );
}

$import_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_ajax() && isset($_FILES['fichier_import'])) {
    if (!$can_create) {
        $import_result = ['ok' => false, 'erreur' => 'Accès refusé.'];
    } else {
        $ext = strtolower(pathinfo($_FILES['fichier_import']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls','csv'], true)) {
            $import_result = ['ok' => false, 'erreur' => 'Format invalide. Utilisez .xlsx, .xls ou .csv.'];
        } else {
            require_once __DIR__ . '/../vendor/autoload.php';
            try {
                $rows = import_lire_fichier($_FILES['fichier_import']['tmp_name']);
                if (empty($rows)) throw new Exception('Fichier vide.');

                $headers = array_map('trim', $rows[0]);
                $col = array_flip($headers);
                if (!isset($col['Type']))
                    throw new Exception('Colonne manquante : "Type". Colonnes détectées : ' . implode(', ', array_filter($headers)));
                if (!isset($col['Marque']) && !isset($col['Modèle']))
                    throw new Exception('Colonnes manquantes : au moins "Marque" ou "Modèle". Colonnes détectées : ' . implode(', ', array_filter($headers)));

                $get = fn(array $row, string $k): string => isset($col[$k]) ? trim((string)($row[$col[$k]] ?? '')) : '';

                $nom_par_libelle = [];
                foreach ($nomenclatures as $n) $nom_par_libelle[mb_strtolower(trim($n['libelle']))] = $n;

                // Compteurs lus une fois puis incrémentés en mémoire — pas une
                // requête par ligne, indispensable pour un fichier de plusieurs
                // milliers de lignes.
                $numero_chrono_next = (int)db_fetch_value("SELECT COALESCE(MAX(numero_chrono),0) FROM equipements");
                $seq_par_code = [];
                $prochain_numero = function (string $code) use (&$seq_par_code): string {
                    if (!isset($seq_par_code[$code])) {
                        $seq_par_code[$code] = (int)db_fetch_value(
                            "SELECT COALESCE(MAX((regexp_replace(numero_serie_interne,'.*-',''))::int),0)
                             FROM equipements
                             WHERE numero_serie_interne ~ ('^' || ? || '-[A-Z]+-[0-9]+$')",
                            [$code]
                        );
                    }
                    $seq_par_code[$code]++;
                    return $code . '-EMUCI-' . str_pad((string)$seq_par_code[$code], 4, '0', STR_PAD_LEFT);
                };

                // Anti-doublon sur le N° série externe (seul identifiant physique
                // de l'équipement) : contre la base, toutes catégories et
                // réformés compris, et contre les lignes déjà lues du fichier —
                // réimporter le même fichier ne crée donc rien deux fois.
                $nse_existants = [];
                foreach (db_fetch_all(
                    "SELECT LOWER(TRIM(numero_serie_origine)) AS k, numero_serie_interne
                     FROM equipements
                     WHERE numero_serie_origine IS NOT NULL AND TRIM(numero_serie_origine) <> ''"
                ) as $r) $nse_existants[$r['k']] = $r['numero_serie_interne'];
                $nse_fichier = [];

                $duree_mois_cat = $ohada_durees[$f_categorie] ?? $ohada_durees['default'];
                $etats_valides  = ['neuf','bon','usage','endommage','hs'];

                $nb_ok = 0; $erreurs = [];
                db_begin();
                try {
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue;
                        $ln = $i + 1;

                        $marque = $get($row, 'Marque');
                        $modele = $get($row, 'Modèle');
                        if (!$marque && !$modele) { $erreurs[] = "Ligne $ln : Marque ou Modèle obligatoire."; continue; }

                        // nomenclature_id est NOT NULL en base (equipements) :
                        // Type est donc obligatoire ici, pas optionnel comme le
                        // reste des colonnes.
                        $type_lib = $get($row, 'Type');
                        if ($type_lib === '') { $erreurs[] = "Ligne $ln : Type obligatoire."; continue; }
                        $n = $nom_par_libelle[mb_strtolower($type_lib)] ?? null;
                        if (!$n) { $erreurs[] = "Ligne $ln : type \"$type_lib\" introuvable au catalogue."; continue; }
                        $nom_id = (int)$n['id'];
                        $code_nom = $n['code'] ?: 'EQP';

                        $site_nom = $get($row, 'Site');
                        $site_id = null;
                        if ($site_nom !== '') {
                            $site_id = import_resoudre_site($site_nom, $sites_list);
                            if (!$site_id) { $erreurs[] = "Ligne $ln : site \"$site_nom\" introuvable."; continue; }
                        }

                        $etat = strtolower($get($row, 'État')) ?: 'neuf';
                        if (!in_array($etat, $etats_valides, true)) {
                            $erreurs[] = "Ligne $ln : état \"$etat\" invalide (neuf/bon/usage/endommage/hs)."; continue;
                        }

                        $date_raw = $get($row, 'Date acquisition');
                        $date_achat = null;
                        if ($date_raw !== '') {
                            $dt = DateTime::createFromFormat('d/m/Y', $date_raw) ?: DateTime::createFromFormat('Y-m-d', $date_raw);
                            if (!$dt) { $erreurs[] = "Ligne $ln : date \"$date_raw\" invalide (attendu JJ/MM/AAAA)."; continue; }
                            $date_achat = $dt->format('Y-m-d');
                        }

                        $nse         = $get($row, 'N° Série externe');
                        if ($nse !== '') {
                            $k = mb_strtolower($nse);
                            if (isset($nse_existants[$k])) {
                                $erreurs[] = "Ligne $ln : doublon, le N° série \"$nse\" existe déjà ({$nse_existants[$k]})."; continue;
                            }
                            if (isset($nse_fichier[$k])) {
                                $erreurs[] = "Ligne $ln : doublon, le N° série \"$nse\" est déjà en ligne {$nse_fichier[$k]} du fichier."; continue;
                            }
                            $nse_fichier[$k] = $ln;
                        }
                        $emplacement = $get($row, 'Emplacement');
                        $prix_achat  = (float)str_replace([' ', ','], ['', '.'], $get($row, 'Prix achat') ?: '0');

                        $statut_stock   = $etat === 'hs' ? 'hs' : (!$site_id ? 'en_stock' : 'affecte');
                        $date_fin_cycle = $date_achat ? date('Y-m-d', strtotime($date_achat . " +{$duree_mois_cat} months")) : null;

                        $numero_chrono_next++;
                        $nsi = $prochain_numero($code_nom);

                        db_query(
                            "INSERT INTO equipements
                             (marque,modele,categorie,nomenclature_id,numero_serie_interne,numero_chrono,numero_serie_origine,
                              site_id,emplacement,etat,statut_stock,date_acquisition,prix_achat,date_fin_cycle,duree_amortissement_mois,actif)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)",
                            [$marque,$modele,$f_categorie,$nom_id,$nsi,$numero_chrono_next,$nse ?: null,
                             $site_id,$emplacement ?: null,$etat,$statut_stock,$date_achat,$prix_achat,$date_fin_cycle,$duree_mois_cat]
                        );
                        $nb_ok++;
                    }
                    audit_log($user['id'], 'CREATE', 'equipements', null,
                        "Import en masse ($f_categorie) : $nb_ok créés, " . count($erreurs) . ' erreurs');
                    db_commit();
                    $import_result = ['ok' => true, 'nb_ok' => $nb_ok, 'erreurs' => array_slice($erreurs, 0, 50), 'nb_err' => count($erreurs)];
                } catch (Exception $e) {
                    db_rollback();
                    throw $e;
                }
            } catch (Exception $e) {
                $import_result = ['ok' => false, 'erreur' => $e->getMessage()];
            }
        }
    }
}

// ── NETTOYAGE DES DOUBLONS déjà en base (anciens imports, avant l'anti-
// doublon ci-dessus). Deux sortes de groupes parmi les équipements actifs
// de la catégorie :
//  - « serie »  : même N° série externe → doublon certain, pré-coché ;
//  - « probable »: sans N° série, mêmes type/marque/modèle/site/emplacement/
//    date → peut être un vrai lot d'équipements identiques, jamais pré-coché.
// Dans chaque groupe on garde celui qui a le plus d'historique (affectations,
// mouvements, interventions, réceptions), puis le plus ancien. Un doublon qui
// a lui-même un historique n'est pas supprimable ici (à traiter à la main).
// Suppression = soft delete actif=0, comme l'action 'delete' de
// api/equipements.php — réversible, aucune ligne liée n'est touchée.
$can_delete = can($perm_module, 'can_delete');

function _doublons_equipements(string $categorie): array {
    $rows = db_fetch_all(
        "WITH base AS (
            SELECT e.id, e.numero_serie_interne, e.numero_serie_origine, e.marque, e.modele,
                   e.emplacement, e.date_acquisition, e.prix_achat, e.created_at,
                   n.libelle AS type_lib, s.nom AS site_nom,
                   CASE WHEN COALESCE(TRIM(e.numero_serie_origine),'') <> ''
                        THEN 'S:' || LOWER(TRIM(e.numero_serie_origine))
                        ELSE 'P:' || COALESCE(e.nomenclature_id::text,'') || '|' || LOWER(COALESCE(TRIM(e.marque),''))
                             || '|' || LOWER(COALESCE(TRIM(e.modele),'')) || '|' || COALESCE(e.site_id::text,'')
                             || '|' || LOWER(COALESCE(TRIM(e.emplacement),'')) || '|' || COALESCE(e.date_acquisition::text,'')
                   END AS cle,
                   (SELECT COUNT(*) FROM affectations_equipements  x WHERE x.equipement_id=e.id)
                 + (SELECT COUNT(*) FROM mouvements_equipements    x WHERE x.equipement_id=e.id)
                 + (SELECT COUNT(*) FROM interventions_maintenance x WHERE x.equipement_id=e.id)
                 + (SELECT COUNT(*) FROM receptions_site           x WHERE x.equipement_id=e.id) AS nb_hist
            FROM equipements e
            LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
            LEFT JOIN sites s ON s.id=e.site_id
            WHERE e.actif=1 AND e.categorie=?
         )
         SELECT * FROM base
         WHERE cle IN (SELECT cle FROM base GROUP BY cle HAVING COUNT(*) > 1)
         ORDER BY cle, nb_hist DESC, id",
        [$categorie]
    );
    $groupes = [];
    foreach ($rows as $r) {
        $g = &$groupes[$r['cle']];
        if (!$g) $g = ['certain' => str_starts_with($r['cle'], 'S:'), 'garde' => $r, 'autres' => []];
        else     $g['autres'][] = $r;
        unset($g);
    }
    return array_values($groupes);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_ajax() && isset($_POST['supprimer_doublons'])) {
    if (!$can_delete) { http_response_code(403); exit('Accès refusé.'); }
    // Seuls les ids que le calcul serveur désigne comme doublons supprimables
    // sont acceptés : jamais l'équipement gardé d'un groupe, jamais un
    // équipement avec historique, quoi que le formulaire envoie.
    $supprimables = [];
    foreach (_doublons_equipements($f_categorie) as $g)
        foreach ($g['autres'] as $r) if ((int)$r['nb_hist'] === 0) $supprimables[(int)$r['id']] = $g['garde']['numero_serie_interne'];
    $nb = 0;
    db_begin();
    try {
        foreach (array_unique(array_map('intval', (array)($_POST['ids'] ?? []))) as $id) {
            if (!isset($supprimables[$id])) continue;
            $eq = db_fetch_one("SELECT * FROM equipements WHERE id=? AND actif=1", [$id]);
            if (!$eq) continue;
            db_query("UPDATE equipements SET actif=0 WHERE id=?", [$id]);
            audit_log($user['id'], 'DELETE', 'equipements', $id,
                "Suppression doublon {$eq['numero_serie_interne']} (gardé : {$supprimables[$id]})");
            $nb++;
        }
        db_commit();
    } catch (Exception $e) {
        db_rollback();
        throw $e;
    }
    header('Location: ?categorie=' . urlencode($f_categorie) . '&doublons=1&nettoyes=' . $nb);
    exit;
}
$voir_doublons = $can_delete && ($_GET['doublons'] ?? '') === '1';
$doublons      = $voir_doublons ? _doublons_equipements($f_categorie) : [];

// ── AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'apercu_numero') {
        if (!$can_create) json_response(false,'Accès refusé.');
        $nom_id = (int)($_POST['nomenclature_id'] ?? 0);
        json_response(true, '', ['numero' => _prochain_numero_serie($nom_id)]);
    }

    if ($action === 'creer' || $action === 'modifier') {
        if (!$can_create && $action==='creer') json_response(false,'Accès refusé.');
        if (!$can_update && $action==='modifier') json_response(false,'Accès refusé.');

        $marque       = trim($_POST['marque']               ?? '');
        $modele       = trim($_POST['modele']               ?? '');
        $nsi          = trim($_POST['numero_serie_interne'] ?? '');
        $nse          = trim($_POST['numero_serie_externe'] ?? '');
        $nom_id       = (int)($_POST['nomenclature_id']     ?? 0);
        $site_id      = (int)($_POST['site_id']             ?? 0) ?: null;
        $emplacement  = trim($_POST['emplacement']           ?? '');
        $etat         = trim($_POST['etat']                 ?? 'neuf');
        $date_achat   = trim($_POST['date_achat']           ?? '');
        $prix_achat   = (float)($_POST['prix_achat']        ?? 0);
        // statut_stock dérivé automatiquement — pas du formulaire
        if ($etat === 'hs')   $statut_stock = 'hs';
        elseif (!$site_id)    $statut_stock = 'en_stock';
        else                  $statut_stock = 'affecte';

        if (!$marque && !$nsi) json_response(false,'Marque ou N° série interne obligatoire.');
        // nomenclature_id est NOT NULL en base (equipements) : sans ce
        // contrôle, l'INSERT/UPDATE plantait avec une erreur SQL brute au
        // lieu d'un message clair.
        if (!$nom_id) json_response(false,'Type obligatoire.');

        // Calcul fin cycle OHADA
        $duree_mois     = $ohada_durees[$f_categorie] ?? $ohada_durees['default'];
        $date_fin_cycle = $date_achat
            ? date('Y-m-d', strtotime($date_achat . " +{$duree_mois} months"))
            : null;

        $label = ($marque ? $marque . ' ' : '') . ($modele ?: $nsi);

        if ($action === 'creer') {
            if ($nsi === '' || $nsi === '0') $nsi = _prochain_numero_serie($nom_id);
            $numero_chrono = (int)db_fetch_value("SELECT COALESCE(MAX(numero_chrono),0)+1 FROM equipements");
            db_query(
                "INSERT INTO equipements
                 (marque,modele,categorie,nomenclature_id,numero_serie_interne,numero_chrono,numero_serie_origine,
                  site_id,emplacement,etat,statut_stock,date_acquisition,prix_achat,date_fin_cycle,duree_amortissement_mois,actif)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)",
                [$marque,$modele,$f_categorie,$nom_id ?: null,$nsi,$numero_chrono,$nse,
                 $site_id,$emplacement ?: null,$etat,$statut_stock,$date_achat ?: null,$prix_achat,$date_fin_cycle,$duree_mois]
            );
            $id = (int)db_last_id();
            audit_log($user['id'],'CREATE','equipements',$id,"Création équipement: $label");
            json_response(true,'Équipement créé.', ['id'=>$id]);
        } else {
            $id = (int)($_POST['id'] ?? 0);
            db_query(
                "UPDATE equipements
                 SET marque=?,modele=?,nomenclature_id=?,numero_serie_interne=?,numero_serie_origine=?,
                     site_id=?,emplacement=?,etat=?,statut_stock=?,date_acquisition=?,prix_achat=?,date_fin_cycle=?,duree_amortissement_mois=?
                 WHERE id=?",
                [$marque,$modele,$nom_id ?: null,$nsi,$nse,
                 $site_id,$emplacement ?: null,$etat,$statut_stock,$date_achat ?: null,$prix_achat,$date_fin_cycle,$duree_mois,$id]
            );
            audit_log($user['id'],'UPDATE','equipements',$id,"Modification équipement: $label");
            json_response(true,'Équipement mis à jour.');
        }
    }

    // Soft delete (actif=0), même effet que l'action 'delete' de
    // api/equipements.php mais contrôlé sur le module de la catégorie
    // (equipements / equipements_operationnel).
    if ($action === 'supprimer') {
        if (!$can_delete) json_response(false,'Accès refusé.');
        $id = (int)($_POST['id'] ?? 0);
        $eq = db_fetch_one("SELECT * FROM equipements WHERE id=? AND actif=1 AND categorie=?", [$id, $f_categorie]);
        if (!$eq) json_response(false,'Équipement introuvable.');
        db_query("UPDATE equipements SET actif=0 WHERE id=?", [$id]);
        audit_log($user['id'],'DELETE','equipements',$id,"Suppression {$eq['numero_serie_interne']}");
        json_response(true,'Équipement supprimé.');
    }

    json_response(false,'Action inconnue.');
}

// ── DONNÉES
$where = ["e.categorie=?","e.actif=1"]; $params = [$f_categorie];
if ($f_site)   { $where[] = "e.site_id=?";          $params[] = $f_site; }
if ($f_etat === 'ok') { $where[] = "e.etat IN ('neuf','bon')"; }
elseif ($f_etat)      { $where[] = "e.etat=?";       $params[] = $f_etat; }
if ($f_type)   { $where[] = "e.nomenclature_id=?";   $params[] = $f_type; }
if ($f_search) { $where[] = "(e.numero_serie_interne LIKE ? OR e.marque LIKE ? OR e.modele LIKE ?)"; $params[] = "%$f_search%"; $params[] = "%$f_search%"; $params[] = "%$f_search%"; }
if ($f_statut_stock) { $where[] = "e.statut_stock=?"; $params[] = $f_statut_stock; }
if ($f_fin_cycle)    { $where[] = "e.date_fin_cycle IS NOT NULL AND e.date_fin_cycle < (CURRENT_DATE + INTERVAL 30 DAY)"; }

$equipements = db_fetch_all(
    "SELECT e.*, s.nom AS site_nom, n.libelle AS type_nom,
            -- Taux de pannes : interventions correctives / total interventions × 100
            (SELECT COUNT(*) FROM interventions_maintenance i WHERE i.equipement_id=e.id AND i.type_action='maintenance_corrective') AS nb_curative,
            (SELECT COUNT(*) FROM interventions_maintenance i WHERE i.equipement_id=e.id) AS nb_interventions_total,
            -- Valeur résiduelle OHADA (duree_vie_mois dans nomenclatures)
            CASE WHEN e.date_acquisition IS NOT NULL AND e.prix_achat > 0 AND COALESCE(e.duree_amortissement_mois, n.duree_vie_mois, 0) > 0
                 THEN GREATEST(0, ROUND(e.prix_achat - (e.prix_achat / COALESCE(e.duree_amortissement_mois, n.duree_vie_mois)) * TIMESTAMPDIFF(MONTH, e.date_acquisition, CURRENT_DATE), 0))
                 ELSE NULL END AS valeur_residuelle,
            CASE WHEN e.date_acquisition IS NOT NULL AND COALESCE(e.duree_amortissement_mois, n.duree_vie_mois, 0) > 0
                 THEN ROUND(TIMESTAMPDIFF(MONTH, e.date_acquisition, CURRENT_DATE) / COALESCE(e.duree_amortissement_mois, n.duree_vie_mois) * 100, 1)
                 ELSE NULL END AS pct_amorti
     FROM equipements e
     LEFT JOIN sites s ON s.id=e.site_id
     LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
     WHERE ".implode(' AND ',$where)."
     ORDER BY e.etat='hs' DESC, e.date_fin_cycle ASC, e.numero_serie_interne",
    $params
);

// ── Blocs résumé par type (nomenclature)
$blocs_site_cond = $site_force ? "AND e.site_id=$site_force" : "";
$blocs_type = db_fetch_all(
    "SELECT n.libelle, COUNT(e.id) AS nb_total,
            SUM(CASE WHEN e.etat IN ('neuf','bon') THEN 1 ELSE 0 END) AS nb_ok,
            SUM(CASE WHEN e.etat='hs' THEN 1 ELSE 0 END) AS nb_hs,
            SUM(CASE WHEN e.statut_stock='en_stock' THEN 1 ELSE 0 END) AS nb_stock
     FROM nomenclatures n
     LEFT JOIN equipements e ON e.nomenclature_id=n.id AND e.actif=1 AND e.categorie=? $blocs_site_cond
     WHERE n.categorie=?
     GROUP BY n.id HAVING COUNT(e.id) > 0
     ORDER BY nb_total DESC",
    [$f_categorie, $f_categorie]
);

// ── KPIs
$nb_total  = count($equipements);

// Compteurs des tuiles KPI : perimetre identique (categorie/site/type/recherche)
// mais jamais filtres par etat/statut_stock/fin_cycle eux-memes, pour que
// chaque tuile reste exacte quel que soit le statut actuellement affiche
// et reste cliquable pour y sauter directement (cf. equip-kpis plus bas).
$where_kpi = ["e.categorie=?","e.actif=1"]; $params_kpi = [$f_categorie];
if ($f_site)   { $where_kpi[] = "e.site_id=?";          $params_kpi[] = $f_site; }
if ($f_type)   { $where_kpi[] = "e.nomenclature_id=?";  $params_kpi[] = $f_type; }
if ($f_search) { $where_kpi[] = "(e.numero_serie_interne LIKE ? OR e.marque LIKE ? OR e.modele LIKE ?)"; $params_kpi[] = "%$f_search%"; $params_kpi[] = "%$f_search%"; $params_kpi[] = "%$f_search%"; }
$kpi = db_fetch_one(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN e.etat IN ('neuf','bon') THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN e.etat='hs' THEN 1 ELSE 0 END) AS hs,
            SUM(CASE WHEN e.statut_stock='en_stock' THEN 1 ELSE 0 END) AS stock,
            SUM(CASE WHEN e.date_fin_cycle IS NOT NULL AND e.date_fin_cycle < (CURRENT_DATE + INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS fin_cycle
     FROM equipements e
     WHERE ".implode(' AND ',$where_kpi),
    $params_kpi
);
$kpi_total     = (int)($kpi['total'] ?? 0);
$kpi_ok        = (int)($kpi['ok'] ?? 0);
$kpi_hs        = (int)($kpi['hs'] ?? 0);
$kpi_stock     = (int)($kpi['stock'] ?? 0);
$kpi_fin_cycle = (int)($kpi['fin_cycle'] ?? 0);

// URL de chaque tuile : conserve le perimetre (site/type/recherche), un seul
// filtre de statut actif a la fois.
$kpi_link = function(array $statut) use ($f_categorie, $f_site, $f_type, $f_search): string {
    $params = array_filter(array_merge([
        'categorie' => $f_categorie,
        'site'      => $f_site ?: null,
        'type'      => $f_type ?: null,
        'q'         => $f_search !== '' ? $f_search : null,
    ], $statut), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
};
$kpi_url_total    = $kpi_link([]);
$kpi_url_ok       = $kpi_link(['etat' => 'ok']);
$kpi_url_hs       = $kpi_link(['etat' => 'hs']);
$kpi_url_stock    = $kpi_link(['statut_stock' => 'en_stock']);
$kpi_url_fincycle = $kpi_link(['fin_cycle' => '1']);
$kpi_active_ok    = $f_etat === 'ok';
$kpi_active_hs    = $f_etat === 'hs';
$kpi_active_stock = $f_statut_stock === 'en_stock';
$kpi_active_fin   = $f_fin_cycle;
$kpi_active_total = !$kpi_active_ok && !$kpi_active_hs && !$kpi_active_stock && !$kpi_active_fin;

// ── EXPORT EXCEL
if (isset($_GET['export'])) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) die('PhpSpreadsheet non installé.');
    require_once $autoload;
    $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $sp->getActiveSheet();
    $ws->setTitle('Équipements');
    $etats = ['neuf'=>'Neuf','bon'=>'Bon état','usage'=>'Usagé','endommage'=>'Endommagé','hs'=>'Hors service'];
    $headers = ['N° Série','Type','Marque','Modèle','Site','Emplacement','État','Statut stock',
                'Date acq.','Prix achat','Valeur résiduelle','Amort. %',
                'Fin de cycle','Nb interventions','Taux curative %'];
    foreach ($headers as $i => $h) {
        $cell = $ws->getCell([$i+1, 1]);
        $cell->setValue($h);
        $cell->getStyle()->getFont()->setBold(true);
        $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
             ->getStartColor()->setARGB('FF06033A');
        $cell->getStyle()->getFont()->getColor()->setARGB('FFFFFFFF');
    }
    $row = 2;
    foreach ($equipements as $e) {
        $taux_curative = $e['nb_interventions_total'] > 0
            ? round($e['nb_curative'] / $e['nb_interventions_total'] * 100, 1) : 0;
        $ws->setCellValue([1,  $row], $e['numero_serie_interne'] ?? '');
        $ws->setCellValue([2,  $row], $e['type_nom'] ?? '');
        $ws->setCellValue([3,  $row], $e['marque'] ?? '');
        $ws->setCellValue([4,  $row], $e['modele'] ?? '');
        $ws->setCellValue([5,  $row], $e['site_nom'] ?? '');
        $ws->setCellValue([6,  $row], $e['emplacement'] ?? '');
        $ws->setCellValue([7,  $row], $etats[$e['etat']] ?? $e['etat']);
        $ws->setCellValue([8,  $row], $e['statut_stock'] ?? '');
        $ws->setCellValue([9,  $row], $e['date_acquisition'] ?? '');
        $ws->setCellValue([10, $row], $e['prix_achat'] ? (float)$e['prix_achat'] : '');
        $ws->setCellValue([11, $row], $e['valeur_residuelle'] !== null ? (float)$e['valeur_residuelle'] : '');
        $ws->setCellValue([12, $row], $e['pct_amorti'] !== null ? (float)$e['pct_amorti'] : '');
        $ws->setCellValue([13, $row], $e['date_fin_cycle'] ?? '');
        $ws->setCellValue([14, $row], (int)$e['nb_interventions_total']);
        $ws->setCellValue([15, $row], $taux_curative);
        $row++;
    }
    foreach (range(1, count($headers)) as $col)
        $ws->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
    $cat   = ucfirst($f_categorie);
    $fname = "equipements_{$f_categorie}_".date('Ymd').".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment;filename=\"$fname\"");
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
    exit;
}

include __DIR__ . '/../templates/header.php';
?>
<style>
.equip-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
.ek{background:white;border-radius:13px;border:1px solid var(--border);padding:14px 16px;border-left:4px solid var(--blue);display:block;text-decoration:none;color:inherit;cursor:pointer;transition:box-shadow .15s,border-color .15s}
.ek:hover{box-shadow:0 2px 10px rgba(0,0,0,.08)}
.ek-active{border-color:var(--navy);box-shadow:0 0 0 2px var(--navy) inset}
.ek.green{border-left-color:var(--success)} .ek.red{border-left-color:var(--danger)} .ek.orange{border-left-color:#f39c12} .ek.purple{border-left-color:#8e44ad}
.ek-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:24px;font-weight:900;color:var(--navy)}
.ek-lbl{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}


.etat-badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:12px;font-weight:700}
.etat-neuf{background:#d1fae5;color:#065f46} .etat-bon{background:#dbeafe;color:#1d4ed8}
.etat-usage{background:#fef3c7;color:#92400e} .etat-endomage,.etat-endommage{background:#fee2e2;color:#991b1b}
.etat-hs{background:#1a1a1a;color:white}
.taux-ok{color:var(--success-d);font-weight:700} .taux-warn{color:#f39c12;font-weight:700} .taux-danger{color:var(--danger-d);font-weight:700}
</style>

<?php if($import_result): ?>
<div class="alert alert-<?= $import_result['ok'] ? ($import_result['nb_err'] ? 'warning' : 'success') : 'danger' ?>" style="margin-bottom:16px">
  <?php if(!$import_result['ok']): ?>
    <i class="ph ph-x-circle" aria-hidden="true"></i> Import échoué : <?= h($import_result['erreur']) ?>
  <?php else: ?>
    <i class="ph ph-check-circle" aria-hidden="true"></i> Import terminé : <strong><?= $import_result['nb_ok'] ?></strong> équipement(s) créé(s).
    <?php if($import_result['nb_err']): ?>
      <strong><?= $import_result['nb_err'] ?></strong> ligne(s) ignorée(s) :
      <ul style="margin:8px 0 0;padding-left:20px">
        <?php foreach($import_result['erreurs'] as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
      </ul>
      <?php if($import_result['nb_err'] > count($import_result['erreurs'])): ?>
        <div style="margin-top:4px">… et <?= $import_result['nb_err'] - count($import_result['erreurs']) ?> autre(s).</div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if($can_create): ?>
<!-- ── LIGNE 1 : boutons Ajouter / Importer ── -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap">
  <button type="button" class="btn btn-primary" onclick="ouvrirCreation()">
    <i class="ph-duotone ph-plus"></i> Ajouter
  </button>
  <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalImport').style.display='flex'">
    <i class="ph-duotone ph-upload-simple"></i> Importer
  </button>
  <a class="btn btn-secondary" href="?categorie=<?= h($f_categorie) ?>&modele=1">
    <i class="ph-duotone ph-download-simple"></i> Télécharger le modèle
  </a>
  <?php if($can_delete): ?>
  <a class="btn btn-secondary" href="?categorie=<?= h($f_categorie) ?>&doublons=1">
    <i class="ph-duotone ph-copy"></i> Doublons
  </a>
  <?php endif; ?>
</div>

<!-- MODAL IMPORT -->
<div id="modalImport" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;padding:20px">
  <div style="background:white;border-radius:20px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)">Importer des équipements</h3>
      <button onclick="document.getElementById('modalImport').style.display='none'" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px">
      Fichier Excel (.xlsx/.xls) ou CSV. Colonnes attendues : <strong>Type (obligatoire, doit exister au catalogue)</strong>, Marque ou Modèle (au moins l'un des deux), N° Série externe, Site, État, Date acquisition, Prix achat, Emplacement — téléchargez le modèle pour les exemples.
      Une ligne dont le N° Série externe existe déjà (en base ou plus haut dans le fichier) est ignorée comme doublon.
      Le numéro de série interne est généré automatiquement.
    </p>
    <form method="POST" enctype="multipart/form-data">
      <input type="file" name="fichier_import" accept=".xlsx,.xls,.csv" required class="form-control" style="margin-bottom:16px">
      <div style="display:flex;justify-content:flex-end;gap:10px">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalImport').style.display='none'">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="ph ph-upload-simple" aria-hidden="true"></i> Importer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── KPIs ── -->
<div class="equip-kpis" id="equipKpis">
  <a href="<?= h($kpi_url_total) ?>" class="ek<?= $kpi_active_total?' ek-active':'' ?>" title="Voir tous les équipements">          <div class="ek-val"><?= $kpi_total ?></div>                                          <div class="ek-lbl">Total</div></a>
  <a href="<?= h($kpi_url_ok) ?>" class="ek green<?= $kpi_active_ok?' ek-active':'' ?>" title="Filtrer sur les équipements opérationnels">    <div class="ek-val" style="color:var(--success-d)"><?= $kpi_ok ?></div>                <div class="ek-lbl"><i class="ph ph-check-circle" aria-hidden="true"></i> Opérationnels</div></a>
  <a href="<?= h($kpi_url_hs) ?>" class="ek red<?= $kpi_active_hs?' ek-active':'' ?>" title="Filtrer sur les équipements hors service">      <div class="ek-val" style="color:var(--danger-d)"><?= $kpi_hs ?></div>                 <div class="ek-lbl"><i class="ph ph-x-circle" aria-hidden="true"></i> Hors service</div></a>
  <a href="<?= h($kpi_url_stock) ?>" class="ek purple<?= $kpi_active_stock?' ek-active':'' ?>" title="Filtrer sur les équipements en stock">   <div class="ek-val" style="color:#8e44ad"><?= $kpi_stock ?></div>                    <div class="ek-lbl"><i class="ph ph-package" aria-hidden="true"></i> En stock</div></a>
  <a href="<?= h($kpi_url_fincycle) ?>" class="ek orange<?= $kpi_active_fin?' ek-active':'' ?>" title="Filtrer sur les équipements en fin de cycle sous 30 jours">   <div class="ek-val" style="color:#f39c12"><?= $kpi_fin_cycle ?></div>               <div class="ek-lbl"><i class="ph ph-warning" aria-hidden="true"></i> Fin cycle &lt;30j</div></a>
</div>

<!-- ── LIGNE 2 : Filtres (remplace les blocs résumé) ── -->
<form method="GET" id="equipFiltreForm" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:18px">
  <input type="hidden" name="categorie" value="<?= h($f_categorie) ?>">
  <input type="hidden" name="statut_stock" value="<?= h($f_statut_stock) ?>">
  <input type="hidden" name="fin_cycle" value="<?= $f_fin_cycle?'1':'' ?>">

  <?php if($role_slug !== 'coordinateur_site'): ?>
  <select name="site" onchange="equipFiltrer(this.form)" aria-label="Filtrer par site" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none">
    <option value="">Tous les sites</option>
    <?php foreach($sites_list as $s): ?>
    <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <select name="etat" onchange="equipFiltrer(this.form)" aria-label="Filtrer par état" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none">
    <option value="">Tous états</option>
    <option value="ok"        <?= $f_etat==='ok'?'selected':''        ?>>Opérationnel (neuf/bon)</option>
    <option value="neuf"      <?= $f_etat==='neuf'?'selected':''      ?>>Neuf</option>
    <option value="bon"       <?= $f_etat==='bon'?'selected':''       ?>>Bon état</option>
    <option value="usage"     <?= $f_etat==='usage'?'selected':''     ?>>Usagé</option>
    <option value="endommage" <?= $f_etat==='endommage'?'selected':'' ?>>Endommagé</option>
    <option value="hs"        <?= $f_etat==='hs'?'selected':''        ?>>H.S.</option>
  </select>

  <select name="type" onchange="equipFiltrer(this.form)" aria-label="Filtrer par type d'équipement" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none">
    <option value="">Tous les types</option>
    <?php foreach($nomenclatures as $n): ?>
    <option value="<?= $n['id'] ?>" <?= $f_type===$n['id']?'selected':'' ?>><?= h($n['libelle']) ?></option>
    <?php endforeach; ?>
  </select>

  <div style="position:relative;flex:1;min-width:180px">
    <i class="ph-duotone ph-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:15px;pointer-events:none"></i>
    <input type="text" name="q" value="<?= h($f_search) ?>" placeholder="Rechercher..." aria-label="Rechercher un équipement"
           onchange="equipFiltrer(this.form)"
           style="width:100%;padding:9px 12px 9px 34px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none">
  </div>

  <a href="?categorie=<?= h($f_categorie) ?>" id="equipEffacerBtn" onclick="return equipEffacer(event)" class="btn btn-secondary btn-sm" title="Réinitialiser les filtres"
     style="<?= ($f_site||$f_etat||$f_type||$f_search||$f_statut_stock||$f_fin_cycle) ? '' : 'display:none' ?>"><i class="ph ph-x" aria-hidden="true"></i> Effacer</a>

  <a href="?categorie=<?= h($f_categorie) ?>&site=<?= $f_site ?>&etat=<?= h($f_etat) ?>&type=<?= $f_type ?>&q=<?= urlencode($f_search) ?>&statut_stock=<?= h($f_statut_stock) ?>&fin_cycle=<?= $f_fin_cycle?'1':'' ?>&export=1"
     id="equipExportLink" class="btn btn-secondary btn-sm">
    <i class="ph-duotone ph-file-xls"></i> Excel
  </a>
</form>

<?php if($voir_doublons): ?>
<!-- DOUBLONS -->
<?php if(isset($_GET['nettoyes'])): ?>
<div class="alert alert-success" style="margin-bottom:16px">
  <i class="ph ph-check-circle" aria-hidden="true"></i> <strong><?= (int)$_GET['nettoyes'] ?></strong> doublon(s) supprimé(s).
</div>
<?php endif; ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h3><i class="ph-duotone ph-copy" style="color:var(--primary-d)"></i> Doublons
      <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($doublons) ?> groupe(s))</span></h3>
    <a class="btn btn-secondary" href="?categorie=<?= h($f_categorie) ?>">Fermer</a>
  </div>
  <?php if(!$doublons): ?>
    <p style="padding:16px;color:var(--muted)">Aucun doublon dans cette catégorie.</p>
  <?php else: ?>
  <p style="padding:12px 16px 0;font-size:13px;color:var(--muted)">
    Dans chaque groupe, la ligne <strong>Gardé</strong> est conservée (celle qui a le plus d'historique, sinon la plus ancienne).
    Même N° série externe = doublon certain, coché d'office. Sans N° série = doublon <em>probable</em> (ce peut être un lot d'équipements identiques) : à cocher vous-même.
    Un doublon qui a déjà un historique (affectation, mouvement, intervention) n'est pas supprimable ici.
  </p>
  <form method="POST" onsubmit="return confirm('Supprimer les doublons cochés ?')">
    <input type="hidden" name="supprimer_doublons" value="1">
    <div class="table-wrap" style="max-height:520px;overflow:auto">
      <table>
        <thead><tr>
          <th></th><th>N° interne</th><th>N° série externe</th><th>Type</th><th>Marque / Modèle</th>
          <th>Site</th><th>Date acq.</th><th>Créé le</th><th style="text-align:center">Historique</th>
        </tr></thead>
        <tbody>
        <?php foreach($doublons as $gi => $g): ?>
          <?php foreach(array_merge([$g['garde']], $g['autres']) as $k => $r): ?>
          <tr style="<?= $gi % 2 ? 'background:#f8fafc' : '' ?><?= $k === 0 ? ';border-top:2px solid var(--border)' : '' ?>">
            <td>
              <?php if($k === 0): ?>
                <span class="etat-badge etat-bon">Gardé</span>
              <?php elseif((int)$r['nb_hist'] > 0): ?>
                <span title="A un historique : à traiter à la main" style="color:var(--muted)"><i class="ph ph-lock-simple"></i></span>
              <?php else: ?>
                <input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" <?= $g['certain'] ? 'checked' : '' ?>>
                <?php if(!$g['certain']): ?><span style="font-size:11px;color:#92400e">probable</span><?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?= h($r['numero_serie_interne']) ?></td>
            <td><?= h($r['numero_serie_origine'] ?: '—') ?></td>
            <td><?= h($r['type_lib'] ?? '') ?></td>
            <td><?= h(trim(($r['marque'] ?? '') . ' ' . ($r['modele'] ?? ''))) ?></td>
            <td><?= h($r['site_nom'] ?? 'Non affecté') ?><?php if(!empty($r['emplacement'])): ?><div style="font-size:12px;color:var(--muted)"><?= h($r['emplacement']) ?></div><?php endif; ?></td>
            <td><?= fmt_date($r['date_acquisition']) ?></td>
            <td><?= fmt_datetime($r['created_at']) ?></td>
            <td style="text-align:center"><?= (int)$r['nb_hist'] ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;justify-content:flex-end;padding:14px 16px">
      <button type="submit" class="btn btn-danger"><i class="ph ph-trash" aria-hidden="true"></i> Supprimer les doublons cochés</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- LISTE ÉQUIPEMENTS -->
<div class="card" id="equipResultCard">
  <div class="card-header">
    <h3>
      <?= $f_categorie==='informatique'
        ? '<i class="ph-duotone ph-monitor" style="color:var(--primary-d)"></i> Équipements Informatique'
        : '<i class="ph-duotone ph-wrench" style="color:var(--primary-d)"></i> Équipements Opérationnel' ?>
      <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= $nb_total ?>)</span>
      <?php if($f_type): ?>
        <span style="font-size:12px;font-weight:600;color:var(--primary-d);background:var(--primary-l);padding:2px 10px;border-radius:20px;margin-left:8px">
          <?= h(current(array_filter($nomenclatures, fn($n)=>$n['id']===$f_type))['libelle'] ?? '') ?>
        </span>
      <?php endif; ?>
    </h3>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Équipement</th><th>Type</th><th>N° Série</th><th>Site</th>
        <th style="text-align:center">État</th>
        <th style="text-align:center">Taux pannes</th>
        <th style="text-align:center">Valeur résid. (FCFA)</th>
        <th style="text-align:center">Fin cycle</th>
        <th style="text-align:center">Statut stock</th>
        <?php if($can_update || $can_delete): ?><th style="text-align:center">Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if(empty($equipements)): ?>
        <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--muted)">Aucun équipement.</td></tr>
      <?php else: foreach($equipements as $e):
        $nb_int = (int)($e['nb_interventions_total']??0);
        $nb_cur = (int)($e['nb_curative']??0);
        $taux   = $nb_int > 0 ? round($nb_cur/$nb_int*100) : 0;
        $taux_cls = $taux >= 70 ? 'taux-danger' : ($taux >= 40 ? 'taux-warn' : 'taux-ok');
        $days_left = $e['date_fin_cycle'] ? (int)round((strtotime($e['date_fin_cycle'])-time())/86400) : null;
      ?>
        <tr style="<?= $e['etat']==='hs'?'background:#fff5f5':'' ?>">
          <td style="font-weight:600;color:var(--navy)"><?= h($e['numero_serie_interne']??'—') ?></td>
          <td style="font-size:12px"><?= h($e['type_nom']??'—') ?></td>
          <td style="font-family:monospace;font-size:12px"><?= h($e['numero_serie_interne']??'—') ?></td>
          <td><?= h($e['site_nom']??'Non affecté') ?><?php if(!empty($e['emplacement'])): ?><div style="font-size:12px;color:var(--muted)"><?= h($e['emplacement']) ?></div><?php endif; ?></td>
          <td style="text-align:center"><span class="etat-badge etat-<?= $e['etat'] ?>"><?= ucfirst($e['etat']) ?></span></td>
          <td style="text-align:center">
            <?php if($nb_int > 0): ?>
            <span class="<?= $taux_cls ?>"><?= $taux ?>%</span>
            <div style="font-size:12px;color:var(--muted)"><?= $nb_cur ?>/<?= $nb_int ?> int.</div>
            <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
          </td>
          <td style="text-align:center;font-size:12px;font-weight:600">
            <?php if($e['valeur_residuelle'] !== null): ?>
            <?= fmt_number($e['valeur_residuelle']) ?> FCFA
            <div style="font-size:12px;color:var(--muted)"><?= $e['pct_amorti'] ?>% amorti</div>
            <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
          </td>
          <td style="text-align:center;font-size:12px">
            <?php if($days_left !== null): ?>
            <span style="color:<?= $days_left<0?'var(--danger-d)':($days_left<30?'#f39c12':'var(--success-d)') ?>;font-weight:700">
              <?= $days_left < 0 ? 'Dépassé' : "$days_left j" ?>
            </span>
            <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
          </td>
          <td style="text-align:center">
            <span style="padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;background:<?= ($e['statut_stock']??'')==='en_stock'?'#e8f4f9':'#f0f0f0' ?>;color:<?= ($e['statut_stock']??'')==='en_stock'?'var(--blue)':'#666' ?>">
              <?= ($e['statut_stock']??'affecte')==='en_stock'?'<i class="ph ph-package" aria-hidden="true"></i> Stock':'<i class="ph ph-check-circle" aria-hidden="true"></i> Affecté' ?>
            </span>
          </td>
          <?php if($can_update || $can_delete): ?>
          <td style="text-align:center;white-space:nowrap">
            <?php if($can_update): ?>
            <button class="btn btn-secondary btn-sm" onclick="modifierEquip(<?= htmlspecialchars(json_encode($e),ENT_QUOTES) ?>)"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
            <?php endif; ?>
            <?php if($can_delete): ?>
            <button class="btn btn-danger btn-sm" title="Supprimer" onclick="supprimerEquip(<?= (int)$e['id'] ?>, <?= htmlspecialchars(json_encode($e['numero_serie_interne']),ENT_QUOTES) ?>)"><i class="ph ph-trash" aria-hidden="true"></i></button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL CRÉATION/MODIFICATION -->
<?php if($can_create || $can_update): ?>
<div id="modalEquip" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;padding:20px">
  <div style="background:white;border-radius:20px;padding:28px;width:580px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)" id="titleModal">Nouvel équipement</h3>
      <button onclick="fermerModal()" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <input type="hidden" id="eId">
    <input type="hidden" id="eAction" value="creer">
    <div id="eAlert"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
      <div class="form-group">
        <label>Marque</label>
        <input type="text" class="form-control" id="eMarque" placeholder="ex: HP, OKI, Canon...">
      </div>
      <div class="form-group">
        <label>Modèle</label>
        <input type="text" class="form-control" id="eModele" placeholder="ex: LaserJet Pro, C650...">
      </div>
      <div class="form-group">
        <label>Type (nomenclature) *</label>
        <select class="form-control" id="eNom_id">
          <option value="">— Sélectionner —</option>
          <?php foreach($nomenclatures as $n): ?>
          <option value="<?= $n['id'] ?>"><?= h($n['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>N° Série interne</label>
        <input type="text" class="form-control" id="eNsi" placeholder="Auto-généré après sélection du type">
      </div>
      <div class="form-group">
        <label>N° Série externe</label>
        <input type="text" class="form-control" id="eNse">
      </div>
      <div class="form-group">
        <label>Site</label>
        <select class="form-control" id="eSite">
          <option value="">Non affecté</option>
          <?php foreach($sites_list as $s): ?>
          <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Emplacement</label>
        <input type="text" class="form-control" id="eEmplacement" placeholder="ex: Bureau RH, 2e étage, Salle serveur...">
      </div>
      <div class="form-group">
        <label>État</label>
        <select class="form-control" id="eEtat">
          <option value="neuf">Neuf</option>
          <option value="bon">Bon état</option>
          <option value="usage">Usagé</option>
          <option value="endommage">Endommagé</option>
          <option value="hs">Hors service</option>
        </select>
      </div>
      <div class="form-group">
        <label>Statut stock</label>
        <select class="form-control" id="eStatutStock">
          <option value="affecte">Affecté (en service)</option>
          <option value="en_stock">📦 En stock (disponible)</option>
        </select>
      </div>
      <div class="form-group">
        <label>Date achat</label>
        <input type="date" class="form-control" id="eDate">
      </div>
      <div class="form-group">
        <label>Prix achat (FCFA)</label>
        <input type="number" class="form-control" id="ePrix" value="0">
      </div>
    </div>
    <div style="background:var(--lighter);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:var(--muted)">
      <i class="ph ph-lightbulb" aria-hidden="true"></i> <strong>Amortissement OHADA :</strong> <?= $f_categorie==='informatique'?'Matériel informatique — 3 ans (36 mois)':'Matériel opérationnel — 5 ans (60 mois)' ?>. La date de fin de cycle et la valeur résiduelle sont calculées automatiquement.
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermerModal()">Annuler</button>
      <button class="btn btn-primary" id="btnSave" onclick="sauvegarder()"><i class="ph ph-check-circle" aria-hidden="true"></i> Enregistrer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
async function supprimerEquip(id, numero){
  if (!confirm(`Supprimer l'équipement ${numero} ?`)) return;
  try {
    const d = await ap({action:'supprimer', id});
    toast(d.message, d.success ? 'success' : 'error');
    if (d.success) setTimeout(() => location.reload(), 800);
  } catch(err) { toast(err.message, 'error'); }
}
function ap(d){return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(achParseJson);}

// ── Filtres sans rechargement de page : remplace les KPI et le tableau par
// le fragment equivalent de la page fraichement chargee (memes id), evite
// le flash de rechargement complet (cf. pages/operations/bobines.php).
let equipEnVol = null;
function equipCharger(url){
  if(!window.fetch || !window.DOMParser){ location.href = url; return; }
  if(equipEnVol) try{ equipEnVol.abort(); }catch(e){}
  const ctrl = window.AbortController ? new AbortController() : null;
  equipEnVol = ctrl;
  fetch(url, {credentials:'same-origin', signal: ctrl?ctrl.signal:undefined, headers:{'X-Requested-With':'fetch'}})
    .then(r => { if(!r.ok) throw new Error(r.status); return r.text(); })
    .then(html => {
      if(equipEnVol !== ctrl) return;
      const doc = new DOMParser().parseFromString(html, 'text/html');
      ['equipKpis','equipResultCard'].forEach(id => {
        const neuf = doc.getElementById(id);
        const ancien = document.getElementById(id);
        if(!neuf || !ancien) throw new Error('structure');
        ancien.replaceWith(neuf);
      });
      history.pushState({equip:1}, '', url);
      equipEnVol = null;
    })
    .catch(e => {
      if(e && e.name==='AbortError') return;
      equipEnVol = null;
      location.href = url;
    });
}
function equipMajLiens(form){
  const actif = ['site','etat','type','q','statut_stock','fin_cycle'].some(n => form.elements[n] && form.elements[n].value);
  const btn = document.getElementById('equipEffacerBtn');
  if(btn) btn.style.display = actif ? '' : 'none';
  const exp = document.getElementById('equipExportLink');
  if(exp){ const p = new URLSearchParams(new FormData(form)); p.set('export','1'); exp.href = location.pathname + '?' + p.toString(); }
}
function equipFiltrer(form){
  equipMajLiens(form);
  equipCharger(location.pathname + '?' + new URLSearchParams(new FormData(form)).toString());
}
function equipEffacer(ev){
  ev.preventDefault();
  const form = document.getElementById('equipFiltreForm');
  ['site','etat','type','q','statut_stock','fin_cycle'].forEach(n => { if(form.elements[n]) form.elements[n].value = ''; });
  equipMajLiens(form);
  equipCharger(location.pathname + '?' + new URLSearchParams(new FormData(form)).toString());
  return false;
}
window.addEventListener('popstate', function(ev){ if(ev.state && ev.state.equip) location.reload(); });
function toast(m,t='success'){let el=document.getElementById('toast-live');if(!el){el=document.createElement('div');el.id='toast-live';el.setAttribute('role','status');el.setAttribute('aria-live','polite');el.setAttribute('aria-atomic','true');document.body.appendChild(el);}clearTimeout(el._hideTimer);el.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;background:${t==='success'?'#27ae60':'#e74c3c'};color:white`;el.textContent=m;el._hideTimer=setTimeout(()=>{el.style.display='none';},3500);}

function ouvrirCreation(){
  document.getElementById('titleModal').textContent='Nouvel équipement';
  document.getElementById('eAction').value='creer';
  document.getElementById('eId').value='';
  ['eMarque','eModele','eNsi','eNse','ePrix','eEmplacement'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('eEtat').value='neuf';
  document.getElementById('eStatutStock').value='affecte';
  document.getElementById('eAlert').innerHTML='';
  document.getElementById('modalEquip').style.display='flex';
}
function modifierEquip(e){
  document.getElementById('titleModal').textContent='Modifier équipement';
  document.getElementById('eAction').value='modifier';
  document.getElementById('eId').value=e.id;
  document.getElementById('eMarque').value=e.marque||'';document.getElementById('eModele').value=e.modele||'';
  document.getElementById('eNom_id').value=e.nomenclature_id||'';
  document.getElementById('eNsi').value=e.numero_serie_interne||'';
  document.getElementById('eNse').value=e.numero_serie_origine||'';
  document.getElementById('eSite').value=e.site_id||'';
  document.getElementById('eEmplacement').value=e.emplacement||'';
  document.getElementById('eEtat').value=e.etat||'bon';
  document.getElementById('eStatutStock').value=e.statut_stock||'affecte';
  document.getElementById('eDate').value=e.date_acquisition||'';
  document.getElementById('ePrix').value=e.prix_achat||0;
  document.getElementById('eAlert').innerHTML='';
  document.getElementById('modalEquip').style.display='flex';
}
function fermerModal(){document.getElementById('modalEquip').style.display='none';}
document.getElementById('eNom_id').addEventListener('change', async function(){
  if (document.getElementById('eAction').value !== 'creer') return;
  if (document.getElementById('eNsi').value.trim() !== '') return;
  if (!this.value) return;
  const d = await ap({action:'apercu_numero', nomenclature_id:this.value});
  if (d.success) document.getElementById('eNsi').value = d.data.numero;
});
async function sauvegarder(){
  if (!document.getElementById('eNom_id').value) {
    document.getElementById('eAlert').innerHTML = '<div class="alert alert-danger">Le type (nomenclature) est obligatoire.</div>';
    return;
  }
  const btn = document.getElementById('btnSave');
  btn.disabled = true;
  btn.textContent = '⏳ Enregistrement…';
  try {
    const d = await ap({
      action              : document.getElementById('eAction').value,
      id                  : document.getElementById('eId').value,
      marque              : document.getElementById('eMarque').value.trim(),
      modele              : document.getElementById('eModele').value.trim(),
      nomenclature_id     : document.getElementById('eNom_id').value,
      numero_serie_interne: document.getElementById('eNsi').value.trim(),
      numero_serie_externe: document.getElementById('eNse').value.trim(),
      site_id             : document.getElementById('eSite').value,
      emplacement         : document.getElementById('eEmplacement').value.trim(),
      etat                : document.getElementById('eEtat').value,
      statut_stock        : document.getElementById('eStatutStock').value,
      date_achat          : document.getElementById('eDate').value,
      prix_achat          : document.getElementById('ePrix').value,
    });
    if (d.success) {
      toast(d.message, 'success');
      fermerModal();
      setTimeout(() => location.reload(), 800);
    } else {
      document.getElementById('eAlert').innerHTML =
        `<div class="alert alert-danger">${d.message}</div>`;
    }
  } catch(err) {
    document.getElementById('eAlert').innerHTML =
      `<div class="alert alert-danger">${(err && err.message) || 'Erreur réseau. Réessayez.'}</div>`;
  } finally {
    btn.disabled = false;
    btn.textContent = '✅ Enregistrer';
  }
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
