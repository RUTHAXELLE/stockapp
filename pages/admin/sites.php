<?php
// ============================================================
//  pages/admin/sites.php  —  Gestion des sites
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_auth();
require_permission('sites', 'can_read');

$user        = current_user();
$page_title  = 'Sites';
$active_page = 'sites';

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── CRÉER SITE
    if ($action === 'create') {
        require_permission('sites', 'can_create');
        $code    = strtoupper(trim($_POST['code'] ?? ''));
        $nom     = trim($_POST['nom']             ?? '');
        $type    = trim($_POST['type']            ?? '');
        $adresse = trim($_POST['adresse']         ?? '');
        $ville   = trim($_POST['ville']           ?? '');
        $resp_id = (int)($_POST['responsable_id'] ?? 0) ?: null;
        $opt_caisse = in_array($type, ['entrepot','siege','magasin']) ? 0 : (int)($_POST['option_caisse'] ?? 0);
        // Colonne sites.operation présente ? (sql/migration_operation_sites_duplicata_postimmat.sql)
        // — ignorée tant qu'elle n'est pas passée, pour ne pas casser la création de site.
        $operation_ok = db_colonne_existe('sites', 'operation');
        $operation = in_array($type, ['saisie','pose','mixte']) && trim($_POST['operation'] ?? '') === 'reimmat_post'
            ? 'reimmat_post' : 'primo';
        if (!$code || !$nom || !$type) json_response(false, 'Code, nom et type sont obligatoires.');
        if (db_fetch_value("SELECT COUNT(*) FROM sites WHERE code=?", [$code]) > 0)
            json_response(false, "Le code site $code existe déjà.");
        db_query(
            "INSERT INTO sites (code,nom,type,option_caisse" . ($operation_ok ? ',operation' : '') . ",adresse,ville,responsable_id)
             VALUES (?,?,?,?" . ($operation_ok ? ',?' : '') . ",?,?,?)",
            array_merge([$code, $nom, $type, $opt_caisse], $operation_ok ? [$operation] : [], [$adresse, $ville, $resp_id])
        );
        $id = (int)db_last_id();
        audit_log($user['id'], 'CREATE', 'sites', $id, "Création site $code — $nom");
        json_response(true, 'Site créé.', ['id' => $id]);
    }

    // ── MODIFIER SITE
    if ($action === 'update') {
        require_permission('sites', 'can_update');
        $id         = (int)($_POST['id']             ?? 0);
        $nom        = trim($_POST['nom']             ?? '');
        $type       = trim($_POST['type']            ?? '');
        $adresse    = trim($_POST['adresse']         ?? '');
        $ville      = trim($_POST['ville']           ?? '');
        $actif      = (int)($_POST['actif']          ?? 1);
        $resp_id    = (int)($_POST['responsable_id'] ?? 0) ?: null;
        $opt_caisse = in_array($type, ['entrepot','siege','magasin']) ? 0 : (int)($_POST['option_caisse'] ?? 0);
        $operation_ok = db_colonne_existe('sites', 'operation');
        $operation = in_array($type, ['saisie','pose','mixte']) && trim($_POST['operation'] ?? '') === 'reimmat_post'
            ? 'reimmat_post' : 'primo';
        if (!$nom || !$type) json_response(false, 'Nom et type obligatoires.');
        // Point généré depuis OptoPlate à partir d'une date (vide = non)
        $auto_ok = db_colonne_existe('sites', 'point_auto_depuis');
        $auto_dep = trim($_POST['point_auto_depuis'] ?? '');
        if ($auto_dep !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $auto_dep) || strtotime($auto_dep) === false))
            json_response(false, 'Date de début du point automatique invalide.');
        $old = db_fetch_one("SELECT * FROM sites WHERE id=?", [$id]);
        db_query("UPDATE sites SET nom=?,type=?,option_caisse=?" . ($operation_ok ? ',operation=?' : '') . ",adresse=?,ville=?,responsable_id=?,actif=? WHERE id=?",
            array_merge([$nom, $type, $opt_caisse], $operation_ok ? [$operation] : [], [$adresse, $ville, $resp_id, $actif, $id]));
        if ($auto_ok) {
            db_query("UPDATE sites SET point_auto_depuis=? WHERE id=?", [$auto_dep ?: null, $id]);
            if (($old['point_auto_depuis'] ?? null) != ($auto_dep ?: null))
                audit_log($user['id'], 'UPDATE', 'sites', $id, "Point généré depuis OptoPlate : " . ($auto_dep ? "à partir du $auto_dep" : 'désactivé'));
        }
        audit_log($user['id'], 'UPDATE', 'sites', $id, "Modification site {$old['code']}");
        json_response(true, 'Site mis à jour.');
    }

    // ── DÉSACTIVER SITE (soft)
    if ($action === 'deactivate') {
        require_permission('sites', 'can_update');
        $id  = (int)($_POST['id'] ?? 0);
        $old = db_fetch_one("SELECT code,nom FROM sites WHERE id=?", [$id]);
        if (!$old) json_response(false, 'Site introuvable.');
        db_query("UPDATE sites SET actif=0 WHERE id=?", [$id]);
        audit_log($user['id'], 'UPDATE', 'sites', $id, "Désactivation site {$old['code']}");
        json_response(true, "Site «{$old['nom']}» désactivé.");
    }

    // ── RÉACTIVER SITE
    if ($action === 'reactivate') {
        require_permission('sites', 'can_update');
        $id  = (int)($_POST['id'] ?? 0);
        $old = db_fetch_one("SELECT code,nom FROM sites WHERE id=?", [$id]);
        if (!$old) json_response(false, 'Site introuvable.');
        db_query("UPDATE sites SET actif=1 WHERE id=?", [$id]);
        audit_log($user['id'], 'UPDATE', 'sites', $id, "Réactivation site {$old['code']}");
        json_response(true, "Site «{$old['nom']}» réactivé.");
    }

    // ── SUPPRIMER DÉFINITIVEMENT (hard delete)
    if ($action === 'hard_delete') {
        require_permission('sites', 'can_delete');
        $id  = (int)($_POST['id'] ?? 0);
        $old = db_fetch_one("SELECT code,nom FROM sites WHERE id=?", [$id]);
        if (!$old) json_response(false, 'Site introuvable.');
        $checks = [
            'équipements'       => (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE site_id=?", [$id]),
            'utilisateurs'      => (int)db_fetch_value("SELECT COUNT(*) FROM users WHERE site_id=?", [$id]),
            'bobines'           => (int)db_fetch_value("SELECT COUNT(*) FROM op_bobines WHERE site_id=?", [$id]),
            'points journaliers'=> (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE site_id=?", [$id]),
            'validations stock' => (int)db_fetch_value("SELECT COUNT(*) FROM validations_stock_matin WHERE site_id=?", [$id]),
            'commandes'         => (int)db_fetch_value("SELECT COUNT(*) FROM commandes WHERE site_id=?", [$id]),
            'commandes bobines' => (int)db_fetch_value("SELECT COUNT(*) FROM commandes_bobines WHERE site_id=?", [$id]),
        ];
        $blockers = [];
        foreach ($checks as $label => $n) {
            if ($n > 0) $blockers[] = "$n $label";
        }
        if (!empty($blockers)) {
            json_response(false, "Suppression impossible — données liées : " . implode(', ', $blockers) . ". Désactivez le site à la place.");
        }
        db_query("DELETE FROM sites WHERE id=?", [$id]);
        audit_log($user['id'], 'DELETE', 'sites', $id, "Suppression définitive site {$old['code']} — {$old['nom']}");
        json_response(true, "Site «{$old['nom']}» supprimé définitivement.");
    }

    // ── LIER / CRÉER site depuis EMUCI inconnu
    if ($action === 'lier_site_emuci') {
        require_permission('sites', 'can_update');
        $inconnu_id = (int)($_POST['inconnu_id'] ?? 0);
        $decision   = trim($_POST['decision']    ?? '');
        $site_id    = (int)($_POST['site_id']    ?? 0);
        $inconnu = db_fetch_one("SELECT * FROM emuci_sites_inconnus WHERE id=?", [$inconnu_id]);
        if (!$inconnu) json_response(false,'Entrée introuvable.');
        if ($decision === 'ignorer') {
            db_query("UPDATE emuci_sites_inconnus SET statut='ignore',traite_par=?,traite_at=NOW() WHERE id=?", [$user['id'],$inconnu_id]);
            json_response(true,'Ignoré.');
        }
        if ($decision === 'lier') {
            if (!$site_id) json_response(false,'Site ERP EMUCI obligatoire.');
            $nom_emuci = $inconnu['nom_emuci'];
            db_query("UPDATE sites SET nom_emuci=?, nom=? WHERE id=?", [$nom_emuci, $nom_emuci, $site_id]);
            db_query("UPDATE emuci_sites_inconnus SET statut='lie',site_id_lie=?,traite_par=?,traite_at=NOW() WHERE nom_emuci=?", [$site_id,$user['id'],$nom_emuci]);
            db_query("UPDATE import_optoplate SET site_id=? WHERE site_nom_emuci=? AND site_id IS NULL", [$site_id,$nom_emuci]);
            db_query("UPDATE import_optotrace  SET site_id=? WHERE site_nom_emuci=? AND site_id IS NULL", [$site_id,$nom_emuci]);
            db_query("UPDATE op_bobines JOIN import_optotrace ot ON ot.keyname=op_bobines.numero SET op_bobines.site_id=? WHERE ot.site_nom_emuci=? AND op_bobines.site_id IS NULL", [$site_id,$nom_emuci]);
            $nom = db_fetch_value("SELECT nom FROM sites WHERE id=?",[$site_id]);
            audit_log($user['id'],'UPDATE','sites',$site_id,"Mapping EMUCI '$nom_emuci' → '$nom'");
            json_response(true,"Site lié. Tous les imports mis à jour.");
        }
        if ($decision === 'creer') {
            $nom_nouveau  = trim($_POST['nom_nouveau']  ?? '') ?: $inconnu['nom_emuci'];
            $type_nouveau = trim($_POST['type_nouveau'] ?? 'pose');
            $nom_emuci    = $inconnu['nom_emuci'];
            $code_nouveau = strtoupper(substr(preg_replace('/[^A-Z0-9]/','',strtoupper($nom_emuci)),0,6));
            $base = $code_nouveau; $suffix = 1;
            while (db_fetch_value("SELECT COUNT(*) FROM sites WHERE code=?",[$code_nouveau]) > 0)
                $code_nouveau = $base . $suffix++;
            db_query("INSERT INTO sites (code,nom,type,nom_emuci,actif) VALUES (?,?,?,?,1)", [$code_nouveau,$nom_nouveau,$type_nouveau,$nom_emuci]);
            $new_id = (int)db_last_id();
            db_query("UPDATE emuci_sites_inconnus SET statut='cree',site_id_lie=?,traite_par=?,traite_at=NOW() WHERE nom_emuci=?", [$new_id,$user['id'],$nom_emuci]);
            db_query("UPDATE import_optoplate SET site_id=? WHERE site_nom_emuci=? AND site_id IS NULL", [$new_id,$nom_emuci]);
            db_query("UPDATE import_optotrace  SET site_id=? WHERE site_nom_emuci=? AND site_id IS NULL", [$new_id,$nom_emuci]);
            db_query("UPDATE op_bobines JOIN import_optotrace ot ON ot.keyname=op_bobines.numero SET op_bobines.site_id=? WHERE ot.site_nom_emuci=? AND op_bobines.site_id IS NULL", [$new_id,$nom_emuci]);
            audit_log($user['id'],'CREATE','sites',$new_id,"Création depuis EMUCI '$nom_emuci'");
            json_response(true,"Site '$nom_nouveau' créé.");
        }
        json_response(false,'Décision invalide.');
    }

    // ── GET ONE (pour modal détail / édition)
    if ($action === 'get') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = db_fetch_one(
            "SELECT s.*, CONCAT(u.prenom,' ',u.nom) AS responsable_nom
             FROM sites s LEFT JOIN users u ON u.id=s.responsable_id WHERE s.id=?", [$id]
        );
        if (!$row) json_response(false, 'Introuvable.');
        $row['equip_par_type'] = db_fetch_all(
            "SELECT n.code, COALESCE(n.libelle,'Sans type') AS libelle, COUNT(e.id) AS total, n.id AS nom_id
             FROM equipements e LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
             WHERE e.site_id=? AND e.actif=1 GROUP BY n.id ORDER BY libelle", [$id]
        );
        $row['utilisateurs'] = db_fetch_all(
            "SELECT u.id, CONCAT(u.prenom,' ',u.nom) AS nom, u.email, r.nom AS role
             FROM users u JOIN roles r ON r.id=u.role_id WHERE u.site_id=? AND u.actif=1", [$id]
        );
        json_response(true, '', $row);
    }

    // ── CALCUL CAPACITÉ
    if ($action === 'calc_capacite') {
        $type_site = trim($_POST['type_site'] ?? '');
        if (!$type_site) json_response(false, 'Type de site obligatoire.');
        $besoins = db_fetch_all(
            "SELECT cs.nomenclature_id, cs.quantite AS requis, cs.optionnel, n.code, n.libelle,
                    COUNT(e.id) AS stock_disponible
             FROM configurations_site cs JOIN nomenclatures n ON n.id=cs.nomenclature_id
             LEFT JOIN equipements e ON e.nomenclature_id=cs.nomenclature_id AND e.actif=1 AND e.site_id IS NULL AND e.etat IN ('neuf','bon')
             WHERE cs.type_site=? GROUP BY cs.nomenclature_id, cs.quantite, cs.optionnel, n.code, n.libelle", [$type_site]
        );
        if (empty($besoins)) json_response(false, "Aucune configuration définie pour «$type_site».");
        $nb_sites = PHP_INT_MAX;
        $details  = [];
        foreach ($besoins as $b) {
            $ratio = $b['requis'] > 0 ? floor($b['stock_disponible'] / $b['requis']) : PHP_INT_MAX;
            if (!$b['optionnel'] && $ratio < $nb_sites) $nb_sites = $ratio;
            $details[] = ['code'=>$b['code'],'libelle'=>$b['libelle'],'requis'=>(int)$b['requis'],'disponible'=>(int)$b['stock_disponible'],'couvre'=>$ratio===PHP_INT_MAX?'∞':$ratio,'optionnel'=>(bool)$b['optionnel'],'ok'=>$b['stock_disponible']>=$b['requis']];
        }
        if ($nb_sites === PHP_INT_MAX) $nb_sites = 0;
        json_response(true, '', ['nb_sites'=>$nb_sites,'details'=>$details,'type'=>$type_site]);
    }

    // ── SAUVEGARDER CONFIG SITE
    if ($action === 'save_config') {
        require_permission('sites', 'can_update');
        $type_site = trim($_POST['type_site'] ?? '');
        $items     = json_decode($_POST['items'] ?? '[]', true);
        if (!$type_site || !is_array($items)) json_response(false, 'Données invalides.');
        db_begin();
        try {
            db_query("DELETE FROM configurations_site WHERE type_site=?", [$type_site]);
            foreach ($items as $item) {
                $nom_id = (int)($item['nomenclature_id'] ?? 0);
                $qte    = max(1, (int)($item['quantite'] ?? 1));
                $opt    = (int)(!empty($item['optionnel']));
                if ($nom_id) db_query("INSERT INTO configurations_site (type_site,nomenclature_id,quantite,optionnel) VALUES (?,?,?,?)", [$type_site,$nom_id,$qte,$opt]);
            }
            audit_log($user['id'],'UPDATE','sites',null,"Config type site $type_site mise à jour");
            db_commit();
            json_response(true, 'Configuration enregistrée.');
        } catch (Exception $e) { db_rollback(); json_response(false,'Erreur : '.$e->getMessage()); }
    }

    json_response(false, 'Action inconnue.');
}

