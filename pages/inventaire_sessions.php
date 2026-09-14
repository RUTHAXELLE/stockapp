<?php
// ============================================================
//  pages/inventaire_sessions.php — Sessions d'inventaire mensuel
//  n° 19 réunion ERP : un responsable ouvre une session (période + sites),
//  les chefs de site saisissent pendant la fenêtre, le responsable suit
//  l'avancement puis clôture une fois tous les sites validés.
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/inventaire.php';

require_auth();
require_permission('inventaire_sessions', 'can_read');

$user        = current_user();
$page_title  = "Sessions d'inventaire";
$active_page = 'inventaire_sessions';

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'ouvrir') {
        $libelle = trim($_POST['libelle'] ?? '');
        $debut   = trim($_POST['date_debut'] ?? '');
        $periode = trim($_POST['type_periode'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');
        $sites   = $_POST['sites'] ?? [];
        if (!is_array($sites)) $sites = [];
        $sites = array_values(array_unique(array_map('intval', $sites)));

        if (!$debut) json_response(false, 'Date de début obligatoire.');
        if (!isset(INV_PERIODES[$periode])) json_response(false, 'Périodicité invalide.');
        if (empty($sites)) json_response(false, 'Choisissez au moins un site.');

        // La date de fin se déduit toujours de la périodicité choisie —
        // jamais saisie librement (mensuelle/trimestrielle/semestrielle/annuelle).
        $fin = inv_date_fin($debut, $periode);

        // Aucun site déjà couvert par une autre session ouverte : sinon un
        // inventaire créé ensuite ne saurait pas à quelle session se rattacher.
        $ph = implode(',', array_fill(0, count($sites), '?'));
        $conflits = db_fetch_all(
            "SELECT DISTINCT s.nom FROM inventaire_session_sites iss
             JOIN inventaire_sessions ise ON ise.id = iss.session_id
             JOIN sites s ON s.id = iss.site_id
             WHERE ise.statut = 'ouverte' AND iss.site_id IN ($ph)",
            $sites
        );
        if ($conflits) {
            $noms = implode(', ', array_column($conflits, 'nom'));
            json_response(false, "Déjà couvert par une session ouverte : $noms.");
        }

        // Colonne jamais vide : à défaut d'un libellé saisi par l'admin, on
        // en génère un à partir de la périodicité et de la date de début.
        $libelle = $libelle ?: inv_libelle_auto($debut, $periode);

        db_begin();
        try {
            db_query(
                "INSERT INTO inventaire_sessions (libelle, date_debut, date_fin, type_periode, notes, ouverte_par) VALUES (?,?,?,?,?,?)",
                [$libelle, $debut, $fin, $periode, $notes ?: null, $user['id']]
            );
            $session_id = (int)db_last_id();

            foreach ($sites as $site_id) {
                db_query("INSERT INTO inventaire_session_sites (session_id, site_id) VALUES (?,?)", [$session_id, $site_id]);
            }

            // Auto-provisionne l'inventaire de chaque site dès l'ouverture : le
            // chef de site le trouve déjà prêt à saisir, il n'a plus à créer
            // lui-même un "nouvel inventaire" ni à choisir une période.
            // Provisionne Bobines, Rivets, PMMA et Équipements — un site sans
            // stock/équipement de l'un des quatre ne bloque pas l'ouverture,
            // juste signalé à part.
            $sites_sans_bobine = [];
            $sites_sans_rivet  = [];
            $sites_sans_pmma   = [];
            $sites_sans_equip  = [];
            foreach ($sites as $site_id) {
                try {
                    inv_creer_mensuel($site_id, $debut, $session_id, $user['id']);
                } catch (Exception $e) {
                    $sites_sans_bobine[] = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]);
                }
                try {
                    inv_creer_rivets($site_id, $debut, $session_id, $user['id']);
                } catch (Exception $e) {
                    $sites_sans_rivet[] = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]);
                }
                try {
                    inv_creer_pmma($site_id, $debut, $session_id, $user['id']);
                } catch (Exception $e) {
                    $sites_sans_pmma[] = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]);
                }
                try {
                    inv_creer_equipements($site_id, $debut, $session_id, $user['id']);
                } catch (Exception $e) {
                    $sites_sans_equip[] = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]);
                }
            }

            // Notifier les coordinateurs des sites concernés
            $coords = db_fetch_all(
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                 WHERE r.slug='coordinateur_site' AND u.actif=1 AND u.site_id IN ($ph)",
                $sites
            );
            $periode_lbl = mb_strtolower(inv_periode_label($periode));
            foreach ($coords as $c) {
                db_query(
                    "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$c['id'], 'info', '📋 Session d\'inventaire ouverte',
                     "Une session d'inventaire $periode_lbl est ouverte du " . fmt_date($debut) . " au " . fmt_date($fin) . " pour votre site. Votre inventaire est déjà prêt : ouvrez-le directement depuis Inventaire bobines.",
                     '/pages/inventaire_bobines.php']
                );
            }

            audit_log($user['id'], 'CREATE', 'inventaire_sessions', $session_id,
                "Ouverture session inventaire ($periode) " . count($sites) . " site(s), du $debut au $fin"
                . ($sites_sans_bobine ? ' — sans bobine active : ' . implode(', ', $sites_sans_bobine) : '')
                . ($sites_sans_rivet ? ' — sans stock rivets : ' . implode(', ', $sites_sans_rivet) : '')
                . ($sites_sans_pmma ? ' — sans stock PMMA : ' . implode(', ', $sites_sans_pmma) : '')
                . ($sites_sans_equip ? ' — sans équipement actif : ' . implode(', ', $sites_sans_equip) : ''));
            db_commit();
            $manques = [];
            if ($sites_sans_bobine) $manques[] = 'aucune bobine active pour ' . implode(', ', $sites_sans_bobine);
            if ($sites_sans_rivet)  $manques[] = 'aucun stock de rivets pour ' . implode(', ', $sites_sans_rivet);
            if ($sites_sans_pmma)   $manques[] = 'aucun stock de PMMA pour ' . implode(', ', $sites_sans_pmma);
            if ($sites_sans_equip)  $manques[] = 'aucun équipement actif pour ' . implode(', ', $sites_sans_equip);
            $msg = $manques
                ? 'Session ouverte. Inventaire prêt pour chaque site, sauf : ' . implode(' ; ', $manques) . ' (à créer une fois le stock enregistré).'
                : 'Session ouverte — l\'inventaire est déjà prêt pour chaque site.';
            json_response(true, $msg, ['id' => $session_id]);
        } catch (Exception $e) { db_rollback(); json_response(false, 'Erreur : ' . $e->getMessage()); }
    }

    if ($action === 'cloturer') {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $session = db_fetch_one("SELECT * FROM inventaire_sessions WHERE id=?", [$session_id]);
        if (!$session) json_response(false, 'Session introuvable.');
        if ($session['statut'] !== 'ouverte') json_response(false, 'Cette session est déjà clôturée.');

        $non_clotures = db_fetch_all(
            "SELECT DISTINCT s.nom
             FROM inventaire_session_sites iss
             JOIN sites s ON s.id = iss.site_id
             LEFT JOIN inventaires_bobines ib ON ib.session_id = iss.session_id AND ib.site_id = iss.site_id AND ib.statut != 'annule'
             LEFT JOIN inventaires_rivets  ir ON ir.session_id = iss.session_id AND ir.site_id = iss.site_id AND ir.statut != 'annule'
             LEFT JOIN inventaires_pmma    ip ON ip.session_id = iss.session_id AND ip.site_id = iss.site_id AND ip.statut != 'annule'
             LEFT JOIN inventaires_equipements ie ON ie.session_id = iss.session_id AND ie.site_id = iss.site_id AND ie.statut != 'annule'
             WHERE iss.session_id = ?
               AND ((ib.id IS NULL OR ib.statut != 'valide') OR (ir.id IS NULL OR ir.statut != 'valide') OR (ip.id IS NULL OR ip.statut != 'valide') OR (ie.id IS NULL OR ie.statut != 'valide'))
             ORDER BY s.nom",
            [$session_id]
        );
        if ($non_clotures) {
            $noms = implode(', ', array_column($non_clotures, 'nom'));
            json_response(false, "Tous les sites ne sont pas encore clôturés (bobines, rivets, PMMA et équipements) : $noms.");
        }

        db_query("UPDATE inventaire_sessions SET statut='cloturee', cloturee_par=?, cloturee_at=NOW() WHERE id=?",
            [$user['id'], $session_id]);
        audit_log($user['id'], 'UPDATE', 'inventaire_sessions', $session_id, "Clôture session inventaire #$session_id");
        json_response(true, 'Session clôturée.');
    }

    json_response(false, 'Action inconnue.');
}

