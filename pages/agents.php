<?php
// ============================================================
//  pages/agents.php — Annuaire des agents NSIIV
//  Liste, filtres, et import Excel (PhpSpreadsheet).
//  Alimenté par import ; utilisé en autofill dans les demandes.
// ============================================================
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();
require_permission('agents', 'can_read');

$user        = current_user();
$can_import  = can('agents', 'can_create');
$can_update  = can('agents', 'can_update');
$can_delete  = can('agents', 'can_delete');
$page_title  = 'Annuaire des agents';
$active_page = 'agents';

// ── MODÈLE EXCEL (téléchargement du gabarit vide)
if (isset($_GET['modele'])) {
    $sp = new Spreadsheet();
    $ws = $sp->getActiveSheet();
    $ws->setTitle('Agents');
    $headers = ['Matricule','Nom','Prénom','Email','Téléphone','Fonction','Département','Direction','Site','Grade','Statut'];
    // PhpSpreadsheet 5.x (composer.json : ^5.9) a retiré les méthodes
    // ...ByColumnAndRow(), remplacées par les coordonnées lettre+ligne.
    foreach ($headers as $i => $h) {
        $col = Coordinate::stringFromColumnIndex($i + 1);
        $ws->setCellValue("{$col}1", $h);
        $ws->getColumnDimension($col)->setAutoSize(true);
        $ws->getStyle("{$col}1")->getFont()->setBold(true);
    }
    $ws->setCellValue('K2', 'actif');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="modele_agents.xlsx"');
    header('Cache-Control: max-age=0');
    (new XlsxWriter($sp))->save('php://output');
    exit;
}

// ── MODIFICATION (POST action=update)
$update_error = null;
if ($can_update && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $aid    = (int)($_POST['agent_id'] ?? 0);
    $nom    = mb_strtoupper(trim($_POST['nom']    ?? ''), 'UTF-8');
    $prenom = mb_strtoupper(trim($_POST['prenom'] ?? ''), 'UTF-8');
    $email  = trim($_POST['email'] ?? '');
    $tel    = preg_replace('/\D/', '', (string)($_POST['telephone'] ?? ''));
    $mat    = trim($_POST['matricule'] ?? '') ?: null;
    $statut = ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif';

    if ($aid <= 0 || $nom === '' || $prenom === '') {
        $update_error = 'Le nom et le prénom sont obligatoires.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $update_error = "L'email est obligatoire et doit être valide.";
    } elseif ($tel !== '' && strlen($tel) !== 10) {
        $update_error = 'Le téléphone doit contenir exactement 10 chiffres.';
    } elseif ($mat !== null && db_fetch_value("SELECT id FROM agents WHERE matricule = ? AND id <> ?", [$mat, $aid])) {
        $update_error = "Le matricule « $mat » est déjà utilisé par un autre agent.";
    } else {
        db_query(
            "UPDATE agents SET matricule=?,nom=?,prenom=?,email=?,telephone=?,fonction=?,departement=?,direction=?,site=?,grade=?,statut=?,updated_at=CURRENT_TIMESTAMP WHERE id=?",
            [
                $mat, $nom, $prenom, $email, $tel ?: null,
                trim($_POST['fonction'] ?? '') ?: null, trim($_POST['departement'] ?? '') ?: null,
                trim($_POST['direction'] ?? '') ?: null, trim($_POST['site'] ?? '') ?: null,
                trim($_POST['grade'] ?? '') ?: null, $statut, $aid,
            ]
        );
        header('Location: ' . APP_URL . '/pages/agents.php?updated=1');
        exit;
    }
}

