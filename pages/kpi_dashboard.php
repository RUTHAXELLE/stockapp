<?php
// ============================================================
//  pages/kpi_dashboard.php  —  Dashboard KPI centralise
//  n° 2.2 du CR de reunion PDG.
//
//  Distinct des deux tableaux de bord existants :
//   - dashboard.php      : operationnel, oriente action du jour
//   - pdg_overview.php   : vue executive, decisionnelle et narrative
//   - celui-ci           : lecture visuelle des indicateurs, sept
//                          familles cote a cote, chacune avec son
//                          chiffre de tete, son detail et son graphe
//
//  ── Refonte 2026-09 sur maquette de reference ──
//  L'ecran empilait des rangees de tuiles identiques : lisible mais
//  plat, et sans aucune vue de l'evolution — un chiffre et sa
//  variation ne disent pas si la tendance monte depuis trois periodes
//  ou vient de casser. Chaque famille devient un panneau autonome
//  associant le chiffre, son detail et sa courbe.
//
//  Trois ecarts assumes par rapport a la maquette :
//   1. Pas de barre superieure ni de rail lateral propres a la page :
//      l'application en a deja (templates/header.php). En ajouter une
//      seconde donnerait deux navigations concurrentes.
//   2. Les sous-tuiles n'ont pas de cadre. La maquette encadre des
//      cartes dans des cartes ; un fond teinte porte la meme lecture
//      sans le double filet, qui alourdit et tient mal en theme sombre.
//   3. La carte promotionnelle de bas de page est ecartee. PRODUCT.md
//      pose un outil interne sobre et factuel : un encart d'ambiance
//      occupe une place que la production par site utilise mieux.
//
//  La granularite vient de includes/periode.php, partagee avec la vue
//  executive : les quatre filtres se comportent donc identiquement sur
//  les deux ecrans.
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/periode.php';
require_once __DIR__ . '/../includes/consommation.php';
require_once __DIR__ . '/../includes/referentiels.php';
require_once __DIR__ . '/../includes/preferences.php';

require_auth();
require_permission('kpi_dashboard', 'can_read');

$user        = current_user();
$role_slug   = $user['role_slug'] ?? '';
$page_title  = 'Dashboard KPI';
$active_page = 'kpi_dashboard';

// ── Vues enregistrees : actions AJAX, traitees avant tout calcul
// d'indicateur. Repondre a un enregistrement ne demande pas de construire
// les sept panneaux.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'vue_creer') {
        $nom = trim($_POST['nom'] ?? '');
        $flt = trim($_POST['filtres'] ?? '');
        if ($nom === '')            json_response(false, 'Donnez un nom à cette vue.');
        if (mb_strlen($nom) > 80)   json_response(false, 'Nom trop long (80 caractères maximum).');
        // Les filtres sont ceux de la page, pas une chaine libre : on les
        // relit et on ne garde que les cles connues. Sans ce tri, une vue
        // pourrait transporter n'importe quel parametre vers l'ecran.
        parse_str($flt, $brut);
        $garde = [];
        foreach (['periode','mois','jour','annee'] as $k)
            if (isset($brut[$k]) && is_scalar($brut[$k])) $garde[$k] = (string)$brut[$k];
        if (isset($brut['sites']) && is_array($brut['sites']))
            $garde['sites'] = array_values(array_filter(array_map('intval', $brut['sites'])));
        try {
            db_query("INSERT INTO vues_enregistrees (user_id, ecran, nom, filtres, partagee)
                      VALUES (?,?,?,?,?)
                      ON CONFLICT (user_id, ecran, nom)
                      DO UPDATE SET filtres = EXCLUDED.filtres, partagee = EXCLUDED.partagee",
                [(int)$user['id'], 'kpi_dashboard', $nom, http_build_query($garde),
                 !empty($_POST['partagee']) ? 1 : 0]);
        } catch (Throwable $e) {
            json_response(false, 'Enregistrement impossible : la migration des vues n’est pas passée.');
        }
        audit_log($user['id'], 'CREATE', 'kpi_dashboard', 0, "Vue enregistrée « $nom »");
        json_response(true, "Vue « $nom » enregistrée.");
    }

    if ($action === 'vue_supprimer') {
        // Le filtre sur user_id est la seule autorisation qui compte : une
        // vue partagee reste modifiable par son seul proprietaire.
        try {
            db_query("DELETE FROM vues_enregistrees WHERE id = ? AND user_id = ?",
                     [(int)($_POST['id'] ?? 0), (int)$user['id']]);
        } catch (Throwable $e) {
            json_response(false, 'Suppression impossible.');
        }
        json_response(true, 'Vue supprimée.');
    }

    json_response(false, 'Action inconnue.');
}

$is_coord   = ($role_slug === 'coordinateur_site');
$site_force = ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;

$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
$sites_ids  = array_map(fn($s) => (int)$s['id'], $sites_list);

// ── Perimetre : une selection de sites, plus un site unique.
// Un superviseur arbitre entre vingt et un sites mais n'en suit
// generalement que quelques-uns ; le choix « un seul ou tous » l'obligeait
// a repasser par la liste a chaque comparaison. Une selection vide vaut
// « tout le perimetre » : c'est le sens qu'on donne a des cases toutes
// decochees, et cela garde l'ecran utilisable au lieu de le vider.
//
// Le coordinateur reste force sur son site : c'est impose par son role,
// pas choisi, et sa preference ne doit pas pouvoir l'elargir.
$sites_sel = $site_force ? [$site_force] : pref_filtre(
    'kpi_dashboard.sites',
    $_GET['sites'] ?? null,
    fn($v) => pref_liste_ids($v, $sites_ids),
    []);

// La granularite se memorise elle aussi : c'est le filtre le plus
// souvent repose, et le seul dont PRODUCT.md donne un defaut par profil.
if (!isset($_GET['periode'])) {
    $memo = pref_toutes()['kpi_dashboard.periode']
         ?? pref_defauts_role()['kpi_dashboard.periode'] ?? null;
    if ($memo !== null && in_array($memo, ['journalier','hebdomadaire','mensuel','annuel'], true))
        $_GET['periode'] = $memo;
} elseif (pref_interaction()) {
    pref_filtre('kpi_dashboard.periode', $_GET['periode'],
        fn($v) => in_array($v, ['journalier','hebdomadaire','mensuel','annuel'], true) ? $v : null,
        'mensuel');
}

$P   = periode_contexte();
$fmt = $P['date_fmt'];
$val = $P['val'];
$prc = $P['val_prec'];

// $site_id reste defini pour les usages ou un identifiant unique a du
// sens (couverture de stock, classement mono-site).
$site_id = count($sites_sel) === 1 ? $sites_sel[0] : 0;

// Filtres site — les identifiants sont castes en entier par
// pref_clause_in(), aucune valeur d'URL n'atteint la requete telle quelle.
$sf_p = pref_clause_in('p.site_id', $sites_sel);
$sf   = pref_clause_in('site_id',   $sites_sel);
$sf_b = pref_clause_in('b.site_id', $sites_sel);

/** Deux scalaires (periode courante / precedente) en une requete. */
function kpi_paire(string $sql, array $params): array {
    $r = db_fetch_one($sql, $params);
    return [(float)($r['courant'] ?? 0), (float)($r['precedent'] ?? 0)];
}

// ── PRODUCTION — la periode selectionnee
[$plaques, $plaques_p] = kpi_paire(
    "SELECT COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.total_plaques END),0) AS courant,
            COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.total_plaques END),0) AS precedent
       FROM op_points_journaliers p
      WHERE p.statut <> 'brouillon' $sf_p", [$val, $prc]);

[$engins, $engins_p] = kpi_paire(
    "SELECT COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.total_engins END),0) AS courant,
            COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.total_engins END),0) AS precedent
       FROM op_points_journaliers p
      WHERE p.statut <> 'brouillon' $sf_p", [$val, $prc]);

