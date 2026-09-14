<?php
// ============================================================
//  pages/demandes_a_valider.php — File d'attente du validateur
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/demandes.php';

require_auth();
require_permission('demandes', 'can_read');
$user = current_user();
$_SESSION['groupe_actif'] = 'DEMANDES';
$page_title  = 'Demandes à valider';
$active_page = 'demandes_valider';

// ── Calcul de la plage de dates selon la période choisie
$periode  = $_GET['periode'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';

$today = date('Y-m-d');
if ($periode === 'today') {
    $date_from = $date_to = $today;
} elseif ($periode === 'week') {
    $date_from = date('Y-m-d', strtotime('monday this week'));
    $date_to   = $today;
} elseif ($periode === 'month') {
    $date_from = date('Y-m-01');
    $date_to   = $today;
} elseif ($periode === '3months') {
    $date_from = date('Y-m-d', strtotime('-3 months'));
    $date_to   = $today;
} elseif ($periode === 'custom') {
    // on garde date_from / date_to tels quels depuis GET
}
// Si aucun filtre : pas de restriction de date
$fFrom = $date_from !== '' ? $date_from : null;
$fTo   = $date_to   !== '' ? $date_to   : null;

$my_roles    = di_user_roles((int)$user['id']);
$a_valider   = di_a_valider($user, $fFrom, $fTo);
$deja_traite = di_deja_traite($user, $fFrom, $fTo);
$can_it      = di_user_can_traiter_it((int)$user['id'], $my_roles);
$a_traiter   = $can_it ? di_a_traiter($user, $fFrom, $fTo) : [];
$type_labels = [];
foreach (di_types_actifs() as $t) $type_labels[$t['code']] = $t['label'];

// ── Filtres complémentaires
$fRef  = trim($_GET['ref'] ?? '');
$fType = trim($_GET['type'] ?? '');
$fDem  = trim($_GET['dem'] ?? '');
$fStat = trim($_GET['statut'] ?? '');

// Les listes déroulantes sont bâties sur le périmètre du validateur, avant
// filtrage : proposer une valeur qui ne renverrait rien n'aide personne, et
// une valeur absente de la liste ne serait plus sélectionnable.
$opt_types = $opt_dems = $opt_stats = [];
foreach (array_merge($a_valider, $deja_traite, $a_traiter) as $d) {
    $c = $d['type_code'] ?? '';
    if ($c !== '') $opt_types[$c] = $type_labels[$c] ?? $c;
    $dm = trim((string)($d['_demandeur'] ?? ''));
    if ($dm !== '') $opt_dems[$dm] = $dm;
    $st = $d['statut'] ?? '';
    if ($st !== '') $opt_stats[$st] = di_statut_label($st)[0];
}
asort($opt_types); asort($opt_dems); asort($opt_stats);

// La référence se cherche par fragment, le reste par égalité.
function di_filtre(array $lignes, string $ref, string $type, string $dem, string $stat): array {
    if ($ref === '' && $type === '' && $dem === '' && $stat === '') return $lignes;
    return array_values(array_filter($lignes, function ($d) use ($ref, $type, $dem, $stat) {
        if ($ref !== '' && stripos((string)($d['numero'] ?? ''), $ref) === false) return false;
        if ($type !== '' && ($d['type_code'] ?? '') !== $type) return false;
        if ($dem !== '' && trim((string)($d['_demandeur'] ?? '')) !== $dem) return false;
        if ($stat !== '' && ($d['statut'] ?? '') !== $stat) return false;
        return true;
    }));
}

$total_av = count($a_valider);
$total_dj = count($deja_traite);
$total_at = count($a_traiter);
$a_valider   = di_filtre($a_valider,   $fRef, $fType, $fDem, $fStat);
$deja_traite = di_filtre($deja_traite, $fRef, $fType, $fDem, $fStat);
$a_traiter   = di_filtre($a_traiter,   $fRef, $fType, $fDem, $fStat);
$filtre_actif = ($fRef !== '' || $fType !== '' || $fDem !== '' || $fStat !== '' || $fFrom || $fTo);

include __DIR__ . '/../templates/header.php';
?>
<style>
  .di-wrap{max-width:980px;margin:0 auto}
  .di-tbl{width:100%;border-collapse:collapse;background:var(--card,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden}
  .di-tbl th{text-align:left;padding:12px 16px;font-size:12px;color:var(--muted,#7f8c8d);background:var(--input,#f8fafc)}
  .di-tbl td{padding:12px 16px;border-top:1px solid var(--border,#eef2f7);font-size:14px}
  .di-tbl tr:hover td{background:var(--input,#f8fafc);cursor:pointer}
  .di-empty{text-align:center;padding:36px 20px;color:var(--muted,#7f8c8d);background:var(--card,#fff);
    border:1.5px solid var(--border,#e2e8f0);border-radius:16px}
  .di-chip{font-size:12px;font-weight:700;padding:3px 9px;border-radius:9px;background:#eef1fc;color:#3B4FBE}
  .di-chip.done{background:#eafaf1;color:#1f9d5b}
  .di-section{font-size:13px;font-weight:700;color:var(--navy,#06033A);margin:26px 0 10px;
    display:flex;align-items:center;gap:10px}
  .di-count{font-size:12px;font-weight:700;padding:2px 9px;border-radius:9px;background:#eef1fc;color:#3B4FBE}
  .di-count.done{background:#eafaf1;color:#1f9d5b}

  /* ── Filtres */
  .fbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    background:var(--card,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:12px;
    padding:12px 16px;margin-bottom:20px}
  .fbar-label{font-size:12px;font-weight:700;color:var(--muted,#7f8c8d);white-space:nowrap}
  .fpill{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;
    border:1.5px solid var(--border,#e2e8f0);background:var(--input,#f8fafc);color:var(--muted,#7f8c8d);
    text-decoration:none;transition:.15s}
  .fpill:hover,.fpill.active{background:#3B4FBE;color:#fff;border-color:#3B4FBE}
  .fdate{padding:7px 11px;border:1.5px solid var(--border,#d5dde8);border-radius:9px;
    font-size:13px;font-family:inherit;background:var(--input,#f8fafc);color:var(--text,#2c3e50)}
  .fsep{color:var(--muted,#cbd5e1);font-weight:300}
  .fbtn{padding:7px 16px;border:none;border-radius:9px;background:#3B4FBE;color:#fff;
    font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
  .fbtn-ghost{background:var(--input,#f0f4f8);color:var(--text,#2c3e50)}

  /* Seconde barre : filtres sur le contenu */
  .fbar-2{margin-top:-10px}
  .fgrp{display:flex;flex-direction:column;gap:4px;min-width:0}
  .fgrp .fbar-label{font-size:12px;text-transform:uppercase;letter-spacing:.4px}
  .ftxt{min-width:180px}
  .fsel{min-width:150px;max-width:230px;cursor:pointer;
    text-overflow:ellipsis;padding-right:26px;appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M2 4.5L6 8.5L10 4.5' stroke='%237f8c8d' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 9px center;background-size:11px}
  .fbar-2 .fbtn{align-self:flex-end}

  /* Au-delà de quatre lignes, la liste défile dans son cadre : la section
     suivante reste atteignable sans parcourir tout l'historique. */
  .di-scroll{max-height:calc(44px + 4 * 45px);overflow-y:auto;
    border:1.5px solid var(--border,#e2e8f0);border-radius:14px;background:var(--card,#fff)}
  .di-scroll .di-tbl{border:none;border-radius:0}
  .di-scroll .di-tbl thead th{position:sticky;top:0;z-index:1;
    background:var(--input,#f8fafc);box-shadow:0 1px 0 var(--border,#e2e8f0)}
  .di-scroll::-webkit-scrollbar{width:9px}
  .di-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:9px}
  .di-scroll::-webkit-scrollbar-track{background:transparent}
</style>

<div class="di-wrap">
  <div style="margin-bottom:16px">
    <h2 style="margin:0 0 3px">Demandes à valider</h2>
    <p style="color:var(--muted,#7f8c8d);margin:0;font-size:13px">
      <?php if (!$my_roles): ?>Aucun rôle de validation ne vous est attribué.
      <?php else: ?>Circuits : <?= h(implode(', ', $my_roles)) ?>.<?php endif; ?>
    </p>
  </div>

  <!-- ── Barre de filtres -->
  <form method="get" class="fbar" id="fform">
    <span class="fbar-label">Période</span>

    <?php
    // Changer de période ne doit pas effacer les filtres de contenu.
    $lien = function (array $over) use ($periode, $date_from, $date_to, $fRef, $fType, $fDem, $fStat) {
        $p = ['periode'=>$periode, 'date_from'=>$date_from, 'date_to'=>$date_to,
              'ref'=>$fRef, 'type'=>$fType, 'dem'=>$fDem, 'statut'=>$fStat];
        $p = array_merge($p, $over);
        $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
        return $p ? '?' . http_build_query($p) : '?';
    };
    $pills = [
        ''       => 'Tout',
        'today'  => "Aujourd'hui",
        'week'   => 'Cette semaine',
        'month'  => 'Ce mois',
        '3months'=> '3 derniers mois',
        'custom' => 'Personnalisé',
    ];
    foreach ($pills as $val => $lbl):
        $active = ($periode === $val) ? 'active' : '';
        // « Tout » remet aussi les dates à zéro.
        $href = $lien(['periode' => $val, 'date_from' => '', 'date_to' => '']);
    ?>
      <a href="<?= h($href) ?>" class="fpill <?= $active ?>"><?= $lbl ?></a>
    <?php endforeach; ?>

    <div id="custom-dates" style="display:<?= $periode === 'custom' ? 'flex' : 'none' ?>;align-items:center;gap:8px">
      <span class="fbar-label">Du</span>
      <input type="date" name="date_from" class="fdate" value="<?= h($date_from) ?>">
      <span class="fsep">au</span>
      <input type="date" name="date_to" class="fdate" value="<?= h($date_to) ?>">
      <input type="hidden" name="periode" value="custom">
      <?php // Les filtres de contenu voyagent avec, sinon choisir des dates les effacerait. ?>
      <?php if ($fRef  !== ''): ?><input type="hidden" name="ref"    value="<?= h($fRef)  ?>"><?php endif; ?>
      <?php if ($fType !== ''): ?><input type="hidden" name="type"   value="<?= h($fType) ?>"><?php endif; ?>
      <?php if ($fDem  !== ''): ?><input type="hidden" name="dem"    value="<?= h($fDem)  ?>"><?php endif; ?>
      <?php if ($fStat !== ''): ?><input type="hidden" name="statut" value="<?= h($fStat) ?>"><?php endif; ?>
      <button type="submit" class="fbtn">Appliquer</button>
    </div>
  </form>

  <!-- ── Filtres sur le contenu -->
  <form method="get" class="fbar fbar-2">
    <?php if ($periode !== ''): ?><input type="hidden" name="periode" value="<?= h($periode) ?>"><?php endif; ?>
    <?php if ($date_from !== ''): ?><input type="hidden" name="date_from" value="<?= h($date_from) ?>"><?php endif; ?>
    <?php if ($date_to !== ''): ?><input type="hidden" name="date_to" value="<?= h($date_to) ?>"><?php endif; ?>

    <div class="fgrp">
      <label class="fbar-label" for="f-ref">Référence</label>
      <input type="search" id="f-ref" name="ref" class="fdate ftxt" value="<?= h($fRef) ?>"
             placeholder="Numéro ou fragment" autocomplete="off">
    </div>

    <div class="fgrp">
      <label class="fbar-label" for="f-type">Type</label>
      <select id="f-type" name="type" class="fdate fsel">
        <option value="">Tous</option>
        <?php foreach ($opt_types as $code => $lbl): ?>
          <option value="<?= h($code) ?>"<?= $fType === $code ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fgrp">
      <label class="fbar-label" for="f-dem">Demandeur</label>
      <select id="f-dem" name="dem" class="fdate fsel">
        <option value="">Tous</option>
        <?php foreach ($opt_dems as $nom): ?>
          <option value="<?= h($nom) ?>"<?= $fDem === $nom ? ' selected' : '' ?>><?= h($nom) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fgrp">
      <label class="fbar-label" for="f-stat">Statut</label>
      <select id="f-stat" name="statut" class="fdate fsel">
        <option value="">Tous</option>
        <?php foreach ($opt_stats as $code => $lbl): ?>
          <option value="<?= h($code) ?>"<?= $fStat === $code ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="fbtn">Filtrer</button>
    <?php if ($filtre_actif): ?>
      <a href="?" class="fbtn fbtn-ghost" style="text-decoration:none;padding:7px 14px">Tout effacer</a>
    <?php endif; ?>
  </form>

  <?php if ($fFrom || $fTo): ?>
  <p style="font-size:12px;color:var(--muted,#7f8c8d);margin:-10px 0 16px">
    Filtré :
    <?php if ($fFrom && $fTo && $fFrom === $fTo): ?>
      <?= date('d/m/Y', strtotime($fFrom)) ?>
    <?php elseif ($fFrom && $fTo): ?>
      du <?= date('d/m/Y', strtotime($fFrom)) ?> au <?= date('d/m/Y', strtotime($fTo)) ?>
    <?php elseif ($fFrom): ?>
      à partir du <?= date('d/m/Y', strtotime($fFrom)) ?>
    <?php else: ?>
      jusqu'au <?= date('d/m/Y', strtotime($fTo)) ?>
    <?php endif; ?>
    — <a href="?" style="color:#3B4FBE">Effacer</a>
  </p>
  <?php endif; ?>

  <!-- ── En attente de mon visa -->
  <div class="di-section">
    En attente de mon visa
    <span class="di-count"><?= count($a_valider) ?><?= count($a_valider) < $total_av ? ' / ' . $total_av : '' ?></span>
  </div>

  <?php if (empty($a_valider)): ?>
    <div class="di-empty">
      <i class="ph-duotone ph-seal-check" style="font-size:40px;color:#cbd5e1"></i>
      <p style="margin:10px 0 0">Rien à valider<?= $filtre_actif ? ' avec ces filtres' : ' pour le moment' ?>.</p>
    </div>
  <?php else: ?>
    <div<?= count($a_valider) > 4 ? ' class="di-scroll"' : '' ?>>
    <table class="di-tbl">
      <thead><tr><th>Référence</th><th>Type</th><th>Demandeur</th><th>Étape</th><th>Soumise le</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($a_valider as $d): ?>
        <tr onclick="location.href='<?= APP_URL ?>/pages/demandes.php?id=<?= (int)$d['id'] ?>'">
          <td style="font-weight:700"><?= h($d['numero']) ?></td>
          <td><?= h($type_labels[$d['type_code']] ?? $d['type_code']) ?></td>
          <td><?= h($d['_demandeur'] ?? '') ?></td>
          <td><span class="di-chip"><?= h($d['_etape_label']) ?></span></td>
          <td style="color:var(--muted,#7f8c8d);font-size:13px"><?= $d['submitted_at'] ? date('d/m/Y', strtotime($d['submitted_at'])) : '—' ?></td>
          <td><?= di_badge(di_statut_effectif($d)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <!-- ── À traiter (IT) -->
  <?php if ($can_it): ?>
  <div class="di-section">
    À traiter (IT)
    <span class="di-count"><?= count($a_traiter) ?><?= count($a_traiter) < $total_at ? ' / ' . $total_at : '' ?></span>
  </div>

  <?php if (empty($a_traiter)): ?>
    <div class="di-empty">
      <i class="ph-duotone ph-check-circle" style="font-size:40px;color:#cbd5e1"></i>
      <p style="margin:10px 0 0">Rien à traiter<?= $filtre_actif ? ' avec ces filtres' : ' pour le moment' ?>.</p>
    </div>
  <?php else: ?>
    <div<?= count($a_traiter) > 4 ? ' class="di-scroll"' : '' ?>>
    <table class="di-tbl">
      <thead><tr><th>Référence</th><th>Type</th><th>Demandeur</th><th>Approuvée le</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($a_traiter as $d): ?>
        <tr onclick="location.href='<?= APP_URL ?>/pages/demandes.php?id=<?= (int)$d['id'] ?>'">
          <td style="font-weight:700"><?= h($d['numero']) ?></td>
          <td><?= h($type_labels[$d['type_code']] ?? $d['type_code']) ?></td>
          <td><?= h($d['_demandeur'] ?? '') ?></td>
          <td style="color:var(--muted,#7f8c8d);font-size:13px"><?= $d['updated_at'] ? date('d/m/Y', strtotime($d['updated_at'])) : '—' ?></td>
          <td><?= di_badge(di_statut_effectif($d)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ── Demandes où j'ai déjà visé -->
  <?php if (!empty($deja_traite)): ?>
  <div class="di-section">
    Demandes où j'ai déjà visé
    <span class="di-count done"><?= count($deja_traite) ?><?= count($deja_traite) < $total_dj ? ' / ' . $total_dj : '' ?></span>
  </div>
  <?php // Au-delà de quatre lignes la liste défile dans son cadre. ?>
  <div<?= count($deja_traite) > 4 ? ' class="di-scroll"' : '' ?>>
    <table class="di-tbl">
      <thead><tr><th>Référence</th><th>Type</th><th>Demandeur</th><th>Étape courante</th><th>Ticket GLPI</th><th>Soumise le</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($deja_traite as $d): ?>
        <tr onclick="location.href='<?= APP_URL ?>/pages/demandes.php?id=<?= (int)$d['id'] ?>'">
          <td style="font-weight:700"><?= h($d['numero']) ?></td>
          <td><?= h($type_labels[$d['type_code']] ?? $d['type_code']) ?></td>
          <td><?= h($d['_demandeur'] ?? '') ?></td>
          <td><span class="di-chip done"><?= h($d['_etape_label']) ?></span></td>
          <td style="font-size:13px;font-weight:700"><?= !empty($d['ticket_glpi']) ? h($d['ticket_glpi']) : '<span style="font-weight:400;color:var(--muted,#7f8c8d)">—</span>' ?></td>
          <td style="color:var(--muted,#7f8c8d);font-size:13px"><?= $d['submitted_at'] ? date('d/m/Y', strtotime($d['submitted_at'])) : '—' ?></td>
          <td><?= di_badge(di_statut_effectif($d)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($filtre_actif): ?>
  <div class="di-section">Demandes où j'ai déjà visé <span class="di-count done">0<?= $total_dj ? ' / ' . $total_dj : '' ?></span></div>
  <div class="di-empty">Aucune demande traitée ne correspond à ces filtres.</div>
  <?php endif; ?>

</div>

<script>
// Afficher/masquer la zone dates personnalisées
document.querySelectorAll('.fpill').forEach(function(a) {
  a.addEventListener('click', function(e) {
    if (this.getAttribute('href').includes('custom')) {
      e.preventDefault();
      document.getElementById('custom-dates').style.display = 'flex';
    } else {
      document.getElementById('custom-dates').style.display = 'none';
    }
  });
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