// ── CRÉATION MANUELLE (POST action=create)
$create_error = null;
if ($can_import && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $nom    = mb_strtoupper(trim($_POST['nom']    ?? ''), 'UTF-8');
    $prenom = mb_strtoupper(trim($_POST['prenom'] ?? ''), 'UTF-8');
    $email  = trim($_POST['email'] ?? '');
    $tel    = preg_replace('/\D/', '', (string)($_POST['telephone'] ?? ''));
    $mat    = trim($_POST['matricule'] ?? '') ?: null;
    $statut = ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif';

    if ($nom === '' || $prenom === '') {
        $create_error = 'Le nom et le prénom sont obligatoires.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $create_error = "L'email est obligatoire et doit être valide.";
    } elseif ($tel !== '' && strlen($tel) !== 10) {
        $create_error = 'Le téléphone doit contenir exactement 10 chiffres.';
    } elseif ($mat !== null && db_fetch_value("SELECT id FROM agents WHERE matricule = ?", [$mat])) {
        $create_error = "Le matricule « $mat » est déjà utilisé par un autre agent.";
    } else {
        db_query(
            "INSERT INTO agents (matricule,nom,prenom,email,telephone,fonction,departement,direction,site,grade,statut) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [
                $mat, $nom, $prenom, $email, $tel ?: null,
                trim($_POST['fonction'] ?? '') ?: null, trim($_POST['departement'] ?? '') ?: null,
                trim($_POST['direction'] ?? '') ?: null, trim($_POST['site'] ?? '') ?: null,
                trim($_POST['grade'] ?? '') ?: null, $statut,
            ]
        );
        header('Location: ' . APP_URL . '/pages/agents.php?created=1');
        exit;
    }
}

// ── SUPPRESSION (POST action=delete)
if ($can_delete && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $aid = (int)($_POST['agent_id'] ?? 0);
    if ($aid > 0) {
        db_query("DELETE FROM agents WHERE id = ?", [$aid]);
        header('Location: ' . APP_URL . '/pages/agents.php?deleted=1');
        exit;
    }
}

// ── IMPORT EXCEL
$import_result = null;
if ($can_import && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    if (!empty($_FILES['fichier_excel']['tmp_name'])) {
        $import_result = agents_import_excel($_FILES['fichier_excel']['tmp_name']);
    } else {
        $import_result = ['ok' => false, 'msg' => 'Aucun fichier reçu.'];
    }
}

function agents_import_excel(string $tmpPath): array
{
    // Correspondance nom de colonne Excel → champ DB
    $col_map = [
        'matricule'   => ['matricule', 'mat', 'immatriculation'],
        'nom'         => ['nom'],
        'prenom'      => ['prénom', 'prenom', 'prénoms', 'prenoms'],
        'email'       => ['email', 'e-mail', 'mail', 'courriel'],
        'telephone'   => ['téléphone', 'telephone', 'tel', 'tél', 'phone'],
        'fonction'    => ['fonction', 'poste', 'emploi'],
        'departement' => ['département', 'departement', 'dept', 'dpt'],
        'direction'   => ['direction', 'dir'],
        'site'        => ['site'],
        'grade'       => ['grade', 'catégorie', 'categorie'],
        'statut'      => ['statut', 'status', 'état', 'etat'],
    ];

    try {
        $sp    = IOFactory::load($tmpPath);
        $sheet = $sp->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, true);
    } catch (\Exception $e) {
        return ['ok' => false, 'msg' => 'Fichier invalide : ' . $e->getMessage()];
    }

    if (empty($rows)) {
        return ['ok' => false, 'msg' => 'Fichier vide.'];
    }

    // En-têtes sur la première ligne
    $header_row = array_shift($rows);
    $col_idx    = [];   // field → clé colonne Excel (A, B, ...)
    foreach ($header_row as $col => $header) {
        $norm = mb_strtolower(trim((string)$header), 'UTF-8');
        foreach ($col_map as $field => $aliases) {
            if (in_array($norm, $aliases, true)) {
                $col_idx[$field] = $col;
                break;
            }
        }
    }

    if (!isset($col_idx['nom'])) {
        return ['ok' => false, 'msg' => "Colonne « Nom » introuvable. Vérifiez les en-têtes (ligne 1) du fichier Excel."];
    }

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $errors   = [];

    foreach ($rows as $row_num => $row) {
        $get = fn($field) => isset($col_idx[$field]) ? trim((string)($row[$col_idx[$field]] ?? '')) : '';

        $nom = $get('nom');
        if ($nom === '') {
            $skipped++;
            continue;
        }

        $mat    = $get('matricule') ?: null;
        $statut = $get('statut') ?: 'actif';
        if (!in_array($statut, ['actif', 'inactif'], true)) $statut = 'actif';

        $vals = [
            'nom'         => $nom,
            'prenom'      => $get('prenom'),
            'email'       => $get('email') ?: null,
            'telephone'   => $get('telephone') ?: null,
            'fonction'    => $get('fonction') ?: null,
            'departement' => $get('departement') ?: null,
            'direction'   => $get('direction') ?: null,
            'site'        => $get('site') ?: null,
            'grade'       => $get('grade') ?: null,
            'statut'      => $statut,
        ];

        try {
            if ($mat !== null && $mat !== '') {
                $existing = db_fetch_value("SELECT id FROM agents WHERE matricule = ?", [$mat]);
                if ($existing) {
                    db_query(
                        "UPDATE agents SET nom=?,prenom=?,email=?,telephone=?,fonction=?,departement=?,direction=?,site=?,grade=?,statut=?,updated_at=CURRENT_TIMESTAMP WHERE id=?",
                        [...array_values($vals), $existing]
                    );
                    $updated++;
                } else {
                    db_query(
                        "INSERT INTO agents (matricule,nom,prenom,email,telephone,fonction,departement,direction,site,grade,statut) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                        [$mat, ...array_values($vals)]
                    );
                    $inserted++;
                }
            } else {
                db_query(
                    "INSERT INTO agents (nom,prenom,email,telephone,fonction,departement,direction,site,grade,statut) VALUES (?,?,?,?,?,?,?,?,?,?)",
                    array_values($vals)
                );
                $inserted++;
            }
        } catch (\Exception $e) {
            $errors[] = "Ligne " . ($row_num + 2) . " : " . $e->getMessage();
        }
    }

    return [
        'ok'       => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ];
}