// ============================================================
//  DONNÉES
// ============================================================
$sites_list = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");
$detail_id  = (int)($_GET['id'] ?? 0);

if ($detail_id) {
    $session = db_fetch_one(
        "SELECT se.*, CONCAT(uo.prenom,' ',uo.nom) AS ouverte_par_nom, CONCAT(uc.prenom,' ',uc.nom) AS cloturee_par_nom
         FROM inventaire_sessions se
         LEFT JOIN users uo ON uo.id = se.ouverte_par
         LEFT JOIN users uc ON uc.id = se.cloturee_par
         WHERE se.id = ?", [$detail_id]
    );
    if (!$session) { header('Location: ' . APP_URL . '/pages/inventaire_sessions.php'); exit; }

    $sites_session = db_fetch_all(
        "SELECT s.id, s.nom,
                ib.id AS inv_id, ib.statut AS inv_statut, ib.date_inventaire,
                ib.nb_bobines, ib.nb_ecarts, ib.total_films_physique,
                ir.id AS riv_inv_id, ir.statut AS riv_statut, ir.nb_ecarts AS riv_nb_ecarts,
                ip.id AS pmma_inv_id, ip.statut AS pmma_statut, ip.nb_ecarts AS pmma_nb_ecarts,
                ie.id AS equip_inv_id, ie.statut AS equip_statut, ie.nb_ecarts AS equip_nb_ecarts
         FROM inventaire_session_sites iss
         JOIN sites s ON s.id = iss.site_id
         LEFT JOIN inventaires_bobines ib ON ib.session_id = iss.session_id AND ib.site_id = iss.site_id AND ib.statut != 'annule'
         LEFT JOIN inventaires_rivets  ir ON ir.session_id = iss.session_id AND ir.site_id = iss.site_id AND ir.statut != 'annule'
         LEFT JOIN inventaires_pmma    ip ON ip.session_id = iss.session_id AND ip.site_id = iss.site_id AND ip.statut != 'annule'
         LEFT JOIN inventaires_equipements ie ON ie.session_id = iss.session_id AND ie.site_id = iss.site_id AND ie.statut != 'annule'
         WHERE iss.session_id = ?
         ORDER BY s.nom", [$detail_id]
    );
    $nb_total    = count($sites_session);
    $nb_clotures = count(array_filter($sites_session, fn($s) => $s['inv_statut'] === 'valide' && $s['riv_statut'] === 'valide' && $s['pmma_statut'] === 'valide' && $s['equip_statut'] === 'valide'));
    $nb_en_cours = count(array_filter($sites_session, fn($s) => $s['inv_statut'] === 'brouillon' || $s['riv_statut'] === 'brouillon' || $s['pmma_statut'] === 'brouillon' || $s['equip_statut'] === 'brouillon'));
    $nb_attente  = $nb_total - $nb_clotures - $nb_en_cours;
    $peut_cloturer = $session['statut'] === 'ouverte' && $nb_total > 0 && $nb_clotures === $nb_total;
}