$heures = (float) db_fetch_value(
    "SELECT COALESCE(SUM(p.nb_heures_travail),0) FROM op_points_journaliers p
      WHERE TO_CHAR(p.date_point,'$fmt')=? AND p.statut <> 'brouillon' $sf_p", [$val]);
$prod_horaire = $heures > 0 ? $engins / $heures : 0;

// ── PRODUCTION — les quatre echelles cote a cote
// La maquette pose jour / semaine / mois / annee ensemble : c'est ce qui
// permet de voir qu'une bonne journee tient dans un mauvais mois.
//
// ── Comparaison A DATE, et non de periode entiere ──
// Le premier jet comparait le mois courant au mois precedent complet. Le
// 8 septembre, cela opposait 8 jours a 31 : la tuile annoncait -72,7 %
// (2 070 contre 7 590) alors qu'a nombre de jours egal la production
// etait stable (2 070 contre 2 080, soit -0,5 %). Le meme biais jouait a
// l'envers sur l'annee, affichee en hausse de 75,5 % pour une raison
// purement mecanique. Sur un ecran de direction, la fleche est le premier
// element lu : elle ne peut pas mesurer le temps ecoule.
//
// Chaque echelle compare donc son cumul a date au cumul de la periode
// precedente arrete au meme rang : lundi→aujourd'hui contre
// lundi→meme jour la semaine passee, 1er→quantieme contre 1er→meme
// quantieme, etc.
$auj = date('Y-m-d');

/** Date sûre : un quantieme absent du mois vise est ramene a son dernier
 *  jour. Sans ce garde-fou, le 31 mars compare au « 31 fevrier » que
 *  strtotime deplace au 2 ou 3 mars, et le 29 fevrier bissextile glisse
 *  au 1er mars de l'annee precedente. */
function kpi_date_rang(int $an, int $mois, int $jour): string {
    $fin = (int) date('t', mktime(0, 0, 0, $mois, 1, $an));
    return sprintf('%04d-%02d-%02d', $an, $mois, min($jour, $fin));
}

$q  = (int) date('j');            // quantieme du jour
$an = (int) date('Y');
$mo = (int) date('n');

$lundi   = date('Y-m-d', strtotime('monday this week'));
$mois_du = date('Y-m-01');
$mp      = strtotime($mois_du . ' -1 month');

$bornes = [
    // [libelle, debut courant, fin courante, debut precedent, fin precedente]
    ['Jour',    $auj, $auj,
                date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    ['Semaine', $lundi, $auj,
                date('Y-m-d', strtotime($lundi . ' -7 days')),
                date('Y-m-d', strtotime($auj . ' -7 days'))],
    ['Mois',    $mois_du, $auj,
                date('Y-m-01', $mp),
                kpi_date_rang((int)date('Y', $mp), (int)date('n', $mp), $q)],
    ['Année',   date('Y-01-01'), $auj,
                ($an - 1) . '-01-01', kpi_date_rang($an - 1, $mo, $q)],
];

$sel = []; $par = [];
foreach ($bornes as $i => [$lbl, $du, $au, $du_p, $au_p]) {
    $sel[] = "COALESCE(SUM(p.total_plaques) FILTER (WHERE p.date_point BETWEEN ?::date AND ?::date),0) AS c$i,"
           . "COALESCE(SUM(p.total_plaques) FILTER (WHERE p.date_point BETWEEN ?::date AND ?::date),0) AS p$i";
    array_push($par, $du, $au, $du_p, $au_p);
}
$ech = db_fetch_one("SELECT " . implode(',', $sel)
    . " FROM op_points_journaliers p WHERE p.statut <> 'brouillon' $sf_p", $par) ?: [];

$echelles = [];
foreach ($bornes as $i => [$lbl, $du, $au, $du_p, $au_p]) {
    // La note porte l'intervalle exact compare : « vs mois precedent »
    // laissait croire au mois entier, ce qui etait justement le probleme.
    $note = 'vs ' . fmt_date($du_p, 'd/m')
          . ($du_p === $au_p ? '' : ' – ' . fmt_date($au_p, 'd/m'));
    $echelles[] = [$lbl, (float)($ech["c$i"] ?? 0), (float)($ech["p$i"] ?? 0), $note];
}

// ── PRODUCTION — courbe d'evolution
// On compare les SOUS-periodes de la periode courante a celles de la
// precedente, alignees par rang (jour de semaine, quantieme, mois) et non
// par date : sans cet alignement, comparer le 3 mars au 3 fevrier n'aurait
// pas de sens un mois sur deux.
$periode = $P['periode'];
$sous    = null;
if ($periode === 'hebdomadaire') {
    $sous = ['fmt'=>'ID', 'cles'=>['1','2','3','4','5','6','7'],
             'lbl'=>['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'],
             'du'=>$P['du'], 'au'=>$P['au'],
             'du_p'=>date('Y-m-d', strtotime($P['du'].' -7 days')),
             'au_p'=>date('Y-m-d', strtotime($P['au'].' -7 days'))];
} elseif ($periode === 'mensuel') {
    $du_p  = date('Y-m-01', strtotime($P['du'].' -1 month'));
    $nb_j  = (int) date('t', strtotime($P['du']));
    $cles  = []; for ($i = 1; $i <= $nb_j; $i++) $cles[] = sprintf('%02d', $i);
    $sous  = ['fmt'=>'DD', 'cles'=>$cles,
              'lbl'=>array_map(fn($c) => (int)$c % 5 === 0 || $c === '01' ? (string)(int)$c : '', $cles),
              'du'=>$P['du'], 'au'=>$P['au'],
              'du_p'=>$du_p, 'au_p'=>date('Y-m-t', strtotime($du_p))];
} elseif ($periode === 'annuel') {
    $an   = (int)$P['annee'];
    $sous = ['fmt'=>'MM', 'cles'=>['01','02','03','04','05','06','07','08','09','10','11','12'],
             'lbl'=>['J','F','M','A','M','J','J','A','S','O','N','D'],
             'du'=>"$an-01-01", 'au'=>"$an-12-31",
             'du_p'=>($an-1)."-01-01", 'au_p'=>($an-1)."-12-31"];
}

/** Somme des plaques par sous-periode, sur un intervalle de dates. */
function kpi_serie(string $sfmt, string $du, string $au, string $filtre): array {
    $out = [];
    foreach (db_fetch_all(
        "SELECT TO_CHAR(p.date_point,'$sfmt') AS b, COALESCE(SUM(p.total_plaques),0) AS v
           FROM op_points_journaliers p
          WHERE p.date_point BETWEEN ?::date AND ?::date
            AND p.statut <> 'brouillon' $filtre
          GROUP BY 1", [$du, $au]) as $r) $out[$r['b']] = (float)$r['v'];
    return $out;
}

$serie_lbl = []; $serie_a = []; $serie_b = []; $serie_note = ''; $serie_borne = -1;
if ($sous) {
    $ca = kpi_serie($sous['fmt'], $sous['du'],   $sous['au'],   $sf_p);
    $cb = kpi_serie($sous['fmt'], $sous['du_p'], $sous['au_p'], $sf_p);
    foreach ($sous['cles'] as $k) { $serie_a[] = $ca[$k] ?? 0; $serie_b[] = $cb[$k] ?? 0; }
    $serie_lbl  = $sous['lbl'];
    $serie_note = 'période actuelle contre ' . mb_strtolower($P['libelle_prec']);
    // Une periode en cours n'a pas encore ses derniers points : les tracer
    // a zero dessine une chute qui n'a pas eu lieu. La courbe s'arrete donc
    // au jour ecoule, l'axe couvrant toujours la periode entiere pour que
    // la comparaison avec la precedente reste alignee.
    if (date('Y-m-d') >= $sous['du'] && date('Y-m-d') <= $sous['au']) {
        $auj = ['ID' => (string)(int)date('N'), 'DD' => date('d'), 'MM' => date('m')][$sous['fmt']];
        $pos = array_search($auj, $sous['cles'], true);
        if ($pos !== false) {
            $serie_borne = (int)$pos + 1;
            $serie_note .= ' — ' . $serie_borne . ' sur ' . count($sous['cles']) . ' écoulés';
        }
    }
} else {
    // Vue journaliere : pas de sous-periode a decouper. Les quatorze
    // derniers jours situent la journee dans sa tendance, ce qu'un seul
    // point ne fait pas.
    $fin = $P['val']; $deb = date('Y-m-d', strtotime($fin.' -13 days'));
    $c   = kpi_serie('YYYY-MM-DD', $deb, $fin, $sf_p);
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime($fin." -$i days"));
        $serie_a[]   = $c[$d] ?? 0;
        $serie_lbl[] = ($i === 13 || $i === 0) ? date('d/m', strtotime($d)) : '';
    }
    $serie_note = 'quatorze derniers jours';
}

// ── BOBINES
$bob = db_fetch_one(
    "SELECT COUNT(*) FILTER (WHERE b.statut IN ('en_cours','en_stock'))       AS actives,
            COUNT(*) FILTER (WHERE b.statut = 'epuisee')                      AS epuisees,
            COUNT(*) FILTER (WHERE b.statut = 'retiree')                      AS retirees,
            COALESCE(SUM(b.films_restants),0)                                 AS restants,
            COALESCE(SUM(b.films_utilises),0)                                 AS utilises,
            COALESCE(SUM(b.films_endommages),0)                               AS endommages,
            COALESCE(SUM(b.qte_initiale),0)                                   AS initial
       FROM op_bobines b WHERE 1=1 $sf_b") ?: [];
$b_init  = (float)($bob['initial'] ?? 0);
$b_rest  = (float)($bob['restants'] ?? 0);
$b_endo  = (float)($bob['endommages'] ?? 0);
// Le taux d'utilisation se lit sur ce qui a QUITTE la bobine, mesure par
// difference entre dotation et reliquat — et non sur films_utilises.
// Cette colonne n'est alimentee que par le point journalier ; les bobines
// entrees par import (import_bobines.php, import_emuci.php) posent
// qte_initiale et films_restants sans jamais y toucher. Sur un parc
// majoritairement importe, elle reste donc proche de zero pendant que le
// stock descend : la production affichait 0,1 % d'utilisation pour
// 790 167 films restants sur 961 000 de dotation, soit 40 % reels.
$b_sorti = max(0.0, $b_init - $b_rest);
$taux_util  = $b_init  > 0 ? $b_sorti / $b_init  * 100 : 0;
$taux_perte = $b_sorti > 0 ? $b_endo  / $b_sorti * 100 : 0;
$conso_jour = conso_moy_site($site_id, 30);
$couverture = $conso_jour > 0 ? (int) floor((float)($bob['restants'] ?? 0) / $conso_jour) : null;

// Detail par serie : le taux global masque qu'une serie peut etre a bout
// quand une autre est neuve. La serie porte le format lisible, pas le code.
$bob_series = [];
foreach (db_fetch_all(
    "SELECT TRIM(b.serie) AS serie,
            COALESCE(SUM(b.qte_initiale),0)   AS init,
            COALESCE(SUM(b.films_restants),0) AS rest
       FROM op_bobines b
      WHERE b.serie IS NOT NULL $sf_b
      GROUP BY TRIM(b.serie) HAVING COALESCE(SUM(b.qte_initiale),0) > 0
      ORDER BY 1") as $r) {
    $ini = (float)$r['init'];
    $bob_series[] = [
        'lbl' => libelle_format_serie($r['serie']),
        'cat' => categorie_serie($r['serie']),
        'pct' => $ini > 0 ? max(0.0, $ini - (float)$r['rest']) / $ini * 100 : 0,
    ];
}

// ── PMMA
$pmma_stock = db_fetch_all(
    "SELECT sp.type_pmma, SUM(sp.quantite) AS qte,
            MIN(COALESCE(sp.seuil_alerte,10)) AS seuil,
            SUM(CASE WHEN sp.quantite < COALESCE(sp.seuil_alerte,10) THEN 1 ELSE 0 END) AS bas
       FROM stock_pmma_site sp WHERE 1=1 " . pref_clause_in('sp.site_id', $sites_sel) . "
      GROUP BY sp.type_pmma ORDER BY sp.type_pmma");
$pmma_bas = 0; $pmma_total = 0;
foreach ($pmma_stock as $x) { $pmma_bas += (int)$x['bas']; $pmma_total += (int)$x['qte']; }

[$pmma_conso, $pmma_conso_p] = kpi_paire(
    "SELECT COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN pu.utilises END),0) AS courant,
            COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN pu.utilises END),0) AS precedent
       FROM op_pmma_utilises pu JOIN op_points_journaliers p ON p.id = pu.point_id
      WHERE 1=1 $sf_p", [$val, $prc]);

// Consommation par type sur la periode — le graphe en barres de la maquette.
$pmma_par_type = db_fetch_all(
    "SELECT pu.type_pmma AS t, COALESCE(SUM(pu.utilises),0) AS v
       FROM op_pmma_utilises pu JOIN op_points_journaliers p ON p.id = pu.point_id
      WHERE TO_CHAR(p.date_point,'$fmt')=? AND p.statut <> 'brouillon' $sf_p
      GROUP BY pu.type_pmma HAVING COALESCE(SUM(pu.utilises),0) > 0
      ORDER BY 2 DESC", [$val]);

// Types reellement sous leur seuil, avec le site concerne : une alerte qui
// ne dit pas ou regarder oblige a rouvrir une autre page.
$pmma_alertes = db_fetch_all(
    "SELECT sp.type_pmma AS t, s.nom AS site, sp.quantite AS q, COALESCE(sp.seuil_alerte,10) AS seuil
       FROM stock_pmma_site sp JOIN sites s ON s.id = sp.site_id
      WHERE sp.quantite < COALESCE(sp.seuil_alerte,10)
        " . pref_clause_in('sp.site_id', $sites_sel) . "
      ORDER BY sp.quantite ASC LIMIT 4");

// ── RIVETS
$riv_stock = (int) db_fetch_value(
    "SELECT COALESCE(SUM(quantite),0) FROM op_stock_rivets WHERE 1=1 $sf");
$riv_bas = (int) db_fetch_value(
    "SELECT COUNT(*) FROM op_stock_rivets WHERE quantite < COALESCE(seuil_alerte,200) $sf");
[$riv_conso, $riv_conso_p] = kpi_paire(
    "SELECT COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.rivets_utilises END),0) AS courant,
            COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.rivets_utilises END),0) AS precedent
       FROM op_points_journaliers p WHERE 1=1 $sf_p", [$val, $prc]);
$riv_alertes = db_fetch_all(
    "SELECT s.nom AS site, r.type_rivet AS t, r.quantite AS q, COALESCE(r.seuil_alerte,200) AS seuil
       FROM op_stock_rivets r JOIN sites s ON s.id = r.site_id
      WHERE r.quantite < COALESCE(r.seuil_alerte,200)
        " . pref_clause_in('r.site_id', $sites_sel) . "
      ORDER BY r.quantite ASC LIMIT 4");

// ── COMMANDES
$cmd = db_fetch_one(
    "SELECT COUNT(*)                                                            AS total,
            COUNT(*) FILTER (WHERE statut IN ('livre','recu'))                  AS servies,
            COUNT(*) FILTER (WHERE statut IN ('en_attente','en_attente_livraison','en_cours_livraison')) AS en_cours,
            COALESCE(AVG(CASE WHEN livraison_at IS NOT NULL
                         THEN EXTRACT(EPOCH FROM (livraison_at - created_at))/86400 END),0) AS delai
       FROM commandes
      WHERE TO_CHAR(created_at,'$fmt')=? $sf", [$val]) ?: [];
$cmd_total = (int)($cmd['total'] ?? 0);
$taux_service = $cmd_total > 0 ? (int)$cmd['servies'] / $cmd_total * 100 : 0;

// Taux de satisfaction sur les six dernieres periodes : un taux isole ne
// dit pas si le service se degrade ou se retablit.
$cmd_hist = [];
$cmd_hist_lbl = [];
$unite_pas = ['journalier'=>'day', 'hebdomadaire'=>'week',
               'mensuel'=>'month', 'annuel'=>'year'][$periode];
for ($i = 5; $i >= 0; $i--) {
    $ref = date('Y-m-d', strtotime($P['du'] . " -$i $unite_pas"));
    $k   = ['journalier'=>date('Y-m-d', strtotime($ref)),
            'hebdomadaire'=>date('o-W', strtotime($ref)),
            'mensuel'=>date('Y-m', strtotime($ref)),
            'annuel'=>date('Y', strtotime($ref))][$periode];
    $r = db_fetch_one(
        "SELECT COUNT(*) AS n, COUNT(*) FILTER (WHERE statut IN ('livre','recu')) AS ok
           FROM commandes WHERE TO_CHAR(created_at,'$fmt')=? $sf", [$k]) ?: [];
    $n = (int)($r['n'] ?? 0);
    $cmd_hist[]     = $n > 0 ? (int)$r['ok'] / $n * 100 : 0;
    $cmd_hist_lbl[] = ($i === 5 || $i === 0) ? $k : '';
}

// ── ÉQUIPEMENTS
// equipements.etat prend huit valeurs dans l'application : neuf, bon, ok,
// usage d'un cote, hs, reforme, endommage, maintenance de l'autre. La
// requete ne comptait disponible que `etat = 'ok'` : un parc entier saisi
// en « neuf » ou « bon » ressortait a 0 % de disponibilite, ce que la
// production affichait effectivement (0,0 % sur 21 equipements actifs).
// Une valeur inconnue tombe volontairement dans « autre etat » plutot que
// dans « disponible » : elle reste visible au lieu de gonfler le taux.
$eq = db_fetch_one(
    "SELECT COUNT(*)                                                    AS total,
            COUNT(*) FILTER (WHERE etat IN ('ok','neuf','bon','usage')) AS ok,
            COUNT(*) FILTER (WHERE etat IN ('hs','reforme','endommage')) AS hs,
            COUNT(*) FILTER (WHERE etat = 'maintenance')                AS maint,
            COUNT(*) FILTER (WHERE statut_stock = 'affecte')            AS affectes
       FROM equipements WHERE actif = 1 $sf") ?: [];
$eq_total = (int)($eq['total'] ?? 0);
$eq_ok    = (int)($eq['ok'] ?? 0);
$eq_hs    = (int)($eq['hs'] ?? 0);
$eq_maint = (int)($eq['maint'] ?? 0);
$eq_autre = max(0, $eq_total - $eq_ok - $eq_hs - $eq_maint);
$dispo    = $eq_total > 0 ? $eq_ok / $eq_total * 100 : 0;
$interv_ouvertes = (int) db_fetch_value(
    "SELECT COUNT(*) FROM interventions_maintenance
      WHERE statut_apres <> 'resolu' " . pref_clause_in('site_id', $sites_sel));

// ── SITES — production comparee et classement
$classement = db_fetch_all(
    "SELECT s.nom,
            COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.total_plaques END),0) AS plaques,
            COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.total_plaques END),0) AS plaques_p,
            COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.total_engins END),0)  AS engins,
            COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.nb_heures_travail END),0) AS heures
       FROM sites s
       LEFT JOIN op_points_journaliers p ON p.site_id = s.id AND p.statut <> 'brouillon'
      WHERE s.actif = 1 " . pref_clause_in('s.id', $sites_sel) . "
      GROUP BY s.id, s.nom
      HAVING COALESCE(SUM(CASE WHEN TO_CHAR(p.date_point,'$fmt')=? THEN p.total_plaques END),0) > 0
      ORDER BY plaques DESC", [$val, $prc, $val, $val, $val]);
$plaques_max = 0;
foreach ($classement as $c)
    $plaques_max = max($plaques_max, (int)$c['plaques'], (int)$c['plaques_p']);

// Une periode en cours n'a pas la duree de celle a laquelle on la compare.
// Sans le dire, une barre deux fois plus courte se lit comme une chute de
// production alors qu'il ne s'est ecoule que la moitie du temps.
$en_cours = ($serie_borne > 0 && isset($sous))
          ? 'période en cours : ' . $serie_borne . ' sur ' . count($sous['cles'])
            . ' — l’écart avec ' . mb_strtolower($P['libelle_prec'])
            . ' tient d’abord au temps écoulé'
          : '';

// ============================================================
//  RENDU — aides d'affichage
// ============================================================

/** Variation en %, ou null si la periode precedente est vide. */
function kpi_var(float $c, float $p): ?float { return $p > 0 ? ($c - $p) / $p * 100 : null; }

/**
 * Delta signe. $sens = 'haut' quand une hausse est favorable, 'bas'
 * quand c'est une baisse qui l'est (pertes, pannes, delais) : sans cela
 * une fleche verte signalerait une degradation.
 */
function kpi_delta(?float $var, string $sens = 'haut'): string {
    if ($var === null) return '<span class="kd neutre">pas de comparaison</span>';
    $hausse = $var > 0.05; $baisse = $var < -0.05;
    $bon = $hausse ? ($sens === 'haut') : ($baisse ? ($sens === 'bas') : null);
    $cls = $bon === null ? 'neutre' : ($bon ? 'bon' : 'mauvais');
    $fl  = $hausse ? '&#9650;' : ($baisse ? '&#9660;' : '=');
    return '<span class="kd ' . $cls . '">' . $fl . ' '
         . number_format(abs($var), 1, ',', ' ') . ' %</span>';
}

/**
 * Courbe SVG. Une ou deux series alignees sur les memes abscisses.
 * SVG plutot que canvas : les couleurs suivent les variables de theme,
 * le trace reste net a tout facteur de zoom, et rien ne depend de JS —
 * un canvas non dessine laisse un bloc vide, un SVG non lu reste lisible.
 */
function kpi_courbe(array $labels, array $a, array $b = [], string $la = '', string $lb = '',
                    string $unite = '', int $borne_a = -1): string {
    $n = count($a);
    if ($n < 2) return '<p class="kvide">Pas assez de points pour tracer une évolution.</p>';
    $W = 520; $H = 150; $pl = 34; $pr = 8; $pt = 10; $pb = 22;
    $cw = $W - $pl - $pr; $ch = $H - $pt - $pb;
    $max = max(1.0, max($a), $b ? max($b) : 0);
    // Plafond arrondi : un axe qui s'arrete sur 1 837 se lit moins vite
    // qu'un axe qui s'arrete sur 2 000.
    $pas = pow(10, max(0, floor(log10($max)) - 1));
    $max = ceil($max / max($pas, 1)) * max($pas, 1);
    $x = fn($i) => $pl + ($n > 1 ? $cw * $i / ($n - 1) : 0);
    $y = fn($v) => $pt + $ch - ($ch * min($v, $max) / $max);
    // $lim borne la serie tracee sans toucher a l'echelle des abscisses :
    // une periode en cours s'arrete au point atteint, pas au bord du cadre.
    $trace = function (array $s, int $lim = -1) use ($n, $x, $y) {
        $m = ($lim > 1 && $lim < $n) ? $lim : $n;
        $p = []; for ($i = 0; $i < $m; $i++) $p[] = round($x($i), 1) . ',' . round($y($s[$i]), 1);
        return implode(' ', $p);
    };
    $ya = ''; $vu = null;
    for ($g = 3; $g >= 0; $g--) {
        $t = fmt_number((int)round($max * $g / 3));
        $ya .= '<span>' . ($t === $vu ? '' : h($t)) . '</span>';
        $vu = $t;
    }

    $o  = '<div class="kwrap"><div class="kya" aria-hidden="true">' . $ya . '</div>';
    $o .= '<svg class="kchart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" preserveAspectRatio="none"'
        . ' aria-label="' . h($la !== '' ? $la : 'Évolution') . ' — maximum '
        . h(fmt_number((int)$max)) . ' ' . h($unite) . '">';
    for ($g = 0; $g <= 3; $g++) {
        $yy = round($y($max * $g / 3), 1);
        $o .= '<line class="kgrid" x1="' . $pl . '" y1="' . $yy . '" x2="' . ($W - $pr) . '" y2="' . $yy . '"/>';
    }
    if ($b) $o .= '<polyline class="kline kline-b" points="' . $trace($b) . '"/>';
    $o .= '<polyline class="kline kline-a" points="' . $trace($a, $borne_a) . '"/>';
    $o .= '</svg></div>';

    // Etiquettes d'abscisse placees au pourcentage exact du point : une
    // rangee de cellules egales les decalerait d'une demi-colonne.
    $xa = '';
    for ($i = 0; $i < $n; $i++) {
        if (($labels[$i] ?? '') === '') continue;
        $pc = $n > 1 ? $i / ($n - 1) * 100 : 50;
        $xa .= '<span style="left:' . round($pc, 2) . '%">' . h($labels[$i]) . '</span>';
    }
    if ($xa !== '') $o .= '<div class="kxa" aria-hidden="true">' . $xa . '</div>';
    if ($la !== '') {
        $o .= '<p class="klg"><span class="kpt kpt-a"></span>' . h($la);
        if ($lb !== '') $o .= '<span class="kpt kpt-b"></span>' . h($lb);
        $o .= '</p>';
    }
    return $o;
}

/**
 * Anneau de repartition. $segments = [[libelle, valeur, classe], ...].
 * Un seul segment donne la jauge de taux ; plusieurs donnent la
 * repartition. Le centre porte la valeur qui compte, pas un pourcentage
 * qu'il faudrait retraduire.
 */
function kpi_anneau(array $segments, string $centre_v, string $centre_l, string $aria): string {
    $tot = 0.0; foreach ($segments as $s) $tot += max(0.0, (float)$s[1]);
    $R = 52; $C = 2 * M_PI * $R; $off = 0.0;
    $o = '<svg class="kring" viewBox="0 0 140 140" role="img" aria-label="' . h($aria) . '">';
    $o .= '<circle class="kring-bg" cx="70" cy="70" r="' . $R . '"/>';
    foreach ($segments as $s) {
        $v = max(0.0, (float)$s[1]); if ($tot <= 0 || $v <= 0) continue;
        $len = $C * $v / $tot;
        $o .= '<circle class="kring-s ' . h($s[2]) . '" cx="70" cy="70" r="' . $R . '"'
            . ' stroke-dasharray="' . round($len, 2) . ' ' . round($C - $len, 2) . '"'
            . ' stroke-dashoffset="' . round(-$off, 2) . '"/>';
        $off += $len;
    }
    $o .= '<text class="kring-v" x="70" y="68" text-anchor="middle">' . h($centre_v) . '</text>';
    $o .= '<text class="kring-l" x="70" y="88" text-anchor="middle">' . h($centre_l) . '</text>';
    return $o . '</svg>';
}

// ── Libelles de la barre de filtres
$noms_sites = [];
foreach ($sites_list as $s) $noms_sites[(int)$s['id']] = $s['nom'];
if (!$sites_sel) {
    $perimetre_lbl = 'tous les sites';
} elseif (count($sites_sel) === 1) {
    $perimetre_lbl = $noms_sites[$sites_sel[0]] ?? 'un site';
} elseif (count($sites_sel) <= 3) {
    $perimetre_lbl = implode(', ', array_map(fn($i) => $noms_sites[$i] ?? '?', $sites_sel));
} else {
    $perimetre_lbl = count($sites_sel) . ' sites sur ' . count($sites_list);
}

// « Filtres memorises » n'est affiche que si la page a effectivement
// repris une preference, et non a chaque fois qu'une preference existe :
// annoncer une memorisation qui n'a pas servi induirait en erreur.
$memorise = !isset($_GET['periode']) && !isset($_GET['sites'])
         && (isset(pref_toutes()['kpi_dashboard.periode'])
             || isset(pref_toutes()['kpi_dashboard.sites']));

$vues            = vues_listees('kpi_dashboard');
$vue_active      = trim($_GET['vue'] ?? '');
$peut_enregistrer = !$is_coord;

include __DIR__ . '/../templates/header.php';
?>
<style>
/* ── Palette de graphes ────────────────────────────────────────────
   Quatre teintes categorielles, choisies pour rester distinctes en
   clair comme en sombre et pour ne jamais servir de couleur de texte
   (elles ne passeraient pas AA) : uniquement des traits et des fonds. */
.kpi{--k1:#3D4FD1;--k2:#0A7A52;--k3:#B45309;--k4:#7C3AED;--kbg:var(--lighter)}
:root[data-theme="dark"] .kpi,
:root:not([data-theme="light"]) .kpi{--k1:#8FA0FF;--k2:#34D399;--k3:#FBBF24;--k4:#C4B5FD}
@media (prefers-color-scheme: light){:root:not([data-theme="dark"]) .kpi{--k1:#3D4FD1;--k2:#0A7A52;--k3:#B45309;--k4:#7C3AED}}

/* ── En-tete de page ─────────────────────────────────────────────── */
/* Barre de filtres collante, meme comportement que .pdg-topbar sur
   pages/pdg_overview.php (templates/dash_style.php) : elle reste
   atteignable depuis n'importe quel panneau, sans remonter en haut de
   page. Le fond reprend celui de la page pour rester opaque une fois
   colle sous la topbar de l'appli. */
.kpi-bar{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;
  flex-wrap:wrap;position:sticky;top:var(--topbar-h,64px);z-index:40;
  background:var(--tertiary,#F0F4FF);margin:0 -10px 18px;padding:14px 10px 12px;
  border-bottom:1px solid transparent;transition:border-color .18s,box-shadow .18s}
/* margin-left:auto plutot que compter sur le seul justify-content:
   space-between ne pousse a droite le formulaire de filtres que tant
   qu'il partage sa ligne avec le titre. Des que la barre passe a la
   ligne (fenetre etroite), le formulaire se retrouve seul sur la
   sienne et repart a gauche -- margin-left:auto le maintient a droite
   dans les deux cas. */
.kpi-bar form{margin-left:auto}
body.pdg-collee .kpi-bar{border-bottom-color:var(--border);
  box-shadow:0 6px 14px -10px rgba(6,3,58,.35)}
@media(max-width:700px){.kpi-bar{position:static;margin:0 0 18px;padding:0}}
.kpi-bar h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:1.125rem;font-weight:800;
  color:var(--navy);display:flex;align-items:center;gap:8px}
.kpi-bar p{font-size:0.8125rem;color:var(--muted);margin-top:4px}

/* ── Changement de filtre anime (templates/dash_anim.php) ──────────
   Meme traitement que .pdg-bar/.pdg-busy sur pages/pdg_overview.php :
   classes fixees par le script, pas de nom propre a cette page. */
.pdg-bar{position:fixed;top:0;left:0;right:0;height:2px;z-index:9999;background:transparent;
  opacity:0;transition:opacity .15s;pointer-events:none}
.pdg-busy .pdg-bar{opacity:1}
.pdg-bar::after{content:'';position:absolute;top:0;left:0;height:100%;width:38%;
  background:var(--navy,#06033A);animation:pdg-slide 1.05s cubic-bezier(.65,0,.35,1) infinite}
@keyframes pdg-slide{0%{left:-38%}100%{left:100%}}
.pdg-busy .ms-b{cursor:progress}
@media(prefers-reduced-motion:reduce){.pdg-bar::after{animation:none;width:100%}}

/* ── Grille de panneaux ──────────────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;align-items:start}
.kp{grid-column:span 12;min-width:0;background:var(--card,#fff);border:1px solid var(--border);
  border-radius:var(--radius,16px);padding:18px 20px}
@media(min-width:900px){
  .kp--prod{grid-column:span 7}.kp--bob{grid-column:span 5}
  .kp--pmma{grid-column:span 5}.kp--riv{grid-column:span 7}
  .kp--cmd{grid-column:span 7}.kp--eq{grid-column:span 5}
  .kp--sites{grid-column:span 12}
}
@media(min-width:1400px){
  .kp--prod{grid-column:span 5}.kp--bob{grid-column:span 4}.kp--pmma{grid-column:span 3}
  .kp--riv{grid-column:span 3}.kp--cmd{grid-column:span 5}.kp--eq{grid-column:span 4}
}

/* ── En-tete de panneau ──────────────────────────────────────────── */
.kp-h{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.kp-ic{flex:none;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;
  background:var(--primary-l);color:var(--primary-d);font-size:1.0625rem}
.kp-t{font-family:'Plus Jakarta Sans',sans-serif;font-size:0.875rem;font-weight:800;
  color:var(--navy);letter-spacing:.02em;text-transform:uppercase;min-width:0}
.kp-t em{display:block;font-style:normal;font-size:0.75rem;font-weight:600;
  color:var(--muted);text-transform:none;letter-spacing:0;margin-top:2px}

/* ── Cellules de metrique ────────────────────────────────────────
   Fond teinte sans filet : une carte dans une carte donne deux cadres
   concentriques qui n'apportent aucune information et tiennent mal en
   theme sombre. */
.kc-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(112px,1fr));gap:8px}
.kc{background:var(--kbg);border-radius:10px;padding:10px 12px;min-width:0}
.kc-l{font-size:0.75rem;font-weight:700;color:var(--muted);white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
.kc-v{font-family:'Plus Jakarta Sans',sans-serif;font-size:1.375rem;font-weight:800;
  color:var(--navy);line-height:1.15;margin-top:3px;font-variant-numeric:tabular-nums}
.kc-v.sm{font-size:1.125rem}
.kc-n{font-size:0.75rem;color:var(--muted);margin-top:3px;line-height:1.35}
.kd{display:inline-block;font-size:0.75rem;font-weight:700;margin-top:4px}
.kd.bon{color:var(--success-d,#0A7A52)}
.kd.mauvais{color:var(--danger-d,#C0392B)}
.kd.neutre{color:var(--muted);font-weight:600}

/* ── Graphes ─────────────────────────────────────────────────────── */
.kwrap{display:grid;grid-template-columns:auto minmax(0,1fr);gap:7px;margin-top:12px}
.kya{display:flex;flex-direction:column;justify-content:space-between;height:150px;
  font-size:0.75rem;color:var(--muted);text-align:right;font-variant-numeric:tabular-nums;
  line-height:1}
.kya span{display:block}
.kxa{position:relative;height:1.1rem;margin-top:5px;font-size:0.75rem;color:var(--muted)}
.kxa span{position:absolute;transform:translateX(-50%);white-space:nowrap}
.kxa span:first-child{transform:none}
.kxa span:last-child{transform:translateX(-100%)}
.kchart{width:100%;height:150px;display:block}
.kgrid{stroke:var(--border);stroke-width:1;vector-effect:non-scaling-stroke}
.kline{fill:none;stroke-width:2.2;stroke-linejoin:round;stroke-linecap:round;
  vector-effect:non-scaling-stroke}
.kline-a{stroke:var(--k1)}
.kline-b{stroke:var(--muted);stroke-width:1.6;stroke-dasharray:4 3;opacity:.75}
.klg{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:0.75rem;
  color:var(--muted);margin-top:8px}
.kpt{width:16px;height:2.5px;border-radius:2px;background:var(--k1);flex:none}
.kpt-b{background:var(--muted);margin-left:10px}
.kvide{font-size:0.8125rem;color:var(--muted);margin-top:12px}

.kring{width:126px;height:126px;flex:none}
.kring-bg{fill:none;stroke:var(--kbg);stroke-width:14}
.kring-s{fill:none;stroke-width:14;stroke-linecap:butt;transform:rotate(-90deg);
  transform-origin:70px 70px}
.kring-s.s1{stroke:var(--k1)}.kring-s.s2{stroke:var(--k2)}
.kring-s.s3{stroke:var(--k3)}.kring-s.s4{stroke:var(--danger)}
.kring-v{fill:var(--navy);font-size:24px;font-weight:800;
  font-family:'Plus Jakarta Sans',sans-serif}
.kring-l{fill:var(--muted);font-size:13.5px}

/* ── Barres horizontales ─────────────────────────────────────────── */
.kb{display:grid;grid-template-columns:minmax(64px,auto) 1fr 44px;align-items:center;
  gap:10px;padding:5px 0;font-size:0.8125rem}
.kb-n{color:var(--navy);font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;
  white-space:nowrap}
.kb-t{height:8px;border-radius:5px;background:var(--kbg);overflow:hidden;min-width:0}
.kb-t i{display:block;height:100%;border-radius:5px;background:var(--k1)}
.kb-t i.c2{background:var(--k2)}.kb-t i.c3{background:var(--k3)}.kb-t i.c4{background:var(--k4)}
.kb-v{text-align:right;font-weight:700;color:var(--navy);font-variant-numeric:tabular-nums}

/* ── Barres verticales (consommation par type, production par site) ── */
/* Valeur, barre et libelle en flux normal : les positionner en absolu
   decrochait la valeur du haut du cadre au lieu de coiffer sa barre, et
   la colonne la plus haute passait sous le titre de section. */
.kv{display:flex;align-items:stretch;gap:10px;height:158px;margin-top:12px;overflow-x:auto}
.kv-c{flex:1 1 0;min-width:38px;display:flex;flex-direction:column;justify-content:flex-end;gap:4px}
.kv-v{font-size:0.75rem;font-weight:700;color:var(--navy);text-align:center;
  font-variant-numeric:tabular-nums;white-space:nowrap}
.kv-b{flex:1 1 auto;min-height:0;display:flex;gap:3px;align-items:flex-end;justify-content:center}
.kv-b i{width:100%;max-width:26px;border-radius:4px 4px 0 0;background:var(--k1);min-height:2px}
.kv-b i.c2{background:var(--k2)}.kv-b i.c3{background:var(--k3)}.kv-b i.c4{background:var(--k4)}
.kv-b i.prec{background:var(--border)}
.kv-l{font-size:0.75rem;color:var(--muted);text-align:center;min-width:0;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ── Encart d'alerte ─────────────────────────────────────────────── */
.ka{margin-top:12px;background:var(--kbg);border-radius:10px;padding:11px 13px}
.ka-h{display:flex;align-items:center;gap:7px;font-size:0.8125rem;font-weight:700;
  color:var(--warning-d,#8A5A00);margin-bottom:7px}
.ka-l{display:flex;justify-content:space-between;gap:10px;font-size:0.8125rem;
  padding:4px 0;border-bottom:1px solid var(--border)}
.ka-l:last-child{border-bottom:none}
.ka-l b{color:var(--navy);font-weight:600;min-width:0;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.ka-l span{color:var(--danger-d,#C0392B);font-weight:700;white-space:nowrap;
  font-variant-numeric:tabular-nums}
.ka-ok{font-size:0.8125rem;color:var(--success-d,#0A7A52);font-weight:600}

/* ── Classement ──────────────────────────────────────────────────── */
.kcl{display:grid;grid-template-columns:24px 1fr auto auto;align-items:center;gap:10px;
  padding:7px 0;border-bottom:1px solid var(--border);font-size:0.8125rem}
.kcl:last-child{border-bottom:none}
.kcl-r{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;
  background:var(--kbg);color:var(--muted);font-size:0.75rem;font-weight:700}
.kcl:nth-child(1) .kcl-r{background:var(--primary-l);color:var(--primary-d)}
.kcl-n{color:var(--navy);font-weight:600;min-width:0;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.kcl-v{font-weight:800;color:var(--navy);font-variant-numeric:tabular-nums}
.kp-2c{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:20px}
@media(max-width:780px){.kp-2c{grid-template-columns:minmax(0,1fr)}}
.kp-ring{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.kp-lg{display:flex;flex-direction:column;gap:6px;font-size:0.8125rem;min-width:0}
.kp-lg div{display:flex;align-items:center;gap:8px;color:var(--navy)}
.kp-lg u{width:9px;height:9px;border-radius:50%;flex:none;text-decoration:none}
.kp-lg u.s1{background:var(--k1)}.kp-lg u.s2{background:var(--k2)}
.kp-lg u.s3{background:var(--k3)}.kp-lg u.s4{background:var(--danger)}
.kp-lg b{margin-left:auto;font-variant-numeric:tabular-nums}
.kp-sep{height:1px;background:var(--border);margin:14px 0}
.kp-st{font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;
  letter-spacing:.04em;margin-bottom:6px}

/* ── Sélecteur à choix multiple et vues enregistrées ─────────────
   Vingt et un sites ne tiennent pas dans une rangée de pastilles : le
   déroulant garde une hauteur fixe quel que soit leur nombre. */
.ms{position:relative}
.ms-b{display:flex;align-items:center;justify-content:space-between;gap:8px;min-width:170px;
  padding:8px 11px;border:1.5px solid var(--border);border-radius:var(--radius-sm,10px);
  background:var(--card,#fff);font-family:inherit;font-size:0.8125rem;font-weight:600;
  color:var(--navy);cursor:pointer;text-align:left}
.ms-b:hover{border-color:var(--primary-d)}
.ms.open .ms-b{border-color:var(--primary-d)}
.ms.open .ms-b>.ph-caret-down{transform:rotate(180deg)}
.ms-b>.ph-caret-down{color:var(--muted);transition:transform .15s;flex:none}
.ms-t{display:flex;align-items:center;gap:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ms-p{display:none;position:absolute;z-index:150;right:0;top:calc(100% + 5px);min-width:250px;
  max-height:340px;overflow-y:auto;background:var(--card,#fff);border:1.5px solid var(--primary-d);
  border-radius:var(--radius-sm,10px);box-shadow:0 12px 30px rgba(30,43,74,.16)}
.ms.open .ms-p{display:block}
.ms.haut .ms-p{top:auto;bottom:calc(100% + 5px)}
.ms-h{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--lighter);
  border-bottom:1px solid var(--border);position:sticky;top:0}
.ms-h button{border:none;background:none;padding:0;font-family:inherit;font-size:0.75rem;
  font-weight:700;color:var(--primary-d);cursor:pointer;text-decoration:underline}
.ms-c{margin-left:auto;font-size:0.75rem;color:var(--muted)}
.ms-i{display:flex;align-items:center;gap:9px;padding:7px 12px;cursor:pointer;
  border-bottom:1px solid var(--border);font-size:0.8125rem;color:var(--navy)}
.ms-i:hover{background:var(--lighter)}
.ms-i input{flex:none;width:16px;height:16px;margin:0;accent-color:var(--primary-d)}
.ms-i span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ms-f{display:flex;justify-content:flex-end;gap:7px;padding:9px 12px;background:var(--card,#fff);
  border-top:1px solid var(--border);position:sticky;bottom:0}
.ms-f button,.ms-nv button{border:1.5px solid var(--border);background:var(--card,#fff);
  border-radius:8px;padding:6px 13px;font-family:inherit;font-size:0.75rem;font-weight:700;
  color:var(--navy);cursor:pointer}
.ms-f button:not(.ms-ok):hover{background:var(--lighter)}
.ms-ok{background:var(--primary-d);border-color:var(--primary-d);color:#fff}
.ms-f button.ms-ok:hover,.ms-nv button.ms-ok:hover{background:var(--primary-d);filter:brightness(1.12)}
.ms-p--vues{min-width:280px}
.ms-v{display:flex;align-items:center;border-bottom:1px solid var(--border)}
.ms-v a{flex:1;min-width:0;padding:9px 12px;font-size:0.8125rem;color:var(--navy);
  text-decoration:none;font-weight:600}
.ms-v a:hover{background:var(--lighter)}
.ms-v em{display:block;font-style:normal;font-size:0.75rem;font-weight:500;color:var(--muted)}
.ms-x{border:none;background:none;padding:8px 11px;color:var(--muted);cursor:pointer;font-size:0.9375rem}
.ms-x:hover{color:var(--danger-d,#C0392B)}
.ms-vide{padding:12px;font-size:0.8125rem;color:var(--muted);line-height:1.45}
.ms-nv{display:flex;align-items:center;gap:7px;flex-wrap:wrap;padding:10px 12px;
  border-top:1px solid var(--border);background:var(--lighter)}
.ms-nv input[type=text]{flex:1;min-width:110px;padding:6px 9px;border:1.5px solid var(--border);
  border-radius:8px;font-size:0.8125rem;font-family:inherit;box-sizing:border-box}
.ms-part{display:flex;align-items:center;gap:5px;font-size:0.75rem;color:var(--muted);
  white-space:nowrap;cursor:pointer}
.ms-part input{width:auto;margin:0}
</style>

<div class="kpi">

<div class="kpi-bar">
  <div>
    <h2><i class="ph ph-gauge" aria-hidden="true"></i> Indicateurs de performance</h2>
    <p><?= h($P['libelle']) ?> · comparaison avec <?= h($P['libelle_prec']) ?>
      · <?= h($perimetre_lbl) ?><?= $memorise ? ' · filtres mémorisés' : '' ?></p>
  </div>
  <?php /* data-dash-filtre : le changement de periode passe par l'echange
           anime de templates/dash_anim.php (meme mecanisme que
           pages/pdg_overview.php), au lieu d'un rechargement complet. Les
           deux blocs a choix multiple (#msSites, #msVues) portent
           data-dash-nofiltre : leurs propres cases/champs ne doivent pas
           declencher l'echange a chaque interaction, seul le bouton
           « Appliquer » le fait, explicitement, via window.dashFiltrer(). */ ?>
  <form method="GET" id="kpiForm" data-dash-filtre style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php /* Le marqueur distingue une action de l'utilisateur d'un lien
             reçu : seule la première mémorise ses filtres. */ ?>
    <input type="hidden" name="<?= MARQUEUR_INTERACTION ?>" value="1">

    <?php if (!$is_coord): ?>
    <div class="ms" id="msSites" data-dash-nofiltre>
      <button type="button" class="ms-b" onclick="msOuvrir(this)" aria-expanded="false">
        <span class="ms-t"></span><i class="ph ph-caret-down" aria-hidden="true"></i>
      </button>
      <div class="ms-p">
        <div class="ms-h">
          <button type="button" onclick="msTout(this,0)">Tous les sites</button>
          <span class="ms-c"><?= count($sites_list) ?> sites</span>
        </div>
        <?php foreach ($sites_list as $s): $i = (int)$s['id']; ?>
        <label class="ms-i">
          <input type="checkbox" name="sites[]" value="<?= $i ?>"
                 <?= in_array($i, $sites_sel, true) ? 'checked' : '' ?> onchange="msMaj(this)">
          <span><?= h($s['nom']) ?></span>
        </label>
        <?php endforeach; ?>
        <div class="ms-f">
          <button type="button" onclick="msAnnuler(this)">Annuler</button>
          <button type="button" class="ms-ok" onclick="msValider(this)">Appliquer</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?= periode_selecteur($P) ?>

    <?php if ($vues || $peut_enregistrer): ?>
    <div class="ms" id="msVues" data-dash-nofiltre>
      <button type="button" class="ms-b" onclick="msOuvrir(this)" aria-expanded="false"
              title="Vues enregistrées">
        <span class="ms-t"><i class="ph ph-bookmark-simple" aria-hidden="true"></i>
          <?= $vue_active ? h($vue_active) : 'Vues' ?></span>
        <i class="ph ph-caret-down" aria-hidden="true"></i>
      </button>
      <div class="ms-p ms-p--vues">
        <?php if ($vues): foreach ($vues as $v): ?>
        <div class="ms-v">
          <a href="?<?= h($v['filtres']) ?>&amp;vue=<?= h($v['nom']) ?>"><?= h($v['nom']) ?>
            <?php if (!$v['mienne']): ?><em>par <?= h($v['auteur'] ?: 'un collègue') ?></em><?php endif; ?>
            <?php if ($v['partagee'] && $v['mienne']): ?><em>partagée</em><?php endif; ?>
          </a>
          <?php if ($v['mienne']): ?>
          <button type="button" class="ms-x" title="Supprimer cette vue"
                  onclick="vueSupprimer(<?= (int)$v['id'] ?>, this)">
            <i class="ph ph-trash" aria-hidden="true"></i></button>
          <?php endif; ?>
        </div>
        <?php endforeach; else: ?>
        <p class="ms-vide">Aucune vue enregistrée. Réglez vos filtres, puis nommez-les ici.</p>
        <?php endif; ?>
        <?php if ($peut_enregistrer): ?>
        <div class="ms-nv">
          <input type="text" id="vueNom" maxlength="80" placeholder="Nom de la vue"
                 aria-label="Nom de la vue à enregistrer">
          <label class="ms-part"><input type="checkbox" id="vuePartage"> Partager</label>
          <button type="button" class="ms-ok" onclick="vueEnregistrer()">Enregistrer</button>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </form>
</div>

<div class="kpi-grid">

  <!-- ══ PRODUCTION ══ -->
  <section class="kp kp--prod" aria-labelledby="kp-prod">
    <div class="kp-h">
      <span class="kp-ic"><i class="ph ph-car" aria-hidden="true"></i></span>
      <h3 class="kp-t" id="kp-prod">Production<em>plaques posées — comparaison à date, à nombre de jours égal</em></h3>
    </div>
    <div class="kc-row">
      <?php foreach ($echelles as [$lbl, $v, $vp, $note]): ?>
      <div class="kc">
        <div class="kc-l"><?= h($lbl) ?></div>
        <div class="kc-v"><?= fmt_number((int)$v) ?></div>
        <?= kpi_delta(kpi_var($v, $vp)) ?>
        <div class="kc-n"><?= h($note) ?> (<?= fmt_number((int)$vp) ?>)</div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="kp-sep"></div>
    <div class="kp-st">Évolution — <?= h($serie_note) ?></div>
    <?= kpi_courbe($serie_lbl, $serie_a, $serie_b,
                   'Période actuelle', $serie_b ? 'Période précédente' : '', 'plaques',
                   $serie_borne) ?>
  </section>

  <!-- ══ BOBINES ══ -->
  <section class="kp kp--bob" aria-labelledby="kp-bob">
    <div class="kp-h">
      <span class="kp-ic"><i class="ph ph-film-strip" aria-hidden="true"></i></span>
      <h3 class="kp-t" id="kp-bob">Bobines<em>état du parc — photo instantanée, hors période</em></h3>
    </div>
    <div class="kp-ring">
      <?= kpi_anneau([['Utilisé', $taux_util, 's1'], ['Restant', 100 - $taux_util, '']],
                     number_format($taux_util, 1, ',', ' ') . ' %', "d'utilisation",
                     'Taux d\'utilisation ' . number_format($taux_util, 1, ',', ' ') . ' %') ?>
      <div class="kp-lg">
        <div><u class="s1"></u>Actives<b><?= fmt_number((int)($bob['actives'] ?? 0)) ?></b></div>
        <div><u class="s3"></u>Épuisées<b><?= fmt_number((int)($bob['epuisees'] ?? 0)) ?></b></div>
        <div><u class="s4"></u>Films endommagés<b><?= fmt_number((int)$b_endo) ?></b></div>
        <div><u class="s2"></u>Films restants<b><?= fmt_number((int)($bob['restants'] ?? 0)) ?></b></div>
      </div>
    </div>
    <p class="kc-n" style="margin-top:10px">
      <?= $couverture !== null
          ? 'Couverture : ' . fmt_number($couverture) . ' jours au rythme observé sur 30 jours.'
          : 'Aucune consommation observée sur 30 jours : la couverture ne peut pas être calculée.' ?>
      Perte : <?= number_format($taux_perte, 2, ',', ' ') ?> % des films sortis.
    </p>
    <?php if ($bob_series): ?>
    <div class="kp-sep"></div>
    <div class="kp-st">Utilisation par format</div>
    <?php $i = 0; foreach ($bob_series as $bs): $i++; ?>
    <div class="kb">
      <span class="kb-n"><?= h($bs['lbl']) ?></span>
      <span class="kb-t"><i class="c<?= (($i - 1) % 4) + 1 ?>" style="width:<?= round(min(100, $bs['pct']), 1) ?>%"></i></span>
      <span class="kb-v"><?= number_format($bs['pct'], 0, ',', ' ') ?> %</span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <!-- ══ PMMA ══ -->
  <section class="kp kp--pmma" aria-labelledby="kp-pmma">
    <div class="kp-h">
      <span class="kp-ic"><i class="ph ph-printer" aria-hidden="true"></i></span>
      <h3 class="kp-t" id="kp-pmma">PMMA<em>consommation de la période, stock et seuils</em></h3>
    </div>
    <div class="kc-row">
      <div class="kc">
        <div class="kc-l">Consommés</div>
        <div class="kc-v"><?= fmt_number($pmma_conso) ?></div>
        <?= kpi_delta(kpi_var($pmma_conso, $pmma_conso_p)) ?>
        <div class="kc-n">vs <?= h($P['libelle_prec']) ?> (<?= fmt_number($pmma_conso_p) ?>)</div>
      </div>
      <div class="kc">
        <div class="kc-l">Stock disponible</div>
        <div class="kc-v"><?= fmt_number($pmma_total) ?></div>
        <div class="kc-n"><?= count($pmma_stock) ?> type(s) suivi(s)</div>
      </div>
    </div>
    <?php if ($pmma_par_type): $mx = 0; foreach ($pmma_par_type as $t) $mx = max($mx, (int)$t['v']); ?>
    <div class="kp-st" style="margin-top:14px">Consommation par type</div>
    <div class="kv">
      <?php $i = 0; foreach ($pmma_par_type as $t): $i++; ?>
      <div class="kv-c">
        <span class="kv-v"><?= fmt_number((int)$t['v']) ?></span>
        <span class="kv-b"><i class="c<?= (($i - 1) % 4) + 1 ?>"
          style="height:<?= $mx > 0 ? round((int)$t['v'] / $mx * 100, 1) : 0 ?>%"></i></span>
        <span class="kv-l"><?= h($t['t']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="ka">
      <?php if ($pmma_alertes): ?>
      <div class="ka-h"><i class="ph-fill ph-warning" aria-hidden="true"></i> Sous le seuil d'alerte</div>
      <?php foreach ($pmma_alertes as $a): ?>
      <div class="ka-l"><b><?= h($a['t']) ?> · <?= h($a['site']) ?></b>
        <span><?= fmt_number((int)$a['q']) ?> / <?= fmt_number((int)$a['seuil']) ?></span></div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="ka-ok"><i class="ph-fill ph-check-circle" aria-hidden="true"></i>
        Aucun type de PMMA sous son seuil.</div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ══ RIVETS ══ -->
  <section class="kp kp--riv" aria-labelledby="kp-riv">
    <div class="kp-h">
      <span class="kp-ic"><i class="ph ph-push-pin" aria-hidden="true"></i></span>
      <h3 class="kp-t" id="kp-riv">Rivets<em>consommation de la période et stock disponible</em></h3>
    </div>
    <div class="kc-row">
      <div class="kc">
        <div class="kc-l">Consommés</div>
        <div class="kc-v"><?= fmt_number($riv_conso) ?></div>
        <?= kpi_delta(kpi_var($riv_conso, $riv_conso_p)) ?>
        <div class="kc-n">vs <?= h($P['libelle_prec']) ?> (<?= fmt_number($riv_conso_p) ?>)</div>
      </div>
      <div class="kc">
        <div class="kc-l">Stock disponible</div>
        <div class="kc-v"><?= fmt_number($riv_stock) ?></div>
        <div class="kc-n"><?= $riv_bas > 0
            ? fmt_number($riv_bas) . ' site(s) sous le seuil'
            : 'aucun site sous le seuil' ?></div>
      </div>
    </div>
    <div class="ka">
      <?php if ($riv_alertes): ?>
      <div class="ka-h"><i class="ph-fill ph-warning" aria-hidden="true"></i> Sous le seuil d'alerte</div>
      <?php foreach ($riv_alertes as $a): ?>
      <div class="ka-l"><b><?= h($a['site']) ?> · <?= h($a['t']) ?></b>
        <span><?= fmt_number((int)$a['q']) ?> / <?= fmt_number((int)$a['seuil']) ?></span></div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="ka-ok"><i class="ph-fill ph-check-circle" aria-hidden="true"></i>
        Tous les sites sont au-dessus de leur seuil.</div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ══ COMMANDES ══ -->
  <section class="kp kp--cmd" aria-labelledby="kp-cmd">
    <div class="kp-h">
      <span class="kp-ic"><i class="ph ph-clipboard-text" aria-hidden="true"></i></span>
      <h3 class="kp-t" id="kp-cmd">Commandes<em>commandes créées sur la période</em></h3>
    </div>
    <div class="kc-row">
      <div class="kc">
        <div class="kc-l">Taux de satisfaction</div>
        <div class="kc-v"><?= $cmd_total > 0 ? number_format($taux_service, 1, ',', ' ') . ' %' : '—' ?></div>
        <div class="kc-n"><?= $cmd_total > 0
            ? (int)$cmd['servies'] . ' servie(s) sur ' . $cmd_total
            : 'aucune commande sur la période' ?></div>
      </div>
      <div class="kc">
        <div class="kc-l">Délai moyen</div>
        <div class="kc-v"><?= $cmd_total > 0 ? number_format((float)$cmd['delai'], 1, ',', ' ') . ' j' : '—' ?></div>
        <div class="kc-n">de la création à la livraison</div>
      </div>
      <div class="kc">
        <div class="kc-l">En attente</div>
        <div class="kc-v"><?= fmt_number((int)($cmd['en_cours'] ?? 0)) ?></div>
        <div class="kc-n">non encore livrées</div>
      </div>
    </div>
    <div class="kp-sep"></div>
    <div class="kp-st">Taux de satisfaction — six dernières périodes</div>
    <?= kpi_courbe($cmd_hist_lbl, $cmd_hist, [], 'Taux de satisfaction', '', '%') ?>
  </section>

  <!-- ══ ÉQUIPEMENTS ══ -->
  <section class="kp kp--eq" aria-labelledby="kp-eq">
    <div class="kp-h">
      <span class="kp-ic"><i class="ph ph-desktop" aria-hidden="true"></i></span>
      <h3 class="kp-t" id="kp-eq">Équipements<em>parc actif — photo instantanée</em></h3>
    </div>
    <div class="kc-row">
      <div class="kc">
        <div class="kc-l">Disponibilité</div>
        <div class="kc-v"><?= $eq_total > 0 ? number_format($dispo, 1, ',', ' ') . ' %' : '—' ?></div>
        <div class="kc-n"><?= $eq_total > 0 ? fmt_number($eq_hs) . ' hors service' : 'aucun équipement actif' ?></div>
      </div>
      <div class="kc">
        <div class="kc-l">Interventions</div>
        <div class="kc-v"><?= fmt_number($interv_ouvertes) ?></div>
        <div class="kc-n">non résolues à ce jour</div>
      </div>
    </div>
    <?php if ($eq_total > 0): ?>
    <div class="kp-sep"></div>
    <div class="kp-ring">
      <?= kpi_anneau([['Disponibles', $eq_ok, 's2'], ['Hors service', $eq_hs, 's4'],
                      ['Maintenance', $eq_maint, 's3'], ['Autre état', $eq_autre, 's1']],
                     fmt_number($eq_total), 'équipements',
                     "Répartition du parc : $eq_ok disponibles, $eq_hs hors service, "
                     . "$eq_maint en maintenance, $eq_autre autre état") ?>
      <div class="kp-lg">
        <div><u class="s2"></u>Disponibles<b><?= fmt_number($eq_ok) ?></b></div>
        <div><u class="s4"></u>Hors service<b><?= fmt_number($eq_hs) ?></b></div>
        <?php if ($eq_maint): ?>
        <div><u class="s3"></u>Maintenance<b><?= fmt_number($eq_maint) ?></b></div>
        <?php endif; ?>
        <?php if ($eq_autre): ?>
        <div><u class="s1"></u>Autre état<b><?= fmt_number($eq_autre) ?></b></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>

  <!-- ══ SITES ══ -->
  <?php if (count($sites_sel) !== 1): ?>
  <section class="kp kp--sites" aria-labelledby="kp-sites">
    <div class="kp-h">
      <span class="kp-ic"><i class="ph ph-map-pin" aria-hidden="true"></i></span>
      <h3 class="kp-t" id="kp-sites">Sites<em>plaques posées — <?= h($P['libelle']) ?></em></h3>
    </div>
    <?php if (empty($classement)): ?>
      <p class="kvide">Aucune production enregistrée sur cette période.</p>
    <?php else: ?>
    <div class="kp-2c">
      <div>
        <div class="kp-st">Production par site, comparée à <?= h($P['libelle_prec']) ?></div>
        <?php if ($en_cours): ?>
        <p class="kc-n" style="margin:-2px 0 4px"><?= h(ucfirst($en_cours)) ?>.</p>
        <?php endif; ?>
        <div class="kv">
          <?php foreach (array_slice($classement, 0, 8) as $c): ?>
          <div class="kv-c">
            <span class="kv-v"><?= fmt_number((int)$c['plaques']) ?></span>
            <span class="kv-b">
              <i style="height:<?= $plaques_max > 0 ? round((int)$c['plaques'] / $plaques_max * 100, 1) : 0 ?>%"></i>
              <i class="prec" style="height:<?= $plaques_max > 0 ? round((int)$c['plaques_p'] / $plaques_max * 100, 1) : 0 ?>%"></i>
            </span>
            <span class="kv-l" title="<?= h($c['nom']) ?>"><?= h($c['nom']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <p class="klg"><span class="kpt"></span>Période actuelle
          <span class="kpt kpt-b" style="background:var(--border)"></span><?= h($P['libelle_prec']) ?></p>
      </div>
      <div>
        <div class="kp-st">Classement productivité</div>
        <?php $r = 0; foreach (array_slice($classement, 0, 6) as $c): $r++;
          $vh = (float)$c['heures'] > 0 ? (float)$c['engins'] / (float)$c['heures'] : 0; ?>
        <div class="kcl">
          <span class="kcl-r"><?= $r ?></span>
          <span class="kcl-n"><?= h($c['nom']) ?></span>
          <span class="kcl-v"><?= fmt_number((int)$c['plaques']) ?></span>
          <?= kpi_delta(kpi_var((float)$c['plaques'], (float)$c['plaques_p'])) ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

</div>
</div>

<script>var MARQUEUR_INTERACTION = '<?= MARQUEUR_INTERACTION ?>';</script>

<script>
// ── Selecteur a choix multiple
// Le libelle du bouton doit dire le perimetre sans l'ouvrir : « Tous les
// sites », le nom quand il n'y en a qu'un, un compte au-dela.
function msDd(el){ return el.closest('.ms'); }

function msTexte(dd){
  var t = dd.querySelector('.ms-t'); if (!t || dd.id !== 'msSites') return;
  var c = dd.querySelectorAll('input[type=checkbox]'), pris = [];
  c.forEach(function(x){
    if (x.checked) pris.push(x.parentElement.querySelector('span').textContent.trim());
  });
  t.textContent = pris.length === 0 ? 'Tous les sites'
                : pris.length === 1 ? pris[0]
                : pris.length + ' sites sur ' + c.length;
}

function msPhoto(dd){
  dd._photo = Array.prototype.map.call(
    dd.querySelectorAll('input[type=checkbox]'), function(c){ return c.checked; });
}

function msOuvrir(btn){
  var dd = msDd(btn), ouvert = dd.classList.contains('open');
  document.querySelectorAll('.ms.open').forEach(function(o){
    o.classList.remove('open');
    o.querySelector('.ms-b').setAttribute('aria-expanded', 'false');
  });
  if (ouvert) return;
  msPhoto(dd);
  dd.classList.add('open');
  btn.setAttribute('aria-expanded', 'true');
  // Panneau qui deborde en bas : on le retourne, sinon son pied — donc le
  // bouton Appliquer — sort de l'ecran.
  dd.classList.remove('haut');
  var r = dd.querySelector('.ms-p').getBoundingClientRect();
  if (r.bottom > window.innerHeight - 8 && btn.getBoundingClientRect().top > r.height + 8)
    dd.classList.add('haut');
}

function msMaj(el){ msTexte(msDd(el)); }

function msTout(btn, on){
  var dd = msDd(btn);
  dd.querySelectorAll('input[type=checkbox]').forEach(function(c){ c.checked = !!on; });
  msTexte(dd);
}

function msAnnuler(btn){
  var dd = msDd(btn);
  if (dd._photo) dd.querySelectorAll('input[type=checkbox]').forEach(function(c, i){
    c.checked = !!dd._photo[i];
  });
  msTexte(dd);
  dd.classList.remove('open');
}

function msValider(btn){
  msDd(btn).classList.remove('open');
  var form = document.getElementById('kpiForm');
  // window.dashFiltrer (templates/dash_anim.php) echange le contenu sans
  // rechargement, comme pages/pdg_overview.php ; repli sur l'envoi
  // classique si le module n'est pas charge.
  if (window.dashFiltrer) window.dashFiltrer(form); else form.submit();
}

document.addEventListener('click', function(e){
  if (e.target.closest('.ms')) return;
  document.querySelectorAll('.ms.open').forEach(function(o){ o.classList.remove('open'); });
});
document.addEventListener('keydown', function(e){
  if (e.key !== 'Escape') return;
  document.querySelectorAll('.ms.open').forEach(function(o){
    var b = o.querySelector('.ms-f button');
    if (b) msAnnuler(b); else o.classList.remove('open');
  });
});

// ── Vues enregistrees
function vuePost(donnees, apres){
  fetch(location.pathname, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: new URLSearchParams(donnees).toString()
  }).then(function(r){ return r.json(); })
    .then(function(j){
      if (typeof toast === 'function') toast(j.message, j.success ? 'success' : 'danger');
      else alert(j.message);
      if (j.success) apres();
    })
    .catch(function(){ alert('Erreur reseau.'); });
}

function vueEnregistrer(){
  var champ = document.getElementById('vueNom');
  var nom = champ.value.trim();
  if (!nom) { champ.focus(); return; }
  // On enregistre les filtres tels qu'affiches, marqueur d'interaction
  // exclu : il n'a de sens que pour la memorisation automatique.
  var d = new FormData(document.getElementById('kpiForm'));
  d.delete(MARQUEUR_INTERACTION);
  vuePost({ action: 'vue_creer', nom: nom,
            filtres: new URLSearchParams(d).toString(),
            partagee: document.getElementById('vuePartage').checked ? 1 : '' },
          function(){ location.reload(); });
}

function vueSupprimer(id, btn){
  vuePost({ action: 'vue_supprimer', id: id },
          function(){ btn.closest('.ms-v').remove(); });
}

document.querySelectorAll('.ms').forEach(msTexte);
</script>

<?php
// Meme moteur de rafraichissement que pages/pdg_overview.php : echange
// anime du contenu au changement de filtre, sans rechargement de page
// (cf. templates/dash_anim.php — RACINE y reconnait '.kpi').
include __DIR__ . '/../templates/dash_anim.php';
include __DIR__ . '/../templates/footer.php';
?>