// ── Filtres + liste
$f_q      = trim($_GET['q']      ?? '');
$f_dept   = trim($_GET['dept']   ?? '');
$f_site   = trim($_GET['site']   ?? '');
$f_statut = trim($_GET['statut'] ?? '');

$where  = [];
$params = [];
if ($f_q !== '') {
    $where[]  = "(nom LIKE ? OR prenom LIKE ? OR COALESCE(matricule,'') LIKE ? OR COALESCE(email,'') LIKE ?)";
    $like     = "%$f_q%";
    array_push($params, $like, $like, $like, $like);
}
if ($f_dept !== '')   { $where[] = 'departement = ?'; $params[] = $f_dept; }
if ($f_site !== '')   { $where[] = 'site = ?';        $params[] = $f_site; }
if ($f_statut !== '') { $where[] = 'statut = ?';      $params[] = $f_statut; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$agents = db_fetch_all("SELECT * FROM agents $where_sql ORDER BY nom, prenom", $params);
$depts  = db_fetch_all("SELECT DISTINCT departement FROM agents WHERE departement IS NOT NULL AND departement <> '' ORDER BY departement");
$sites  = db_fetch_all("SELECT DISTINCT site FROM agents WHERE site IS NOT NULL AND site <> '' ORDER BY site");
$dirs   = db_fetch_all("SELECT DISTINCT direction FROM agents WHERE direction IS NOT NULL AND direction <> '' ORDER BY direction");

// Listes de référence pour le formulaire d'agent (datalist) — tables gérées
// dans l'admin, pas les valeurs déjà présentes dans agents. Il n'existe pas
// de table dédiée aux directions : ce champ reste une saisie libre suggérée
// à partir des valeurs déjà utilisées ($dirs ci-dessus).
$departements_ref = db_fetch_all("SELECT label FROM departements WHERE actif=1 ORDER BY label");
$sites_ref        = db_fetch_all("SELECT nom FROM sites WHERE actif=1 ORDER BY nom");
$kpis   = db_fetch_one(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN statut='actif' THEN 1 ELSE 0 END) AS actifs,
            COUNT(DISTINCT CASE WHEN departement IS NOT NULL AND departement <> '' THEN departement END) AS nb_depts,
            COUNT(DISTINCT CASE WHEN site IS NOT NULL AND site <> '' THEN site END) AS nb_sites
     FROM agents"
);

include __DIR__ . '/../templates/header.php';
?>
<style>
.ag-page  { padding: 24px; max-width: 1400px; margin: 0 auto; }
.ag-head  { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; justify-content: space-between; }
.ag-head-l{ display: flex; align-items: center; gap: 12px; }
.ag-head h2{ font-size: 22px; font-weight: 800; color: var(--navy); margin: 0; }
.ag-head p { margin: 0; font-size: 13px; color: var(--muted); }