// ============================================================
//  DONNÉES & FILTRES
// ============================================================
$f_search = trim($_GET['q']     ?? '');
$f_type   = trim($_GET['type']  ?? '');
$f_ville  = trim($_GET['ville'] ?? '');
$f_actif  = $_GET['actif']      ?? '1';

$where  = ['1=1'];
$params = [];
if ($f_search) {
    $where[]  = '(s.nom LIKE ? OR s.code LIKE ? OR s.ville LIKE ?)';
    $params[] = "%$f_search%"; $params[] = "%$f_search%"; $params[] = "%$f_search%";
}
if ($f_type)  { $where[] = 's.type=?';  $params[] = $f_type; }
if ($f_ville) { $where[] = 's.ville=?'; $params[] = $f_ville; }
if ($f_actif !== 'tous') { $where[] = 's.actif=?'; $params[] = (int)$f_actif; }
$wsql = implode(' AND ', $where);

$sites = db_fetch_all(
    "SELECT s.*,
            CONCAT(u.prenom,' ',u.nom) AS responsable_nom,
            COUNT(DISTINCT e.id)  AS nb_equip,
            COUNT(DISTINCT us.id) AS nb_users
     FROM sites s
     LEFT JOIN users u  ON u.id=s.responsable_id
     LEFT JOIN equipements e  ON e.site_id=s.id AND e.actif=1
     LEFT JOIN users us ON us.site_id=s.id AND us.actif=1
     WHERE $wsql
     GROUP BY s.id, u.prenom, u.nom ORDER BY s.actif DESC, s.nom ASC",
    $params
);