$sessions = db_fetch_all(
    "SELECT se.*, CONCAT(u.prenom,' ',u.nom) AS ouverte_par_nom,
            COUNT(iss.site_id) AS nb_sites,
            COUNT(*) FILTER (WHERE ib.statut = 'valide') AS nb_clotures
     FROM inventaire_sessions se
     LEFT JOIN users u ON u.id = se.ouverte_par
     LEFT JOIN inventaire_session_sites iss ON iss.session_id = se.id
     LEFT JOIN inventaires_bobines ib ON ib.session_id = se.id AND ib.site_id = iss.site_id AND ib.statut != 'annule'
     GROUP BY se.id, u.prenom, u.nom
     ORDER BY se.statut = 'ouverte' DESC, se.date_debut DESC
     LIMIT 100"
);

include __DIR__ . '/../templates/header.php';
?>
<style>
.ses-kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}
.ses-k{background:white;border:1px solid var(--border);border-radius:14px;padding:16px 20px;border-left:4px solid var(--blue)}
.ses-k.green{border-left-color:var(--success)} .ses-k.orange{border-left-color:#f39c12}
.ses-k-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:26px;font-weight:900;color:var(--navy);line-height:1}
.ses-k-lbl{font-size:12px;color:var(--muted);margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.statut-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.statut-ouverte{background:#e8f4f9;color:#1565c0} .statut-cloturee{background:#d5f5e3;color:#1e7e40}
.site-statut{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.site-statut.non_commence{background:#f1f5f9;color:#64748b}
.site-statut.en_cours{background:#fef9e7;color:#9a6c00}
.site-statut.cloture{background:#d5f5e3;color:#1e7e40}
.site-picker{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;padding:12px;background:#f8fafc}
.site-chip{display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 10px;border-radius:8px;background:white;border:1px solid var(--border);cursor:pointer}
.site-chip:has(input:checked){border-color:var(--blue);background:#eaf2fb}
</style>

<?php if ($detail_id && isset($session)): ?>
  <!-- ═══════════ DÉTAIL D'UNE SESSION ═══════════ -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <a href="<?= APP_URL ?>/pages/inventaire_sessions.php" style="color:var(--muted);font-size:13px;text-decoration:none">← Sessions</a>
      <h2 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:19px;font-weight:800;color:var(--navy);margin-top:4px">
        <?= h($session['libelle'] ?: 'Session du ' . fmt_date($session['date_debut'])) ?>
        <span class="statut-badge statut-<?= $session['statut'] ?>"><?= $session['statut']==='ouverte'?'⏳ Ouverte':'<i class="ph ph-check-circle" aria-hidden="true"></i> Clôturée' ?></span>
      </h2>
      <p style="font-size:13px;color:var(--muted);margin-top:4px">
        <?= h(inv_periode_label($session['type_periode'] ?? 'mensuel')) ?>
        · Du <?= fmt_date($session['date_debut']) ?> au <?= fmt_date($session['date_fin']) ?>
        · Ouverte par <?= h($session['ouverte_par_nom'] ?? '—') ?>
        <?php if ($session['statut'] === 'cloturee'): ?>
        · Clôturée par <?= h($session['cloturee_par_nom'] ?? '—') ?> le <?= fmt_datetime($session['cloturee_at']) ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($peut_cloturer): ?>
    <button class="btn btn-success" onclick="cloturerSession(<?= $session['id'] ?>)"><i class="ph ph-check-circle" aria-hidden="true"></i> Clôturer la session</button>
    <?php elseif ($session['statut'] === 'ouverte'): ?>
    <span style="font-size:12.5px;color:var(--muted)"><i class="ph ph-lock" aria-hidden="true"></i> Clôture possible une fois tous les sites clôturés</span>
    <?php endif; ?>
  </div>

  <div class="ses-kpi">
    <div class="ses-k"><div class="ses-k-val"><?= $nb_total ?></div><div class="ses-k-lbl">Sites concernés</div></div>
    <div class="ses-k orange"><div class="ses-k-val" style="color:#f39c12"><?= $nb_en_cours + $nb_attente ?></div><div class="ses-k-lbl">En cours / non commencés</div></div>
    <div class="ses-k green"><div class="ses-k-val" style="color:var(--success-d)"><?= $nb_clotures ?></div><div class="ses-k-lbl">Clôturés</div></div>
  </div>

  <div class="card">
    <div class="card-header"><h3><i class="ph ph-chart-bar" aria-hidden="true"></i> Récapitulatif par site</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Site</th>
          <th>Bobines</th><th style="text-align:center">Écarts</th>
          <th>Rivets</th><th style="text-align:center">Écarts</th>
          <th>PMMA</th><th style="text-align:center">Écarts</th>
          <th>Équipements</th><th style="text-align:center">Manquants</th>
          <th style="text-align:center">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($sites_session as $s):
          $st = $s['inv_statut'] === 'valide' ? 'cloture' : ($s['inv_statut'] === 'brouillon' ? 'en_cours' : 'non_commence');
          $st_lbl = ['non_commence'=>'⏳ Non commencé','en_cours'=>'<i class="ph ph-note-pencil" aria-hidden="true"></i> En cours','cloture'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Clôturé'][$st];
          $riv_st = $s['riv_statut'] === 'valide' ? 'cloture' : ($s['riv_statut'] === 'brouillon' ? 'en_cours' : 'non_commence');
          $riv_st_lbl = ['non_commence'=>'⏳ Non commencé','en_cours'=>'<i class="ph ph-note-pencil" aria-hidden="true"></i> En cours','cloture'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Clôturé'][$riv_st];
          $pmma_st = $s['pmma_statut'] === 'valide' ? 'cloture' : ($s['pmma_statut'] === 'brouillon' ? 'en_cours' : 'non_commence');
          $pmma_st_lbl = ['non_commence'=>'⏳ Non commencé','en_cours'=>'<i class="ph ph-note-pencil" aria-hidden="true"></i> En cours','cloture'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Clôturé'][$pmma_st];
          $equip_st = $s['equip_statut'] === 'valide' ? 'cloture' : ($s['equip_statut'] === 'brouillon' ? 'en_cours' : 'non_commence');
          $equip_st_lbl = ['non_commence'=>'⏳ Non commencé','en_cours'=>'<i class="ph ph-note-pencil" aria-hidden="true"></i> En cours','cloture'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Clôturé'][$equip_st];
        ?>
          <tr>
            <td style="font-weight:700"><?= h($s['nom']) ?></td>
            <td><span class="site-statut <?= $st ?>"><?= $st_lbl ?></span></td>
            <td style="text-align:center">
              <?php if (($s['nb_ecarts'] ?? 0) > 0): ?>
              <span style="font-weight:800;color:#e74c3c"><?= $s['nb_ecarts'] ?></span>
              <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td><span class="site-statut <?= $riv_st ?>"><?= $riv_st_lbl ?></span></td>
            <td style="text-align:center">
              <?php if (($s['riv_nb_ecarts'] ?? 0) > 0): ?>
              <span style="font-weight:800;color:#e74c3c"><?= $s['riv_nb_ecarts'] ?></span>
              <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td><span class="site-statut <?= $pmma_st ?>"><?= $pmma_st_lbl ?></span></td>
            <td style="text-align:center">
              <?php if (($s['pmma_nb_ecarts'] ?? 0) > 0): ?>
              <span style="font-weight:800;color:#e74c3c"><?= $s['pmma_nb_ecarts'] ?></span>
              <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td><span class="site-statut <?= $equip_st ?>"><?= $equip_st_lbl ?></span></td>
            <td style="text-align:center">
              <?php if (($s['equip_nb_ecarts'] ?? 0) > 0): ?>
              <span style="font-weight:800;color:#e74c3c"><?= $s['equip_nb_ecarts'] ?></span>
              <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td style="text-align:center;white-space:nowrap;display:flex;gap:4px;justify-content:center">
              <?php if ($s['inv_id']): ?>
              <a href="<?= APP_URL ?>/pages/inventaire_detail.php?id=<?= $s['inv_id'] ?>" class="btn btn-secondary btn-sm"><i class="ph ph-film-strip" aria-hidden="true"></i> Bobines</a>
              <?php endif; ?>
              <?php if ($s['riv_inv_id']): ?>
              <a href="<?= APP_URL ?>/pages/inventaire_detail_rivets.php?id=<?= $s['riv_inv_id'] ?>" class="btn btn-secondary btn-sm"><i class="ph ph-nut" aria-hidden="true"></i> Rivets</a>
              <?php endif; ?>
              <?php if ($s['pmma_inv_id']): ?>
              <a href="<?= APP_URL ?>/pages/inventaire_detail_pmma.php?id=<?= $s['pmma_inv_id'] ?>" class="btn btn-secondary btn-sm"><i class="ph ph-printer" aria-hidden="true"></i> PMMA</a>
              <?php endif; ?>
              <?php if ($s['equip_inv_id']): ?>
              <a href="<?= APP_URL ?>/pages/inventaire_detail_equipements.php?id=<?= $s['equip_inv_id'] ?>" class="btn btn-secondary btn-sm"><i class="ph ph-desktop" aria-hidden="true"></i> Équip.</a>
              <?php endif; ?>
              <?php if (!$s['inv_id'] && !$s['riv_inv_id'] && !$s['pmma_inv_id'] && !$s['equip_inv_id']): ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>
  <!-- ═══════════ LISTE DES SESSIONS ═══════════ -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:19px;font-weight:800;color:var(--navy)"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Sessions d'inventaire</h2>
      <p style="font-size:13px;color:var(--muted);margin-top:2px">Encadrer les campagnes d'inventaire mensuel, site par site.</p>
    </div>
    <button class="btn btn-primary" onclick="ouvrirModal()">+ Ouvrir une session</button>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Périodicité</th><th>Dates</th><th>Libellé</th><th style="text-align:center">Sites</th>
          <th style="text-align:center">Avancement</th><th>Statut</th><th>Ouverte par</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($sessions)): ?>
          <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Aucune session pour le moment.</td></tr>
        <?php else: foreach ($sessions as $s): ?>
          <tr>
            <td style="font-weight:600;white-space:nowrap"><?= h(inv_periode_label($s['type_periode'] ?? 'mensuel')) ?></td>
            <td style="font-weight:600;white-space:nowrap"><?= fmt_date($s['date_debut']) ?> → <?= fmt_date($s['date_fin']) ?></td>
            <td><?= h($s['libelle'] ?: '—') ?></td>
            <td style="text-align:center;font-weight:700"><?= $s['nb_sites'] ?></td>
            <td style="text-align:center"><?= $s['nb_clotures'] ?> / <?= $s['nb_sites'] ?></td>
            <td><span class="statut-badge statut-<?= $s['statut'] ?>"><?= $s['statut']==='ouverte'?'⏳ Ouverte':'<i class="ph ph-check-circle" aria-hidden="true"></i> Clôturée' ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= h($s['ouverte_par_nom'] ?? '—') ?></td>
            <td><a href="?id=<?= $s['id'] ?>" class="btn btn-secondary btn-sm"><i class="ph ph-eye" aria-hidden="true"></i> Voir</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- MODAL OUVERTURE -->
  <div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
    <div style="background:white;border-radius:20px;padding:28px;width:560px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:800;color:var(--navy)"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Ouvrir une session d'inventaire</h3>
        <button onclick="fermer()" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
      </div>
      <div id="mAlert"></div>
      <div class="form-group" style="margin-bottom:14px"><label>Libellé (optionnel)</label>
        <input type="text" class="form-control" id="fLibelle" placeholder="Laissé vide = généré automatiquement (ex. Inventaire mensuel — août 2026)">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:8px">
        <div class="form-group"><label>Du *</label><input type="date" class="form-control" id="fDebut" value="<?= date('Y-m-d') ?>" onchange="majFin()"></div>
        <div class="form-group"><label>Périodicité *</label>
          <select class="form-control" id="fPeriode" onchange="majFin()">
            <option value="mensuel">Mensuelle (1 mois)</option>
            <option value="trimestriel">Trimestrielle (3 mois)</option>
            <option value="semestriel">Semestrielle (6 mois)</option>
            <option value="annuel">Annuelle (12 mois)</option>
          </select>
        </div>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label>Fin de la période <span style="font-weight:400;color:var(--muted)">(calculée)</span></label>
        <input type="text" class="form-control" id="fFinAffiche" disabled style="background:#f1f5f9;color:var(--muted)">
      </div>
      <div class="form-group" style="margin-bottom:10px">
        <label style="display:flex;align-items:center;justify-content:space-between">
          <span>Sites concernés *</span>
          <span style="font-weight:400"><label style="font-size:12.5px;font-weight:400;cursor:pointer"><input type="checkbox" id="fTousSites" onchange="toggleTousSites(this.checked)"> Tous les sites</label></span>
        </label>
        <div class="site-picker" id="sitePicker">
          <?php foreach ($sites_list as $s): ?>
          <label class="site-chip"><input type="checkbox" class="site-cb" value="<?= $s['id'] ?>"> <?= h($s['nom']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-group" style="margin-bottom:20px"><label>Notes</label><textarea class="form-control" id="fNotes" rows="2"></textarea></div>
      <div style="display:flex;justify-content:flex-end;gap:10px">
        <button class="btn btn-secondary" onclick="fermer()">Annuler</button>
        <button class="btn btn-primary" id="btnOuvrir" onclick="ouvrirSession()"><i class="ph ph-rocket-launch" aria-hidden="true"></i> Ouvrir la session</button>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
function ap(d){
  const p=new URLSearchParams();
  for(const k in d){
    const v=d[k];
    if(Array.isArray(v)) v.forEach(x=>p.append(k+'[]',x));
    else p.append(k,v);
  }
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:p}).then(r=>r.json());
}
function toast(m,t='success'){let el=document.getElementById('toast-live');if(!el){el=document.createElement('div');el.id='toast-live';el.setAttribute('role','status');el.setAttribute('aria-live','polite');el.setAttribute('aria-atomic','true');document.body.appendChild(el);}clearTimeout(el._hideTimer);el.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;background:${t==='success'?'#27ae60':'#e74c3c'};color:white;box-shadow:0 4px 20px rgba(0,0,0,.15)`;el.textContent=m;el._hideTimer=setTimeout(()=>{el.style.display='none';},3500);}

<?php if (!$detail_id): ?>
const DUREES_MOIS = {mensuel:1, trimestriel:3, semestriel:6, annuel:12};
function majFin(){
  const debut = document.getElementById('fDebut').value;
  const periode = document.getElementById('fPeriode').value;
  const affiche = document.getElementById('fFinAffiche');
  if(!debut){affiche.value='';return;}
  const d = new Date(debut+'T00:00:00');
  d.setMonth(d.getMonth() + DUREES_MOIS[periode]);
  d.setDate(d.getDate() - 1);
  affiche.value = d.toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'});
}
function ouvrirModal(){
  document.getElementById('fLibelle').value='';
  document.getElementById('fDebut').value='<?= date('Y-m-d') ?>';
  document.getElementById('fPeriode').value='mensuel';
  document.getElementById('fNotes').value='';
  document.getElementById('fTousSites').checked=false;
  document.querySelectorAll('.site-cb').forEach(cb=>cb.checked=false);
  document.getElementById('mAlert').innerHTML='';
  majFin();
  document.getElementById('modal').style.display='flex';
}
function fermer(){document.getElementById('modal').style.display='none';}
document.getElementById('modal').addEventListener('click',e=>{if(e.target===document.getElementById('modal'))fermer();});
function toggleTousSites(checked){document.querySelectorAll('.site-cb').forEach(cb=>cb.checked=checked);}
async function ouvrirSession(){
  const sites=[...document.querySelectorAll('.site-cb:checked')].map(cb=>cb.value);
  const debut=document.getElementById('fDebut').value, periode=document.getElementById('fPeriode').value;
  const alertEl=document.getElementById('mAlert');
  if(!debut){alertEl.innerHTML='<div class="alert alert-danger">Date de début obligatoire.</div>';return;}
  if(!sites.length){alertEl.innerHTML='<div class="alert alert-danger">Choisissez au moins un site.</div>';return;}
  const btn=document.getElementById('btnOuvrir');btn.disabled=true;btn.textContent='⏳...';
  const d=await ap({action:'ouvrir',libelle:document.getElementById('fLibelle').value,date_debut:debut,type_periode:periode,
    notes:document.getElementById('fNotes').value, sites});
  btn.disabled=false;btn.textContent='🚀 Ouvrir la session';
  if(d.success){toast(d.message);setTimeout(()=>location.href='?id='+d.data.id,600);}
  else alertEl.innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}
<?php else: ?>
async function cloturerSession(id){
  if(!confirm('Clôturer cette session ? Cette action est définitive.'))return;
  const d=await ap({action:'cloturer',session_id:id});
  if(d.success){toast(d.message);setTimeout(()=>location.reload(),700);}else toast(d.message,'error');
}
<?php endif; ?>
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