/* KPIs */
.ag-kpis { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
.ag-kpi  { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px;
           display: flex; align-items: center; gap: 14px; }
.ag-kpi-ic { width: 42px; height: 42px; border-radius: 10px; display: grid; place-items: center;
             font-size: 20px; flex-shrink: 0; }
.ag-kpi-v  { font-size: 26px; font-weight: 800; line-height: 1; }
.ag-kpi-l  { font-size: 12px; color: var(--muted); margin-top: 2px; }

/* Filtres */
.ag-filters { background: white; border: 1px solid var(--border); border-radius: 12px;
              padding: 14px 18px; margin-bottom: 18px; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.ag-filters label { font-size: 12px; font-weight: 600; color: var(--muted); display: block; margin-bottom: 4px;
                    text-transform: uppercase; letter-spacing: .5px; }
.ag-filters input, .ag-filters select { border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px;
                                        font-size: 13px; background: #f8fafc; font-family: inherit; }
.ag-filters input { min-width: 200px; }
.ag-btn     { padding: 8px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
              border: none; font-family: inherit; display: inline-flex; align-items: center; gap: 6px; }
.ag-btn-pri { background: var(--navy); color: white; }
.ag-btn-sec { background: #f1f5fb; color: #475069; border: 1px solid var(--border); }
.ag-btn-grn { background: #0F8A47; color: white; }

/* Tableau */
.ag-table-wrap { background: white; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.ag-table-head { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.ag-table-head h2 { font-size: 14px; font-weight: 700; color: var(--navy); margin: 0; }
/* Scroll interne au-delà de 4 lignes : hauteur = header + 4 lignes (~46px/ligne) */
.ag-scroll { overflow-x: auto; max-height: 268px; overflow-y: auto; }
.ag-table  { width: 100%; border-collapse: collapse; font-size: 13px; }
.ag-table thead th { background: var(--navy); color: white; padding: 9px 12px; text-align: left;
                     font-size: 12px; font-weight: 700; white-space: nowrap;
                     position: sticky; top: 0; z-index: 1; }
.ag-table tbody tr:nth-child(even) td { background: #f8fafc; }
.ag-table tbody tr:hover td { background: #EFF6FF; }
.ag-table td { padding: 9px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.ag-badge    { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.ag-actif    { background: #D1FAE5; color: #065F46; }
.ag-inactif  { background: #FEE2E2; color: #991B1B; }
.ag-empty    { text-align: center; padding: 48px 20px; color: var(--muted); }
.ag-empty i  { font-size: 40px; display: block; margin-bottom: 10px; opacity: .35; }

/* Import modal */
.ag-modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 900; align-items: center; justify-content: center; }
.ag-modal-bg.open { display: flex; }
.ag-modal    { background: white; border-radius: 18px; padding: 28px 30px; width: 100%; max-width: 520px; box-shadow: 0 20px 60px rgba(0,0,0,.22); }
.ag-modal h3 { margin: 0 0 6px; font-size: 18px; font-weight: 800; color: var(--navy); }
.ag-modal p  { margin: 0 0 18px; font-size: 13px; color: var(--muted); }
.ag-drop     { border: 2px dashed #d0d7e6; border-radius: 12px; padding: 28px 20px; text-align: center;
               cursor: pointer; transition: .18s; background: #f8fafc; margin-bottom: 16px; }
.ag-drop:hover, .ag-drop.over { border-color: #3B4FBE; background: #f0f4ff; }
.ag-drop i   { font-size: 32px; color: #a0aab8; display: block; margin-bottom: 8px; }
.ag-drop-lbl { font-size: 13px; color: var(--muted); }
.ag-drop input[type=file] { display: none; }
.ag-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.ag-flash    { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px;
               transition: opacity .4s ease, max-height .4s ease, margin .4s ease, padding .4s ease; overflow: hidden; }
.ag-flash.ag-flash-out { opacity: 0; max-height: 0; margin-bottom: 0; padding-top: 0; padding-bottom: 0; }
.ag-ok       { background: #D1FAE5; color: #065F46; border-left: 3px solid #0F8A47; }
.ag-err      { background: #FEE2E2; color: #991B1B; border-left: 3px solid #DC2626; }

@media (max-width: 768px) {
    .ag-kpis { grid-template-columns: repeat(2,1fr); }
}
</style>

<div class="ag-page">

  <!-- Flash résultat import -->
  <?php if ($import_result): ?>
    <?php if ($import_result['ok']): ?>
      <div class="ag-flash ag-ok">
        <b><i class="ph ph-check-circle"></i> Import terminé</b> —
        <?= (int)$import_result['inserted'] ?> ajouté(s),
        <?= (int)$import_result['updated'] ?> mis à jour,
        <?= (int)$import_result['skipped'] ?> ligne(s) ignorée(s).
        <?php if (!empty($import_result['errors'])): ?>
          <br><b>Erreurs :</b> <?= h(implode(' / ', array_slice($import_result['errors'], 0, 5))) ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="ag-flash ag-err"><i class="ph ph-warning"></i> <?= h($import_result['msg']) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (isset($_GET['deleted'])): ?>
    <div class="ag-flash ag-ok ag-flash-auto"><i class="ph ph-check-circle"></i> Agent supprimé.</div>
  <?php endif; ?>

  <?php if (isset($_GET['updated'])): ?>
    <div class="ag-flash ag-ok ag-flash-auto"><i class="ph ph-check-circle"></i> Agent mis à jour.</div>
  <?php endif; ?>

  <?php if (isset($_GET['created'])): ?>
    <div class="ag-flash ag-ok ag-flash-auto"><i class="ph ph-check-circle"></i> Agent ajouté.</div>
  <?php endif; ?>

  <?php if ($update_error): ?>
    <div class="ag-flash ag-err"><i class="ph ph-warning"></i> <?= h($update_error) ?></div>
  <?php endif; ?>

  <?php if ($create_error): ?>
    <div class="ag-flash ag-err"><i class="ph ph-warning"></i> <?= h($create_error) ?></div>
  <?php endif; ?>

  <!-- En-tête -->
  <div class="ag-head">
    <div class="ag-head-l">
      <i class="ph ph-users" style="font-size:28px;color:#1B75BC"></i>
      <div>
        <h2>Annuaire des agents</h2>
        <p>Référentiel NSIIV — utilisé pour l'autofill des demandes internes</p>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="?modele=1" class="ag-btn ag-btn-sec">
        <i class="ph ph-download-simple"></i> Modèle Excel
      </a>
      <?php if ($can_import): ?>
      <button type="button" class="ag-btn ag-btn-pri" onclick="openCreateAgent()">
        <i class="ph ph-user-plus"></i> Nouvel agent
      </button>
      <button type="button" class="ag-btn ag-btn-grn" onclick="document.getElementById('ag-modal').classList.add('open')">
        <i class="ph ph-upload-simple"></i> Importer Excel
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPIs -->
  <div class="ag-kpis">
    <div class="ag-kpi">
      <div class="ag-kpi-ic" style="background:#EFF6FF"><i class="ph ph-users" style="color:#1B75BC"></i></div>
      <div><div class="ag-kpi-v" style="color:#1B75BC"><?= (int)$kpis['total'] ?></div><div class="ag-kpi-l">Total agents</div></div>
    </div>
    <div class="ag-kpi">
      <div class="ag-kpi-ic" style="background:#D1FAE5"><i class="ph ph-user-check" style="color:#065F46"></i></div>
      <div><div class="ag-kpi-v" style="color:#065F46"><?= (int)$kpis['actifs'] ?></div><div class="ag-kpi-l">Actifs</div></div>
    </div>
    <div class="ag-kpi">
      <div class="ag-kpi-ic" style="background:#FEF3C7"><i class="ph ph-buildings" style="color:#D97706"></i></div>
      <div><div class="ag-kpi-v" style="color:#D97706"><?= (int)$kpis['nb_depts'] ?></div><div class="ag-kpi-l">Départements</div></div>
    </div>
    <div class="ag-kpi">
      <div class="ag-kpi-ic" style="background:#F3F4F6"><i class="ph ph-map-pin" style="color:#6B7280"></i></div>
      <div><div class="ag-kpi-v" style="color:#6B7280"><?= (int)$kpis['nb_sites'] ?></div><div class="ag-kpi-l">Sites</div></div>
    </div>
  </div>

  <!-- Filtres -->
  <form method="get" class="ag-filters">
    <div>
      <label>Recherche</label>
      <input type="text" name="q" value="<?= h($f_q) ?>" placeholder="Nom, matricule, email…">
    </div>
    <div>
      <label>Département</label>
      <select name="dept">
        <option value="">Tous</option>
        <?php foreach ($depts as $d): ?>
          <option value="<?= h($d['departement']) ?>" <?= $f_dept === $d['departement'] ? 'selected' : '' ?>><?= h($d['departement']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Site</label>
      <select name="site">
        <option value="">Tous</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= h($s['site']) ?>" <?= $f_site === $s['site'] ? 'selected' : '' ?>><?= h($s['site']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Statut</label>
      <select name="statut">
        <option value="">Tous</option>
        <option value="actif"   <?= $f_statut === 'actif'   ? 'selected' : '' ?>>Actif</option>
        <option value="inactif" <?= $f_statut === 'inactif' ? 'selected' : '' ?>>Inactif</option>
      </select>
    </div>
    <button type="submit" class="ag-btn ag-btn-pri"><i class="ph ph-funnel"></i> Filtrer</button>
    <a href="?" style="padding:8px 10px;color:var(--muted);font-size:13px;text-decoration:none;align-self:flex-end">Réinitialiser</a>
  </form>

  <!-- Tableau -->
  <div class="ag-table-wrap">
    <div class="ag-table-head">
      <h2><i class="ph ph-rows" style="margin-right:6px"></i><?= count($agents) ?> agent<?= count($agents) > 1 ? 's' : '' ?></h2>
    </div>

    <?php if (empty($agents)): ?>
      <div class="ag-empty">
        <i class="ph ph-users-three"></i>
        <?php if ((int)$kpis['total'] === 0): ?>
          Aucun agent dans l'annuaire. Importez un fichier Excel ou ajoutez un agent pour commencer.
        <?php else: ?>
          Aucun résultat pour ces filtres.
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="ag-scroll">
        <table class="ag-table">
          <thead>
            <tr>
              <th>Matricule</th>
              <th>Nom &amp; Prénom</th>
              <th>Email</th>
              <th>Téléphone</th>
              <th>Fonction</th>
              <th>Département</th>
              <th>Site</th>
              <th>Grade</th>
              <th>Statut</th>
              <?php if ($can_update || $can_delete): ?><th style="text-align:right">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($agents as $a): ?>
            <tr>
              <td style="font-family:monospace;font-weight:700;color:var(--navy)"><?= $a['matricule'] ? h($a['matricule']) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td style="font-weight:600"><?= h(trim($a['nom'] . ' ' . $a['prenom'])) ?></td>
              <td style="font-size:12px"><?= $a['email'] ? h($a['email']) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td style="font-size:12px;white-space:nowrap"><?= $a['telephone'] ? h($a['telephone']) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td><?= $a['fonction'] ? h($a['fonction']) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td><?= $a['departement'] ? h($a['departement']) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td style="font-size:12px"><?= $a['site'] ? h($a['site']) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td style="font-size:12px"><?= $a['grade'] ? h($a['grade']) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td>
                <span class="ag-badge <?= $a['statut'] === 'actif' ? 'ag-actif' : 'ag-inactif' ?>">
                  <?= $a['statut'] === 'actif' ? 'Actif' : 'Inactif' ?>
                </span>
              </td>
              <?php if ($can_update || $can_delete): ?>
              <td style="text-align:right;white-space:nowrap">
                <?php if ($can_update): ?>
                <button type="button" onclick="openEditAgent(<?= (int)$a['id'] ?>)" style="background:none;border:none;cursor:pointer;color:#1B75BC;font-size:16px;padding:6px" title="Modifier">
                  <i class="ph ph-pencil-simple"></i>
                </button>
                <?php endif; ?>
                <?php if ($can_delete): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cet agent ?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="agent_id" value="<?= (int)$a['id'] ?>">
                  <button type="submit" style="background:none;border:none;cursor:pointer;color:#DC2626;font-size:16px;padding:6px;margin-left:8px" title="Supprimer">
                    <i class="ph ph-trash"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Modal import -->
<?php if ($can_import): ?>
<div class="ag-modal-bg" id="ag-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ag-modal">
    <h3><i class="ph ph-upload-simple" style="margin-right:6px"></i>Importer des agents</h3>
    <p>Sélectionnez un fichier Excel (.xlsx). Les agents existants (même matricule) seront mis à jour, les nouveaux seront ajoutés.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="import">
      <div class="ag-drop" id="ag-drop" onclick="document.getElementById('ag-file').click()">
        <i class="ph ph-file-xls"></i>
        <div class="ag-drop-lbl" id="ag-drop-lbl">Cliquez pour choisir un fichier Excel (.xlsx, .xls)</div>
        <input type="file" name="fichier_excel" id="ag-file" accept=".xlsx,.xls">
      </div>
      <div class="ag-modal-actions">
        <button type="button" class="ag-btn ag-btn-sec" onclick="document.getElementById('ag-modal').classList.remove('open')">Annuler</button>
        <button type="submit" class="ag-btn ag-btn-grn"><i class="ph ph-upload-simple"></i> Importer</button>
      </div>
    </form>
    <div style="margin-top:14px;font-size:12px;color:var(--muted)">
      Colonnes attendues (ligne 1) : <b>Matricule, Nom, Prénom, Email, Téléphone, Fonction, Département, Direction, Site, Grade, Statut</b>
      — <a href="?modele=1" style="color:#1B75BC">Télécharger le modèle</a>
    </div>
  </div>
</div>
<script>
(function(){
  var drop = document.getElementById('ag-drop');
  var inp  = document.getElementById('ag-file');
  var lbl  = document.getElementById('ag-drop-lbl');
  if(!drop || !inp) return;
  inp.addEventListener('change', function(){
    lbl.textContent = inp.files[0] ? inp.files[0].name : 'Cliquez pour choisir un fichier Excel (.xlsx, .xls)';
  });
  drop.addEventListener('dragover', function(e){ e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('dragleave', function(){ drop.classList.remove('over'); });
  drop.addEventListener('drop', function(e){
    e.preventDefault(); drop.classList.remove('over');
    if(e.dataTransfer.files[0]){ inp.files = e.dataTransfer.files; lbl.textContent = e.dataTransfer.files[0].name; }
  });
  // Ouvrir si résultat import
  <?php if ($import_result): ?>
  // Import vient d'avoir lieu — pas besoin de rouvrir la modal
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<!-- Modal création / modification (formulaire partagé) -->
<?php if ($can_update || $can_import): ?>
<div class="ag-modal-bg" id="ag-edit-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ag-modal" style="max-width:640px">
    <h3><i class="ph ph-pencil-simple" id="ed-icon" style="margin-right:6px"></i><span id="ed-title">Modifier l'agent</span></h3>
    <form method="post" id="ag-edit-form">
      <input type="hidden" name="action" id="ed-action" value="update">
      <input type="hidden" name="agent_id" id="ed-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 14px;margin-bottom:14px">
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Matricule</label>
          <input type="text" name="matricule" id="ed-matricule" style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Statut</label>
          <select name="statut" id="ed-statut" style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit">
            <option value="actif">Actif</option>
            <option value="inactif">Inactif</option>
          </select>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Nom *</label>
          <input type="text" name="nom" id="ed-nom" required
                 style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit;text-transform:uppercase"
                 oninput="this.value=this.value.toUpperCase()">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Prénom *</label>
          <input type="text" name="prenom" id="ed-prenom" required
                 style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit;text-transform:uppercase"
                 oninput="this.value=this.value.toUpperCase()">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Email *</label>
          <input type="email" name="email" id="ed-email" required style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Téléphone</label>
          <input type="tel" name="telephone" id="ed-telephone" inputmode="numeric" pattern="[0-9]{10}" maxlength="10"
                 placeholder="10 chiffres" title="10 chiffres, sans espace ni lettre"
                 style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit"
                 oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Fonction</label>
          <input type="text" name="fonction" id="ed-fonction" style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Département</label>
          <input type="text" name="departement" id="ed-departement" list="dl-departement" autocomplete="off"
                 style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit">
          <datalist id="dl-departement">
            <?php foreach ($departements_ref as $d): ?><option value="<?= h($d['label']) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Direction</label>
          <input type="text" name="direction" id="ed-direction" list="dl-direction" autocomplete="off"
                 style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit">
          <datalist id="dl-direction">
            <?php foreach ($dirs as $d): ?><option value="<?= h($d['direction']) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Site</label>
          <input type="text" name="site" id="ed-site" list="dl-site" autocomplete="off"
                 style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit">
          <datalist id="dl-site">
            <?php foreach ($sites_ref as $s): ?><option value="<?= h($s['nom']) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Grade</label>
          <select name="grade" id="ed-grade" style="width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit">
            <option value="">— Choisir —</option>
            <option value="CDI">CDI</option>
            <option value="CDD">CDD</option>
            <option value="STAGE">STAGE</option>
            <option value="ALTERNANCE">ALTERNANCE</option>
            <option value="INTÉRIM">INTÉRIM</option>
            <option value="CONSULTANT">CONSULTANT</option>
            <option value="PRESTATAIRE">PRESTATAIRE</option>
            <option value="VACATAIRE">VACATAIRE</option>
            <option value="BÉNÉVOLE">BÉNÉVOLE</option>
            <option value="AUTRE">AUTRE</option>
          </select>
        </div>
      </div>
      <div class="ag-modal-actions">
        <button type="button" class="ag-btn ag-btn-sec" onclick="document.getElementById('ag-edit-modal').classList.remove('open')">Annuler</button>
        <button type="submit" class="ag-btn ag-btn-pri"><i class="ph ph-check"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<script>
const AGENTS_DATA = <?= json_encode(array_column($agents, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
function openEditAgent(id) {
  const a = AGENTS_DATA[id];
  if (!a) return;
  document.getElementById('ed-title').textContent = "Modifier l'agent";
  document.getElementById('ed-icon').className    = 'ph ph-pencil-simple';
  document.getElementById('ed-action').value      = 'update';
  document.getElementById('ed-id').value          = a.id;
  document.getElementById('ed-matricule').value   = a.matricule || '';
  document.getElementById('ed-nom').value         = a.nom || '';
  document.getElementById('ed-prenom').value      = a.prenom || '';
  document.getElementById('ed-email').value       = a.email || '';
  document.getElementById('ed-telephone').value   = a.telephone || '';
  document.getElementById('ed-fonction').value    = a.fonction || '';
  document.getElementById('ed-departement').value = a.departement || '';
  document.getElementById('ed-direction').value   = a.direction || '';
  document.getElementById('ed-site').value        = a.site || '';
  setGradeValue(a.grade || '');
  document.getElementById('ed-statut').value      = a.statut === 'inactif' ? 'inactif' : 'actif';
  document.getElementById('ag-edit-modal').classList.add('open');
}
// Un agent importé avant l'introduction de la liste fermée peut porter une
// valeur de grade hors liste : on l'ajoute à la volée plutôt que de la
// faire disparaître silencieusement du formulaire.
function setGradeValue(val) {
  var sel = document.getElementById('ed-grade');
  var found = false;
  for (var i = 0; i < sel.options.length; i++) {
    if (sel.options[i].value === val) { found = true; break; }
  }
  var extra = sel.querySelector('option[data-extra]');
  if (extra) extra.remove();
  if (val && !found) {
    var opt = document.createElement('option');
    opt.value = val; opt.textContent = val; opt.setAttribute('data-extra', '1');
    sel.appendChild(opt);
  }
  sel.value = val;
}
function openCreateAgent() {
  document.getElementById('ag-edit-form').reset();
  document.getElementById('ed-title').textContent = 'Ajouter un agent';
  document.getElementById('ed-icon').className    = 'ph ph-user-plus';
  document.getElementById('ed-action').value      = 'create';
  document.getElementById('ed-id').value          = '';
  setGradeValue('');
  document.getElementById('ag-edit-modal').classList.add('open');
}
</script>
<?php endif; ?>

<script>
// Message de confirmation (suppression/modif/ajout) : disparaît tout seul
// et nettoie l'URL pour qu'un rechargement ne le réaffiche pas indéfiniment.
(function(){
  var url = new URL(window.location.href);
  var hadFlashParam = ['deleted','updated','created'].some(k => url.searchParams.has(k));
  if (hadFlashParam) {
    ['deleted','updated','created'].forEach(k => url.searchParams.delete(k));
    window.history.replaceState(null, '', url.pathname + url.search);
  }
  document.querySelectorAll('.ag-flash-auto').forEach(function(el){
    setTimeout(function(){
      el.classList.add('ag-flash-out');
      el.addEventListener('transitionend', function(){ el.remove(); }, {once:true});
    }, 3500);
  });
})();
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
