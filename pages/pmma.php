<?php
// ============================================================
//  pages/pmma.php — Suivi consommation PMMA
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/referentiels.php';

require_auth();
$user       = current_user();
$role_slug  = $user['role_slug'] ?? '';
$page_title = 'Suivi PMMA';
$active_page= 'pmma';
$is_coord   = $role_slug === 'coordinateur_site';
$site_force = ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;
$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
require_permission('pmma', 'can_read');
$can_saisie = can('pmma', 'can_create');

// ── Catalogue des types de PMMA
// Le type était saisi en texte libre, ce qui créait un type nouveau à
// chaque variante de casse ou d'orthographe : la base porte déjà
// « PMMA Type A » et « PMMA TYPE B ». La liste vient maintenant du
// catalogue (op_types_pmma), et les types encore présents en stock sans
// y figurer restent proposés pour ne pas rendre leur stock inaccessible.
$catalogue_pmma = types_pmma_actifs();
$types_connus   = array_keys($catalogue_pmma);
foreach (db_fetch_all("SELECT DISTINCT TRIM(type_pmma) AS t FROM stock_pmma_site
                        WHERE COALESCE(TRIM(type_pmma),'') <> ''") as $r) {
    if (!in_array($r['t'], $types_connus, true)) $types_connus[] = $r['t'];
}
sort($types_connus);

// Capacité carton par type (ex: 25, 50) — pour afficher le stock à la fois
// en unités et en cartons, demandé après une confusion où une sortie de
// "50" a été comprise comme 50 cartons alors que la quantité saisie est
// toujours en unités (cf. $action==='sortie' plus bas).
$type_capacites = [];
foreach ($types_connus as $t) $type_capacites[$t] = capacite_carton_pmma($t);

// ── IMPORT EN MASSE (Excel/CSV) — remplace le stock (photo exacte), pas
// une addition : un réimport (fichier corrigé) ne double jamais le stock.
// Form classique (pas AJAX), même principe que pages/equipements.php.
require_once __DIR__ . '/../includes/import_helpers.php';

if (($_GET['modele'] ?? '') === '1') {
    import_generer_modele(
        ['Site', 'Type PMMA', 'Cartons', 'Unités en vrac'],
        [$sites_list[0]['nom'] ?? '', $types_connus[0] ?? 'Type D', 10, 0],
        'modele_pmma.xlsx'
    );
}

$import_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_ajax() && isset($_FILES['fichier_import'])) {
    if (!$can_saisie) {
        $import_result = ['ok' => false, 'erreur' => 'Accès refusé.'];
    } else {
        $ext = strtolower(pathinfo($_FILES['fichier_import']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls','csv'], true)) {
            $import_result = ['ok' => false, 'erreur' => 'Format invalide. Utilisez .xlsx, .xls ou .csv.'];
        } else {
            try {
                $rows = import_lire_fichier($_FILES['fichier_import']['tmp_name']);
                if (empty($rows)) throw new Exception('Fichier vide.');

                $headers = array_map('trim', $rows[0]);
                $col = array_flip($headers);
                // Saisie en cartons (ce que l'on compte physiquement), les unités
                // sont calculées avec la capacité carton du type (op_types_pmma).
                // L'ancienne colonne « Quantité (unités) » reste acceptée pour
                // les fichiers déjà préparés avec le premier modèle.
                $required = ['Site', 'Type PMMA'];
                $missing  = array_filter($required, fn($r) => !isset($col[$r]));
                if (!isset($col['Cartons']) && !isset($col['Quantité (unités)'])) $missing[] = 'Cartons';
                if ($missing) throw new Exception('Colonnes manquantes : ' . implode(', ', $missing) . '. Colonnes détectées : ' . implode(', ', array_filter($headers)));

                $get = fn(array $row, string $k): string => isset($col[$k]) ? trim((string)($row[$col[$k]] ?? '')) : '';

                // Entier positif ou nul, espaces de milliers tolérés ; null sinon.
                $entier = function (string $v): ?int {
                    $v = str_replace([' ', "\u{a0}", "\u{202f}"], '', $v);
                    return preg_match('/^\d+$/', $v) ? (int)$v : null;
                };

                // Correspondance insensible à la casse vers la forme canonique
                // du type (évite de créer "type d" à côté de "Type D").
                $types_lookup = [];
                foreach ($types_connus as $t) $types_lookup[mb_strtolower(trim($t))] = $t;

                $nb_ok = 0; $erreurs = [];
                db_begin();
                try {
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue;
                        $ln = $i + 1;

                        $site_nom = $get($row, 'Site');
                        $site_id  = $site_nom !== '' ? import_resoudre_site($site_nom, $sites_list) : null;
                        if (!$site_id) { $erreurs[] = "Ligne $ln : site \"$site_nom\" introuvable."; continue; }

                        $type_raw = $get($row, 'Type PMMA');
                        $type = $types_lookup[mb_strtolower(trim($type_raw))] ?? null;
                        if (!$type) { $erreurs[] = "Ligne $ln : type PMMA \"$type_raw\" inconnu au catalogue."; continue; }

                        $cap         = $type_capacites[$type] ?? capacite_carton_pmma($type);
                        $cartons_raw = $get($row, 'Cartons');
                        $vrac_raw    = $get($row, 'Unités en vrac');
                        $qte_raw     = $get($row, 'Quantité (unités)');
                        if ($cartons_raw !== '' || $vrac_raw !== '') {
                            $cartons = $cartons_raw === '' ? 0 : $entier($cartons_raw);
                            if ($cartons === null) { $erreurs[] = "Ligne $ln : nombre de cartons \"$cartons_raw\" invalide (nombre entier attendu)."; continue; }
                            $vrac = $vrac_raw === '' ? 0 : $entier($vrac_raw);
                            if ($vrac === null) { $erreurs[] = "Ligne $ln : unités en vrac \"$vrac_raw\" invalide (nombre entier attendu)."; continue; }
                            if ($vrac >= $cap) { $erreurs[] = "Ligne $ln : $vrac unités en vrac = au moins un carton plein ($cap u. pour $type), à compter dans Cartons."; continue; }
                            $qte_new = $cartons * $cap + $vrac;
                        } elseif ($qte_raw !== '') {
                            $qte_new = $entier($qte_raw);
                            if ($qte_new === null) { $erreurs[] = "Ligne $ln : quantité \"$qte_raw\" invalide."; continue; }
                        } else {
                            $erreurs[] = "Ligne $ln : nombre de cartons manquant."; continue;
                        }

                        $qte_old = (int)(db_fetch_value(
                            "SELECT quantite FROM stock_pmma_site WHERE site_id=? AND type_pmma=?", [$site_id, $type]
                        ) ?? 0);
                        $delta = $qte_new - $qte_old;

                        db_query(
                            "INSERT INTO stock_pmma_site (site_id,type_pmma,quantite,seuil_alerte)
                             VALUES (?,?,?,?)
                             ON DUPLICATE KEY UPDATE quantite=VALUES(quantite), updated_at=CURRENT_TIMESTAMP",
                            [$site_id, $type, $qte_new, $catalogue_pmma[$type]['seuil_defaut'] ?? 10]
                        );
                        if ($delta !== 0) {
                            db_query(
                                "INSERT INTO stock_pmma (site_id,type_pmma,quantite,type_mouvement,notes,created_by) VALUES (?,?,?,?,?,?)",
                                [$site_id, $type, abs($delta), $delta > 0 ? 'entree' : 'sortie', 'Import stock', $user['id']]
                            );
                        }
                        $nb_ok++;
                    }
                    audit_log($user['id'], 'UPDATE', 'pmma', null,
                        "Import en masse PMMA : $nb_ok ligne(s), " . count($erreurs) . ' erreur(s)');
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
$import_result = import_apres_envoi($import_result, 'pmma');   // Post/Redirect/Get : un rechargement ne rejoue pas l'import

// ── AJAX (Entrée/Sortie manuelle — GSB/admin uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    if (!$can_saisie) json_response(false, 'Accès refusé.');
    $action = $_POST['action'] ?? '';

    if ($action === 'entree') {
        $site_id  = $site_force ?: (int)($_POST['site_id'] ?? 0);
        $quantite = (int)($_POST['quantite'] ?? 0);
        $type     = trim($_POST['type_pmma'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        if (!$site_id || $quantite <= 0) json_response(false, 'Site et quantité obligatoires.');
        // Le contrôle serveur ne se contente pas de la liste affichée : la
        // requête AJAX peut être forgée, et un type inventé recréerait
        // exactement le désordre que le catalogue supprime.
        if ($type === '' || !in_array($type, $types_connus, true))
            json_response(false, 'Type de PMMA inconnu au catalogue.');
        db_query("INSERT INTO stock_pmma (site_id,type_pmma,quantite,type_mouvement,notes,created_by) VALUES (?,?,?,'entree',?,?)",
            [$site_id, $type, $quantite, $notes, $user['id']]);
        // Le code d'origine faisait un UPDATE puis SELECT ROW_COUNT() pour
        // savoir s'il fallait créer la ligne : ROW_COUNT() est du MySQL et
        // n'existe pas sur PostgreSQL, l'entrée partait donc en erreur
        // après avoir déjà écrit le mouvement. L'UPSERT fait les deux d'un
        // coup, et la contrainte unique (site_id, type_pmma) le garantit.
        db_query("INSERT INTO stock_pmma_site (site_id,type_pmma,quantite,seuil_alerte)
                  VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite),
                                          updated_at = CURRENT_TIMESTAMP",
            [$site_id, $type, $quantite,
             $catalogue_pmma[$type]['seuil_defaut'] ?? 10]);
        audit_log($user['id'], 'CREATE', 'pmma', null, "Entrée PMMA: $quantite ($type) site:$site_id");
        $cart = cartons_pour($quantite, $type);
        json_response(true, "Entrée de $quantite PMMA enregistrée"
            . ($cart ? " (~$cart carton(s) de " . capacite_carton_pmma($type) . ")." : '.'));
    }

    if ($action === 'sortie') {
        $site_id   = $site_force ?: (int)($_POST['site_id'] ?? 0);
        $quantite  = (int)($_POST['quantite'] ?? 0);
        $type      = trim($_POST['type_pmma'] ?? '');
        $bobine_id = (int)($_POST['bobine_id'] ?? 0);
        if (!$site_id || $quantite <= 0) json_response(false, 'Site et quantité obligatoires.');
        if ($type === '' || !in_array($type, $types_connus, true))
            json_response(false, 'Type de PMMA inconnu au catalogue.');
        $stock_actuel = (int)db_fetch_value(
            "SELECT quantite FROM stock_pmma_site WHERE site_id=? AND type_pmma=?", [$site_id, $type]
        );
        if ($stock_actuel < $quantite) {
            $cap = capacite_carton_pmma($type);
            $msg = "Stock insuffisant. Disponible : $stock_actuel unité(s)";
            if ($stock_actuel > 0) {
                $nb_cart = intdiv($stock_actuel, $cap);
                $reste   = $stock_actuel % $cap;
                $msg .= " (" . $nb_cart . " carton(s)" . ($reste ? " + $reste u." : '') . ")";
            }
            json_response(false, $msg);
        }
        db_query("INSERT INTO stock_pmma (site_id,type_pmma,quantite,type_mouvement,bobine_id,notes,created_by) VALUES (?,?,?,'sortie',?,?,?)",
            [$site_id, $type, $quantite, $bobine_id ?: null, 'Utilisation production', $user['id']]);
        db_query("UPDATE stock_pmma_site SET quantite=quantite-? WHERE site_id=? AND type_pmma=?",
            [$quantite, $site_id, $type]);
        audit_log($user['id'], 'UPDATE', 'pmma', null, "Sortie PMMA: $quantite ($type) site:$site_id");
        json_response(true, "Sortie de $quantite PMMA enregistrée.");
    }

    json_response(false, 'Action inconnue.');
}

// ── FILTRES
$f_site = $site_force ?: (int)($_GET['site'] ?? 0);
$f_type = trim($_GET['type'] ?? '');
$f_from = $_GET['from'] ?? date('Y-m-01');
$f_to   = $_GET['to']   ?? date('Y-m-d');

// Formats PMMA disponibles (liste globale, indépendante des filtres actifs)
$types_list = db_fetch_all(
    "SELECT DISTINCT type_pmma FROM stock_pmma_site WHERE type_pmma <> '' ORDER BY type_pmma"
);

// ── STOCK ACTUEL
$stock_par_site = db_fetch_all(
    "SELECT sp.*, s.nom AS site_nom
     FROM stock_pmma_site sp
     JOIN sites s ON s.id = sp.site_id
     WHERE (? = 0 OR sp.site_id = ?) AND (? = '' OR sp.type_pmma = ?)
     ORDER BY s.nom, sp.type_pmma",
    [$f_site ?: 0, $f_site ?: 0, $f_type, $f_type]
);
if (empty($stock_par_site) && !$f_site && $f_type === '') {
    $stock_par_site = db_fetch_all(
        "SELECT s.id AS site_id, s.nom AS site_nom, '' AS type_pmma, 0 AS quantite, 10 AS seuil_alerte
         FROM sites s WHERE s.actif=1 ORDER BY s.nom"
    );
}

// ── Matrice site × type (vue "tous les sites")
$pmma_types        = [];
$pmma_matrix       = [];
$site_names_order  = [];
foreach ($stock_par_site as $sp_item) {
    $sid = $sp_item['site_id'];
    if (!isset($site_names_order[$sid])) $site_names_order[$sid] = $sp_item['site_nom'];
    if ($sp_item['type_pmma'] === '') continue;
    $t = $sp_item['type_pmma'];
    if (!in_array($t, $pmma_types, true)) $pmma_types[] = $t;
    $pmma_matrix[$sid][$t] = ['quantite' => (int)$sp_item['quantite'], 'seuil' => (int)($sp_item['seuil_alerte'] ?? 10)];
}
sort($pmma_types);

// ── CONSOMMATION (depuis les points journaliers)
$ws_site = $f_site ? "AND p.site_id = $f_site" : '';
$ws_type = '';
$conso_params = [$f_from, $f_to];
if ($f_type !== '') {
    $ws_type = 'AND pu.type_pmma = ?';
    $conso_params[] = $f_type;
}
// Non conformes (PMMA reçus hors règles) : colonne ajoutée par
// sql/migration_pmma_non_conformes.sql, lue à 0 tant qu'elle n'existe pas.
$nc_sql = db_colonne_existe('op_pmma_utilises', 'non_conformes') ? 'pu.non_conformes' : '0';
$conso = db_fetch_all(
    "SELECT p.date_point, p.site_id, s.nom AS site_nom, pu.type_pmma,
            SUM(pu.utilises) AS utilises,
            SUM(pu.endommages) AS endommages,
            SUM($nc_sql) AS non_conformes,
            SUM(pu.utilises + pu.endommages + $nc_sql) AS total_sortis
     FROM op_pmma_utilises pu
     JOIN op_points_journaliers p ON p.id = pu.point_id
     JOIN sites s ON s.id = p.site_id
     WHERE p.date_point BETWEEN ? AND ? $ws_site $ws_type
     GROUP BY p.date_point, p.site_id, s.nom, pu.type_pmma
     ORDER BY p.date_point DESC",
    $conso_params
);

// Lookup stock actuel : site_id_type → quantite
$stock_map = [];
foreach ($stock_par_site as $sp) {
    $key = $sp['site_id'] . '_' . ($sp['type_pmma'] ?: 'Standard');
    $stock_map[$key] = (int)$sp['quantite'];
}
// Lookup stock actuel par type (pour PDF mensuel)
$stock_type_map = [];
foreach ($stock_par_site as $sp) {
    $t = $sp['type_pmma'] ?: 'Standard';
    $stock_type_map[$t] = ($stock_type_map[$t] ?? 0) + (int)$sp['quantite'];
}

// ── SYNTHÈSE PAR TYPE SUR LA PÉRIODE
$totaux_type = [];
$grand_total  = ['utilises' => 0, 'endommages' => 0, 'non_conformes' => 0, 'total' => 0];
foreach ($conso as $c) {
    $k = $c['type_pmma'] ?: 'Standard';
    if (!isset($totaux_type[$k])) $totaux_type[$k] = ['utilises' => 0, 'endommages' => 0, 'total' => 0];
    $totaux_type[$k]['utilises']   += (int)$c['utilises'];
    $totaux_type[$k]['endommages'] += (int)$c['endommages'];
    $totaux_type[$k]['total']      += (int)$c['total_sortis'];
    $grand_total['utilises']       += (int)$c['utilises'];
    $grand_total['endommages']     += (int)$c['endommages'];
    $grand_total['non_conformes']  += (int)$c['non_conformes'];
    $grand_total['total']          += (int)$c['total_sortis'];
}

// Stock bas count
$nb_stock_bas = 0;
foreach ($stock_par_site as $sp_item) {
    if ($sp_item['type_pmma'] && $sp_item['quantite'] < ($sp_item['seuil_alerte'] ?? 10)) $nb_stock_bas++;
}

// Bobines actives pour modal sortie
$bobines_actives = [];
if ($can_saisie) {
    $bobines_actives = db_fetch_all(
        "SELECT id,numero FROM op_bobines WHERE statut='en_cours'"
        . ($site_force ? " AND site_id=$site_force" : "")
        . " ORDER BY numero"
    );
}

// ── EXPORTS
if (isset($_GET['export'])) {
    $export = $_GET['export'];
    $site_label = $f_site
        ? (db_fetch_value("SELECT nom FROM sites WHERE id=?", [$f_site]) ?: 'Site inconnu')
        : 'Tous les sites';
    $type_label = $f_type !== '' ? $f_type : 'Tous les formats';

    if ($export === 'xlsx') {
        $spreadsheet = new Spreadsheet();

        // Feuille 1 : Consommation détail
        $sh1 = $spreadsheet->getActiveSheet();
        $sh1->setTitle('Consommation');
        $headers1 = ['Date', 'Site', 'Type PMMA', 'Utilisés', 'Endommagés', 'Total sorti', 'Stock restant'];
        foreach ($headers1 as $i => $h) {
            $col = chr(65 + $i);
            $sh1->setCellValue("{$col}1", $h);
            $sh1->getStyle("{$col}1")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '06033A']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
        $row = 2;
        foreach ($conso as $c) {
            $sh1->setCellValue("A$row", $c['date_point']);
            $sh1->setCellValue("B$row", $c['site_nom']);
            $sh1->setCellValue("C$row", $c['type_pmma'] ?: 'Standard');
            $sh1->setCellValue("D$row", (int)$c['utilises']);
            $sh1->setCellValue("E$row", (int)$c['endommages']);
            $sh1->setCellValue("F$row", (int)$c['total_sortis']);
            $sh1->setCellValue("G$row", $stock_map[$c['site_id'] . '_' . ($c['type_pmma'] ?: 'Standard')] ?? '');
            $row++;
        }
        // Ligne total
        $sh1->setCellValue("A$row", 'TOTAL');
        $sh1->setCellValue("D$row", $grand_total['utilises']);
        $sh1->setCellValue("E$row", $grand_total['endommages']);
        $sh1->setCellValue("F$row", $grand_total['total']);
        $sh1->getStyle("A$row:F$row")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B75BC']],
        ]);
        foreach (range('A', 'G') as $col) $sh1->getColumnDimension($col)->setAutoSize(true);

        // Feuille 2 : Stock actuel
        $spreadsheet->createSheet();
        $sh2 = $spreadsheet->getSheet(1);
        $sh2->setTitle('Stock actuel');
        $headers2 = ['Site', 'Type PMMA', 'Quantité', 'Seuil alerte', 'État'];
        foreach ($headers2 as $i => $h) {
            $col = chr(65 + $i);
            $sh2->setCellValue("{$col}1", $h);
            $sh2->getStyle("{$col}1")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B75BC']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
        $row2 = 2;
        foreach ($stock_par_site as $sp_item) {
            $seuil = (int)($sp_item['seuil_alerte'] ?? 10);
            $etat  = $sp_item['type_pmma'] && $sp_item['quantite'] < $seuil ? 'Stock bas' : 'OK';
            $sh2->setCellValue("A$row2", $sp_item['site_nom']);
            $sh2->setCellValue("B$row2", $sp_item['type_pmma'] ?: 'Standard');
            $sh2->setCellValue("C$row2", (int)$sp_item['quantite']);
            $sh2->setCellValue("D$row2", $seuil);
            $sh2->setCellValue("E$row2", $etat);
            $row2++;
        }
        foreach (range('A', 'E') as $col) $sh2->getColumnDimension($col)->setAutoSize(true);

        $spreadsheet->setActiveSheetIndex(0);
        $filename = 'suivi_pmma_' . date('Ymd') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = new XlsxWriter($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'pmma_');
        $writer->save($tmp);
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    if ($export === 'pdf') {
        $rows_html = '';
        foreach ($conso as $c) {
            $endomm_color = $c['endommages'] > 0 ? '#991b1b' : '#64748b';
            $restant_pdf  = $stock_map[$c['site_id'] . '_' . ($c['type_pmma'] ?: 'Standard')] ?? '—';
            $rest_color   = is_int($restant_pdf) && $restant_pdf < 10 ? '#991b1b' : '#06033A';
            $rows_html .= '<tr>
                <td>' . h(fmt_date($c['date_point'])) . '</td>
                <td>' . h($c['site_nom']) . '</td>
                <td>' . h($c['type_pmma'] ?: 'Standard') . '</td>
                <td style="text-align:center">' . (int)$c['utilises'] . '</td>
                <td style="text-align:center;color:' . $endomm_color . '">' . (int)$c['endommages'] . '</td>
                <td style="text-align:center;font-weight:700">' . (int)$c['total_sortis'] . '</td>
                <td style="text-align:center;font-weight:700;color:' . $rest_color . '">' . $restant_pdf . '</td>
            </tr>';
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:Arial,sans-serif;font-size:11px;margin:20px}
        h1{font-size:15px;color:#06033A;margin:0 0 3px 0}
        .sub{font-size:9px;color:#64748b;margin-bottom:14px}
        table{width:100%;border-collapse:collapse}
        th{background:#06033A;color:#fff;padding:7px 8px;font-size:10px;text-align:center}
        td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
        tr:nth-child(even) td{background:#f8fafc}
        .total-row td{background:#06033A!important;color:#fff!important;font-weight:bold;text-align:center}
        </style></head><body>
        <table width="100%" style="border-collapse:collapse;margin-bottom:10px"><tr>
          <td style="vertical-align:middle;width:85px;padding-right:10px">' . pdf_logo_img('38px') . '</td>
          <td style="vertical-align:middle;padding-left:12px;border-left:3px solid #06033A">
            <div style="font-size:15px;font-weight:bold;color:#06033A">Suivi Consommation PMMA</div>
            <div style="font-size:9px;color:#64748b;margin-top:2px">Express Multiservices CI</div>
          </td>
        </tr></table>
        <div class="sub">Période : ' . h($f_from) . ' → ' . h($f_to) . ' &nbsp;|&nbsp; Site : ' . h($site_label) . ' &nbsp;|&nbsp; Format : ' . h($type_label) . ' &nbsp;|&nbsp; Généré le ' . date('d/m/Y H:i') . '</div>
        <table><thead><tr>
            <th>Date</th><th>Site</th><th>Type PMMA</th><th>Utilisés</th><th>Endommagés</th><th>Total sorti</th><th>Stock restant</th>
        </tr></thead><tbody>
        ' . $rows_html . '
        <tr class="total-row"><td colspan="3">TOTAL PÉRIODE</td>
            <td>' . $grand_total['utilises'] . '</td>
            <td>' . $grand_total['endommages'] . '</td>
            <td>' . $grand_total['total'] . '</td>
            <td></td>
        </tr></tbody></table></body></html>';

        $opts = new Options();
        $opts->set('isRemoteEnabled', false);
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="suivi_pmma_' . date('Ymd') . '.pdf"');
        echo $pdf->output();
        exit;
    }

    // ── PDF SYNTHÈSE MENSUELLE
    if ($export === 'pdf_mensuel') {
        $mois_fr = ['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin',
                    '07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre'];

        // Grouper par mois × type PMMA
        $par_mois = [];
        foreach ($conso as $c) {
            $m    = substr($c['date_point'], 0, 7); // YYYY-MM
            $type = $c['type_pmma'] ?: 'Standard';
            $key  = $m . '|' . $type;
            if (!isset($par_mois[$key])) {
                $par_mois[$key] = [
                    'mois'       => $m,
                    'mois_label' => ($mois_fr[substr($m,5,2)] ?? substr($m,5,2)) . ' ' . substr($m,0,4),
                    'type_pmma'  => $type,
                    'utilises'   => 0, 'endommages' => 0, 'total' => 0,
                ];
            }
            $par_mois[$key]['utilises']   += (int)$c['utilises'];
            $par_mois[$key]['endommages'] += (int)$c['endommages'];
            $par_mois[$key]['total']      += (int)$c['total_sortis'];
        }
        ksort($par_mois);

        $rows_html = '';
        $cur_mois  = '';
        foreach ($par_mois as $row) {
            $is_new    = $row['mois'] !== $cur_mois;
            $cur_mois  = $row['mois'];
            $bg        = $is_new ? '#f0f4ff' : '#ffffff';
            $ec        = $row['endommages'] > 0 ? '#991b1b' : '#64748b';
            $rest_m = $stock_type_map[$row['type_pmma']] ?? '—';
            $rest_m_color = is_int($rest_m) && $rest_m < 10 ? '#991b1b' : '#06033A';
            $rows_html .= '<tr style="background:' . $bg . '">
                <td style="font-weight:' . ($is_new ? '700' : '400') . ';color:#06033A">' . ($is_new ? $row['mois_label'] : '') . '</td>
                <td>' . h($row['type_pmma']) . '</td>
                <td style="text-align:center;font-weight:700">' . $row['utilises'] . '</td>
                <td style="text-align:center;color:' . $ec . '">' . $row['endommages'] . '</td>
                <td style="text-align:center;font-weight:800;color:#06033A">' . $row['total'] . '</td>
                <td style="text-align:center;font-weight:700;color:' . $rest_m_color . '">' . $rest_m . '</td>
            </tr>';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:Arial,sans-serif;font-size:11px;margin:20px}
        .sub{font-size:9px;color:#64748b;margin-bottom:14px}
        table{width:100%;border-collapse:collapse}
        th{background:#06033A;color:#fff;padding:7px 10px;font-size:10px;text-align:center}
        th:first-child{text-align:left}
        td{padding:6px 10px;border-bottom:1px solid #e2e8f0;font-size:11px}
        .total-row td{background:#1B75BC!important;color:#fff!important;font-weight:bold;text-align:center}
        .total-row td:first-child{text-align:left}
        </style></head><body>
        <table width="100%" style="border-collapse:collapse;margin-bottom:10px"><tr>
          <td style="vertical-align:middle;width:85px;padding-right:10px">' . pdf_logo_img('38px') . '</td>
          <td style="vertical-align:middle;padding-left:12px;border-left:3px solid #06033A">
            <div style="font-size:15px;font-weight:bold;color:#06033A">Synthèse mensuelle PMMA</div>
            <div style="font-size:9px;color:#64748b;margin-top:2px">Express Multiservices CI</div>
          </td>
        </tr></table>
        <div class="sub">Période : ' . h($f_from) . ' → ' . h($f_to) . ' &nbsp;|&nbsp; Site : ' . h($site_label) . ' &nbsp;|&nbsp; Format : ' . h($type_label) . ' &nbsp;|&nbsp; Généré le ' . date('d/m/Y H:i') . '</div>
        <table><thead><tr>
            <th style="text-align:left">Mois</th><th>Type PMMA</th><th>Utilisés</th><th>Endommagés</th><th>Total sorti</th><th>Stock restant</th>
        </tr></thead><tbody>
        ' . ($rows_html ?: '<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px">Aucune donnée sur cette période</td></tr>') . '
        <tr class="total-row">
            <td colspan="2">TOTAL PÉRIODE</td>
            <td>' . $grand_total['utilises'] . '</td>
            <td>' . $grand_total['endommages'] . '</td>
            <td>' . $grand_total['total'] . '</td>
            <td></td>
        </tr></tbody></table></body></html>';

        $opts = new Options();
        $opts->set('isRemoteEnabled', false);
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="pmma_mensuel_' . date('Ymd') . '.pdf"');
        echo $pdf->output();
        exit;
    }
}

include __DIR__ . '/../templates/header.php';
?>
<style>
.pmma-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:24px}
.pmma-card{background:white;border-radius:14px;border:1px solid var(--border);overflow:hidden}
.pmma-head{padding:12px 16px;background:var(--navy);display:flex;justify-content:space-between;align-items:center}
.pmma-site{color:white;font-family:'Montserrat',sans-serif;font-size:13px;font-weight:700}
.pmma-body{padding:14px 16px}
.pmma-alert{background:#fee2e2;color:#991b1b;padding:5px 10px;border-radius:8px;font-size:12px;margin-top:8px;font-weight:600}
.kpi-bar{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.kpi{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 16px}
.kpi-val{font-family:'Montserrat',sans-serif;font-size:28px;font-weight:900;line-height:1}
.kpi-lbl{font-size:12px;color:var(--muted);font-weight:600;margin-top:3px}
.filter-bar{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:20px}
.filter-bar label{font-size:12px;font-weight:600;color:var(--navy);display:block;margin-bottom:4px}
.filter-bar input,.filter-bar select{padding:8px 11px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:white;outline:none}
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(10,22,40,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;animation:mIn .22s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-family:'Montserrat',sans-serif;font-size:16px;font-weight:700}
.mclose{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:none;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center}
.mbody{padding:22px}
.mfoot{padding:12px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:white}
.pmma-tabs{display:flex;gap:6px;border-bottom:2px solid var(--border);margin-bottom:18px}
.pmma-tab-btn{background:none;border:none;padding:10px 16px;font-size:13px;font-weight:700;color:var(--muted);cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:6px}
.pmma-tab-btn.active{color:var(--navy);border-bottom-color:var(--blue)}
.pmma-tab-panel{display:none}
.pmma-tab-panel.active{display:block}
.pmma-matrix td,.pmma-matrix th{text-align:center}
.pmma-matrix td:first-child,.pmma-matrix th:first-child{text-align:left}
#pmmaKpis[data-active="stock"] .kpi[data-kpi-group="conso"]{display:none}
#pmmaKpis[data-active="conso"] .kpi[data-kpi-group="stock"]{display:none}
</style>

<!-- TOOLBAR -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="font-family:'Montserrat',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">Suivi PMMA</h2>
    <p style="font-size:12px;color:var(--muted);margin-top:2px">Consommation depuis les points journaliers — Support plaques d'immatriculation</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'xlsx'])) ?>"
       class="btn btn-secondary" style="font-size:13px;display:flex;align-items:center;gap:6px">
      <i class="ph-duotone ph-microsoft-excel-logo" style="font-size:16px"></i> Excel
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'pdf'])) ?>"
       class="btn btn-secondary" style="font-size:13px;display:flex;align-items:center;gap:6px"
       title="Détail jour par jour">
      <i class="ph-duotone ph-file-pdf" style="font-size:16px"></i> PDF Détail
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'pdf_mensuel'])) ?>"
       class="btn btn-secondary" style="font-size:13px;display:flex;align-items:center;gap:6px"
       title="Synthèse groupée par mois">
      <i class="ph-duotone ph-calendar-blank" style="font-size:16px"></i> PDF Mensuel
    </a>
    <?php if ($can_saisie): ?>
    <button class="btn btn-primary" style="font-size:13px" onclick="document.getElementById('mEntree').classList.add('open')">
      <i class="ph-duotone ph-download-simple"></i> Entrée PMMA
    </button>
    <button class="btn" onclick="document.getElementById('mSortie').classList.add('open')"
      style="background:#e8f4f9;color:var(--blue);border:1.5px solid var(--blue);font-size:13px">
      <i class="ph-duotone ph-upload-simple"></i> Sortie PMMA
    </button>
    <button class="btn btn-secondary" style="font-size:13px" onclick="document.getElementById('modalImportPmma').style.display='flex'">
      <i class="ph-duotone ph-upload-simple"></i> Importer
    </button>
    <a href="?modele=1" class="btn btn-secondary" style="font-size:13px;display:flex;align-items:center;gap:6px">
      <i class="ph-duotone ph-download-simple"></i> Modèle
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if($import_result): ?>
<div class="alert alert-<?= $import_result['ok'] ? ($import_result['nb_err'] ? 'warning' : 'success') : 'danger' ?>" style="margin-bottom:16px">
  <?php if(!$import_result['ok']): ?>
    <i class="ph ph-x-circle" aria-hidden="true"></i> Import échoué : <?= h($import_result['erreur']) ?>
  <?php else: ?>
    <i class="ph ph-check-circle" aria-hidden="true"></i> Import terminé : <strong><?= $import_result['nb_ok'] ?></strong> ligne(s) de stock mise(s) à jour.
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

<?php if($can_saisie): ?>
<!-- MODAL IMPORT PMMA -->
<div id="modalImportPmma" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;padding:20px">
  <div style="background:white;border-radius:20px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)">Importer le stock PMMA</h3>
      <button onclick="document.getElementById('modalImportPmma').style.display='none'" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px">
      Fichier Excel (.xlsx/.xls) ou CSV. Colonnes : Site, Type PMMA, <strong>Cartons</strong>, Unités en vrac (facultatif, carton entamé) — téléchargez le modèle pour les exemples.
      Les unités sont calculées automatiquement : cartons × capacité du type<?php if($type_capacites): ?> (<?= h(implode(', ', array_map(fn($t, $c) => "$t : $c", array_keys($type_capacites), $type_capacites))) ?>)<?php endif; ?> + vrac.
      <strong>Chaque ligne remplace le stock actuel</strong> du site+type (ce n'est pas un ajout).
    </p>
    <form method="POST" enctype="multipart/form-data">
      <input type="file" name="fichier_import" accept=".xlsx,.xls,.csv" required class="form-control" style="margin-bottom:16px">
      <div style="display:flex;justify-content:flex-end;gap:10px">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalImportPmma').style.display='none'">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="ph ph-upload-simple" aria-hidden="true"></i> Importer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- FILTRE BAR -->
<div class="filter-bar">
  <div>
    <label for="fFrom">Du</label>
    <input type="date" id="fFrom" value="<?= h($f_from) ?>">
  </div>
  <div>
    <label for="fTo">Au</label>
    <input type="date" id="fTo" value="<?= h($f_to) ?>">
  </div>
  <?php if (!$site_force): ?>
  <div>
    <label for="fSite">Site</label>
    <select id="fSite" onchange="appliquerFiltres()">
      <option value="0">Tous les sites</option>
      <?php foreach ($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site == $s['id'] ? 'selected' : '' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <?php if (!empty($types_list)): ?>
  <div>
    <label for="fType">Format PMMA</label>
    <select id="fType" onchange="appliquerFiltres()">
      <option value="">Tous les formats</option>
      <?php foreach ($types_list as $t): ?>
      <option value="<?= h($t['type_pmma']) ?>" <?= $f_type === $t['type_pmma'] ? 'selected' : '' ?>><?= h($t['type_pmma']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div style="display:flex;gap:8px;align-items:flex-end">
    <button class="btn btn-primary" style="font-size:13px" onclick="appliquerFiltres()">Appliquer</button>
    <button class="btn btn-secondary" style="font-size:13px" onclick="resetFiltres()">Réinitialiser</button>
  </div>
</div>

<!-- KPI -->
<div class="kpi-bar" id="pmmaKpis" data-active="stock">
  <?php
  $total_stock = array_sum(array_column($stock_par_site, 'quantite'));
  ?>
  <div class="kpi" data-kpi-group="stock">
    <div class="kpi-val" style="color:var(--blue)"><?= fmt_number($total_stock) ?></div>
    <div class="kpi-lbl">Total PMMA en stock</div>
  </div>
  <?php foreach ($pmma_types as $typ): ?>
  <div class="kpi" data-kpi-group="stock">
    <div class="kpi-val" style="color:var(--blue);font-size:22px"><?= fmt_number($stock_type_map[$typ] ?? 0) ?></div>
    <div class="kpi-lbl">Total <?= h($typ) ?> en stock</div>
  </div>
  <?php endforeach; ?>
  <?php if ($nb_stock_bas > 0): ?>
  <div class="kpi" data-kpi-group="stock" style="border-color:#fca5a5;background:#fff5f5">
    <div class="kpi-val" style="color:var(--danger-d)"><?= $nb_stock_bas ?></div>
    <div class="kpi-lbl">Type(s) en stock bas</div>
  </div>
  <?php endif; ?>
  <div class="kpi" data-kpi-group="conso">
    <div class="kpi-val" style="color:var(--navy)"><?= fmt_number($grand_total['total']) ?></div>
    <div class="kpi-lbl">Consommés sur la période</div>
  </div>
  <?php foreach ($pmma_types as $typ): ?>
  <div class="kpi" data-kpi-group="conso">
    <div class="kpi-val" style="color:var(--blue);font-size:22px"><?= fmt_number($totaux_type[$typ]['total'] ?? 0) ?></div>
    <div class="kpi-lbl"><?= h($typ) ?> Consommés</div>
  </div>
  <?php endforeach; ?>
  <?php if ($grand_total['endommages'] > 0): ?>
  <div class="kpi" data-kpi-group="conso" style="border-color:#fca5a5">
    <div class="kpi-val" style="color:var(--danger-d)"><?= fmt_number($grand_total['endommages']) ?></div>
    <div class="kpi-lbl">Endommagés sur la période</div>
  </div>
  <?php endif; ?>
  <?php if ($grand_total['non_conformes'] > 0): ?>
  <div class="kpi" data-kpi-group="conso" style="border-color:#c4b5fd">
    <div class="kpi-val" style="color:#7c3aed"><?= fmt_number($grand_total['non_conformes']) ?></div>
    <div class="kpi-lbl">Non conformes sur la période</div>
  </div>
  <?php endif; ?>
</div>

<!-- ONGLETS -->
<div class="pmma-tabs">
  <button type="button" class="pmma-tab-btn active" data-tab="stock" onclick="pmmaTab('stock')">
    <i class="ph-duotone ph-package"></i> Stock actuel
  </button>
  <button type="button" class="pmma-tab-btn" data-tab="conso" onclick="pmmaTab('conso')">
    <i class="ph-duotone ph-clipboard-text"></i> Historique consommation
  </button>
</div>

<!-- STOCK PAR SITE -->
<div class="pmma-tab-panel active" id="tabPanelStock">
<div id="pmmaStock">
<div style="font-family:'Montserrat',sans-serif;font-size:13px;font-weight:700;color:var(--navy);margin-bottom:10px">
  <i class="ph-duotone ph-package" style="vertical-align:middle"></i> Stock actuel par site
</div>
<?php if ($f_site): ?>
<div class="pmma-grid">
  <?php
  $sites_grouped = [];
  foreach ($stock_par_site as $sp_item) {
      $sites_grouped[$sp_item['site_nom']][] = $sp_item;
  }
  foreach ($sites_grouped as $site_nom => $items): ?>
  <div class="pmma-card">
    <div class="pmma-head">
      <div class="pmma-site"><i class="ph-duotone ph-map-pin"></i> <?= h($site_nom) ?></div>
    </div>
    <div class="pmma-body">
      <?php foreach ($items as $item): if (!$item['type_pmma']) continue;
        $cap_item  = $type_capacites[$item['type_pmma']] ?? capacite_carton_pmma($item['type_pmma']);
        $nb_cart   = intdiv((int)$item['quantite'], $cap_item);
        $reste_u   = (int)$item['quantite'] % $cap_item;
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--navy)"><?= h($item['type_pmma']) ?></div>
          <div style="font-size:12px;color:var(--muted)">Seuil : <?= (int)($item['seuil_alerte'] ?? 10) ?> · carton de <?= $cap_item ?></div>
        </div>
        <div style="text-align:right">
          <div style="font-family:'Montserrat',sans-serif;font-size:26px;font-weight:900;color:<?= $item['quantite'] < ($item['seuil_alerte'] ?? 10) ? 'var(--danger-d)' : 'var(--blue)' ?>">
            <?= (int)$item['quantite'] ?>
          </div>
          <div style="font-size:12px;color:var(--muted)">unités</div>
          <div style="font-size:11.5px;color:var(--muted)">≈ <?= $nb_cart ?> carton(s)<?= $reste_u ? " + $reste_u u." : '' ?></div>
        </div>
      </div>
      <?php if ($item['quantite'] < ($item['seuil_alerte'] ?? 10)): ?>
      <div class="pmma-alert"><i class="ph-duotone ph-warning"></i> Stock bas — commander</div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card">
  <?php if (empty($pmma_types)): ?>
  <div style="text-align:center;padding:30px;color:var(--muted)">Aucune donnée de stock disponible.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="pmma-matrix">
      <thead><tr>
        <th>Site</th>
        <?php foreach ($pmma_types as $t): ?><th><?= h($t) ?> <span style="font-weight:400;color:var(--muted)">(carton de <?= $type_capacites[$t] ?? capacite_carton_pmma($t) ?>)</span></th><?php endforeach; ?>
        <th>Total <span style="font-weight:400;color:var(--muted)">(unités)</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($site_names_order as $sid => $site_nom): $site_total = 0; ?>
        <tr>
          <td style="font-weight:600;color:var(--navy)"><?= h($site_nom) ?></td>
          <?php foreach ($pmma_types as $t): $cell = $pmma_matrix[$sid][$t] ?? null; if ($cell) $site_total += $cell['quantite'];
            $cap_t = $type_capacites[$t] ?? capacite_carton_pmma($t);
          ?>
          <td>
            <?php if ($cell): $bas = $cell['quantite'] < $cell['seuil']; $nb_c = intdiv($cell['quantite'], $cap_t); $rst = $cell['quantite'] % $cap_t; ?>
            <span style="font-weight:700;color:<?= $bas ? 'var(--danger-d)' : 'var(--navy)' ?>"><?= $cell['quantite'] ?></span>
            <?php if ($bas): ?><i class="ph-duotone ph-warning" style="color:var(--danger-d);margin-left:3px" title="Stock bas"></i><?php endif; ?>
            <div style="font-size:11px;color:var(--muted)"><?= $nb_c ?> carton(s)<?= $rst ? "+$rst" : '' ?></div>
            <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td style="font-family:'Montserrat',sans-serif;font-weight:800;color:var(--blue)"><?= $site_total ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>

<!-- TABLEAU CONSOMMATION -->
<div class="pmma-tab-panel" id="tabPanelConso">
<div class="card" id="pmmaResultCard">
  <div class="card-header">
    <h3><i class="ph-duotone ph-clipboard-text" style="vertical-align:middle"></i>
      Consommation PMMA — Points journaliers
      <span style="font-size:12px;font-weight:400;color:var(--muted);margin-left:8px">
        du <?= h(fmt_date($f_from)) ?> au <?= h(fmt_date($f_to)) ?>
      </span>
    </h3>
  </div>
  <div class="table-wrap">
    <table data-paginer="25">
      <thead><tr>
        <th>Date</th>
        <?php if (!$site_force): ?><th>Site</th><?php endif; ?>
        <th>Type PMMA</th>
        <th style="text-align:center">Utilisés</th>
        <th style="text-align:center">Endommagés</th>
        <th style="text-align:center">Non conformes</th>
        <th style="text-align:center">Total sorti</th>
        <th style="text-align:center">Stock restant</th>
      </tr></thead>
      <tbody>
      <?php if (empty($conso)): ?>
        <tr><td colspan="<?= $site_force ? 7 : 8 ?>" style="text-align:center;padding:30px;color:var(--muted)">
          Aucune consommation sur cette période.
        </td></tr>
      <?php else: foreach ($conso as $c): ?>
        <?php $restant = $stock_map[$c['site_id'] . '_' . ($c['type_pmma'] ?: 'Standard')] ?? '—'; ?>
        <tr>
          <td><?= h(fmt_date($c['date_point'])) ?></td>
          <?php if (!$site_force): ?><td><?= h($c['site_nom']) ?></td><?php endif; ?>
          <td><span style="background:#e0f0ff;color:#0d5c8a;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:700">
            <?= h($c['type_pmma'] ?: 'Standard') ?>
          </span></td>
          <td style="text-align:center;font-weight:700;color:var(--blue)"><?= (int)$c['utilises'] ?></td>
          <td style="text-align:center;font-weight:600;color:<?= $c['endommages'] > 0 ? 'var(--danger-d)' : 'var(--muted)' ?>">
            <?= (int)$c['endommages'] ?: '—' ?>
          </td>
          <td style="text-align:center;font-weight:600;color:<?= $c['non_conformes'] > 0 ? '#7c3aed' : 'var(--muted)' ?>">
            <?= (int)$c['non_conformes'] ?: '—' ?>
          </td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:800;font-size:15px"><?= (int)$c['total_sortis'] ?></td>
          <td style="text-align:center;font-weight:700;color:<?= is_int($restant) && $restant < 10 ? 'var(--danger-d)' : 'var(--navy)' ?>">
            <?= is_int($restant) ? $restant : $restant ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      <?php if (!empty($conso)): ?>
        <tr data-pg-fixe style="background:#f0f4ff">
          <td colspan="<?= $site_force ? 2 : 3 ?>" style="font-weight:700;color:var(--navy)">TOTAL PÉRIODE</td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:900;color:var(--blue)"><?= $grand_total['utilises'] ?></td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:900;color:var(--danger-d)"><?= $grand_total['endommages'] ?: '—' ?></td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:900;color:#7c3aed"><?= $grand_total['non_conformes'] ?: '—' ?></td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:900;font-size:16px;color:var(--navy)"><?= $grand_total['total'] ?></td>
          <td></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<?php if ($can_saisie): ?>
<!-- MODAL ENTRÉE -->
<div class="modal-overlay" id="mEntree">
  <div class="modal">
    <div class="mhdr">
      <h3><i class="ph-duotone ph-download-simple"></i> Entrée PMMA</h3>
      <button class="mclose" onclick="document.getElementById('mEntree').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <div id="alertEntree"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
        <?php if (!$site_force): ?>
        <div class="form-group"><label>Site *</label>
          <select class="form-control" id="eSiteId">
            <option value="">— Sélectionner —</option>
            <?php foreach ($sites_list as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php else: ?><input type="hidden" id="eSiteId" value="<?= $site_force ?>"><?php endif; ?>
        <div class="form-group"><label>Type PMMA</label>
          <select class="form-control" id="eTypePmma" onchange="majCartonsHint('e')">
            <option value="">— choisir —</option>
            <?php foreach ($types_connus as $t): ?>
            <option value="<?= h($t) ?>" data-cap="<?= $type_capacites[$t] ?? capacite_carton_pmma($t) ?>"><?= h($t) ?><?php
              if (isset($catalogue_pmma[$t])):
                ?> — carton de <?= (int)$catalogue_pmma[$t]['unites_par_carton'] ?><?php
              endif; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group" style="margin-bottom:14px"><label>Quantité * <span style="font-weight:400;color:var(--muted)">(en unités)</span></label>
        <input type="number" class="form-control" id="eQteEntree" min="1" value="1" oninput="majCartonsHint('e')">
        <div id="eQteCartonsHint" style="font-size:12px;color:var(--muted);margin-top:4px"></div>
      </div>
      <div class="form-group"><label>Notes</label>
        <input type="text" class="form-control" id="eNotesEntree" placeholder="Fournisseur, N° BL…">
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mEntree').classList.remove('open')">Annuler</button>
      <button class="btn btn-primary" onclick="enregistrerEntree()">Enregistrer</button>
    </div>
  </div>
</div>

<!-- MODAL SORTIE -->
<div class="modal-overlay" id="mSortie">
  <div class="modal">
    <div class="mhdr">
      <h3><i class="ph-duotone ph-upload-simple"></i> Sortie PMMA</h3>
      <button class="mclose" onclick="document.getElementById('mSortie').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <div id="alertSortie"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
        <?php if (!$site_force): ?>
        <div class="form-group"><label>Site *</label>
          <select class="form-control" id="sSiteId">
            <option value="">— Sélectionner —</option>
            <?php foreach ($sites_list as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php else: ?><input type="hidden" id="sSiteId" value="<?= $site_force ?>"><?php endif; ?>
        <div class="form-group"><label>Type PMMA</label>
          <select class="form-control" id="sTypePmma" onchange="majCartonsHint('s')">
            <option value="">— choisir —</option>
            <?php foreach ($types_connus as $t): ?>
            <option value="<?= h($t) ?>" data-cap="<?= $type_capacites[$t] ?? capacite_carton_pmma($t) ?>"><?= h($t) ?><?php
              if (isset($catalogue_pmma[$t])):
                ?> — carton de <?= (int)$catalogue_pmma[$t]['unites_par_carton'] ?><?php
              endif; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="form-group"><label>Quantité * <span style="font-weight:400;color:var(--muted)">(en unités)</span></label>
          <input type="number" class="form-control" id="sQteSortie" min="1" value="1" oninput="majCartonsHint('s')">
          <div id="sQteCartonsHint" style="font-size:12px;color:var(--muted);margin-top:4px"></div>
        </div>
        <div class="form-group"><label>Bobine associée</label>
          <select class="form-control" id="sBobineId">
            <option value="">— Optionnel —</option>
            <?php foreach ($bobines_actives as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['numero']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mSortie').classList.remove('open')">Annuler</button>
      <button class="btn" onclick="enregistrerSortie()"
        style="background:#e8f4f9;color:var(--blue);border:1.5px solid var(--blue)">Enregistrer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// Conversion unités -> cartons en direct dans les modals Entrée/Sortie,
// pour éviter la confusion "j'ai saisi 50 en pensant 50 cartons" (la
// quantité est toujours stockée/comparée en unités côté serveur).
function majCartonsHint(prefix){
  const typeSel = document.getElementById(prefix+'TypePmma');
  const qteEl   = document.getElementById(prefix+'Qte'+(prefix==='e'?'Entree':'Sortie'));
  const hintEl  = document.getElementById(prefix+'QteCartonsHint');
  if(!typeSel||!qteEl||!hintEl) return;
  const opt = typeSel.options[typeSel.selectedIndex];
  const cap = opt ? parseInt(opt.dataset.cap||'0',10) : 0;
  const qte = parseInt(qteEl.value||'0',10);
  if(!cap || !qte){ hintEl.textContent=''; return; }
  const nbCartons = Math.floor(qte/cap), reste = qte%cap;
  hintEl.textContent = '≈ '+nbCartons+' carton(s)'+(reste?' + '+reste+' u.':'');
}
function ap(d){
    const fd=new FormData();
    for(const[k,v] of Object.entries(d)) if(v!==undefined) fd.append(k,v);
    return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
}
// ── Filtres sans rechargement de page (meme pattern que equipements.php et
// pages/operations/bobines.php : fetch + DOMParser remplace les zones).
let pmmaEnVol = null;
function pmmaCharger(url){
  if(!window.fetch || !window.DOMParser){ location.href = url; return; }
  if(pmmaEnVol) try{ pmmaEnVol.abort(); }catch(e){}
  const ctrl = window.AbortController ? new AbortController() : null;
  pmmaEnVol = ctrl;
  fetch(url, {credentials:'same-origin', signal: ctrl?ctrl.signal:undefined, headers:{'X-Requested-With':'fetch'}})
    .then(r => { if(!r.ok) throw new Error(r.status); return r.text(); })
    .then(html => {
      if(pmmaEnVol !== ctrl) return;
      const doc = new DOMParser().parseFromString(html, 'text/html');
      ['pmmaKpis','pmmaStock','pmmaResultCard'].forEach(id => {
        const neuf = doc.getElementById(id);
        const ancien = document.getElementById(id);
        if(!neuf || !ancien) throw new Error('structure');
        ancien.replaceWith(neuf);
      });
      const ongletActif = document.getElementById('tabPanelConso').classList.contains('active') ? 'conso' : 'stock';
      document.getElementById('pmmaKpis').dataset.active = ongletActif;
      history.pushState({pmma:1}, '', url);
      pmmaEnVol = null;
    })
    .catch(e => {
      if(e && e.name==='AbortError') return;
      pmmaEnVol = null;
      location.href = url;
    });
}
window.addEventListener('popstate', function(ev){ if(ev.state && ev.state.pmma) location.reload(); });
function pmmaTab(name){
    document.querySelectorAll('.pmma-tab-btn').forEach(b=>b.classList.toggle('active', b.dataset.tab===name));
    document.getElementById('tabPanelStock').classList.toggle('active', name==='stock');
    document.getElementById('tabPanelConso').classList.toggle('active', name==='conso');
    document.getElementById('pmmaKpis').dataset.active = name;
}
function appliquerFiltres(){
    const p=new URLSearchParams();
    p.set('from',document.getElementById('fFrom').value);
    p.set('to',document.getElementById('fTo').value);
    <?php if(!$site_force): ?>
    const site=document.getElementById('fSite').value;
    if(site!=='0') p.set('site',site);
    <?php endif; ?>
    const fType=document.getElementById('fType');
    if(fType && fType.value!=='') p.set('type',fType.value);
    pmmaCharger(location.pathname+'?'+p.toString());
}
function resetFiltres(){
    const today=new Date(),y=today.getFullYear(),m=String(today.getMonth()+1).padStart(2,'0'),d=String(today.getDate()).padStart(2,'0');
    pmmaCharger(location.pathname+'?from='+y+'-'+m+'-01&to='+y+'-'+m+'-'+d);
}
<?php if($can_saisie): ?>
async function enregistrerEntree(){
    const r=await ap({action:'entree',site_id:document.getElementById('eSiteId').value,
        type_pmma:document.getElementById('eTypePmma').value,
        quantite:document.getElementById('eQteEntree').value,
        notes:document.getElementById('eNotesEntree').value});
    if(r.success){toast(r.message,'success');document.getElementById('mEntree').classList.remove('open');setTimeout(()=>location.reload(),800);}
    else document.getElementById('alertEntree').innerHTML=`<div class="alert alert-danger">${r.message}</div>`;
}
async function enregistrerSortie(){
    const r=await ap({action:'sortie',site_id:document.getElementById('sSiteId').value,
        type_pmma:document.getElementById('sTypePmma').value,
        quantite:document.getElementById('sQteSortie').value,
        bobine_id:document.getElementById('sBobineId').value});
    if(r.success){toast(r.message,'success');document.getElementById('mSortie').classList.remove('open');setTimeout(()=>location.reload(),800);}
    else document.getElementById('alertSortie').innerHTML=`<div class="alert alert-danger">${r.message}</div>`;
}
['mEntree','mSortie'].forEach(id=>document.getElementById(id)?.addEventListener('click',e=>{
    if(e.target===e.currentTarget) e.currentTarget.classList.remove('open');
}));
<?php endif; ?>
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
