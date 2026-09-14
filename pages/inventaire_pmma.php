<?php
// ============================================================
//  pages/inventaire_pmma.php — Inventaire PMMA
//  Calqué sur pages/inventaire_rivets.php : PMMA = stock agrégé par
//  (site_id,type_pmma), type_pmma étant un texte libre (pas un format
//  fixe comme gonflable/eclate). Accès géré par permission ('pmma' /
//  'inventaire_pmma'), comme la page pages/pmma.php elle-même — pas de
//  liste de rôles en dur comme pour Bobines/Rivets.
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/inventaire.php';

require_auth();
require_permission('inventaire_pmma', 'can_read');

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$is_coord  = ($role_slug === 'coordinateur_site');
$site_force = ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;

$page_title   = 'Inventaire PMMA';
$active_page  = 'inventaire_pmma';
$sites_list   = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
$can_validate = in_array($role_slug, ['admin','superadmin','superviseur_operation','gestionnaire_stock_bobines','gestionnaire_stock']);
$can_create   = can('inventaire_pmma','can_create');

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'creer') {
        require_permission('inventaire_pmma','can_create');
        $site_id = $site_force ?: ((int)($_POST['site_id'] ?? 0) ?: null);
        $date    = trim($_POST['date']  ?? date('Y-m-d'));
        $type    = in_array($_POST['type']??'', ['journalier','mensuel']) ? $_POST['type'] : 'journalier';
        $notes   = trim($_POST['notes'] ?? '');

        if (!$site_id) json_response(false,'Site obligatoire.');

        if ($type === 'journalier' && $is_coord) {
            json_response(false, "La création d'inventaire journalier n'est plus disponible pour les coordinateurs de site : elle dépend désormais de la session ouverte par l'administrateur.");
        }

        if ($type === 'mensuel') {
            $session = db_fetch_one(
                "SELECT se.id FROM inventaire_sessions se
                 JOIN inventaire_session_sites iss ON iss.session_id = se.id
                 WHERE iss.site_id = ? AND se.statut = 'ouverte'
                   AND CURRENT_DATE BETWEEN se.date_debut AND se.date_fin
                 LIMIT 1",
                [$site_id]
            );
            if (!$session) {
                json_response(false, "Aucune session d'inventaire ouverte pour ce site actuellement. Contactez le responsable des sessions d'inventaire.");
            }
            $session_id = (int)$session['id'];

            $deja = db_fetch_one(
                "SELECT id FROM inventaires_pmma WHERE session_id=? AND site_id=? AND statut!='annule'",
                [$session_id, $site_id]
            );
            if ($deja) json_response(false, "Un inventaire existe déjà pour ce site dans cette session (ouvrez-le depuis la liste).");

            db_begin();
            try {
                $res = inv_creer_pmma($site_id, $date, $session_id, (int)$user['id']);
                audit_log($user['id'],'CREATE','inventaire_pmma',$res['id'],"Création inventaire mensuel PMMA $date site:$site_id — {$res['nb']} type(s)");
                db_commit();
                json_response(true,'Inventaire créé.',['id'=>$res['id'],'nb'=>$res['nb'],'type'=>'mensuel']);
            } catch (Exception $e) { db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
        }

        $exist = db_fetch_one(
            "SELECT id FROM inventaires_pmma WHERE site_id=? AND date_inventaire=? AND type_inventaire=? AND statut!='annule'",
            [$site_id, $date, $type]
        );
        if ($exist) json_response(false, "Un inventaire $type existe déjà pour ce site à cette date.");

        $stocks = db_fetch_all("SELECT type_pmma, quantite FROM stock_pmma_site WHERE site_id=? AND type_pmma <> '' ORDER BY type_pmma", [$site_id]);
        if (empty($stocks)) json_response(false,'Aucun stock de PMMA sur ce site.');

        db_begin();
        try {
            $total_systeme = array_sum(array_column($stocks,'quantite'));
            db_query(
                "INSERT INTO inventaires_pmma (site_id,date_inventaire,type_inventaire,statut,nb_types,notes,total_quantite_systeme,cree_par)
                 VALUES (?,?,?,'brouillon',?,?,?,?)",
                [$site_id,$date,$type,count($stocks),$notes,$total_systeme,$user['id']]
            );
            $inv_id = (int)db_last_id();

            foreach ($stocks as $s) {
                $ecart_connu = (int)db_fetch_value("SELECT COALESCE(SUM(ecart),0) FROM ecarts_pmma WHERE site_id=? AND type_pmma=? AND statut='ouvert'", [$site_id, $s['type_pmma']]);
                db_query(
                    "INSERT INTO inventaire_details_pmma (inventaire_id,type_pmma,stock_systeme,stock_physique,ecart,ecart_connu_avant)
                     VALUES (?,?,?,0,0,?)",
                    [$inv_id,$s['type_pmma'],(int)$s['quantite'],$ecart_connu]
                );
            }
            audit_log($user['id'],'CREATE','inventaire_pmma',$inv_id,"Création inventaire $type PMMA $date site:$site_id — ".count($stocks)." type(s)");
            db_commit();
            json_response(true,'Inventaire créé.',['id'=>$inv_id,'nb'=>count($stocks),'type'=>$type]);
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    if ($action === 'valider') {
        if (!$can_validate) json_response(false,'Accès refusé.');
        $inv_id = (int)($_POST['inv_id'] ?? 0);
        $inv = db_fetch_one("SELECT * FROM inventaires_pmma WHERE id=?",[$inv_id]);
        if (!$inv || $inv['statut'] !== 'brouillon') json_response(false,'Inventaire introuvable ou déjà validé.');

        $lignes = db_fetch_all("SELECT * FROM inventaire_details_pmma WHERE inventaire_id=?",[$inv_id]);
        $nb_ecarts = 0; $total_physique = 0;

        db_begin();
        try {
            foreach ($lignes as $l) {
                if ((int)$l['stock_physique'] <= 0 && (int)$l['ecart'] == 0) continue;
                $phy = (int)$l['stock_physique'];
                $total_physique += $phy;

                db_query(
                    "INSERT INTO stock_pmma_site (site_id,type_pmma,quantite) VALUES (?,?,?)
                     ON CONFLICT (site_id,type_pmma) DO UPDATE SET quantite=?",
                    [$inv['site_id'], $l['type_pmma'], $phy, $phy]
                );

                db_query("UPDATE ecarts_pmma SET statut='resolu',resolu_at=NOW(),resolu_par=? WHERE site_id=? AND type_pmma=? AND statut='ouvert'",
                    [$user['id'], $inv['site_id'], $l['type_pmma']]);

                if ($l['ecart'] != 0) {
                    $nb_ecarts++;
                    db_query("INSERT INTO ecarts_pmma (site_id,type_pmma,date_constat,stock_systeme,stock_physique,ecart,motif,source,inventaire_id,statut,resolu_at,resolu_par,created_by) VALUES (?,?,?,?,?,?,?,?,?,'resolu',NOW(),?,?)",
                        [$inv['site_id'],$l['type_pmma'],$inv['date_inventaire'],$l['stock_systeme'],$phy,$l['ecart'],$l['notes']??'','inventaire',$inv_id,$user['id'],$user['id']]);
                }
            }
            db_query("UPDATE inventaires_pmma SET statut='valide',nb_ecarts=?,total_quantite_physique=?,valide_par=?,valide_at=NOW() WHERE id=?",
                [$nb_ecarts,$total_physique,$user['id'],$inv_id]);
            audit_log($user['id'],'UPDATE','inventaire_pmma',$inv_id,"Validation inventaire PMMA #{$inv_id} ({$inv['type_inventaire']}) — {$nb_ecarts} écart(s)");
            db_commit();
            json_response(true,"Inventaire validé. {$nb_ecarts} écart(s) ajusté(s).",['nb_ecarts'=>$nb_ecarts]);
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    json_response(false,'Action inconnue.');
}

// ============================================================
//  DONNÉES
// ============================================================
$f_site = $site_force ?: (int)($_GET['site'] ?? 0);
$f_type = trim($_GET['type'] ?? '');
$f_mois = trim($_GET['mois'] ?? '');

$where = ['1=1']; $params = [];
if ($site_force)  { $where[] = 'i.site_id=?'; $params[] = $site_force; }
elseif ($f_site)  { $where[] = 'i.site_id=?'; $params[] = $f_site; }
if ($f_type)      { $where[] = 'i.type_inventaire=?'; $params[] = $f_type; }
if ($f_mois)      { $where[] = "TO_CHAR(i.date_inventaire,'YYYY-MM')=?"; $params[] = $f_mois; }

$inventaires = db_fetch_all(
    "SELECT i.*, s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS createur
     FROM inventaires_pmma i
     LEFT JOIN sites s ON s.id=i.site_id
     LEFT JOIN users u ON u.id=i.cree_par
     WHERE " . implode(' AND ',$where) . "
     ORDER BY i.date_inventaire DESC, i.created_at DESC LIMIT 100",
    $params
);

$nb_journalier = count(array_filter($inventaires, fn($i)=>$i['type_inventaire']==='journalier'));
$nb_mensuel    = count(array_filter($inventaires, fn($i)=>$i['type_inventaire']==='mensuel'));
$nb_valides    = count(array_filter($inventaires, fn($i)=>$i['statut']==='valide'));
$nb_brouillon  = count(array_filter($inventaires, fn($i)=>$i['statut']==='brouillon'));

$session_active = null;
if ($site_force) {
    $session_active = db_fetch_one(
        "SELECT se.id, se.libelle, se.date_debut, se.date_fin, se.type_periode,
                ip.id AS inv_id, ip.statut AS inv_statut, ip.nb_types,
                (SELECT COUNT(*) FROM inventaire_details_pmma d
                 WHERE d.inventaire_id = ip.id AND (d.stock_physique > 0 OR d.ecart != 0)) AS nb_saisis
         FROM inventaire_sessions se
         JOIN inventaire_session_sites iss ON iss.session_id = se.id
         LEFT JOIN inventaires_pmma ip ON ip.session_id = se.id AND ip.site_id = iss.site_id AND ip.statut != 'annule'
         WHERE iss.site_id = ? AND se.statut = 'ouverte'
           AND CURRENT_DATE BETWEEN se.date_debut AND se.date_fin
         LIMIT 1",
        [$site_force]
    );
    if ($session_active) {
        $session_active['inv_complet'] = $session_active['inv_statut'] === 'brouillon'
            && (int)$session_active['nb_types'] > 0
            && (int)$session_active['nb_saisis'] >= (int)$session_active['nb_types'];
    }
}

include __DIR__ . '/../templates/header.php';
?>
<style>
.inv-kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.ik{background:white;border:1px solid var(--border);border-radius:14px;padding:16px 20px;border-left:4px solid var(--blue)}
.ik.green{border-left-color:var(--success)} .ik.orange{border-left-color:#f39c12} .ik.purple{border-left-color:#8e44ad}
.ik-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:28px;font-weight:900;color:var(--navy);line-height:1}
.ik-lbl{font-size:12px;color:var(--muted);margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.type-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.type-journalier{background:#e8f4f9;color:var(--blue)} .type-mensuel{background:#f3e8ff;color:#6b21a8}
.statut-inv{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.statut-brouillon{background:#fef9e7;color:#9a6c00} .statut-valide{background:#d5f5e3;color:#1e7e40}
.fsel{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none;cursor:pointer}
.session-banner{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;
  background:#eaf2fb;border:1.5px solid #90caf9;border-radius:14px;padding:14px 20px;margin-bottom:20px}
</style>

<div class="inv-kpi">
  <div class="ik"><div class="ik-val"><?= $nb_journalier ?></div><div class="ik-lbl">Journaliers</div></div>
  <div class="ik purple"><div class="ik-val" style="color:#6b21a8"><?= $nb_mensuel ?></div><div class="ik-lbl">Mensuels</div></div>
  <div class="ik green"><div class="ik-val" style="color:var(--success-d)"><?= $nb_valides ?></div><div class="ik-lbl">Validés</div></div>
  <div class="ik orange"><div class="ik-val" style="color:#f39c12"><?= $nb_brouillon ?></div><div class="ik-lbl">En cours</div></div>
</div>

<?php if ($session_active): ?>
<div class="session-banner">
  <div>
    <i class="ph ph-clipboard-text" aria-hidden="true"></i> Session d'inventaire <strong><?= h(mb_strtolower(inv_periode_label($session_active['type_periode']))) ?></strong>
    en cours<?= $session_active['libelle'] ? ' — ' . h($session_active['libelle']) : '' ?>
    <span style="color:var(--muted)">(du <?= fmt_date($session_active['date_debut']) ?> au <?= fmt_date($session_active['date_fin']) ?>)</span>
  </div>
  <?php if ($session_active['inv_id']): ?>
    <?php if ($session_active['inv_statut'] === 'valide'): ?>
    <span style="font-size:13px;color:var(--success-d);font-weight:700;margin-right:10px"><i class="ph ph-check-circle" aria-hidden="true"></i> Clôturé</span>
    <?php elseif ($session_active['inv_complet']): ?>
    <span style="font-size:13px;color:#1565c0;font-weight:700;margin-right:10px"><i class="ph ph-upload-simple" aria-hidden="true"></i> Complet — en attente de validation</span>
    <?php endif; ?>
    <a href="inventaire_detail_pmma.php?id=<?= (int)$session_active['inv_id'] ?>" class="btn btn-primary btn-sm">
      <?= ($session_active['inv_statut'] === 'valide' || $session_active['inv_complet']) ? '<i class="ph ph-eye" aria-hidden="true"></i> Voir mon inventaire' : '<i class="ph ph-note-pencil" aria-hidden="true"></i> Continuer mon inventaire' ?>
    </a>
  <?php elseif ($can_create): ?>
    <button class="btn btn-primary btn-sm" onclick="creerInventaireSession(<?= (int)$session_active['id'] ?>)"><i class="ph ph-note-pencil" aria-hidden="true"></i> Préparer mon inventaire</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php if(!$site_force): ?>
    <select name="site" class="fsel" aria-label="Filtrer par site" onchange="this.form.submit()">
      <option value="">Tous les sites</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="type" class="fsel" aria-label="Filtrer par type" onchange="this.form.submit()">
      <option value="">Tous types</option>
      <option value="journalier" <?= $f_type==='journalier'?'selected':'' ?>>📅 Journalier</option>
      <option value="mensuel"    <?= $f_type==='mensuel'?'selected':'' ?>>📆 Mensuel</option>
    </select>
    <input type="month" name="mois" value="<?= h($f_mois) ?>" onchange="this.form.submit()" aria-label="Choisir le mois"
           style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none">
  </form>
  <?php if($can_create && !$is_coord): ?>
  <button class="btn btn-primary" onclick="ouvrirModal()">+ Nouvel inventaire</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><h3><i class="ph ph-clipboard-text" aria-hidden="true"></i> Inventaires <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($inventaires) ?>)</span></h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Date</th><th>Type</th><th>Site</th>
        <th style="text-align:center">Types PMMA</th>
        <th style="text-align:center">Stock système</th>
        <th style="text-align:center">Stock physique</th>
        <th style="text-align:center">Écarts</th>
        <th>Statut</th><th>Créé par</th>
        <th style="text-align:center">Actions</th>
      </tr></thead>
      <tbody>
      <?php if(empty($inventaires)): ?>
        <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--muted)">Aucun inventaire.</td></tr>
      <?php else: foreach($inventaires as $inv): ?>
        <tr>
          <td style="font-weight:700;white-space:nowrap"><?= fmt_date($inv['date_inventaire'],'d/m/Y') ?></td>
          <td><span class="type-badge type-<?= $inv['type_inventaire'] ?>"><?= $inv['type_inventaire']==='mensuel'?'<i class="ph ph-calendar-blank" aria-hidden="true"></i> Mensuel':'<i class="ph ph-calendar" aria-hidden="true"></i> Journalier' ?></span></td>
          <td style="font-weight:600"><?= h($inv['site_nom']??'Global') ?></td>
          <td style="text-align:center;font-weight:700"><?= $inv['nb_types'] ?></td>
          <td style="text-align:center;font-weight:700"><?= fmt_number($inv['total_quantite_systeme']??0) ?></td>
          <td style="text-align:center;font-weight:700;color:<?= ($inv['total_quantite_physique']??0)>0?'var(--success-d)':'var(--muted)' ?>">
            <?= ($inv['total_quantite_physique']??0)>0 ? fmt_number($inv['total_quantite_physique']) : '—' ?>
          </td>
          <td style="text-align:center">
            <?php if($inv['nb_ecarts']>0): ?>
            <span style="font-weight:800;color:#e74c3c"><?= $inv['nb_ecarts'] ?></span>
            <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
          </td>
          <td><span class="statut-inv statut-<?= $inv['statut'] ?>"><?= $inv['statut']==='valide'?'<i class="ph ph-check-circle" aria-hidden="true"></i> Validé':'⏳ En cours' ?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?= h($inv['createur']??'—') ?></td>
          <td style="text-align:center;white-space:nowrap;display:flex;gap:4px;justify-content:center">
            <a href="inventaire_detail_pmma.php?id=<?= $inv['id'] ?>" class="btn btn-secondary btn-sm"><i class="ph ph-eye" aria-hidden="true"></i> Détail</a>
            <?php if($inv['statut']==='brouillon' && $can_validate): ?>
            <button class="btn btn-success btn-sm" onclick="valider(<?= $inv['id'] ?>)"><i class="ph ph-check-circle" aria-hidden="true"></i> Valider</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:20px;padding:30px;width:520px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:800;color:var(--navy)"><i class="ph ph-calendar" aria-hidden="true"></i> Nouvel inventaire journalier</h3>
      <button onclick="fermer()" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <p style="font-size:12.5px;color:var(--muted);margin:-10px 0 16px">
      L'inventaire mensuel/trimestriel/semestriel/annuel n'est pas créé ici : il est déjà prêt dès l'ouverture
      d'une session par le responsable (bandeau bleu ci-dessus).
    </p>
    <div id="mAlert"></div>
    <input type="hidden" id="fType" value="journalier">
    <div style="display:grid;grid-template-columns:<?= $site_force?'1fr':'1fr 1fr' ?>;gap:14px;margin-bottom:14px">
      <?php if(!$site_force): ?>
      <div class="form-group">
        <label>Site *</label>
        <select class="form-control" id="fSite">
          <option value="">— Sélectionner —</option>
          <?php foreach($sites_list as $s): ?>
          <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" id="fSite" value="<?= $site_force ?>">
      <?php endif; ?>
      <div class="form-group"><label>Date *</label><input type="date" class="form-control" id="fDate" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="form-group" style="margin-bottom:20px"><label>Notes</label><textarea class="form-control" id="fNotes" rows="2"></textarea></div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer()">Annuler</button>
      <button class="btn btn-primary" id="btnCreer" onclick="creer()"><i class="ph ph-rocket-launch" aria-hidden="true"></i> Créer</button>
    </div>
  </div>
</div>

<script>
function ap(d){return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(r=>r.json());}
function toast(m,t='success'){let el=document.getElementById('toast-live');if(!el){el=document.createElement('div');el.id='toast-live';el.setAttribute('role','status');el.setAttribute('aria-live','polite');el.setAttribute('aria-atomic','true');document.body.appendChild(el);}clearTimeout(el._hideTimer);el.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;background:${t==='success'?'#27ae60':'#e74c3c'};color:white`;el.textContent=m;el._hideTimer=setTimeout(()=>{el.style.display='none';},3500);}
function ouvrirModal(){document.getElementById('fDate').value='<?= date('Y-m-d') ?>';document.getElementById('fNotes').value='';document.getElementById('mAlert').innerHTML='';document.getElementById('modal').style.display='flex';}
function fermer(){document.getElementById('modal').style.display='none';}
document.getElementById('modal').addEventListener('click',e=>{if(e.target===document.getElementById('modal'))fermer();});
async function creerInventaireSession(sessionId){
  const site=<?= (int)$site_force ?>;
  const date=<?= json_encode($session_active['date_debut'] ?? '') ?>;
  const d=await ap({action:'creer',site_id:site,date,type:'mensuel',notes:''});
  if(d.success){toast(`Inventaire prêt — ${d.data.nb} type(s)`);setTimeout(()=>location.href=`inventaire_detail_pmma.php?id=${d.data.id}`,600);}
  else toast(d.message,'error');
}
async function creer(){
  const site=document.getElementById('fSite').value,date=document.getElementById('fDate').value;
  if(!site||!date){document.getElementById('mAlert').innerHTML='<div class="alert alert-danger">Site et date obligatoires.</div>';return;}
  const btn=document.getElementById('btnCreer');btn.disabled=true;btn.textContent='⏳...';
  const d=await ap({action:'creer',site_id:site,date,type:document.getElementById('fType').value,notes:document.getElementById('fNotes').value});
  btn.disabled=false;btn.textContent='🚀 Créer';
  if(d.success){toast(`Inventaire créé — ${d.data.nb} type(s)`);fermer();setTimeout(()=>location.href=`inventaire_detail_pmma.php?id=${d.data.id}`,600);}
  else document.getElementById('mAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}
async function valider(id){
  if(!confirm('Valider cet inventaire ? Le stock système sera ajusté avec les valeurs physiques.'))return;
  const d=await ap({action:'valider',inv_id:id});
  if(d.success){toast(d.message);setTimeout(()=>location.reload(),800);}else toast(d.message,'error');
}
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>
