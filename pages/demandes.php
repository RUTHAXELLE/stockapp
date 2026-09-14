<?php
// ============================================================
//  pages/demandes.php — Mes demandes / Fiche détail / Validation / PDF
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/demandes.php';
require_once __DIR__ . '/../includes/demandes_champs.php';

require_auth();
require_permission('demandes', 'can_read');
$user = current_user();
$_SESSION['groupe_actif'] = 'DEMANDES';
$page_title  = 'Demandes internes';
$active_page = 'demandes';

$my_roles = di_user_roles((int)$user['id']);
$is_admin = in_array(($user['role_slug'] ?? ''), ['admin','superadmin'], true) || in_array('dg', $my_roles, true);

// ── EXPORT PDF ?export=pdf&id=X
if (($_GET['export'] ?? '') === 'pdf' && !empty($_GET['id'])) {
    $d = di_get((int)$_GET['id']);
    if (!$d) { http_response_code(404); exit('Demande introuvable.'); }
    $owner = (int)$d['demandeur_id'] === (int)$user['id'];
    $wf = di_workflow_of($d);
    $isValidator = false;
    foreach ($wf as $st) if (in_array($st['role'], $my_roles, true)) $isValidator = true;
    if (!$isValidator && isset($d['n1_user_id']) && (int)$d['n1_user_id'] === (int)$user['id']) $isValidator = true;
    if (!$isValidator) {
        foreach ($wf as $st) {
            $dept_id = db_fetch_value("SELECT departement_id FROM di_roles WHERE code=?", [$st['role']]);
            if ($dept_id && db_fetch_value("SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=?", [(int)$user['id'], (int)$dept_id])) {
                $isValidator = true; break;
            }
        }
    }
    // Traitement IT : certains types (ex. transfert_agent) n'ont pas d'étape
    // "it" dans leur circuit de validation alors qu'ils exigent un traitement
    // IT après approbation — l'IT doit pouvoir ouvrir la fiche pour la traiter.
    if (!$isValidator) {
        $dType = di_type($d['type_code']);
        if (!empty($dType['traitement_it']) && di_user_can_traiter_it((int)$user['id'], $my_roles)) {
            $isValidator = true;
        }
    }
    if (!$owner && !$isValidator && !$is_admin) { http_response_code(403); exit('Accès refusé.'); }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) { header('Content-Type:text/plain'); exit('Dompdf non installé.'); }
    require_once $autoload;
    $html = di_pdf_html($d);
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('demande_'.$d['numero'].'.pdf', ['Attachment' => false]);
    exit;
}

// ── AJAX : valider / rejeter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $d = di_get($id);
    if (!$d) json_response(false, 'Demande introuvable.');
    try {
        if ($action === 'valider') {
            di_valider($d, $user, trim($_POST['commentaire'] ?? ''));
            json_response(true, 'Étape validée.');
        } elseif ($action === 'rejeter') {
            di_rejeter($d, $user, trim($_POST['motif'] ?? ''));
            json_response(true, 'Demande rejetée.');
        } elseif ($action === 'traiter_it') {
            di_traiter_it($d, $user, trim($_POST['commentaire'] ?? ''), trim($_POST['ticket_glpi'] ?? ''));
            json_response(true, 'Demande marquée comme traitée.');
        }
        json_response(false, 'Action inconnue.');
    } catch (Exception $e) {
        json_response(false, $e->getMessage());
    }
}