// Stats globales (indépendantes des filtres)
$stats_type = db_fetch_all(
    "SELECT type, COUNT(*) AS total, SUM(actif) AS actifs
     FROM sites GROUP BY type ORDER BY FIELD(type, 'saisie','pose','mixte','entrepot','siege','magasin')"
);
$stats_ville = db_fetch_all(
    "SELECT ville, COUNT(*) AS n FROM sites WHERE actif=1 AND ville IS NOT NULL AND ville != ''
     GROUP BY ville ORDER BY n DESC LIMIT 8"
);
$total_actifs = (int)db_fetch_value("SELECT COUNT(*) FROM sites WHERE actif=1");
$total_all    = (int)db_fetch_value("SELECT COUNT(*) FROM sites");

$villes_list = db_fetch_all("SELECT DISTINCT ville FROM sites WHERE ville IS NOT NULL AND ville != '' ORDER BY ville");
$types_labels = ['saisie'=>'Saisie','pose'=>'Pose','mixte'=>'Mixte','entrepot'=>'Entrepôt','siege'=>'Siège','magasin'=>'Magasin'];

// Pour modals
$users_list    = db_fetch_all("SELECT id,prenom,nom FROM users WHERE actif=1 ORDER BY prenom");
$nomenclatures = db_fetch_all("SELECT id,code,libelle FROM nomenclatures ORDER BY libelle");
$all_sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
$stock_dispo = db_fetch_all(
    "SELECT n.id, n.code, n.libelle, COUNT(e.id) AS dispo
     FROM nomenclatures n
     LEFT JOIN equipements e ON e.nomenclature_id=n.id AND e.actif=1 AND e.site_id IS NULL AND e.etat IN ('neuf','bon')
     GROUP BY n.id ORDER BY n.libelle"
);
$sites_inconnus_emuci = db_fetch_all(
    "SELECT * FROM emuci_sites_inconnus WHERE statut='en_attente' ORDER BY nb_occurrences DESC, derniere_apparition DESC"
);
$nb_inconnus = count($sites_inconnus_emuci);

