<?php
// ============================================================
//  pages/import_bobines.php — REMPLACÉ PAR IMPORT OPTOTRACE
//  Les bobines sont désormais gérées via l'import OptoTrace
//  dans le module Import EMUCI (import_emuci.php)
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_auth();
header('Location: ' . APP_URL . '/pages/import_emuci.php?tab=optotrace');
exit;
/* ---- ancien code conservé ci-dessous ---- */
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/referentiels.php';

require_auth();
// Seuls admin et superadmin peuvent faire l'import
if (!in_array(current_user()['role_slug'], ['admin','superadmin'])) {
    http_response_code(403); include __DIR__ . '/403.php'; exit;
}

$user        = current_user();
$page_title  = 'Import stock bobines';
$active_page = 'bobines';

$sites_list = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");

// ============================================================
//  TRAITEMENT POST — Upload + Import
// ============================================================
$result  = null;
$preview = null;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '';

    // ── ÉTAPE 1 : Analyser le fichier Excel
    if ($step === 'preview') {
        if (empty($_FILES['fichier_excel']) || $_FILES['fichier_excel']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Veuillez sélectionner un fichier Excel valide.';
        } else {
            $tmp = $_FILES['fichier_excel']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['fichier_excel']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx','xls'])) {
                $errors[] = 'Format invalide. Utilisez un fichier .xlsx ou .xls';
            } else {
                // Sauvegarder temporairement
                $tmp_dest = sys_get_temp_dir() . '/bobines_import_' . uniqid() . '.' . $ext;
                move_uploaded_file($tmp, $tmp_dest);
                $_SESSION['import_tmp_file'] = $tmp_dest;

                // Parser avec PhpSpreadsheet
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (!file_exists($autoload)) {
                    $errors[] = 'PhpSpreadsheet non installé. Exécutez : composer require phpoffice/phpspreadsheet';
                } else {
                    require_once $autoload;

                    try {
                        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_dest);
                        $sheet       = $spreadsheet->getActiveSheet();
                        $rows        = $sheet->toArray(null, true, true, false);

                        if (empty($rows)) { $errors[] = 'Fichier vide.'; }
                        else {
                            // Détecter les colonnes (première ligne = entêtes)
                            $headers = array_map('strtolower', array_map('trim', $rows[0]));
                            $col_map = array_flip($headers);

                            $required = ['keyname','format','quantity','site','state'];
                            foreach ($required as $r) {
                                if (!isset($col_map[$r])) $errors[] = "Colonne manquante : '$r'";
                            }

                            if (empty($errors)) {
                                $data = [];
                                for ($i = 1; $i < count($rows); $i++) {
                                    $row = $rows[$i];
                                    $keyname  = trim($row[$col_map['keyname']] ?? '');
                                    $format   = strtoupper(trim($row[$col_map['format']] ?? ''));
                                    $quantity = (int)($row[$col_map['quantity']] ?? 0);
                                    $site_nom = trim($row[$col_map['site']] ?? '');
                                    $state    = (int)($row[$col_map['state']] ?? 0);
                                    if (!$keyname || !$format) continue;
                                    $data[] = compact('keyname','format','quantity','site_nom','state');
                                }

                                // Extraire les sites uniques du fichier
                                $sites_fichier = array_unique(array_column($data,'site_nom'));
                                sort($sites_fichier);

                                // Pré-mapping auto si nom identique
                                $mapping_auto = [];
                                foreach ($sites_fichier as $sf) {
                                    foreach ($sites_list as $sl) {
                                        if (strtolower(trim($sl['nom'])) === strtolower(trim($sf))) {
                                            $mapping_auto[$sf] = $sl['id'];
                                            break;
                                        }
                                    }
                                }

                                $_SESSION['import_data']   = $data;
                                $_SESSION['import_sites']  = $sites_fichier;
                                $preview = [
                                    'total'        => count($data),
                                    'actives'      => count(array_filter($data, fn($r)=>$r['state']!=7)),
                                    'retirees'     => count(array_filter($data, fn($r)=>$r['state']==7)),
                                    'avec_films'   => count(array_filter($data, fn($r)=>$r['quantity']>0)),
                                    'sites_fichier'=> $sites_fichier,
                                    'mapping_auto' => $mapping_auto,
                                    'formats'      => array_count_values(array_column($data,'format')),
                                ];
                            }
                        }
                    } catch (Exception $e) {
                        $errors[] = 'Erreur lecture fichier : ' . $e->getMessage();
                    }
                }
            }
        }
    }

    // ── ÉTAPE 2 : Import réel avec le mapping de sites
    if ($step === 'import') {
        $data          = $_SESSION['import_data']  ?? [];
        $sites_fichier = $_SESSION['import_sites'] ?? [];
        $skip_retirees = !empty($_POST['skip_retirees']);
        $skip_zero     = !empty($_POST['skip_zero']);

        if (empty($data)) {
            $errors[] = 'Session expirée. Veuillez recommencer.';
        } else {
            // Construire le mapping site_nom → site_id
            $mapping = [];
            foreach ($sites_fichier as $sf) {
                $site_id = (int)($_POST['map_site_' . md5($sf)] ?? 0);
                $mapping[$sf] = $site_id ?: null;
            }

            $created = 0; $skipped = 0; $updated = 0; $ignored = 0;

            db_begin();
            try {
                foreach ($data as $row) {
                    // Filtres
                    if ($skip_retirees && $row['state'] == 7) { $ignored++; continue; }
                    if ($skip_zero && $row['quantity'] == 0 && $row['state'] != 7) { $ignored++; continue; }

                    $keyname  = $row['keyname'];
                    $format   = $row['format'];
                    $quantity = (int)$row['quantity'];
                    $site_id  = $mapping[$row['site_nom']] ?? null;
                    $state    = (int)$row['state'];

                    // Statut bobine
                    $statut = 'en_stock';
                    if ($state == 7) $statut = 'retiree';
                    elseif ($quantity == 0) $statut = 'epuisee';
                    elseif ($site_id) $statut = 'en_cours';

                    // Série = premier char du format
                    $serie = substr($format, 0, 1);
                    // qte_initiale : lue au catalogue des types de bobines.
                    // L'ancienne règle « WSL/TL = 2000, sinon 500 » sert de
                    // repli pour un code absent du catalogue, afin qu'un
                    // import portant un type inconnu se comporte comme avant.
                    $qte_initiale = capacite_bobine(
                        $format,
                        (str_starts_with($format,'WSL') || str_starts_with($format,'TL')) ? 2000 : 500);

                    // Chercher le type_vehicule
                    $tv = db_fetch_one("SELECT id FROM op_types_vehicule WHERE serie_bobine=?",[$serie]);

                    // Vérifier si la bobine existe déjà
                    $existant = db_fetch_one("SELECT id,stock_systeme FROM op_bobines WHERE numero=?",[$keyname]);

                    if ($existant) {
                        // Mettre à jour le stock système
                        db_query(
                            "UPDATE op_bobines SET stock_systeme=?,films_restants=?,statut=?,site_id=?,format=?,qte_initiale=? WHERE id=?",
                            [$quantity,$quantity,$statut,$site_id,$format,$qte_initiale,$existant['id']]
                        );
                        // Tracer le mouvement si changement
                        if ($existant['stock_systeme'] != $quantity) {
                            $diff = $quantity - (int)$existant['stock_systeme'];
                            db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by) VALUES (?,?,?,?,?,?,?)",
                                [$existant['id'],'ajustement_inventaire',$diff,(int)$existant['stock_systeme'],$quantity,"Import Excel ".(date('Y-m-d')),$user['id']]);
                        }
                        $updated++;
                    } else {
                        // Créer la bobine
                        db_query(
                            "INSERT INTO op_bobines (numero,type_code,serie,type_vehicule_id,site_id,format,qte_initiale,stock_systeme,films_restants,statut)
                             VALUES (?,?,?,?,?,?,?,?,?,?)",
                            [$keyname,$format,$serie,$tv['id']??null,$site_id,$format,$qte_initiale,$quantity,$quantity,$statut]
                        );
                        $new_id = (int)db_last_id();
                        // Mouvement d'entrée
                        if ($quantity > 0) {
                            db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by) VALUES (?,?,?,?,?,?,?)",
                                [$new_id,'entree',$quantity,0,$quantity,"Import Excel ".(date('Y-m-d')),$user['id']]);
                        }
                        $created++;
                    }
                }

                db_commit();
                audit_log($user['id'],'CREATE','operations',null,"Import bobines Excel : $created créées, $updated mises à jour, $ignored ignorées");

                $result = [
                    'success' => true,
                    'created' => $created,
                    'updated' => $updated,
                    'ignored' => $ignored,
                ];
                // Nettoyer session
                unset($_SESSION['import_data'], $_SESSION['import_sites'], $_SESSION['import_tmp_file']);

            } catch (Exception $e) {
                db_rollback();
                $errors[] = 'Erreur import : ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../templates/header.php';
?>
<style>
.import-card{background:white;border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:20px}
.import-card h3{font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:var(--navy);margin-bottom:16px}
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat-box{background:var(--lighter);border-radius:10px;padding:14px;text-align:center}
.stat-val{font-family:'Montserrat',sans-serif;font-size:22px;font-weight:800;color:var(--navy)}
.stat-lbl{font-size:11.5px;color:var(--muted);margin-top:3px}
.site-row{display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;font-size:13px}
.site-nom{min-width:220px;font-weight:600;color:var(--navy)}
.site-arrow{color:var(--muted);font-size:16px;flex-shrink:0}
.alert-success{background:#eafaf1;border:1px solid #a9dfbf;border-radius:10px;padding:16px 20px;font-size:13px;color:#1e8449}
.alert-danger{background:#fdf0ef;border:1px solid #f5c6cb;border-radius:10px;padding:12px 16px;font-size:13px;color:#c0392b;margin-bottom:16px}
.format-chip{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;background:#e3f2fd;color:#1565c0;margin:2px}
</style>

<div style="max-width:860px">

<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
  <a href="<?= APP_URL ?>/pages/operations/bobines.php" style="color:var(--muted);text-decoration:none;font-size:13px">← Retour aux bobines</a>
</div>

<?php if($result && $result['success']): ?>
<!-- RÉSULTAT IMPORT -->
<div class="alert-success" style="margin-bottom:20px">
  <div style="font-size:16px;font-weight:700;margin-bottom:8px"><i class="ph ph-check-circle" aria-hidden="true"></i> Import réussi !</div>
  <div style="display:flex;gap:24px;flex-wrap:wrap">
    <span>🆕 <strong><?= $result['created'] ?></strong> bobines créées</span>
    <span><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> <strong><?= $result['updated'] ?></strong> bobines mises à jour</span>
    <span>⏭ <strong><?= $result['ignored'] ?></strong> ignorées</span>
  </div>
  <div style="margin-top:12px">
    <a href="<?= APP_URL ?>/pages/operations/bobines.php" class="btn btn-primary btn-sm">→ Voir les bobines</a>
  </div>
</div>

<?php else: ?>

<?php if(!empty($errors)): ?>
<div class="alert-danger">
  <?php foreach($errors as $e): ?><div><i class="ph ph-warning" aria-hidden="true"></i> <?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(!$preview): ?>
<!-- ÉTAPE 1 : Upload fichier -->
<div class="import-card">
  <h3><i class="ph ph-upload-simple" aria-hidden="true"></i> Importer le stock bobines depuis Excel</h3>
  <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
    Le fichier doit contenir les colonnes : <code>keyname</code>, <code>format</code>, <code>quantity</code>, <code>site</code>, <code>state</code>
  </p>

  <div style="background:#e8f4fd;padding:14px;border-radius:10px;font-size:13px;margin-bottom:20px;border-left:3px solid var(--blue-mid,#1a56a0)">
    <strong>Correspondance des colonnes :</strong><br>
    <code>keyname</code> → Numéro unique de la bobine<br>
    <code>format</code> → Type de bobine (A001, B002, WSL001...)<br>
    <code>quantity</code> → Films restants (stock système)<br>
    <code>site</code> → Nom du site d'affectation<br>
    <code>state</code> → 0=active, 1=active, 7=retirée
  </div>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="step" value="preview">
    <div class="form-group" style="margin-bottom:16px">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px">Fichier Excel (.xlsx) *</label>
      <input type="file" name="fichier_excel" accept=".xlsx,.xls"
             style="width:100%;padding:10px;border:2px dashed var(--border);border-radius:8px;font-size:13px;cursor:pointer;background:var(--lighter)">
    </div>
    <button type="submit" class="btn btn-primary"><i class="ph ph-chart-bar" aria-hidden="true"></i> Analyser le fichier</button>
  </form>
</div>

<?php else: ?>
<!-- ÉTAPE 2 : Aperçu + Mapping sites -->
<div class="import-card">
  <h3><i class="ph ph-chart-bar" aria-hidden="true"></i> Aperçu — <?= $preview['total'] ?> bobines détectées</h3>

  <div class="stat-grid">
    <div class="stat-box"><div class="stat-val"><?= $preview['total'] ?></div><div class="stat-lbl">Total bobines</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--success-d)"><?= $preview['avec_films'] ?></div><div class="stat-lbl">Avec films restants</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--muted)"><?= $preview['retirees'] ?></div><div class="stat-lbl">Retirées (state=7)</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--blue-mid,#1a56a0)"><?= count($preview['formats']) ?></div><div class="stat-lbl">Types de format</div></div>
  </div>

  <!-- Formats détectés -->
  <div style="margin-bottom:18px">
    <div style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Formats détectés</div>
    <?php arsort($preview['formats']); foreach($preview['formats'] as $fmt=>$nb): ?>
    <span class="format-chip"><?= h($fmt) ?> (<?= $nb ?>)</span>
    <?php endforeach; ?>
  </div>
</div>

<form method="POST">
  <input type="hidden" name="step" value="import">

  <!-- Mapping des sites -->
  <div class="import-card">
    <h3><i class="ph ph-buildings" aria-hidden="true"></i> Correspondance des sites</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
      Associez chaque site du fichier Excel à un site de l'application. Laissez "Ignorer" si le site n'existe pas.
    </p>

    <?php foreach($preview['sites_fichier'] as $sf):
      $key = md5($sf);
      $auto_id = $preview['mapping_auto'][$sf] ?? 0;
      $nb_bobs = count(array_filter($_SESSION['import_data'], fn($r)=>$r['site_nom']===$sf));
      $nb_films = array_sum(array_map(fn($r)=>$r['quantity'], array_filter($_SESSION['import_data'], fn($r)=>$r['site_nom']===$sf)));
    ?>
    <div class="site-row">
      <div class="site-nom">
        <?= h($sf) ?>
        <div style="font-size:12px;color:var(--muted);font-weight:400"><?= $nb_bobs ?> bob · <?= number_format($nb_films) ?> films</div>
      </div>
      <div class="site-arrow">→</div>
      <div style="flex:1">
        <select name="map_site_<?= $key ?>" class="form-control" style="font-size:13px;padding:7px 10px">
          <option value="0">— Ignorer ce site —</option>
          <?php foreach($sites_list as $sl): ?>
          <option value="<?= $sl['id'] ?>" <?= $auto_id==$sl['id']?'selected':'' ?>><?= h($sl['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if($auto_id): ?>
      <span style="font-size:12px;color:var(--success-d);font-weight:600;white-space:nowrap"><i class="ph ph-check-circle" aria-hidden="true"></i> Auto-mappé</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Options import -->
  <div class="import-card">
    <h3><i class="ph ph-gear" aria-hidden="true"></i> Options d'import</h3>
    <div style="display:flex;flex-direction:column;gap:12px">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="skip_retirees" value="1" checked style="width:16px;height:16px">
        <div>
          <strong>Ignorer les bobines retirées</strong> (state=7, quantity=0)
          <div style="font-size:11.5px;color:var(--muted)"><?= $preview['retirees'] ?> bobines concernées</div>
        </div>
      </label>
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="skip_zero" value="1" style="width:16px;height:16px">
        <div>
          <strong>Ignorer les bobines épuisées</strong> (quantity=0 mais state≠7)
          <div style="font-size:11.5px;color:var(--muted)">Réduit le volume d'import</div>
        </div>
      </label>
    </div>
  </div>

  <div style="display:flex;gap:12px;align-items:center">
    <button type="submit" class="btn btn-primary" style="font-size:14px;padding:12px 28px">
      <i class="ph ph-rocket-launch" aria-hidden="true"></i> Lancer l'import
    </button>
    <a href="<?= APP_URL ?>/pages/import_bobines.php" style="font-size:13px;color:var(--muted)">↩ Recommencer</a>
    <span style="font-size:12px;color:var(--muted);margin-left:auto">
      <i class="ph ph-warning" aria-hidden="true"></i> Cette action va créer ou mettre à jour les bobines dans la base de données.
    </span>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>

</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