// ── Fonction : HTML du PDF (reproduit la fiche de l'app source, moteur DomPDF)
function di_pdf_html(array $d): string {
    $type = di_type($d['type_code']);
    $demandeur = db_fetch_one("SELECT prenom, nom, email FROM users WHERE id=?", [$d['demandeur_id']]);
    $dnom = trim(($demandeur['prenom'] ?? '').' '.($demandeur['nom'] ?? ''));
    $wf   = di_workflow_of($d);
    $champs = $d['champs'];
    $fields = di_champs_of($d['type_code']);
    [$slbl, $sc] = di_statut_label(di_statut_effectif($d));
    $cur = (int)$d['etape_actuelle'];
    $enCours = in_array($d['statut'], ['en_attente','en_cours'], true);

    // Détails de la demande
    $rows = '';
    foreach ($fields as $f) {
        $val = $champs[$f['key']] ?? '';
        if (di_value_empty($val)) continue;
        $rows .= '<tr><td class="k">'.h($f['label']).'</td><td class="v">'.nl2br(di_display_value($f, $val)).'</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td class="k" style="color:#b0b7c3">Aucun détail saisi</td><td class="v"></td></tr>';

    // Signatures indexées par étape du circuit
    $sigByStep = [];
    foreach ($d['signatures'] as $s) { $sigByStep[(int)($s['etape'] ?? -1)] = $s; }

    // Blocs de visa (un par étape) — format document administratif
    $nSteps   = count($wf);
    $pct      = $nSteps > 0 ? floor(100 / $nSteps) : 100;
    $visaCells = '';
    foreach ($wf as $i => $st) {
        $label = h($st['label']);
        $sig   = $sigByStep[$i] ?? null;
        $act   = $sig['action'] ?? '';

        if ($act === 'approuve') {
            $hdbg  = '#1f9d5b'; $hdfg = '#fff';
            $icon  = '&#10003;'; // ✓
            $decTx = 'Approuvé';
            $decCl = 'color:#1f9d5b;font-weight:bold';
            $bdBg  = '#f0faf5'; $bdBorder = '#bfe6d0';
        } elseif ($act === 'rejete') {
            $hdbg  = '#e74c3c'; $hdfg = '#fff';
            $icon  = '&#10007;'; // ✗
            $decTx = 'Rejeté';
            $decCl = 'color:#e74c3c;font-weight:bold';
            $bdBg  = '#fdf1f0'; $bdBorder = '#f6c9c4';
        } elseif ($i === $cur && $enCours) {
            $hdbg  = '#3B4FBE'; $hdfg = '#fff';
            $icon  = '&#8987;'; // ⏳
            $decTx = 'En attente de visa';
            $decCl = 'color:#3B4FBE;font-style:italic';
            $bdBg  = '#f4f5fd'; $bdBorder = '#cdd4f6';
        } else {
            $hdbg  = '#b0b7c9'; $hdfg = '#fff';
            $icon  = '&#8212;'; // —
            $decTx = 'Non atteint';
            $decCl = 'color:#b0b7c9;font-style:italic';
            $bdBg  = '#f8f9fb'; $bdBorder = '#e4e8f1';
        }

        $byLine   = $sig ? h($sig['nom'] ?? '') : '&nbsp;';
        $dtLine   = ($sig && !empty($sig['date'])) ? date('d/m/Y', strtotime($sig['date'])) : '&nbsp;';
        $noteLine = $sig ? h($sig['commentaire'] ?? $sig['motif'] ?? '') : '';

        // Image de signature du signataire (data URI stockée dans users.signature)
        $sigImg = '';
        if ($sig && !empty($sig['user_id'])) {
            $sigData = db_fetch_value("SELECT signature FROM users WHERE id=?", [(int)$sig['user_id']]);
            if ($sigData && str_starts_with($sigData, 'data:image/')) {
                $enc    = htmlspecialchars($sigData, ENT_QUOTES, 'UTF-8');
                $sigImg = "<img src=\"{$enc}\" style=\"max-width:100%;max-height:38px;display:block;margin:4px auto\">";
            }
        }
        $sigBlock = $sigImg
            ? $sigImg
            : ($sig ? '<div style="border-top:1px solid '.($act==='approuve'?'#bfe6d0':($act==='rejete'?'#f6c9c4':'#cdd4f6')).';margin:6px 8px 2px"></div>' : '');

        $visaCells .= <<<CELL
<td style="width:{$pct}%;vertical-align:top;padding:0 4px">
  <table style="width:100%;border-collapse:collapse;border:1.5px solid {$bdBorder};border-radius:6px;overflow:hidden">
    <tr><td colspan="2" style="background:{$hdbg};color:{$hdfg};font-size:9.5px;font-weight:bold;
      padding:6px 8px;text-transform:uppercase;letter-spacing:.4px;line-height:1.3">
      {$icon}&nbsp;&nbsp;{$label}
    </td></tr>
    <tr><td colspan="2" style="background:{$bdBg};padding:6px 8px 2px;text-align:center;height:46px">
      {$sigBlock}
    </td></tr>
    <tr style="background:{$bdBg}">
      <td style="padding:3px 8px 7px;font-size:10px;color:#5a6480">Date</td>
      <td style="padding:3px 8px 7px;font-size:10.5px;color:#1f2a44">{$dtLine}</td>
    </tr>
    <tr style="background:{$bdBg}">
      <td colspan="2" style="padding:0 8px 8px;font-size:9.5px;color:#8a93a5;font-style:italic">{$noteLine}&nbsp;</td>
    </tr>
  </table>
</td>
CELL;
    }

    $tlabel  = h($type['label'] ?? $d['type_code']);
    $numero  = h($d['numero']);
    $email   = h($demandeur['email'] ?? '');
    $dnomH   = h($dnom);
    $slblH   = h($slbl);
    $created = date('d/m/Y', strtotime($d['created_at']));
    $genat   = date('d/m/Y H:i');

    // Traitement IT : le n° de ticket GLPI figure sur le document imprimé,
    // c'est lui qui relie la demande à l'intervention réellement menée.
    $ticketLigne = '';
    if (!empty($d['ticket_glpi'])) {
        $tk = h($d['ticket_glpi']);
        $tdate = !empty($d['traite_date']) ? date('d/m/Y', strtotime($d['traite_date'])) : '—';
        $ticketLigne = '<div class="sec">Traitement IT</div>'
          . '<div class="card"><table class="info">'
          . '<tr><td class="k">N° de ticket GLPI</td><td class="v">' . $tk . '</td></tr>'
          . '<tr><td class="k">Traité le</td><td class="v">' . $tdate . '</td></tr>'
          . '</table></div>';
    }

    return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><style>
  @page{margin:0}
  body{font-family:"DejaVu Sans",sans-serif;color:#2c3e50;font-size:12px;margin:0}
  .hd{background:#06033A;padding:18px 26px}
  .hdt{width:100%;border-collapse:collapse}
  .hdl{text-align:left;vertical-align:middle}
  .hdr{text-align:right;vertical-align:top;color:#c9cee8;font-size:10px;line-height:1.55;width:32%}
  .ttl{color:#fff;font-size:19px;font-weight:bold}
  .sub{color:#aeb6dd;font-size:10.5px;margin-top:3px}
  .wrap{padding:20px 26px}
  .badge{display:inline-block;padding:4px 14px;border-radius:20px;color:#fff;font-size:10.5px;font-weight:bold}
  .sec{font-size:11px;font-weight:bold;color:#3B4FBE;text-transform:uppercase;letter-spacing:.6px;margin:20px 0 9px;
    border-bottom:2px solid #eef2f7;padding-bottom:5px}
  .card{border:1px solid #e6eaf3;border-radius:8px;background:#fbfcfe;padding:2px 14px}
  .info{width:100%;border-collapse:collapse}
  .info td{padding:7px 4px;font-size:12px;border-bottom:1px solid #eef2f7;vertical-align:top}
  .info td.k{color:#8a93a5;width:34%}
  .info td.v{font-weight:bold;color:#1f2a44}
  .ft{margin-top:28px;text-align:center;color:#aab1c0;font-size:9px;border-top:1px solid #eef2f7;padding-top:10px}
</style></head><body>
  <div class="hd"><table class="hdt"><tr>
    <td class="hdl"><div class="ttl">{$tlabel}</div><div class="sub">Demande interne · {$dnomH}</div></td>
    <td class="hdr">EMU-CI<br>Réf.&nbsp;{$numero}<br>{$created}</td>
  </tr></table></div>
  <div class="wrap">
    <div style="margin-bottom:14px"><span class="badge" style="background:{$sc}">{$slblH}</span></div>
    <div class="sec">Demandeur</div>
    <div class="card"><table class="info">
      <tr><td class="k">Nom</td><td class="v">{$dnomH}</td></tr>
      <tr><td class="k">Email</td><td class="v">{$email}</td></tr>
    </table></div>
    <div class="sec">Détails de la demande</div>
    <div class="card"><table class="info">{$rows}</table></div>
    {$ticketLigne}
    <div class="sec">Chaîne de visas</div>
    <table style="width:100%;border-collapse:collapse;table-layout:fixed"><tr>{$visaCells}</tr></table>
    <div class="ft">Document généré automatiquement par EMU-CI le {$genat} — Réf. {$numero}</div>
  </div>
</body></html>
HTML;
}

// ── Vue DÉTAIL (?id=)
$detail = null;
if (!empty($_GET['id'])) {
    $detail = di_get((int)$_GET['id']);
    if ($detail) {
        $owner = (int)$detail['demandeur_id'] === (int)$user['id'];
        $wf = di_workflow_of($detail);
        $isValidator = false;
        foreach ($wf as $st) if (in_array($st['role'], $my_roles, true)) $isValidator = true;
        // N+1 résolu pour cette demande
        if (!$isValidator && isset($detail['n1_user_id']) && (int)$detail['n1_user_id'] === (int)$user['id']) {
            $isValidator = true;
        }
        // Validation par département : membre d'un département lié à une étape du circuit
        if (!$isValidator) {
            foreach ($wf as $st) {
                $dept_id = db_fetch_value("SELECT departement_id FROM di_roles WHERE code=?", [$st['role']]);
                if ($dept_id && db_fetch_value("SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=?", [(int)$user['id'], (int)$dept_id])) {
                    $isValidator = true; break;
                }
            }
        }
        // Traitement IT : certains types (ex. transfert_agent) n'ont pas d'étape
        // "it" dans leur circuit de validation alors qu'ils exigent un traitement
        // IT après approbation — l'IT doit pouvoir ouvrir la fiche pour la traiter.
        if (!$isValidator) {
            $dType = di_type($detail['type_code']);
            if (!empty($dType['traitement_it']) && di_user_can_traiter_it((int)$user['id'], $my_roles)) {
                $isValidator = true;
            }
        }
        if (!$owner && !$isValidator && !$is_admin) { $detail = null; }
    }
}

// ── Filtres liste
$fil_search  = $_GET['q']         ?? '';
$fil_type    = $_GET['type']      ?? '';
$fil_statut  = $_GET['statut']    ?? '';
$fSearch = $fil_search !== '' ? $fil_search : null;
$fType   = $fil_type   !== '' ? $fil_type   : null;
$fStatut = $fil_statut !== '' ? $fil_statut : null;
$fFrom = null; $fTo = null;

// ── Liste « Mes demandes »
$where = 'd.demandeur_id=?'; $params = [(int)$user['id']];
if ($fType)   { $where .= ' AND d.type_code = ?'; $params[] = $fType; }
if ($fStatut) {
    if ($fStatut === 'en_cours') { $where .= " AND d.statut IN ('en_attente','en_cours')"; }
    else                         { $where .= ' AND d.statut = ?'; $params[] = $fStatut; }
}
if ($fSearch) {
    $where .= ' AND (d.numero ILIKE ? OR LOWER(u.prenom || \' \' || u.nom) LIKE LOWER(?))';
    $params[] = '%'.$fSearch.'%'; $params[] = '%'.$fSearch.'%';
}
$mes = $detail ? [] : db_fetch_all(
    "SELECT d.id, d.numero, d.type_code, d.statut, d.created_at, d.etape_actuelle,
            (u.prenom || ' ' || u.nom) AS demandeur_nom
     FROM di_demandes d JOIN users u ON u.id = d.demandeur_id
     WHERE $where ORDER BY d.created_at DESC",
    $params
);
$type_labels = [];
$wf_cache    = [];
foreach (di_types_actifs() as $t) {
    $type_labels[$t['code']] = $t['label'];
    $wf_cache[$t['code']]    = json_decode($t['workflow'] ?? '[]', true) ?: [];
}

// ── Mini-dashboard (vue liste uniquement)
$di_stats = ['en_attente'=>0,'en_cours'=>0,'approuve'=>0,'rejete'=>0,'brouillon'=>0];
$nb_a_valider = 0;
if (!$detail) {
    foreach (db_fetch_all("SELECT statut, COUNT(*) AS n FROM di_demandes WHERE demandeur_id=? GROUP BY statut", [$user['id']]) as $r) {
        $k = $r['statut'] === 'approuve_traitement' ? 'approuve' : $r['statut'];
        if (isset($di_stats[$k])) $di_stats[$k] += (int)$r['n'];
    }
    $nb_a_valider = count(di_a_valider($user));
}

include __DIR__ . '/../templates/header.php';
?>
<style>
  .di-wrap{max-width:960px;margin:0 auto}
  .di-topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
  .di-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
  .di-stat{background:var(--card,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:14px;padding:16px 18px;text-decoration:none}
  .di-stat .n{font-size:26px;font-weight:800;line-height:1;color:var(--text,#2c3e50)}
  .di-stat .l{font-size:12px;color:var(--muted,#7f8c8d);margin-top:5px}
  .di-stat-action{background:linear-gradient(135deg,#3B4FBE,#7C92FF);border-color:transparent;color:#fff;transition:.15s}
  .di-stat-action:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(59,79,190,.28)}
  .di-stat-action .n,.di-stat-action .l{color:#fff}
  .di-btn{padding:10px 18px;border:none;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;
    display:inline-flex;align-items:center;gap:7px;font-family:inherit}
  .di-btn-primary{background:linear-gradient(135deg,#3B4FBE,#7C92FF);color:#fff}
  .di-btn-ghost{background:var(--input,#f0f4f8);color:var(--text,#2c3e50)}
  .di-btn-danger{background:#fdf0ef;color:#e74c3c}
  .di-btn-back{background:var(--card,#fff);color:var(--text,#2c3e50);border:1.5px solid var(--border,#e2e8f0)}
  .di-btn-back:hover{border-color:#7C92FF;color:#3B4FBE}
  .di-btn-back i{font-size:15px;transition:transform .15s}
  .di-btn-back:hover i{transform:translateX(-3px)}
  .di-btn-pdf{background:#FEF2F2;color:#DC2626}
  .di-btn-pdf:hover{background:#DC2626;color:#fff;box-shadow:0 6px 16px rgba(220,38,38,.28);transform:translateY(-1px)}
  [data-theme="dark"] .di-btn-pdf{background:#4A1D1D;color:#FCA5A5}
  [data-theme="dark"] .di-btn-pdf:hover{background:#DC2626;color:#fff}
  .di-tbl{width:100%;border-collapse:collapse;background:var(--card,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden}
  .di-tbl th{text-align:left;padding:12px 16px;font-size:12px;color:var(--muted,#7f8c8d);background:var(--input,#f8fafc)}
  .di-tbl td{padding:13px 16px;border-top:1px solid var(--border,#eef2f7);font-size:14px}
  .di-tbl tr:hover td{background:var(--input,#f8fafc)}
  .di-empty{text-align:center;padding:50px 20px;color:var(--muted,#7f8c8d)}
  .di-card{background:var(--card,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:16px;padding:24px;margin-bottom:18px}
  .di-steps{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0}
  .di-step{flex:1;min-width:120px;padding:10px 12px;border-radius:10px;border:1.5px solid var(--border,#e2e8f0);font-size:12px;text-align:center}
  .di-step.done{border-color:#27ae60;background:#eafaf1;color:#1e7e46}
  .di-step.current{border-color:#3B4FBE;background:#eef1fc;color:#3B4FBE;font-weight:700}
  .di-kv{display:grid;grid-template-columns:220px 1fr;gap:8px 16px;font-size:14px}
  .di-kv .k{color:var(--muted,#7f8c8d)}
  .di-alert{display:none;padding:11px 15px;border-radius:9px;font-size:13px;margin-bottom:14px}
  .di-alert.err{display:block;background:#fdf0ef;color:#e74c3c;border-left:3px solid #e74c3c}
  .di-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;align-items:center;justify-content:center}
  .di-modal.open{display:flex}
  .di-modal .box{background:#fff;border-radius:14px;padding:24px;max-width:440px;width:92%}
  .di-modal textarea{width:100%;border:1.5px solid #d5dde8;border-radius:9px;padding:10px;font-family:inherit;font-size:14px}
</style>

<div class="di-wrap">
<?php if ($detail):
    $type = di_type($detail['type_code']);
    $wf   = di_workflow_of($detail);
    $cur  = (int)$detail['etape_actuelle'];
    $n1Id = isset($detail['n1_user_id']) && $detail['n1_user_id'] ? (int)$detail['n1_user_id'] : null;
    $canV = di_can_validate($my_roles, (int)$user['id'], $wf, $cur, (int)$detail['demandeur_id'], $n1Id)
            && in_array($detail['statut'], ['en_attente','en_cours'], true);
    $canTraiterIt = $detail['statut'] === 'approuve_traitement' && empty($detail['traite_it'])
            && di_user_can_traiter_it((int)$user['id'], $my_roles);
    $fields = di_champs_of($detail['type_code']);
    $demandeur = db_fetch_one("SELECT prenom, nom FROM users WHERE id=?", [$detail['demandeur_id']]);
?>
  <div class="di-topbar">
    <a href="<?= APP_URL ?>/pages/demandes.php" class="di-btn di-btn-back"><i class="ph-duotone ph-arrow-left"></i> Retour</a>
    <a href="<?= APP_URL ?>/pages/demandes.php?export=pdf&id=<?= (int)$detail['id'] ?>" target="_blank" class="di-btn di-btn-pdf"><i class="ph-duotone ph-file-pdf"></i> PDF</a>
  </div>

  <div class="di-card">
    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px">
      <div>
        <h2 style="margin:0 0 4px"><?= h($type['label']) ?></h2>
        <div style="font-size:13px;color:var(--muted,#7f8c8d)">
          Réf. <?= h($detail['numero']) ?> · Demandeur : <?= h(trim(($demandeur['prenom']??'').' '.($demandeur['nom']??''))) ?>
          · <?= date('d/m/Y', strtotime($detail['created_at'])) ?>
        </div>
      </div>
      <div style="text-align:right">
        <?= di_badge(di_statut_effectif($detail)) ?>
        <?php if (!empty($detail['traite_it'])):
          $traiteur = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [(int)$detail['traite_par']]);
        ?>
        <div style="font-size:12px;color:#16a085;margin-top:6px">
          <i class="ph-duotone ph-check-circle"></i> Traité par <?= h($traiteur) ?><?= $detail['traite_date'] ? ' le '.date('d/m/Y', strtotime($detail['traite_date'])) : '' ?>
        </div>
        <?php if (!empty($detail['ticket_glpi'])): ?>
        <div style="font-size:12px;font-weight:700;color:var(--navy,#06033A);margin-top:3px">
          Ticket GLPI n° <?= h($detail['ticket_glpi']) ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <h4 style="margin:20px 0 6px;font-size:13px;color:var(--muted,#7f8c8d)">CIRCUIT DE VALIDATION</h4>
    <div class="di-steps">
      <?php foreach ($wf as $i => $st):
        $cls = $i < $cur ? 'done' : ($i === $cur && in_array($detail['statut'],['en_attente','en_cours'],true) ? 'current' : '');
        if ($detail['statut']==='approuve' || $detail['statut']==='approuve_traitement') $cls = 'done';
      ?>
        <div class="di-step <?= $cls ?>"><?= h($st['label']) ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="di-card">
    <h4 style="margin:0 0 14px">Détails de la demande</h4>
    <div class="di-kv">
      <?php foreach ($fields as $f): $v = $detail['champs'][$f['key']] ?? ''; if (di_value_empty($v)) continue; ?>
        <div class="k"><?= h($f['label']) ?></div><div><?= nl2br(di_display_value($f, $v)) ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!empty($detail['historique'])): ?>
  <div class="di-card">
    <h4 style="margin:0 0 18px">Historique</h4>
    <?php
    $mois_fr = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $hist_entries = $detail['historique'];
    foreach ($hist_entries as $idx => $h_entry):
        $action = $h_entry['action'] ?? '';
        $last   = $idx === count($hist_entries) - 1;
        if ($action === 'valide') {
            $ic_bg = '#e8f6ef'; $ic_cl = '#1f9d5b'; $ic = '✓';
            $label = 'Validé — '.($h_entry['etape'] ?? '');
        } elseif ($action === 'rejete') {
            $ic_bg = '#fdecea'; $ic_cl = '#e74c3c'; $ic = '✗';
            $label = 'Rejeté — '.($h_entry['etape'] ?? '');
        } elseif ($action === 'soumis') {
            $ic_bg = '#eef1fc'; $ic_cl = '#3B4FBE'; $ic = '→';
            $label = 'Demande soumise';
        } elseif ($action === 'traite_it') {
            $ic_bg = '#e8f8f5'; $ic_cl = '#16a085'; $ic = '✓';
            $label = 'Traitement IT effectué';
            if (!empty($h_entry['ticket_glpi'])) {
                $label .= ' — ticket GLPI n° ' . $h_entry['ticket_glpi'];
            }
        } else {
            $ic_bg = '#f1f3f8'; $ic_cl = '#98a1b3'; $ic = '→';
            $label = 'Brouillon sauvegardé';
        }
        $note = h($h_entry['commentaire'] ?? $h_entry['motif'] ?? '');
        $ts   = strtotime($h_entry['date'] ?? '');
        $date_fr = $ts ? intval(date('j',$ts)).' '.$mois_fr[intval(date('n',$ts))].' '.date('Y',$ts).' à '.date('H:i',$ts) : '';
    ?>
    <div style="display:flex;gap:14px;padding-bottom:<?= $last?'0':'20px' ?>">
      <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0">
        <div style="width:34px;height:34px;border-radius:50%;background:<?= $ic_bg ?>;color:<?= $ic_cl ?>;
          font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <?= $ic ?>
        </div>
        <?php if (!$last): ?>
        <div style="width:2px;flex:1;background:var(--border,#e2e8f0);margin-top:4px;min-height:16px"></div>
        <?php endif; ?>
      </div>
      <div style="padding-top:6px;flex:1">
        <div style="font-weight:700;font-size:14px;color:var(--navy,#06033A)"><?= h($label) ?></div>
        <div style="font-size:12px;color:var(--muted,#7f8c8d);margin-top:2px">
          <?= h($h_entry['nom'] ?? '') ?><?= $date_fr ? ' · '.$date_fr : '' ?>
        </div>
        <?php if ($note): ?>
        <div style="font-size:12px;color:var(--muted,#7f8c8d);font-style:italic;margin-top:4px"><?= $note ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($canV): ?>
  <div class="di-card">
    <div class="di-alert" id="di-err"></div>
    <h4 style="margin:0 0 12px">Votre visa — étape « <?= h($wf[$cur]['label']) ?> »</h4>
    <div style="display:flex;gap:12px">
      <button class="di-btn di-btn-primary" onclick="diValider()"><i class="ph-duotone ph-check"></i> Valider</button>
      <button class="di-btn di-btn-danger" onclick="document.getElementById('di-reject').classList.add('open')"><i class="ph-duotone ph-x"></i> Rejeter</button>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($canTraiterIt): ?>
  <div class="di-card">
    <div class="di-alert" id="di-err"></div>
    <h4 style="margin:0 0 8px">Traitement IT</h4>
    <p style="margin:0 0 14px;font-size:13px;color:var(--muted,#7f8c8d)">
      Demande approuvée, en attente d'exécution IT (création/modification d'accès, compte…).
      Marquez-la comme traitée une fois l'action réalisée.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:14px">
      <div style="flex:0 0 200px">
        <label for="di-ticket" style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">
          N° de ticket GLPI <span style="color:var(--danger,#e74c3c)">*</span>
        </label>
        <input type="text" id="di-ticket" maxlength="50" placeholder="ex. 4521"
               style="width:100%;padding:9px 12px;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;font-size:13px;font-family:inherit;outline:none">
      </div>
      <div style="flex:1;min-width:220px">
        <label for="di-comm-it" style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">
          Commentaire <span style="font-weight:400;color:var(--muted,#7f8c8d)">(optionnel)</span>
        </label>
        <input type="text" id="di-comm-it" maxlength="255" placeholder="Action réalisée, précisions…"
               style="width:100%;padding:9px 12px;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;font-size:13px;font-family:inherit;outline:none">
      </div>
    </div>
    <button class="di-btn di-btn-primary" onclick="diTraiterIt()"><i class="ph-duotone ph-check-circle"></i> Marquer comme traité</button>
  </div>
  <?php endif; ?>

<?php else: ?>
  <div class="di-topbar">
    <h2 style="margin:0">Mes demandes</h2>
    <?php if (di_peut_creer($user)): ?>
      <a href="<?= APP_URL ?>/pages/demandes_new.php" class="di-btn di-btn-primary"><i class="ph-duotone ph-plus"></i> Nouvelle demande</a>
    <?php endif; ?>
  </div>

  <div class="di-stats">
    <?php if ($nb_a_valider > 0): ?>
      <a href="<?= APP_URL ?>/pages/demandes_a_valider.php" class="di-stat di-stat-action">
        <div class="n"><?= $nb_a_valider ?></div><div class="l">À valider par vous</div></a>
    <?php endif; ?>
    <div class="di-stat"><div class="n" style="color:#e67e22"><?= $di_stats['en_attente'] + $di_stats['en_cours'] ?></div><div class="l">En cours</div></div>
    <div class="di-stat"><div class="n" style="color:#27ae60"><?= $di_stats['approuve'] ?></div><div class="l">Approuvées</div></div>
    <div class="di-stat"><div class="n" style="color:#e74c3c"><?= $di_stats['rejete'] ?></div><div class="l">Rejetées</div></div>
    <div class="di-stat"><div class="n" style="color:#7f8c8d"><?= $di_stats['brouillon'] ?></div><div class="l">Brouillons</div></div>
  </div>

  <!-- ── Barre de filtres -->
  <?php $has_filter = $fType || $fStatut || $fSearch; ?>
  <form method="get" id="fmes" style="background:var(--card,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:14px;
    padding:0;margin-bottom:18px;display:grid;grid-template-columns:1fr 1px 1fr 1px 1fr;align-items:stretch;overflow:hidden">
    <!-- Rechercher -->
    <div style="padding:14px 18px">
      <div style="font-size:12px;font-weight:700;color:var(--muted,#7f8c8d);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">Rechercher</div>
      <input type="text" name="q" value="<?= h($fil_search) ?>" placeholder="Nom, type, référence…"
        style="width:100%;border:none;outline:none;font-size:13px;font-family:inherit;background:transparent;color:var(--text,#2c3e50);padding:0"
        oninput="clearTimeout(this._t);this._t=setTimeout(()=>document.getElementById('fmes').submit(),500)">
    </div>
    <div style="background:var(--border,#e2e8f0)"></div>
    <!-- Type -->
    <div style="padding:14px 18px">
      <div style="font-size:12px;font-weight:700;color:var(--muted,#7f8c8d);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">Type</div>
      <select name="type" style="border:none;outline:none;font-size:13px;font-family:inherit;background:transparent;color:var(--text,#2c3e50);width:100%;cursor:pointer;padding:0"
        onchange="document.getElementById('fmes').submit()">
        <option value="">Tous les types</option>
        <?php foreach (di_types_actifs() as $t): ?>
        <option value="<?= h($t['code']) ?>" <?= $fType===$t['code']?'selected':'' ?>><?= h($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="background:var(--border,#e2e8f0)"></div>
    <!-- Statut -->
    <div style="padding:14px 18px">
      <div style="font-size:12px;font-weight:700;color:var(--muted,#7f8c8d);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">Statut</div>
      <select name="statut" style="border:none;outline:none;font-size:13px;font-family:inherit;background:transparent;color:var(--text,#2c3e50);width:100%;cursor:pointer;padding:0"
        onchange="document.getElementById('fmes').submit()">
        <option value="">Tous les statuts</option>
        <option value="brouillon"  <?= $fStatut==='brouillon'?'selected':'' ?>>Brouillon</option>
        <option value="en_cours"   <?= $fStatut==='en_cours'?'selected':'' ?>>En cours</option>
        <option value="approuve"   <?= $fStatut==='approuve'?'selected':'' ?>>Approuvée</option>
        <option value="rejete"     <?= $fStatut==='rejete'?'selected':'' ?>>Rejetée</option>
      </select>
    </div>
  </form>
  <?php if ($has_filter): ?>
  <div style="margin:-10px 0 14px;font-size:12px">
    <a href="?" style="color:#e74c3c;text-decoration:none;font-weight:700"><i class="ph ph-x" aria-hidden="true"></i> Effacer les filtres</a>
  </div>
  <?php endif; ?>

  <?php if (empty($mes)): ?>
    <div class="di-card di-empty">
      <?php if ($has_filter): ?>
        <i class="ph-duotone ph-magnifying-glass" style="font-size:44px;color:#cbd5e1"></i>
        <p>Aucune demande ne correspond à votre recherche.</p>
        <a href="?" class="di-btn di-btn-ghost" style="margin-top:8px">Effacer les filtres</a>
      <?php else: ?>
        <i class="ph-duotone ph-tray" style="font-size:44px;color:#cbd5e1"></i>
        <?php if (di_peut_creer($user)): ?>
          <p>Vous n'avez pas encore de demande.</p>
          <a href="<?= APP_URL ?>/pages/demandes_new.php" class="di-btn di-btn-primary" style="margin-top:8px">Créer ma première demande</a>
        <?php else: ?>
          <p>Aucune demande à votre nom. Votre profil permet de valider celles des autres, pas d'en déposer.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <table class="di-tbl">
      <thead><tr>
        <th style="width:42px">N°</th><th>Réf.</th><th>Type</th><th>Demandeur</th><th>Statut</th><th>Étape</th><th>Date</th><th></th>
      </tr></thead>
      <tbody>
      <?php $rang = 0; foreach ($mes as $m): $rang++;
        $url = APP_URL.'/pages/demandes.php?id='.(int)$m['id'];
        $wf  = $wf_cache[$m['type_code']] ?? [];
        $idx = (int)($m['etape_actuelle'] ?? 0);
        if ($m['statut'] === 'approuve')            $etape_lbl = 'Approuvée';
        elseif ($m['statut'] === 'approuve_traitement') $etape_lbl = 'Traitement IT';
        elseif ($m['statut'] === 'rejete')   $etape_lbl = 'Rejetée';
        elseif ($m['statut'] === 'brouillon') $etape_lbl = 'Brouillon';
        else $etape_lbl = $wf[$idx]['label'] ?? '—';
      ?>
        <tr style="cursor:pointer" onclick="location.href='<?= $url ?>'">
          <td style="color:var(--muted,#7f8c8d);font-size:12px;font-weight:700"><?= $rang ?></td>
          <td style="font-weight:700;white-space:nowrap"><?= h($m['numero']) ?></td>
          <td><?= h($type_labels[$m['type_code']] ?? $m['type_code']) ?></td>
          <td style="font-size:13px"><?= h($m['demandeur_nom'] ?? '') ?></td>
          <td><?= di_badge(di_statut_effectif($m)) ?></td>
          <td><span style="font-size:12px;font-weight:600;padding:3px 9px;border-radius:8px;
            background:var(--input,#eef1fc);color:var(--navy,#06033A)"><?= h($etape_lbl) ?></span></td>
          <td style="color:var(--muted,#7f8c8d);font-size:13px;white-space:nowrap"><?= date('d M. Y', strtotime($m['created_at'])) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="<?= $url ?>" onclick="event.stopPropagation()"
               style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:8px;
                 font-size:12px;font-weight:700;background:var(--input,#f0f4f8);color:var(--navy,#06033A);
                 text-decoration:none;border:1.5px solid var(--border,#e2e8f0)">
              Voir
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>
</div>

<!-- Modal rejet -->
<div class="di-modal" id="di-reject">
  <div class="box">
    <h3 style="margin:0 0 12px">Motif de rejet</h3>
    <textarea id="di-motif" rows="3" placeholder="Expliquez la raison du rejet…"></textarea>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="di-btn di-btn-ghost" onclick="document.getElementById('di-reject').classList.remove('open')">Annuler</button>
      <button class="di-btn di-btn-danger" onclick="diRejeter()">Confirmer le rejet</button>
    </div>
  </div>
</div>

<script>
const DI_ID = <?= $detail ? (int)$detail['id'] : 0 ?>;
function diPost(fd, ok){
  fetch('<?= APP_URL ?>/pages/demandes.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
    .then(r=>r.json()).then(d=>{ if(d.success){ location.reload(); }
      else { const e=document.getElementById('di-err'); if(e){e.classList.add('err');e.textContent='⚠️ '+d.message;} else alert(d.message); } });
}
function diValider(){ const fd=new FormData(); fd.append('action','valider'); fd.append('id',DI_ID); diPost(fd); }
function diRejeter(){ const m=document.getElementById('di-motif').value.trim();
  if(!m){ alert('Le motif est obligatoire.'); return; }
  const fd=new FormData(); fd.append('action','rejeter'); fd.append('id',DI_ID); fd.append('motif',m); diPost(fd); }
function diTraiterIt(){
  const t=document.getElementById('di-ticket');
  const ticket=(t.value||'').trim();
  if(!ticket){ t.focus(); const e=document.getElementById('di-err');
    if(e){e.classList.add('err');e.textContent='⚠️ Renseignez le numéro du ticket GLPI.';}
    return; }
  const fd=new FormData();
  fd.append('action','traiter_it'); fd.append('id',DI_ID);
  fd.append('ticket_glpi',ticket);
  fd.append('commentaire',(document.getElementById('di-comm-it').value||'').trim());
  diPost(fd);
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