// ── EXPORTS
if (isset($_GET['export'])) {
    $export = $_GET['export'];

    if ($export === 'xlsx') {
        $spreadsheet = new Spreadsheet();
        $sh = $spreadsheet->getActiveSheet();
        $sh->setTitle('Sites');
        $headers = ['Code','Nom','Type','Ville','Équipements','Utilisateurs','Responsable','Statut'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sh->setCellValue("{$col}1", $h);
            $sh->getStyle("{$col}1")->applyFromArray([
                'font'      => ['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
                'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'06033A']],
                'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
            ]);
        }
        $row = 2;
        foreach ($sites as $s) {
            $sh->setCellValue("A$row", $s['code']);
            $sh->setCellValue("B$row", $s['nom']);
            $sh->setCellValue("C$row", $types_labels[$s['type']] ?? $s['type']);
            $sh->setCellValue("D$row", $s['ville'] ?? '');
            $sh->setCellValue("E$row", (int)$s['nb_equip']);
            $sh->setCellValue("F$row", (int)$s['nb_users']);
            $sh->setCellValue("G$row", $s['responsable_nom'] ?? '—');
            $sh->setCellValue("H$row", $s['actif'] ? 'Actif' : 'Inactif');
            $row++;
        }
        foreach (range('A','H') as $col) $sh->getColumnDimension($col)->setAutoSize(true);
        $filename = 'sites_' . date('Ymd') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = new XlsxWriter($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'sites_');
        $writer->save($tmp);
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    if ($export === 'pdf') {
        $rows_html = '';
        foreach ($sites as $s) {
            $sc = $s['actif'] ? '#065f46' : '#991b1b';
            $sb = $s['actif'] ? '#d1fae5' : '#fee2e2';
            $rows_html .= '<tr>
                <td style="font-weight:700">' . h($s['code']) . '</td>
                <td><strong>' . h($s['nom']) . '</strong></td>
                <td>' . h($types_labels[$s['type']] ?? $s['type']) . '</td>
                <td>' . h($s['ville'] ?? '—') . '</td>
                <td style="text-align:center">' . (int)$s['nb_equip'] . '</td>
                <td style="text-align:center">' . (int)$s['nb_users'] . '</td>
                <td>' . h($s['responsable_nom'] ?? '—') . '</td>
                <td style="text-align:center"><span style="background:' . $sb . ';color:' . $sc . ';padding:2px 7px;border-radius:8px;font-size:8px;font-weight:700">' . ($s['actif'] ? 'Actif' : 'Inactif') . '</span></td>
            </tr>';
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:Arial,sans-serif;font-size:10px;margin:20px}
        h1{font-size:14px;color:#06033A;margin:0 0 3px}
        .sub{font-size:9px;color:#64748b;margin-bottom:14px}
        table{width:100%;border-collapse:collapse}
        th{background:#06033A;color:#fff;padding:6px 8px;font-size:9px;text-align:left}
        td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:9px}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>
        <table width="100%" style="border-collapse:collapse;margin-bottom:10px"><tr>
          <td style="vertical-align:middle;width:85px;padding-right:10px">' . pdf_logo_img('38px') . '</td>
          <td style="vertical-align:middle;padding-left:12px;border-left:3px solid #06033A">
            <div style="font-size:14px;font-weight:bold;color:#06033A">Liste des Sites</div>
            <div style="font-size:9px;color:#64748b;margin-top:2px">Express Multiservices CI</div>
          </td>
        </tr></table>
        <div class="sub">Total : ' . count($sites) . ' site(s) — Généré le ' . date('d/m/Y H:i') . '</div>
        <table><thead><tr><th>Code</th><th>Nom</th><th>Type</th><th>Ville</th><th>Équip.</th><th>Util.</th><th>Responsable</th><th>Statut</th></tr></thead>
        <tbody>' . $rows_html . '</tbody></table></body></html>';
        $opts = new Options(); $opts->set('isRemoteEnabled', false);
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html); $pdf->setPaper('A4','landscape'); $pdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="sites_' . date('Ymd') . '.pdf"');
        echo $pdf->output(); exit;
    }
}

include __DIR__ . '/../../templates/header.php';

// Couleurs par type
$type_colors = [
    // #1B75BC ne donnait que 4.25:1 sur #e6f1fb (sous le seuil AA de 4.5) → assombri à 6.7:1
    'saisie'  => ['bg'=>'#e6f1fb','color'=>'#15568B','icon'=>'ph-desktop'],
    'pose'    => ['bg'=>'#e8f8ef','color'=>'#166534','icon'=>'ph-wrench'],
    'mixte'   => ['bg'=>'#fff3e8','color'=>'#9a3412','icon'=>'ph-arrows-left-right'],
    'entrepot'=> ['bg'=>'#e6f9f7','color'=>'#134e4a','icon'=>'ph-warehouse'],
    'siege'   => ['bg'=>'#fdeaea','color'=>'#991b1b','icon'=>'ph-buildings'],
    'magasin' => ['bg'=>'#f3e8ff','color'=>'#6b21a8','icon'=>'ph-package'],
];
?>
<style>
.filter-bar{background:white;border:1px solid var(--border);border-radius:12px;padding:12px 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:18px}
.filter-bar label{font-size:12px;font-weight:700;color:var(--navy);display:block;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px}
.filter-bar input,.filter-bar select{padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:white;outline:none;min-width:140px}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--blue)}
.stats-dash{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:18px}
.stat-tile{position:relative;overflow:hidden;background:white;border:1px solid var(--border);border-radius:12px;padding:11px 14px 11px 18px;cursor:default}
.stat-tile::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent,var(--muted))}
.stat-tile-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
.stat-tile-value{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:700;color:var(--navy)}
.stat-tile-empty{opacity:.5}
.stat-tile-total{background:var(--navy);border-color:var(--navy)}
.stat-tile-total::before{display:none}
.stat-tile-total .stat-tile-label{color:rgba(255,255,255,.65)}
.stat-tile-total .stat-tile-value{color:white}
/* !important nécessaire : header.php impose th{background:var(--tertiary)!important;color:var(--muted)!important} */
.sites-table th{font-size:11.5px;font-weight:700;color:#475569!important;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;border-bottom:2px solid var(--border)}
.sites-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
/* Scroll interne au-delà de 4 lignes : hauteur = header + 4 lignes (~62px/ligne) */
.sites-table-wrap{max-height:284px;overflow-y:auto}
.sites-table thead th{position:sticky;top:0;z-index:1}
.sites-table tr:hover td{background:#f8fafc}
.sites-table tr.inactive td{opacity:.55}
.type-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
/* Compteurs Équip./Util. : nombre nu, lisible d'un coup d'œil.
   Les pastilles bleu pâle précédentes plafonnaient à 4.25:1 (sous le seuil AA). */
.count-cell{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:var(--navy);padding:6px 8px;background:white;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;min-width:40px}
.count-cell.zero{color:var(--muted);font-weight:600}
.status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block}
.status-dot.on{background:#22c55e}
.status-dot.off{background:var(--muted)}
.resp-avatar{width:38px;height:38px;border-radius:50%;background:var(--navy);display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:700;flex-shrink:0;letter-spacing:.3px}
/* #15568B et non #1B75BC : sur le fond #EFF6FF ce dernier ne donnait que
   4,47:1, sous le seuil AA de 4,5. Le nouveau donne 7,05:1. */
.action-btn{width:32px;height:32px;border-radius:8px;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:15px;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;background:#EFF6FF;color:#15568B}
.action-btn:hover{background:#15568B;color:white;transform:scale(1.08)}
.action-btn.edit-btn{background:#F0FDF4;color:#166534}
.action-btn.edit-btn:hover{background:#166534;color:white}
.action-btn.warn{background:#FFF7ED;color:#C2410C}
.action-btn.warn:hover{background:#C2410C;color:white}
.action-btn.success{background:#F0FDF4;color:#15803D}
.action-btn.success:hover{background:#15803D;color:white}
/* #B91C1C : 5,91:1 contre 4,41:1 pour #DC2626 sur ce fond. */
.action-btn.danger{background:#FEF2F2;color:#B91C1C}
.action-btn.danger:hover{background:#B91C1C;color:white}
/* Modals */
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(13,31,53,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;max-width:95vw;max-height:92vh;overflow-y:auto;animation:mIn .25s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:700}
.mclose{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:none;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center}
.mbody{padding:22px}
.mfoot{padding:12px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:white}
/* Capacité */
.capa-result{text-align:center;padding:24px;background:linear-gradient(135deg,var(--navy),#1a3c5e);border-radius:12px;color:white;margin-bottom:18px}
.capa-number{font-family:'Plus Jakarta Sans',sans-serif;font-size:60px;font-weight:800;line-height:1}
.capa-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border)}
.capa-bar{flex:1;height:5px;background:var(--border);border-radius:3px;overflow:hidden}
.capa-fill{height:100%;border-radius:3px}
/* Config */
.tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:18px}
.tab-btn{padding:10px 18px;font-size:13px;font-weight:500;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;background:none;border-top:none;border-left:none;border-right:none;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;font-family:'Manrope',sans-serif}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue)}
.tab-pane{display:none}
.tab-pane.active{display:block}
.cfg-row{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--border)}
</style>

<!-- ── TOOLBAR ─────────────────────────────────── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">Gestion des Sites</h2>
    <p style="font-size:12px;color:var(--muted);margin-top:2px"><?= $total_actifs ?> actif(s) sur <?= $total_all ?> sites au total</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'xlsx'])) ?>"
       class="btn btn-secondary" style="font-size:13px;display:flex;align-items:center;gap:5px">
      <i class="ph-duotone ph-microsoft-excel-logo"></i> Excel
    </a>
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'pdf'])) ?>"
       class="btn btn-secondary" style="font-size:13px;display:flex;align-items:center;gap:5px">
      <i class="ph-duotone ph-file-pdf"></i> PDF
    </a>
    <button class="btn btn-secondary" style="font-size:13px" onclick="openCapa()">
      <i class="ph-duotone ph-calculator"></i> Capacité
    </button>
    <button class="btn btn-secondary" style="font-size:13px" onclick="openConfig()">
      <i class="ph-duotone ph-gear"></i> Config types
    </button>
    <?php if (can('sites','can_create')): ?>
    <button class="btn btn-primary" style="font-size:13px" onclick="openMS()">
      <i class="ph-duotone ph-plus"></i> Nouveau site
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- ── KPI PAR TYPE ─────────────────────────────── -->
<div class="stats-dash">
  <div class="stat-tile stat-tile-total">
    <div class="stat-tile-label">Total actifs</div>
    <div class="stat-tile-value"><?= $total_actifs ?></div>
  </div>
  <?php foreach ($stats_type as $st):
    $tc = $type_colors[$st['type']] ?? ['color'=>'#475569'];
    $empty = (int)$st['actifs'] === 0;
  ?>
  <div class="stat-tile<?= $empty ? ' stat-tile-empty' : '' ?>" style="--accent:<?= $tc['color'] ?>">
    <div class="stat-tile-label"><?= $types_labels[$st['type']] ?? $st['type'] ?></div>
    <div class="stat-tile-value"><?= (int)$st['actifs'] ?></div>
  </div>
  <?php endforeach; ?>
  <?php foreach ($stats_ville as $sv): ?>
  <div class="stat-tile" style="--accent:#64748b">
    <div class="stat-tile-label"><?= h($sv['ville']) ?></div>
    <div class="stat-tile-value"><?= (int)$sv['n'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── FILTER BAR ───────────────────────────────── -->
<div class="filter-bar">
  <div style="flex:1;min-width:200px">
    <label>Recherche</label>
    <input type="text" id="fSearch" value="<?= h($f_search) ?>" placeholder="Nom, code, ville…">
  </div>
  <div>
    <label>Type</label>
    <select id="fType">
      <option value="">Tous les types</option>
      <?php foreach ($types_labels as $k => $l): ?>
      <option value="<?= $k ?>" <?= $f_type === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Ville</label>
    <select id="fVille">
      <option value="">Toutes les villes</option>
      <?php foreach ($villes_list as $v): ?>
      <option value="<?= h($v['ville']) ?>" <?= $f_ville === $v['ville'] ? 'selected' : '' ?>><?= h($v['ville']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Statut</label>
    <select id="fActif">
      <option value="1"  <?= $f_actif==='1'   ?'selected':'' ?>>Actifs</option>
      <option value="0"  <?= $f_actif==='0'   ?'selected':'' ?>>Inactifs</option>
      <option value="tous" <?= $f_actif==='tous'?'selected':'' ?>>Tous</option>
    </select>
  </div>
  <div style="display:flex;gap:8px;align-items:flex-end">
    <button class="btn btn-primary" style="font-size:13px" onclick="applyFilters()">Appliquer</button>
    <button class="btn btn-secondary" style="font-size:13px" onclick="location.href='sites.php'">Réinitialiser</button>
  </div>
</div>

<!-- ── TABLEAU DES SITES ────────────────────────── -->
<div class="card">
  <div class="table-wrap sites-table-wrap">
    <table data-paginer="25" class="sites-table" style="width:100%">
      <thead>
        <tr>
          <th>Site</th>
          <th>Type</th>
          <th>Ville</th>
          <th style="text-align:center">Équip.</th>
          <th style="text-align:center">Util.</th>
          <th>Responsable</th>
          <th style="text-align:center">Statut</th>
          <th style="text-align:center">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($sites)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Aucun site trouvé.</td></tr>
      <?php else: foreach ($sites as $s):
        $tc = $type_colors[$s['type']] ?? ['bg'=>'#f0f4f8','color'=>'#475569'];
        $initials = strtoupper(substr($s['nom'], 0, 2));
      ?>
        <tr class="<?= $s['actif'] ? '' : 'inactive' ?>">
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="width:38px;height:38px;border-radius:10px;background:<?= $tc['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="ph-duotone <?= $type_colors[$s['type']]['icon'] ?? 'ph-buildings' ?>" style="font-size:18px;color:<?= $tc['color'] ?>"></i>
              </div>
              <div>
                <div style="font-weight:700;color:var(--navy);font-size:13.5px"><?= h($s['nom']) ?></div>
                <div style="font-size:12px;color:var(--muted);font-family:monospace"><?= h($s['code']) ?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="type-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>">
              <?= $types_labels[$s['type']] ?? $s['type'] ?>
              <?php if (!empty($s['option_caisse'])): ?>
              <span style="font-size:12px;background:#e8f5e9;color:#2e7d32;padding:1px 6px;border-radius:8px;margin-left:3px"><i class="ph ph-currency-circle-dollar" aria-hidden="true"></i></span>
              <?php endif; ?>
              <?php if (($s['operation'] ?? 'primo') === 'reimmat_post'): ?>
              <span style="font-size:11px;background:#ede9fe;color:#5b21b6;padding:1px 6px;border-radius:8px;margin-left:3px;font-weight:700">Re-Immatriculation</span>
              <?php endif; ?>
            </span>
          </td>
          <td style="font-size:13px;color:var(--muted)"><?= $s['ville'] ? h($s['ville']) : '—' ?></td>
          <td style="text-align:center">
            <span class="count-cell<?= (int)$s['nb_equip'] === 0 ? ' zero' : '' ?>"><?= (int)$s['nb_equip'] ?></span>
          </td>
          <td style="text-align:center">
            <span class="count-cell<?= (int)$s['nb_users'] === 0 ? ' zero' : '' ?>"><?= (int)$s['nb_users'] ?></span>
          </td>
          <td>
            <?php if ($s['responsable_nom']): ?>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="resp-avatar">
                <?= strtoupper(implode('',array_map(fn($w)=>$w[0],array_slice(array_filter(explode(' ',$s['responsable_nom'])),0,2)))) ?>
              </div>
              <span style="font-size:13px;font-weight:600;color:var(--text)"><?= h($s['responsable_nom']) ?></span>
            </div>
            <?php else: ?>
            <span style="font-size:12.5px;color:var(--muted)">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if ($s['actif']): ?>
            <div style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--navy)">
              <span class="status-dot on"></span>Actif
            </div>
            <?php else: ?>
            <div style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--muted)">
              <span class="status-dot off"></span>Inactif
            </div>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <div style="display:flex;gap:5px;justify-content:center">
              <button class="action-btn" onclick="viewS(<?= $s['id'] ?>)" title="Voir le détail">
                <i class="ph-duotone ph-eye"></i>
              </button>
              <?php if (can('sites','can_update')): ?>
              <button class="action-btn edit-btn" onclick="editS(<?= $s['id'] ?>)" title="Modifier">
                <i class="ph-duotone ph-pencil-simple"></i>
              </button>
              <?php if ($s['actif']): ?>
              <button class="action-btn warn" onclick="toggleActif(<?= $s['id'] ?>,'deactivate','<?= h($s['nom']) ?>')" title="Désactiver">
                <i class="ph-duotone ph-toggle-right"></i>
              </button>
              <?php else: ?>
              <button class="action-btn success" onclick="toggleActif(<?= $s['id'] ?>,'reactivate','<?= h($s['nom']) ?>')" title="Réactiver">
                <i class="ph-duotone ph-toggle-left"></i>
              </button>
              <?php endif; ?>
              <?php endif; ?>
              <?php if (can('sites','can_delete')): ?>
              <button class="action-btn danger" onclick="hardDelete(<?= $s['id'] ?>,'<?= h($s['nom']) ?>')" title="Supprimer définitivement">
                <i class="ph-duotone ph-trash"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL CRÉER / MODIFIER ===== -->
<div class="modal-overlay" id="mS">
  <div class="modal" style="width:560px">
    <div class="mhdr"><h3 id="mST">Nouveau site</h3><button class="mclose" onclick="closeMX('mS')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <div id="mSAlert"></div>
      <input type="hidden" id="sId">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
        <div class="form-group"><label>Code * <span style="font-size:12px;color:var(--muted)">(ex: ABJ-01)</span></label>
          <input type="text" class="form-control" id="sCode" placeholder="ABJ-01" oninput="this.value=this.value.toUpperCase()">
        </div>
        <div class="form-group"><label>Type *</label>
          <select class="form-control" id="sType" onchange="toggleCaisse(this.value);toggleOperationField(this.value)">
            <?php foreach ($types_labels as $k => $l): ?>
            <option value="<?= $k ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group" id="sOperationWrap" style="display:none;margin-bottom:14px"><label>Opération</label>
        <select class="form-control" id="sOperation">
          <option value="primo">Primo</option>
          <option value="reimmat_post">Re-Immatriculation</option>
        </select>
        <div style="font-size:12px;color:var(--muted);margin-top:4px">Re-Immatriculation ajoute les catégories Duplicatas et Post-Immat sur le point journalier et les tableaux de bord de ce site.</div>
      </div>
      <div class="form-group" id="sAutoWrap" style="display:none;margin-bottom:14px"><label>Point généré depuis OptoPlate, à partir du</label>
        <input type="date" class="form-control" id="sAuto">
        <div style="font-size:12px;color:var(--muted);margin-top:4px">Pour un site qui ne saisit pas son point : à chaque import OptoPlate, le point de chaque jour est créé depuis les plaques posées (engins et plaques seulement : ni heures, ni rivets, ni PMMA, aucun stock). Laisser vide pour désactiver. S'arrête tout seul dès que le site saisit un point.</div>
      </div>
      <div class="form-group" style="margin-bottom:14px"><label>Nom du site *</label>
        <input type="text" class="form-control" id="sNom" placeholder="Agence Cocody">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
        <div class="form-group"><label>Ville</label>
          <input type="text" class="form-control" id="sVille" placeholder="Abidjan">
        </div>
        <div class="form-group"><label>Responsable</label>
          <select class="form-control" id="sResp">
            <option value="">— Non assigné —</option>
            <?php foreach ($users_list as $u): ?>
            <option value="<?= $u['id'] ?>"><?= h($u['prenom'].' '.$u['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group" style="margin-bottom:14px"><label>Adresse</label>
        <textarea class="form-control" id="sAdresse" rows="2" placeholder="Adresse complète"></textarea>
      </div>
      <div id="sCaisseWrap" style="display:none;margin-bottom:14px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 14px;background:var(--lighter);border-radius:8px;border:1px solid var(--border)">
          <input type="checkbox" id="sCaisse">
          <div><div style="font-weight:600;font-size:13px"><i class="ph ph-currency-circle-dollar" aria-hidden="true"></i> Option Caisse</div>
          <div style="font-size:12px;color:var(--muted)">Ce site encaisse directement</div></div>
        </label>
      </div>
      <div id="sActifWrap" style="display:none">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="sActif" checked> Site actif
        </label>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="closeMX('mS')">Annuler</button>
      <button class="btn btn-primary" id="bSS" onclick="saveS()">Enregistrer</button>
    </div>
  </div>
</div>

<!-- ===== MODAL DÉTAIL ===== -->
<div class="modal-overlay" id="mSD">
  <div class="modal" style="width:660px">
    <div class="mhdr"><h3 id="sdT">Détail site</h3><button class="mclose" onclick="closeMX('mSD')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody" id="sdB"></div>
  </div>
</div>

<!-- ===== MODAL CALCULATEUR ===== -->
<div class="modal-overlay" id="mCapa">
  <div class="modal" style="width:620px">
    <div class="mhdr"><h3>Calculateur de capacité</h3><button class="mclose" onclick="closeMX('mCapa')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Combien de sites de ce type puis-je créer avec le stock disponible ?</p>
      <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
        <?php
        // Entrepôt, Siège et Magasin n'ont pas de kit d'équipements type à
        // respecter : le calculateur ne s'applique qu'aux types de site
        // opérationnels.
        $types_calculables = array_diff_key($types_labels, ['entrepot'=>1,'siege'=>1,'magasin'=>1]);
        foreach ($types_calculables as $k => $l): ?>
        <button class="btn btn-secondary" onclick="calcCapa('<?= $k ?>')" id="cb-<?= $k ?>"><?= $l ?></button>
        <?php endforeach; ?>
      </div>
      <div id="capaResult"></div>
    </div>
  </div>
</div>

<!-- ===== MODAL CONFIG TYPES ===== -->
<div class="modal-overlay" id="mCfg">
  <div class="modal" style="width:680px">
    <div class="mhdr"><h3>Configuration des besoins par type</h3><button class="mclose" onclick="closeMX('mCfg')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <div class="tabs" id="cfgTabs">
        <?php foreach ($types_calculables as $k => $l): ?>
        <button class="tab-btn <?= $k==='saisie'?'active':'' ?>" onclick="showCfgTab('<?= $k ?>',this)"><?= $l ?></button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($types_calculables as $k => $l): ?>
      <div class="tab-pane <?= $k==='saisie'?'active':'' ?>" id="cfg-<?= $k ?>">
        <div id="cfg-rows-<?= $k ?>"></div>
        <div style="display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
          <select id="cfg-add-nom-<?= $k ?>" class="form-control" style="flex:2">
            <option value="">Ajouter un équipement…</option>
            <?php foreach ($nomenclatures as $n): ?>
            <option value="<?= $n['id'] ?>"><?= h($n['code']) ?> — <?= h($n['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" class="form-control" id="cfg-add-qte-<?= $k ?>" value="1" min="1" style="width:70px">
          <label style="display:flex;align-items:center;gap:4px;font-size:12px;white-space:nowrap">
            <input type="checkbox" id="cfg-add-opt-<?= $k ?>"> Optionnel
          </label>
          <button class="btn btn-primary btn-sm" onclick="addCfgRow('<?= $k ?>')">+ Ajouter</button>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:flex-end">
          <button class="btn btn-primary" onclick="saveCfg('<?= $k ?>')">Enregistrer config <?= $l ?></button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
const NOMS = <?= json_encode(array_map(fn($n)=>['id'=>$n['id'],'code'=>$n['code'],'libelle'=>$n['libelle']],$nomenclatures)) ?>;
const STOCK_DISPO = <?= json_encode(array_column($stock_dispo,'dispo','id')) ?>;
const cfgData = {};
<?php foreach ($types_calculables as $k => $l):
  $cfg = db_fetch_all("SELECT cs.*,n.code,n.libelle FROM configurations_site cs JOIN nomenclatures n ON n.id=cs.nomenclature_id WHERE cs.type_site=?",[$k]);
?>
cfgData['<?= $k ?>'] = <?= json_encode($cfg) ?>;
<?php endforeach; ?>

function ap(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))if(v!==undefined)fd.append(k,v);
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json())
    .catch(err=>({success:false,message:'Erreur réseau : '+err.message}));
}
function closeMX(id){document.getElementById(id).classList.remove('open');}
function applyFilters(){
  const p=new URLSearchParams();
  const q=document.getElementById('fSearch').value.trim(); if(q) p.set('q',q);
  const t=document.getElementById('fType').value; if(t) p.set('type',t);
  const v=document.getElementById('fVille').value; if(v) p.set('ville',v);
  p.set('actif',document.getElementById('fActif').value);
  location.href='sites.php'+(p.toString()?'?'+p.toString():'');
}

// ── SITE FORM
function toggleCaisse(type){
  const nonC=['entrepot','siege','magasin'];
  const w=document.getElementById('sCaisseWrap');
  w.style.display=nonC.includes(type)?'none':'block';
  if(nonC.includes(type))document.getElementById('sCaisse').checked=false;
}
function toggleOperationField(type){
  const calculables=['saisie','pose','mixte'];
  const w=document.getElementById('sOperationWrap');
  w.style.display=calculables.includes(type)?'block':'none';
  if(!calculables.includes(type))document.getElementById('sOperation').value='primo';
}
function openMS(){
  ['sId','sCode','sNom','sVille','sAdresse'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('sType').value='saisie';
  document.getElementById('sResp').value='';
  document.getElementById('sCaisse').checked=false;
  document.getElementById('sOperation').value='primo';
  document.getElementById('sAuto').value='';
  document.getElementById('sAutoWrap').style.display='none';
  document.getElementById('sActifWrap').style.display='none';
  document.getElementById('sCode').disabled=false;
  document.getElementById('mSAlert').innerHTML='';
  document.getElementById('mST').textContent='Nouveau site';
  toggleCaisse('saisie');
  toggleOperationField('saisie');
  document.getElementById('mS').classList.add('open');
}
function editS(id){
  ap({action:'get',id}).then(d=>{
    if(!d.success){toast(d.message,'danger');return;}
    const r=d.data;
    document.getElementById('mST').textContent='Modifier : '+r.nom;
    document.getElementById('sId').value=r.id;
    document.getElementById('sCode').value=r.code;
    document.getElementById('sCode').disabled=true;
    document.getElementById('sNom').value=r.nom;
    document.getElementById('sType').value=r.type;
    document.getElementById('sVille').value=r.ville||'';
    document.getElementById('sResp').value=r.responsable_id||'';
    document.getElementById('sAdresse').value=r.adresse||'';
    document.getElementById('sActifWrap').style.display='block';
    document.getElementById('sActif').checked=r.actif==1;
    document.getElementById('sCaisse').checked=r.option_caisse==1;
    document.getElementById('sOperation').value=r.operation||'primo';
    document.getElementById('sAuto').value=r.point_auto_depuis||'';
    document.getElementById('sAutoWrap').style.display=('point_auto_depuis' in r)?'block':'none';
    toggleCaisse(r.type);
    toggleOperationField(r.type);
    document.getElementById('mS').classList.add('open');
  });
}
function saveS(){
  const id=document.getElementById('sId').value, btn=document.getElementById('bSS');
  btn.disabled=true; btn.textContent='…';
  ap({action:id?'update':'create',id,
    code:document.getElementById('sCode').value,
    nom:document.getElementById('sNom').value,
    type:document.getElementById('sType').value,
    ville:document.getElementById('sVille').value,
    adresse:document.getElementById('sAdresse').value,
    responsable_id:document.getElementById('sResp').value,
    option_caisse:document.getElementById('sCaisse').checked?1:0,
    operation:document.getElementById('sOperation').value,
    point_auto_depuis:document.getElementById('sAuto').value,
    actif:document.getElementById('sActif').checked?1:0,
  }).then(d=>{
    btn.disabled=false;btn.textContent='Enregistrer';
    if(d.success){toast(d.message,'success');closeMX('mS');setTimeout(()=>location.reload(),700);}
    else document.getElementById('mSAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
  });
}

// ── DÉSACTIVER / RÉACTIVER / SUPPRIMER
function toggleActif(id, action, nom){
  const msg=action==='deactivate'?`Désactiver le site "${nom}" ?`:`Réactiver le site "${nom}" ?`;
  if(!confirm(msg))return;
  ap({action,id}).then(d=>{
    toast(d.message,d.success?'success':'danger');
    if(d.success)setTimeout(()=>location.reload(),700);
  });
}
function hardDelete(id, nom){
  if(!confirm(`⚠️ Supprimer définitivement le site "${nom}" ?\n\nCette action est irréversible. Toutes les données du site doivent être nulles.`))return;
  ap({action:'hard_delete',id}).then(d=>{
    toast(d.message,d.success?'success':'danger');
    if(d.success)setTimeout(()=>location.reload(),700);
  });
}

// ── DÉTAIL
function viewS(id){
  document.getElementById('mSD').classList.add('open');
  document.getElementById('sdB').innerHTML='<div style="text-align:center;padding:40px;color:var(--muted)">Chargement…</div>';
  ap({action:'get',id}).then(d=>{
    if(!d.success)return;
    const r=d.data;
    document.getElementById('sdT').textContent=r.code+' — '+r.nom;
    const equips=r.equip_par_type.map(e=>`
      <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border)">
        <span style="font-size:12px;font-weight:700;background:var(--lighter);border:1px solid var(--border);border-radius:5px;padding:2px 6px">${e.code}</span>
        <span style="flex:1;font-size:13px">${e.libelle}</span>
        <span style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">${e.total}</span>
      </div>`).join('')||'<p style="color:var(--muted);font-size:13px">Aucun équipement.</p>';
    const users=r.utilisateurs.map(u=>`
      <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border)">
        <div class="resp-avatar">${u.nom.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase()}</div>
        <div><div style="font-size:13px;font-weight:500">${u.nom}</div><div style="font-size:12px;color:var(--muted)">${u.email} · ${u.role}</div></div>
      </div>`).join('')||'<p style="color:var(--muted);font-size:13px">Aucun utilisateur.</p>';
    document.getElementById('sdB').innerHTML=`
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 18px;margin-bottom:18px">
        ${[['Code',r.code],['Type',r.type],['Opération',r.operation==='reimmat_post'?'Re-Immatriculation':'Primo'],['Ville',r.ville||'—'],['Responsable',r.responsable_nom||'—'],['Adresse',r.adresse||'—'],['Statut',r.actif==1?'<i class="ph ph-check-circle" aria-hidden="true"></i> Actif':'<i class="ph ph-x-circle" aria-hidden="true"></i> Inactif']].map(([l,v])=>`<div style="padding:6px 0;border-bottom:1px solid var(--border)"><div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">${l}</div><div style="font-size:13px">${v}</div></div>`).join('')}
      </div>
      <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;margin-bottom:8px">Équipements (${r.equip_par_type.reduce((s,e)=>s+parseInt(e.total),0)})</h4>
      ${equips}
      <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;margin:14px 0 8px">Utilisateurs (${r.utilisateurs.length})</h4>
      ${users}`;
  });
}

// ── CALCULATEUR
function openCapa(){document.getElementById('mCapa').classList.add('open');}
function calcCapa(type){
  document.querySelectorAll('[id^="cb-"]').forEach(b=>b.classList.remove('btn-primary'));
  document.querySelector(`#cb-${type}`).classList.add('btn-primary');
  document.getElementById('capaResult').innerHTML='<div style="text-align:center;padding:20px;color:var(--muted)">Calcul…</div>';
  ap({action:'calc_capacite',type_site:type}).then(d=>{
    if(!d.success){document.getElementById('capaResult').innerHTML=`<div class="alert alert-warning">${d.message}</div>`;return;}
    const{nb_sites,details}=d.data;
    const col=nb_sites===0?'#e74c3c':nb_sites<=2?'#f39c12':'#27ae60';
    const rows=details.map(r=>{
      const pct=r.requis>0?Math.min(100,r.disponible/r.requis*100):100;
      return `<div class="capa-row">
        <span style="width:55px;font-size:12px;font-weight:700;color:var(--muted)">${r.code}</span>
        <span style="flex:1;font-size:12px">${r.libelle}${r.optionnel?' <em style="font-size:12px;color:var(--muted)">(opt.)</em>':''}</span>
        <span style="font-size:12px;color:var(--muted);margin:0 8px">${r.disponible}/${r.requis}</span>
        <div class="capa-bar"><div class="capa-fill" style="width:${Math.min(pct,100)}%;background:${r.ok?'var(--success)':'var(--danger)'}"></div></div>
        <span style="margin-left:8px;font-size:16px;width:18px">${r.ok?'✓':'✗'}</span>
        <span style="font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:800;width:30px;text-align:right;color:var(--navy)">${r.couvre}</span>
      </div>`;}).join('');
    document.getElementById('capaResult').innerHTML=`
      <div class="capa-result" style="border-top:4px solid ${col}">
        <div class="capa-number" style="color:${col}">${nb_sites}</div>
        <div style="font-size:13px;opacity:.75">site(s) «${type}» réalisable(s)</div>
      </div>
      <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;margin-bottom:8px">Détail par équipement</h4>${rows}`;
  });
}

// ── CONFIG
function openConfig(){document.getElementById('mCfg').classList.add('open');Object.keys(cfgData).forEach(k=>renderCfg(k));}
function showCfgTab(k,btn){
  document.querySelectorAll('#cfgTabs .tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('cfg-'+k).classList.add('active');
}
function renderCfg(type){
  const c=document.getElementById('cfg-rows-'+type);
  const rows=cfgData[type]||[];
  if(!rows.length){c.innerHTML='<div style="color:var(--muted);font-size:13px;padding:10px 0">Aucun équipement configuré.</div>';return;}
  c.innerHTML=rows.map((r,i)=>{
    const d=STOCK_DISPO[r.nomenclature_id]||0;
    return `<div class="cfg-row">
      <span style="width:55px;font-size:12px;font-weight:700;color:var(--muted)">${r.code}</span>
      <span style="flex:1;font-size:12.5px">${r.libelle}</span>
      <input type="number" class="form-control" style="width:68px" value="${r.quantite}" min="1" onchange="cfgData['${type}'][${i}].quantite=this.value">
      <label style="font-size:12px;display:flex;align-items:center;gap:4px;white-space:nowrap">
        <input type="checkbox" ${r.optionnel?'checked':''} onchange="cfgData['${type}'][${i}].optionnel=this.checked"> Opt.
      </label>
      <span style="font-size:12px;font-weight:700;padding:2px 8px;border-radius:10px;background:${d>=r.quantite?'#d5f5e3':'#fdf0ef'};color:${d>=r.quantite?'#1e8449':'#922b21'}">${d} dispo</span>
      <button class="btn btn-danger btn-sm" onclick="rmCfgRow('${type}',${i})">✕</button>
    </div>`;}).join('');
}
function addCfgRow(type){
  const sel=document.getElementById('cfg-add-nom-'+type);
  const id=parseInt(sel.value); if(!id){toast('Sélectionnez un équipement.','danger');return;}
  const nom=NOMS.find(n=>n.id===id);
  const qte=parseInt(document.getElementById('cfg-add-qte-'+type).value)||1;
  const opt=document.getElementById('cfg-add-opt-'+type).checked;
  if(cfgData[type].some(r=>r.nomenclature_id==id)){toast('Déjà dans la configuration.','danger');return;}
  cfgData[type].push({nomenclature_id:id,code:nom.code,libelle:nom.libelle,quantite:qte,optionnel:opt?1:0});
  renderCfg(type); sel.value='';
}
function rmCfgRow(type,i){cfgData[type].splice(i,1);renderCfg(type);}
function saveCfg(type){
  ap({action:'save_config',type_site:type,items:JSON.stringify(cfgData[type])}).then(d=>toast(d.message,d.success?'success':'danger'));
}

['mS','mSD','mCapa','mCfg'].forEach(id=>{
  document.getElementById(id).addEventListener('click',e=>{if(e.target===e.currentTarget)closeMX(id);});
});
</script>

<?php if ($nb_inconnus > 0): ?>
<!-- ══ SITES EMUCI INCONNUS ══ -->
<div class="card" style="margin-top:28px;border-top:3px solid #F59E0B">
  <div class="card-header" style="background:#FFFBEB">
    <h3 style="color:#92400E">
      <i class="ph-duotone ph-warning" style="color:#F59E0B"></i>
      Sites EMUCI non reconnus
      <span style="background:#F59E0B;color:white;padding:2px 10px;border-radius:20px;font-size:12px;margin-left:8px"><?= $nb_inconnus ?></span>
    </h3>
    <span style="font-size:12px;color:#92400E">Ces sites apparaissent dans vos imports mais ne sont pas encore dans ERP EMUCI.</span>
  </div>
  <div class="table-wrap sites-table-wrap">
    <table>
      <thead><tr><th style="position:sticky;top:0;z-index:1">Nom EMUCI</th><th style="position:sticky;top:0;z-index:1">Source</th><th style="text-align:center;position:sticky;top:0;z-index:1">Occ.</th><th style="position:sticky;top:0;z-index:1">Dernière apparition</th><th style="text-align:center;position:sticky;top:0;z-index:1">Action</th></tr></thead>
      <tbody>
      <?php foreach ($sites_inconnus_emuci as $si): ?>
      <tr>
        <td style="font-weight:700;color:var(--navy)"><?= h($si['nom_emuci']) ?></td>
        <td><span style="background:<?= $si['type_import']==='optoplate'?'#DBEAFE':'#D1FAE5' ?>;color:<?= $si['type_import']==='optoplate'?'#1D4ED8':'#065F46' ?>;padding:2px 9px;border-radius:12px;font-size:12px;font-weight:700"><?= strtoupper($si['type_import']) ?></span></td>
        <td style="text-align:center;font-weight:700"><?= $si['nb_occurrences'] ?>×</td>
        <td style="font-size:12px;color:var(--muted)"><?= fmt_datetime($si['derniere_apparition']) ?></td>
        <td style="text-align:center">
          <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
            <button class="btn btn-primary btn-sm" onclick="ouvrirLierSite(<?= $si['id'] ?>,'<?= h($si['nom_emuci']) ?>')"><i class="ph ph-link" aria-hidden="true"></i> Lier</button>
            <button class="btn btn-secondary btn-sm" onclick="ouvrirCreerDepuisEmuci(<?= $si['id'] ?>,'<?= h($si['nom_emuci']) ?>')"><i class="ph ph-plus" aria-hidden="true"></i> Créer</button>
            <button class="btn btn-sm" style="background:#F1F5F9;color:#64748B;border:1.5px solid #E2E8F0" onclick="ignorerSiteEmuci(<?= $si['id'] ?>,'<?= h($si['nom_emuci']) ?>')">Ignorer</button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Lier EMUCI -->
<div id="modalLierEmuci" style="display:none;position:fixed;inset:0;background:rgba(6,3,58,.55);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:26px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:700;color:var(--navy);margin-bottom:14px"><i class="ph ph-link" aria-hidden="true"></i> Lier site EMUCI</h3>
    <div id="alertLierEmuci"></div>
    <input type="hidden" id="lierId">
    <div style="background:#EFF6FF;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#1D4ED8">Nom EMUCI : <strong id="lierNomEmuci"></strong></div>
    <div class="form-group" style="margin-bottom:18px"><label>Site ERP EMUCI *</label>
      <select class="form-control" id="lierSiteId">
        <option value="">— Sélectionner —</option>
        <?php foreach ($all_sites_list as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="document.getElementById('modalLierEmuci').style.display='none'">Annuler</button>
      <button class="btn btn-primary" onclick="confirmerLier()"><i class="ph ph-link" aria-hidden="true"></i> Lier</button>
    </div>
  </div>
</div>

<!-- Modal Créer depuis EMUCI -->
<div id="modalCreerEmuci" style="display:none;position:fixed;inset:0;background:rgba(6,3,58,.55);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:26px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:700;color:var(--navy);margin-bottom:14px"><i class="ph ph-plus" aria-hidden="true"></i> Créer site depuis EMUCI</h3>
    <div id="alertCreerEmuci"></div>
    <input type="hidden" id="creerId">
    <div style="background:#EFF6FF;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#1D4ED8">Nom EMUCI : <strong id="creerNomEmuci"></strong></div>
    <div class="form-group" style="margin-bottom:12px"><label>Nom ERP EMUCI *</label><input type="text" class="form-control" id="creerNom"></div>
    <div class="form-group" style="margin-bottom:18px"><label>Type *</label>
      <select class="form-control" id="creerType">
        <option value="pose">Pose</option><option value="saisie">Saisie</option><option value="entrepot">Entrepôt</option><option value="siege">Siège</option><option value="mixte">Mixte</option>
      </select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="document.getElementById('modalCreerEmuci').style.display='none'">Annuler</button>
      <button class="btn btn-primary" onclick="confirmerCreerEmuci()"><i class="ph ph-plus" aria-hidden="true"></i> Créer</button>
    </div>
  </div>
</div>

<script>
function apSite(d){return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)})
  .then(r=>r.json())
  .catch(err=>({success:false,message:'Erreur réseau : '+err.message}));}
function ouvrirLierSite(id,nom){document.getElementById('lierId').value=id;document.getElementById('lierNomEmuci').textContent=nom;document.getElementById('lierSiteId').value='';document.getElementById('alertLierEmuci').innerHTML='';document.getElementById('modalLierEmuci').style.display='flex';}
async function confirmerLier(){
  const sid=document.getElementById('lierSiteId').value;
  if(!sid){document.getElementById('alertLierEmuci').innerHTML='<div class="alert alert-danger">Sélectionnez un site.</div>';return;}
  const d=await apSite({action:'lier_site_emuci',inconnu_id:document.getElementById('lierId').value,decision:'lier',site_id:sid});
  if(d.success){toast(d.message,'success');document.getElementById('modalLierEmuci').style.display='none';setTimeout(()=>location.reload(),700);}
  else document.getElementById('alertLierEmuci').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}
function ouvrirCreerDepuisEmuci(id,nom){document.getElementById('creerId').value=id;document.getElementById('creerNomEmuci').textContent=nom;document.getElementById('creerNom').value=nom;document.getElementById('alertCreerEmuci').innerHTML='';document.getElementById('modalCreerEmuci').style.display='flex';}
async function confirmerCreerEmuci(){
  const nom=document.getElementById('creerNom').value.trim();
  if(!nom){document.getElementById('alertCreerEmuci').innerHTML='<div class="alert alert-danger">Nom obligatoire.</div>';return;}
  const d=await apSite({action:'lier_site_emuci',inconnu_id:document.getElementById('creerId').value,decision:'creer',nom_nouveau:nom,type_nouveau:document.getElementById('creerType').value});
  if(d.success){toast(d.message,'success');document.getElementById('modalCreerEmuci').style.display='none';setTimeout(()=>location.reload(),700);}
  else document.getElementById('alertCreerEmuci').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}
async function ignorerSiteEmuci(id,nom){
  if(!confirm(`Ignorer le site EMUCI "${nom}" ?`))return;
  const d=await apSite({action:'lier_site_emuci',inconnu_id:id,decision:'ignorer'});
  if(d.success){toast(d.message,'success');setTimeout(()=>location.reload(),700);}
  else toast(d.message,'danger');
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
