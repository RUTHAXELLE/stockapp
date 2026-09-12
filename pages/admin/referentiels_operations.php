<?php
// ============================================================
//  pages/admin/referentiels_operations.php
//  Capacités de conditionnement — films par bobine, unités par carton PMMA
//
//  Ces valeurs étaient en dur dans le code (500 dans l'INSERT de création
//  de bobine, « WSL/TL = 2000 sinon 500 » recopié dans les deux imports,
//  rien du tout pour le PMMA). Un changement de conditionnement
//  fournisseur demandait donc un déploiement ; il se fait maintenant ici.
//
//  Écran d'administration et pas de paramétrage d'exploitation : une
//  capacité fausse ne se voit pas, elle se propage silencieusement à
//  toutes les projections et à tout stock créé ensuite.
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/referentiels.php';

require_auth();
require_permission('referentiels_operations', 'can_read');

$user        = current_user();
$page_title  = 'Référentiels & capacités';
$active_page = 'referentiels_operations';
$can_edit    = can('referentiels_operations', 'can_update');
$can_create  = can('referentiels_operations', 'can_create');

// ── AJAX ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Une capacité nulle ou négative n'a pas de sens et diviserait par
    // zéro dans les projections ; le plafond n'est là que pour arrêter
    // une faute de frappe évidente.
    $lire_capacite = function (string $champ, int $max) {
        $v = $_POST[$champ] ?? '';
        if ($v === '' || !is_numeric($v)) return null;
        $v = (int)$v;
        return ($v >= 1 && $v <= $max) ? $v : null;
    };

    // ── Capacité d'une série entière de bobines
    // Le conditionnement dépend de la série, pas de la version : imposer
    // 12 saisies pour les 6 versions d'une série serait une invitation à
    // l'incohérence. L'édition par type reste possible juste en dessous.
    if ($action === 'serie_bobine') {
        if (!$can_edit) json_response(false, 'Action réservée.');
        $serie = trim($_POST['serie'] ?? '');
        $cap   = $lire_capacite('films_par_bobine', 100000);
        if ($serie === '')  json_response(false, 'Série manquante.');
        if ($cap === null)  json_response(false, 'Capacité invalide (1 à 100 000 films).');
        $n = db_fetch_value("SELECT COUNT(*) FROM op_types_bobines WHERE TRIM(serie)=?", [$serie]);
        if (!$n) json_response(false, "Série « $serie » inconnue.");
        db_query("UPDATE op_types_bobines SET films_par_bobine=? WHERE TRIM(serie)=?", [$cap, $serie]);
        audit_log($user['id'], 'UPDATE', 'referentiels_operations', 0,
                  "Capacité série $serie → $cap films/bobine ($n type(s))");
        json_response(true, "Série $serie : $n type(s) mis à $cap films par bobine.");
    }

    if ($action === 'type_bobine') {
        if (!$can_edit) json_response(false, 'Action réservée.');
        $id  = (int)($_POST['id'] ?? 0);
        $cap = $lire_capacite('films_par_bobine', 100000);
        if ($cap === null) json_response(false, 'Capacité invalide (1 à 100 000 films).');
        $t = db_fetch_one("SELECT code, films_par_bobine FROM op_types_bobines WHERE id=?", [$id]);
        if (!$t) json_response(false, 'Type introuvable.');
        db_query("UPDATE op_types_bobines SET films_par_bobine=? WHERE id=?", [$cap, $id]);
        audit_log($user['id'], 'UPDATE', 'referentiels_operations', $id,
                  "Capacité type {$t['code']} : {$t['films_par_bobine']} → $cap films/bobine");
        json_response(true, "{$t['code']} : $cap films par bobine.");
    }

    if ($action === 'type_pmma') {
        if (!$can_edit) json_response(false, 'Action réservée.');
        $id      = (int)($_POST['id'] ?? 0);
        $libelle = trim($_POST['libelle'] ?? '');
        $serie   = strtoupper(trim($_POST['serie'] ?? ''));
        $cap     = $lire_capacite('unites_par_carton', 10000);
        $seuil   = (int)($_POST['seuil_defaut'] ?? 0);
        $actif   = !empty($_POST['actif']) ? 1 : 0;
        if (!$libelle)     json_response(false, 'Libellé obligatoire.');
        if ($cap === null) json_response(false, 'Contenu du carton invalide (1 à 10 000 unités).');
        if ($seuil < 0)    json_response(false, 'Le seuil ne peut pas être négatif.');
        if ($serie !== '' && !in_array($serie, ['A','B','C','D'], true))
            json_response(false, 'Série attendue : A, B, C ou D (ou vide).');
        $t = db_fetch_one("SELECT code, unites_par_carton FROM op_types_pmma WHERE id=?", [$id]);
        if (!$t) json_response(false, 'Type introuvable.');
        // `code` n'est volontairement pas modifiable : c'est la valeur qui
        // relie ce catalogue à stock_pmma_site et à tout l'historique de
        // consommation. La renommer ici détacherait le stock existant.
        db_query("UPDATE op_types_pmma SET libelle=?, serie=?, unites_par_carton=?, seuil_defaut=?, actif=? WHERE id=?",
                 [$libelle, $serie !== '' ? $serie : null, $cap, $seuil, $actif, $id]);
        audit_log($user['id'], 'UPDATE', 'referentiels_operations', $id,
                  "PMMA {$t['code']} : carton {$t['unites_par_carton']} → $cap unités");
        json_response(true, "{$t['code']} : carton de $cap unités.");
    }

    if ($action === 'creer_pmma') {
        if (!$can_create) json_response(false, 'Action réservée.');
        $code    = trim($_POST['code'] ?? '');
        $libelle = trim($_POST['libelle'] ?? '');
        $serie   = strtoupper(trim($_POST['serie'] ?? ''));
        $cap     = $lire_capacite('unites_par_carton', 10000);
        if ($code === '')  json_response(false, 'Le code est obligatoire.');
        if ($cap === null) json_response(false, 'Contenu du carton invalide (1 à 10 000 unités).');
        if ($serie !== '' && !in_array($serie, ['A','B','C','D'], true))
            json_response(false, 'Série attendue : A, B, C ou D (ou vide).');
        if (db_fetch_value("SELECT COUNT(*) FROM op_types_pmma WHERE code=?", [$code]) > 0)
            json_response(false, "Le type « $code » existe déjà.");
        db_query("INSERT INTO op_types_pmma (code, libelle, serie, unites_par_carton, seuil_defaut, actif)
                  VALUES (?,?,?,?,?,1)",
                 [$code, $libelle !== '' ? $libelle : $code, $serie !== '' ? $serie : null, $cap,
                  max(0, (int)($_POST['seuil_defaut'] ?? 10))]);
        $id = (int)db_last_id();
        audit_log($user['id'], 'CREATE', 'referentiels_operations', $id,
                  "Création type PMMA $code — carton de $cap unités");
        json_response(true, "Type « $code » créé.");
    }

    // ── Defaut d'ouverture du dashboard KPI, par role
    // Ce sur quoi un utilisateur tombe la premiere fois, avant d'avoir
    // touche le moindre filtre. C'est la que se joue le « qu'est-ce qui
    // me concerne » de PRODUCT.md : un coordinateur veut sa journee, la
    // direction son mois.
    if ($action === 'defaut_role') {
        if (!$can_edit) json_response(false, 'Action réservée.');
        $role_id = (int)($_POST['role_id'] ?? 0);
        $cle     = trim($_POST['cle'] ?? '');
        $valeur  = trim($_POST['valeur'] ?? '');
        $connus  = ['kpi_dashboard.periode' =>
                        ['journalier','hebdomadaire','mensuel','annuel']];
        if (!isset($connus[$cle]))  json_response(false, 'Réglage inconnu.');
        $r = db_fetch_one("SELECT nom FROM roles WHERE id = ?", [$role_id]);
        if (!$r) json_response(false, 'Rôle introuvable.');

        // Valeur vide = pas de defaut pour ce role : on supprime la ligne
        // plutot que d'enregistrer une chaine vide, qui obligerait chaque
        // lecture a distinguer « vide » de « absent ».
        if ($valeur === '') {
            db_query("DELETE FROM defauts_affichage WHERE role_id = ? AND cle = ?", [$role_id, $cle]);
            audit_log($user['id'], 'DELETE', 'referentiels_operations', $role_id,
                      "Défaut $cle retiré pour {$r['nom']}");
            json_response(true, "{$r['nom']} : plus de défaut, l'écran ouvre sur sa valeur standard.");
        }
        if (!in_array($valeur, $connus[$cle], true)) json_response(false, 'Valeur non autorisée.');
        db_query("INSERT INTO defauts_affichage (role_id, cle, valeur) VALUES (?,?,?)
                  ON CONFLICT (role_id, cle)
                  DO UPDATE SET valeur = EXCLUDED.valeur, updated_at = CURRENT_TIMESTAMP",
            [$role_id, $cle, $valeur]);
        audit_log($user['id'], 'UPDATE', 'referentiels_operations', $role_id,
                  "Défaut $cle = $valeur pour {$r['nom']}");
        json_response(true, "{$r['nom']} : ouverture en « $valeur ».");
    }

    json_response(false, 'Action inconnue.');
}

// ── DONNÉES ─────────────────────────────────────────────────
// format et version viennent d'une autre migration
// (migration_types_bobines_format_version.sql). Cet écran ne doit pas en
// dépendre : sur une base où elle n'est pas passée, il se rabat sur la
// série, dont le format se déduit 1:1, et sur le dernier chiffre du code
// pour la version — c'est exactement le mapping qu'appliquait cette
// migration.
// Le mapping lui-même vit dans includes/referentiels.php : le recopier ici
// aurait refait exactement la duplication que cet écran vient supprimer.
try {
    $types_bobines = db_fetch_all(
        "SELECT id, code, libelle, TRIM(serie) AS serie, format, version, films_par_bobine, actif
           FROM op_types_bobines
          ORDER BY TRIM(serie), code");
} catch (Throwable $e) {
    $types_bobines = db_fetch_all(
        "SELECT id, code, libelle, TRIM(serie) AS serie, films_par_bobine, actif
           FROM op_types_bobines
          ORDER BY TRIM(serie), code");
}
foreach ($types_bobines as $i => $t) {
    if (($t['format'] ?? '') === '')
        $types_bobines[$i]['format'] = libelle_format_serie($t['serie']);
    if (($t['version'] ?? '') === '')
        $types_bobines[$i]['version'] = libelle_version_code($t['code']);
}

$series = [];
foreach ($types_bobines as $t) {
    $s = $t['serie'];
    if (!isset($series[$s])) {
        $series[$s] = ['format' => $t['format'] ?? '—', 'types' => [], 'caps' => []];
    }
    $series[$s]['types'][] = $t;
    $series[$s]['caps'][(int)$t['films_par_bobine']] = true;
}

$types_pmma = db_fetch_all(
    "SELECT p.id, p.code, p.libelle, p.serie, p.unites_par_carton, p.seuil_defaut, p.actif,
            COALESCE(s.qte, 0) AS stock_actuel, COALESCE(s.sites, 0) AS nb_sites
       FROM op_types_pmma p
       LEFT JOIN (SELECT TRIM(type_pmma) AS t, SUM(quantite) AS qte, COUNT(*) AS sites
                    FROM stock_pmma_site GROUP BY TRIM(type_pmma)) s ON s.t = p.code
      ORDER BY p.code");

// Types présents dans les stocks mais absents du catalogue : ils tomberaient
// sur la capacité par défaut sans qu'on le sache. Mieux vaut les afficher.
$orphelins = db_fetch_all(
    "SELECT TRIM(type_pmma) AS code, SUM(quantite) AS qte
       FROM stock_pmma_site
      WHERE COALESCE(TRIM(type_pmma),'') <> ''
        AND TRIM(type_pmma) NOT IN (SELECT code FROM op_types_pmma)
      GROUP BY TRIM(type_pmma) ORDER BY 1");

// ── Defauts d'ouverture par role
$roles_defauts = [];
try {
    $roles_defauts = db_fetch_all(
        "SELECT r.id, r.nom, r.slug,
                (SELECT d.valeur FROM defauts_affichage d
                  WHERE d.role_id = r.id AND d.cle = 'kpi_dashboard.periode') AS periode,
                (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.actif = 1) AS nb
           FROM roles r ORDER BY r.nom");
} catch (Throwable $e) {
    // Migration des preferences pas encore passee : l'onglet reste vide
    // plutot que de faire tomber les deux autres.
}

include __DIR__ . '/../../templates/header.php';
?>
<style>
.ref-intro{background:white;border:1px solid var(--border);border-radius:14px;
  padding:16px 20px;margin-bottom:18px;font-size:13.5px;color:var(--muted);line-height:1.6}
.ref-onglets{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.ref-onglet{border:1.5px solid var(--border);background:white;border-radius:10px;
  padding:9px 16px;font-family:inherit;font-size:13.5px;font-weight:700;
  color:var(--navy);cursor:pointer}
.ref-onglet.on{border-color:var(--primary-d);background:var(--primary-l);color:var(--primary-d)}
.ref-card{background:white;border:1px solid var(--border);border-radius:14px;
  padding:18px 20px;margin-bottom:16px}
.ref-card h4{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:800;
  color:var(--navy);margin:0 0 3px}
.ref-card .sub{font-size:12.5px;color:var(--muted);margin-bottom:14px;line-height:1.5}
table.ref-t{width:100%;border-collapse:collapse;font-size:13.5px}
table.ref-t th{text-align:left;font-size:10.5px;font-weight:700;letter-spacing:.06em;
  text-transform:uppercase;color:var(--muted);padding:0 10px 8px 0;
  border-bottom:1px solid var(--border);white-space:nowrap}
table.ref-t td{padding:10px 10px 10px 0;border-bottom:1px solid var(--border);color:var(--navy)}
table.ref-t tr:last-child td{border-bottom:none}
table.ref-t td.n,table.ref-t th.n{text-align:right}
.ref-serie{font-family:'IBM Plex Mono',monospace;font-weight:800;color:var(--primary-d)}
.ref-inp{width:110px;padding:7px 9px;border:1.5px solid var(--border);border-radius:8px;
  font-size:13px;text-align:right;font-variant-numeric:tabular-nums;box-sizing:border-box}
.ref-inp.txt{text-align:left;width:100%;min-width:120px}
.ref-inp.mini{width:64px}
.ref-multi{color:var(--warning-d,#92400e);font-size:11.5px;font-weight:700}
.ref-lien{border:none;background:none;padding:0;font-family:inherit;font-size:12px;
  font-weight:700;color:var(--primary-d);cursor:pointer;text-decoration:underline}
.ref-detail{background:var(--lighter);border-radius:10px;padding:12px 14px;margin-top:10px}
.ref-cat{display:inline-block;padding:1px 8px;border-radius:9px;font-size:10.5px;
  font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.ref-cat.bobine{background:var(--primary-l,#eaf3fb);color:var(--primary-d,#3D4FD1)}
.ref-cat.vignette{background:var(--secondary-l,#E8F5FF);color:#0E5A94}
.ref-orph{background:#fdf3f2;border-left:3px solid var(--danger);border-radius:0 9px 9px 0;
  padding:12px 15px;margin-top:14px;font-size:13px;line-height:1.55;color:var(--navy)}
.ref-msg{position:fixed;right:20px;bottom:20px;z-index:60;padding:11px 16px;border-radius:10px;
  font-size:13.5px;font-weight:700;color:white;box-shadow:0 10px 26px rgba(6,3,58,.2);display:none}
.ref-msg.ok{background:#0f6b3f}.ref-msg.ko{background:#8c2c22}
</style>

<div style="margin-bottom:18px">
  <h2 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">
    <i class="ph ph-sliders-horizontal" aria-hidden="true"></i> Référentiels &amp; capacités
  </h2>
  <p style="font-size:13px;color:var(--muted);margin-top:4px">
    Conditionnement des bobines et du PMMA — les valeurs de référence de tout l'ERP.
  </p>
</div>

<div class="ref-intro">
  Ces capacités servent à trois choses : fixer le stock initial d'une bobine à sa création,
  convertir un besoin en nombre de bobines ou de cartons à commander, et calculer les
  projections d'autonomie. Une valeur fausse ne provoque aucune erreur visible — elle
  décale silencieusement tous ces calculs. <?= $can_edit ? '' : '<br><strong>Vous consultez cet écran en lecture seule.</strong>' ?>
</div>

<div class="ref-onglets">
  <button type="button" class="ref-onglet on" data-vue="bob" onclick="refVue('bob')">
    <i class="ph ph-scroll" aria-hidden="true"></i> Bobines &amp; vignettes
  </button>
  <button type="button" class="ref-onglet" data-vue="pm" onclick="refVue('pm')">
    <i class="ph ph-printer" aria-hidden="true"></i> PMMA — unités par carton
  </button>
  <button type="button" class="ref-onglet" data-vue="df" onclick="refVue('df')">
    <i class="ph ph-eye" aria-hidden="true"></i> Affichage par défaut
  </button>
</div>

<!-- ══ BOBINES ══ -->
<div id="vue-bob">
  <div class="ref-card">
    <h4>Capacité par série</h4>
    <div class="sub">Deux articles distincts : les <strong>bobines</strong> de films (séries A à D)
      et les <strong>vignettes</strong> (TL Réservoir, WSL Pare-brise). Le conditionnement dépend
      de la série, pas de la version — les versions d'une même série partagent le même
      conditionnement. Modifier une série applique la valeur à tous ses types.</div>
    <table class="ref-t">
      <thead><tr>
        <th>Série</th><th>Format</th><th>Catégorie</th><th class="n">Types</th>
        <th class="n">Films par unité</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($series as $s => $inf):
        $caps = array_keys($inf['caps']); $uniforme = count($caps) === 1; ?>
      <tr>
        <td><span class="ref-serie"><?= h($s) ?></span></td>
        <td style="color:var(--muted)"><?= h($inf['format']) ?></td>
        <td><span class="ref-cat <?= h(categorie_serie($s)) ?>">
          <?= h(libelle_categorie(categorie_serie($s))) ?></span></td>
        <td class="n"><?= count($inf['types']) ?></td>
        <td class="n">
          <input class="ref-inp" type="number" min="1" max="100000"
                 id="cap-<?= h($s) ?>" value="<?= $uniforme ? (int)$caps[0] : '' ?>"
                 <?= $can_edit ? '' : 'disabled' ?>
                 placeholder="<?= $uniforme ? '' : 'valeurs multiples' ?>">
          <?php if (!$uniforme): ?>
          <div class="ref-multi">valeurs différentes selon le type</div>
          <?php endif; ?>
        </td>
        <td class="n">
          <?php if ($can_edit): ?>
          <button type="button" class="btn btn-secondary btn-sm"
                  onclick="refSerie('<?= h($s) ?>')">Appliquer</button>
          <?php endif; ?>
          <button type="button" class="ref-lien" onclick="refDetail('<?= h($s) ?>')">détail</button>
        </td>
      </tr>
      <tr id="det-<?= h($s) ?>" style="display:none"><td colspan="6">
        <div class="ref-detail">
          <div class="sub" style="margin-bottom:8px">Exception possible type par type — à n'utiliser
            que si un conditionnement diffère réellement au sein de la série.</div>
          <table class="ref-t">
            <thead><tr><th>Code</th><th>Version</th><th class="n">Films</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($inf['types'] as $t): ?>
            <tr>
              <td><strong><?= h($t['code']) ?></strong></td>
              <td style="color:var(--muted)"><?= h($t['version'] ?? '—') ?></td>
              <td class="n"><input class="ref-inp mini" type="number" min="1" max="100000"
                     id="tb-<?= (int)$t['id'] ?>" value="<?= (int)$t['films_par_bobine'] ?>"
                     <?= $can_edit ? '' : 'disabled' ?>></td>
              <td class="n"><?php if ($can_edit): ?>
                <button type="button" class="ref-lien" onclick="refType(<?= (int)$t['id'] ?>)">enregistrer</button>
              <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ PMMA ══ -->
<div id="vue-pm" style="display:none">
  <div class="ref-card">
    <h4>Types de PMMA</h4>
    <div class="sub">La saisie et le stock restent comptés <strong>en unités</strong> : le contenu du
      carton ne sert qu'à convertir un besoin en cartons à commander. Le code n'est pas modifiable —
      c'est lui qui relie ce catalogue au stock et à l'historique de consommation.</div>
    <?php if (empty($types_pmma)): ?>
      <div style="color:var(--muted);font-size:13.5px">Aucun type au catalogue.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="ref-t">
      <thead><tr>
        <th>Code</th><th>Libellé</th><th>Série</th>
        <th class="n">Unités / carton</th><th class="n">Seuil</th>
        <th class="n">Stock actuel</th><th>Actif</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($types_pmma as $p): $i = (int)$p['id']; ?>
      <tr>
        <td><strong><?= h($p['code']) ?></strong></td>
        <td><input class="ref-inp txt" id="pl-<?= $i ?>" value="<?= h($p['libelle']) ?>"
                   <?= $can_edit ? '' : 'disabled' ?>></td>
        <td>
          <select class="ref-inp mini" id="ps-<?= $i ?>" <?= $can_edit ? '' : 'disabled' ?>>
            <option value="">—</option>
            <?php foreach (['A','B','C','D'] as $s): ?>
            <option value="<?= $s ?>" <?= $p['serie'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="n"><input class="ref-inp mini" type="number" min="1" max="10000"
               id="pc-<?= $i ?>" value="<?= (int)$p['unites_par_carton'] ?>"
               <?= $can_edit ? '' : 'disabled' ?>></td>
        <td class="n"><input class="ref-inp mini" type="number" min="0"
               id="pq-<?= $i ?>" value="<?= (int)$p['seuil_defaut'] ?>"
               <?= $can_edit ? '' : 'disabled' ?>></td>
        <td class="n" style="color:var(--muted)">
          <?= fmt_number((int)$p['stock_actuel']) ?>
          <?php if ((int)$p['nb_sites']): ?>
          <div style="font-size:11px"><?= (int)$p['nb_sites'] ?> site(s)</div>
          <?php endif; ?>
        </td>
        <td><input type="checkbox" id="pa-<?= $i ?>" <?= (int)$p['actif'] ? 'checked' : '' ?>
                   <?= $can_edit ? '' : 'disabled' ?>></td>
        <td class="n"><?php if ($can_edit): ?>
          <button type="button" class="btn btn-secondary btn-sm" onclick="refPmma(<?= $i ?>)">Enregistrer</button>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($orphelins)): ?>
    <div class="ref-orph">
      <strong>Types présents en stock mais absents du catalogue :</strong>
      <?= h(implode(', ', array_column($orphelins, 'code'))) ?>.
      Ils n'ont pas de contenu de carton déclaré et sont ignorés des conversions.
      Créez-les ci-dessous avec exactement le même libellé pour les rattacher.
    </div>
    <?php endif; ?>
  </div>

  <?php if ($can_create): ?>
  <div class="ref-card">
    <h4>Ajouter un type de PMMA</h4>
    <div class="sub">Le code doit reprendre à l'identique le libellé utilisé dans les stocks,
      sinon le nouveau type ne se rattachera à rien.</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <div><label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:4px">Code</label>
        <input class="ref-inp txt" id="nc" placeholder="PMMA type A"></div>
      <div><label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:4px">Libellé</label>
        <input class="ref-inp txt" id="nl" placeholder="facultatif"></div>
      <div><label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:4px">Série</label>
        <select class="ref-inp mini" id="ns">
          <option value="">—</option><option>A</option><option>B</option><option>C</option><option>D</option>
        </select></div>
      <div><label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:4px">Unités / carton</label>
        <input class="ref-inp mini" type="number" min="1" max="10000" id="ncap" value="25"></div>
      <button type="button" class="btn btn-primary btn-sm" onclick="refCreer()">
        <i class="ph ph-plus" aria-hidden="true"></i> Ajouter</button>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ══ DÉFAUTS D'AFFICHAGE ══ -->
<div id="vue-df" style="display:none">
  <div class="ref-card">
    <h4>Ouverture du Dashboard KPI</h4>
    <div class="sub">Sur quelle granularité l'écran s'ouvre pour un utilisateur qui n'a encore
      rien filtré. Dès qu'il choisit lui-même une période, c'est son choix qui est retenu la
      fois suivante : ce réglage ne s'impose pas, il évite seulement de tomber sur une vue qui
      ne concerne personne. Laisser « aucun » revient à ouvrir sur le mensuel.</div>
    <?php if (empty($roles_defauts)): ?>
      <div style="color:var(--muted);font-size:13.5px">Migration des préférences non appliquée.</div>
    <?php else: ?>
    <table class="ref-t">
      <thead><tr><th>Rôle</th><th class="n">Utilisateurs</th><th>Ouvre sur</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($roles_defauts as $r): $i = (int)$r['id']; ?>
      <tr>
        <td><strong><?= h($r['nom']) ?></strong></td>
        <td class="n" style="color:var(--muted)"><?= (int)$r['nb'] ?></td>
        <td>
          <select class="ref-inp txt" id="dr-<?= $i ?>" <?= $can_edit ? '' : 'disabled' ?>>
            <option value="">— aucun (mensuel) —</option>
            <?php foreach (['journalier'=>'Journalier','hebdomadaire'=>'Hebdomadaire',
                            'mensuel'=>'Mensuel','annuel'=>'Annuel'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $r['periode'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="n"><?php if ($can_edit): ?>
          <button type="button" class="btn btn-secondary btn-sm" onclick="refDefaut(<?= $i ?>)">Enregistrer</button>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="ref-msg" id="refMsg"></div>

<script>
function refVue(v){
  ['bob','pm','df'].forEach(function(k){
    var e = document.getElementById('vue-' + k);
    if (e) e.style.display = (k === v) ? '' : 'none';
  });
  document.querySelectorAll('.ref-onglet').forEach(function(b){
    b.classList.toggle('on', b.dataset.vue === v);
  });
}

function refDetail(s){
  var l = document.getElementById('det-' + s);
  l.style.display = (l.style.display === 'none') ? '' : 'none';
}

function refMsg(ok, texte){
  var m = document.getElementById('refMsg');
  m.className = 'ref-msg ' + (ok ? 'ok' : 'ko');
  m.textContent = texte;
  m.style.display = 'block';
  clearTimeout(refMsg._t);
  refMsg._t = setTimeout(function(){ m.style.display = 'none'; }, 3200);
}

function refPost(donnees, recharger){
  var c = new URLSearchParams(donnees);
  fetch(location.pathname, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: c.toString()
  }).then(function(r){ return r.json(); })
    .then(function(j){
      refMsg(!!j.success, j.message || '');
      // Une capacité de série touche plusieurs lignes : seule cette
      // action justifie de recharger, sinon l'écran perdrait les saisies
      // en cours dans les autres champs.
      if (j.success && recharger) setTimeout(function(){ location.reload(); }, 700);
    })
    .catch(function(){ refMsg(false, 'Erreur réseau.'); });
}

function refSerie(s){
  refPost({action:'serie_bobine', serie:s,
           films_par_bobine: document.getElementById('cap-' + s).value}, true);
}
function refType(id){
  refPost({action:'type_bobine', id:id,
           films_par_bobine: document.getElementById('tb-' + id).value}, false);
}
function refPmma(id){
  refPost({action:'type_pmma', id:id,
           libelle:  document.getElementById('pl-' + id).value,
           serie:    document.getElementById('ps-' + id).value,
           unites_par_carton: document.getElementById('pc-' + id).value,
           seuil_defaut:      document.getElementById('pq-' + id).value,
           actif: document.getElementById('pa-' + id).checked ? 1 : ''}, false);
}
function refDefaut(id){
  refPost({action:'defaut_role', role_id:id, cle:'kpi_dashboard.periode',
           valeur: document.getElementById('dr-' + id).value}, false);
}
function refCreer(){
  refPost({action:'creer_pmma',
           code:    document.getElementById('nc').value,
           libelle: document.getElementById('nl').value,
           serie:   document.getElementById('ns').value,
           unites_par_carton: document.getElementById('ncap').value}, true);
}
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
