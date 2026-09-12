<?php
// ============================================================
//  pages/pdg_overview.php — Vue exécutive PDG
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/periode.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/achats.php';

require_auth();
$user = current_user();

$page_title  = 'Vue PDG';
$active_page = 'pdg_overview';

// n° 2.2 — la granularité temporelle vient désormais de
// includes/periode.php, partagée avec le dashboard KPI. Les variables
// locales sont conservées sous leurs noms d'origine : les DATE_FORMAT(...)
// et les comparaisons à la période précédente en aval restent inchangés
// (cf. includes/periode.php — date_fmt en tokens MySQL sur cette branche,
// pas les tokens TO_CHAR de main).
$P = periode_contexte();
$periode          = $P['periode'];
$date_fmt         = $P['date_fmt'];
$periode_val      = $P['val'];
$periode_val_prec = $P['val_prec'];
$periode_du       = $P['du'];
$periode_au       = $P['au'];
$mois_display     = $P['libelle'];
$mois_prec_lbl    = $P['libelle_prec'];
$periode_mot      = $P['mot'];
$mois             = $P['mois'];
$jour             = $P['jour'];
$annee            = (string)$P['annee'];
$annee_filtre     = (int)$P['annee'];
$annee_min        = $P['annee_min'];
$annee_max        = $P['annee_max'];
$site_id          = (int)($_GET['site_id'] ?? 0);

// ── LISTE DES SITES pour le filtre
$sites_list = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");
$site_nom_sel = '';
foreach ($sites_list as $_s) { if ((int)$_s['id'] === $site_id) { $site_nom_sel = $_s['nom']; break; } }

// ── HELPERS SQL site-filter (site_id=0 → tous les sites, int donc safe en interpolation)
$sf   = $site_id ? "AND site_id = $site_id"   : "";   // colonne site_id directe
$sf_p = $site_id ? "AND p.site_id = $site_id" : "";   // table aliasée p
$sf_s = $site_id ? "AND s.id = $site_id"      : "";   // table sites aliasée s
$sf_b = $site_id ? "AND b.site_id = $site_id" : "";   // table op_bobines aliasée b

// ── OPÉRATIONS mois courant
$ops = db_fetch_one(
    "SELECT COUNT(*) AS total_points,
            SUM(CASE WHEN statut='valide' THEN 1 ELSE 0 END) AS points_valides,
            SUM(CASE WHEN statut='en_attente_validation' THEN 1 ELSE 0 END) AS points_attente,
            SUM(CASE WHEN statut='rejete' THEN 1 ELSE 0 END) AS points_rejetes,
            COALESCE(SUM(total_engins),0) AS total_engins,
            COALESCE(SUM(total_plaques),0) AS total_plaques,
            COALESCE(SUM(rivets_utilises),0) AS rivets_utilises,
            COALESCE(ROUND(AVG(NULLIF(moyenne_prod,0)),1),0) AS moy_prod
     FROM op_points_journaliers
     WHERE DATE_FORMAT(date_point,'$date_fmt')=? AND statut != 'brouillon' $sf",
    [$periode_val]
);

// ── OPÉRATIONS période précédente (tendance)
$ops_prec = db_fetch_one(
    "SELECT COALESCE(SUM(total_engins),0) AS total_engins,
            COALESCE(SUM(total_plaques),0) AS total_plaques
     FROM op_points_journaliers
     WHERE DATE_FORMAT(date_point,'$date_fmt')=? AND statut != 'brouillon' $sf",
    [$periode_val_prec]
);
$engins_curr = (int)($ops['total_engins'] ?? 0);
$engins_prev = (int)($ops_prec['total_engins'] ?? 0);
$engins_delta = $engins_curr - $engins_prev;
$engins_trend = $engins_prev > 0 ? round($engins_delta / $engins_prev * 100, 1) : null;

// ── SITES — performance par site
$prod_par_site = db_fetch_all(
    "SELECT s.nom, s.type,
            COUNT(p.id) AS nb_points,
            COALESCE(SUM(p.total_engins),0) AS engins,
            COALESCE(SUM(p.total_plaques),0) AS plaques,
            COALESCE(ROUND(AVG(NULLIF(p.moyenne_prod,0)),1),0) AS moy_vh,
            SUM(CASE WHEN p.statut='en_attente_validation' THEN 1 ELSE 0 END) AS en_attente
     FROM sites s
     LEFT JOIN op_points_journaliers p ON p.site_id=s.id
                AND DATE_FORMAT(p.date_point,'$date_fmt')=? AND p.statut != 'brouillon'
     WHERE s.actif=1 $sf_s
     GROUP BY s.id, s.nom, s.type ORDER BY engins DESC",
    [$periode_val]
);
$max_engins = empty($prod_par_site) ? 1 : max(1, ...array_column($prod_par_site, 'engins'));
$best_site  = !empty($prod_par_site) ? $prod_par_site[0] : null;

// ── Taux de validation points
$tx_valid = ($ops['total_points']??0) > 0
    ? round(($ops['points_valides']??0) / ($ops['total_points']??1) * 100)
    : 0;

// ── BOBINES
$bobines_stats = db_fetch_one(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN statut='en_cours' THEN 1 ELSE 0 END) AS en_cours,
            SUM(CASE WHEN statut='en_stock' THEN 1 ELSE 0 END) AS en_stock,
            SUM(CASE WHEN statut='epuisee' THEN 1 ELSE 0 END) AS epuisees,
            COALESCE(SUM(films_restants),0) AS films_restants
     FROM op_bobines WHERE 1=1 $sf"
);
$films_mois = (int)db_fetch_value(
    "SELECT COALESCE(SUM(fu.films_utilises),0)
     FROM op_films_utilises fu
     JOIN op_points_journaliers p ON p.id=fu.point_id
     WHERE DATE_FORMAT(p.date_point,'$date_fmt')=? $sf_p",
    [$periode_val]
);
$films_mois_prec = (int)db_fetch_value(
    "SELECT COALESCE(SUM(fu.films_utilises),0)
     FROM op_films_utilises fu
     JOIN op_points_journaliers p ON p.id=fu.point_id
     WHERE DATE_FORMAT(p.date_point,'$date_fmt')=? $sf_p",
    [$periode_val_prec]
);
$films_par_site = db_fetch_all(
    "SELECT s.nom, COALESCE(SUM(fu.films_utilises),0) AS films
     FROM sites s
     LEFT JOIN op_points_journaliers p ON p.site_id=s.id AND DATE_FORMAT(p.date_point,'$date_fmt')=?
     LEFT JOIN op_films_utilises fu ON fu.point_id=p.id
     WHERE s.actif=1 $sf_s
     GROUP BY s.id, s.nom ORDER BY films DESC",
    [$periode_val]
);
$films_detail_raw = db_fetch_all(
    "SELECT s.nom AS site, b.type_code, COALESCE(SUM(fu.films_utilises),0) AS films
     FROM sites s
     JOIN op_points_journaliers p ON p.site_id=s.id AND DATE_FORMAT(p.date_point,'$date_fmt')=?
     JOIN op_films_utilises fu ON fu.point_id=p.id
     JOIN op_bobines b ON b.id=fu.bobine_id
     WHERE s.actif=1 $sf_s
     GROUP BY s.id, s.nom, b.type_code ORDER BY s.nom, b.type_code",
    [$periode_val]
);
$films_par_type = [];
$films_by_type  = [];
foreach ($films_detail_raw as $d) {
    $films_par_type[$d['type_code']] = ($films_par_type[$d['type_code']] ?? 0) + (int)$d['films'];
    $films_by_type[$d['type_code']][] = ['site'=>$d['site'],'films'=>(int)$d['films']];
}
arsort($films_par_type);
$js_films_detail = json_encode(array_values(array_map(fn($t)=>$films_by_type[$t]??[], array_keys($films_par_type))));

// ── COMMANDES
$cmd_stats = db_fetch_one(
    "SELECT SUM(CASE WHEN statut='en_attente' THEN 1 ELSE 0 END) AS en_attente,
            SUM(CASE WHEN statut='en_attente_livraison' THEN 1 ELSE 0 END) AS a_livrer,
            SUM(CASE WHEN statut='en_cours_livraison' THEN 1 ELSE 0 END) AS en_route,
            SUM(CASE WHEN statut='livre' AND DATE_FORMAT(created_at,'$date_fmt')=? THEN 1 ELSE 0 END) AS livrees_mois
     FROM commandes WHERE 1=1 $sf",
    [$periode_val]
);

// ── RIVETS
$rivets = db_fetch_all(
    "SELECT s.nom, sr.type_rivet, COALESCE(sr.quantite,0) AS quantite
     FROM sites s JOIN op_stock_rivets sr ON sr.site_id=s.id
     WHERE s.actif=1 $sf_s
     ORDER BY s.nom, FIELD(sr.type_rivet, 'gonflable','eclate')"
);
$rps = [];
foreach ($rivets as $r) $rps[$r['nom']][$r['type_rivet']] = (int)$r['quantite'];

// ── PMMA
$pmma_par_type = db_fetch_all(
    "SELECT sp.type_pmma, COALESCE(SUM(sp.quantite),0) AS total,
            COUNT(CASE WHEN sp.quantite < sp.seuil_alerte THEN 1 END) AS nb_bas
     FROM stock_pmma_site sp JOIN sites s ON s.id=sp.site_id
     WHERE s.actif=1 $sf_s
     GROUP BY sp.type_pmma ORDER BY total DESC"
);
$pmma_detail = db_fetch_all(
    "SELECT s.nom, sp.type_pmma, COALESCE(sp.quantite,0) AS quantite, COALESCE(sp.seuil_alerte,10) AS seuil
     FROM sites s JOIN stock_pmma_site sp ON sp.site_id=s.id
     WHERE s.actif=1 $sf_s ORDER BY sp.type_pmma, s.nom"
);

// ── ALERTES
// La table articles est un catalogue global : elle porte stock_global et
// seuil_alerte, sans aucune colonne de site. Le filtre par site appliqué ici
// référençait donc une colonne inexistante et faisait échouer toute la page
// dès qu'un site était choisi (SQLSTATE 42703). Le compte reste global,
// puisque c'est la seule lecture que le schéma permet.
$alertes_stock  = (int)db_fetch_value("SELECT COUNT(*) FROM articles WHERE stock_global <= seuil_alerte AND seuil_alerte > 0");
$rivets_bas     = (int)db_fetch_value("SELECT COUNT(*) FROM op_stock_rivets WHERE quantite < 200 $sf");
$points_attente = (int)($ops['points_attente'] ?? 0);
$cmd_en_attente = (int)($cmd_stats['en_attente'] ?? 0);
$total_alertes  = $points_attente + $cmd_en_attente + $alertes_stock + $rivets_bas;

// ── DEMANDES INTERNES
// di_demandes ne porte pas de site : une demande est rattachée à son
// demandeur, et c'est le site du demandeur qui la situe. Les deux filtres
// visaient une colonne di_demandes.site_id qui n'existe pas — même panne que
// pour les articles ci-dessus, masquée par elle.
$sf_di      = $site_id ? "AND d.demandeur_id IN (SELECT id FROM users WHERE site_id = $site_id)" : "";
$sf_di_bare = $site_id ? "AND demandeur_id IN (SELECT id FROM users WHERE site_id = $site_id)"   : "";
$di_pending = (int)db_fetch_value("SELECT COUNT(*) FROM di_demandes WHERE statut IN ('en_attente','en_cours') $sf_di_bare");
$di_mois    = (int)db_fetch_value("SELECT COUNT(*) FROM di_demandes WHERE DATE_FORMAT(created_at,'$date_fmt')=? AND statut != 'brouillon' $sf_di_bare", [$periode_val]);
$di_approuv = (int)db_fetch_value("SELECT COUNT(*) FROM di_demandes WHERE DATE_FORMAT(updated_at,'$date_fmt')=? AND statut IN ('approuve','approuve_traitement') $sf_di_bare", [$periode_val]);
$di_tx      = $di_mois > 0 ? round($di_approuv / $di_mois * 100) : 0;
// L'étape qui retient la demande est plus utile que son auteur : elle dit
// chez qui elle attend. etape_actuelle indexe la liste des étapes triées par
// ordre, à partir de zéro : ROW_NUMBER reproduit exactement cet index.
$di_recents = [];
try {
    $di_recents = db_fetch_all(
        "SELECT d.id, d.numero, dt.label AS type_lbl, d.statut, d.demandeur_id, d.n1_user_id,
                d.etape_actuelle, d.workflow_snapshot,
                e.label AS etape_lbl, e.role_code AS etape_role
         FROM di_demandes d
         JOIN di_types dt ON dt.code = d.type_code
         LEFT JOIN (
            SELECT type_id, label, role_code,
                   ROW_NUMBER() OVER (PARTITION BY type_id ORDER BY ordre ASC) - 1 AS idx
            FROM di_etapes
         ) e ON e.type_id = dt.id AND e.idx = d.etape_actuelle
         WHERE d.statut IN ('en_attente','en_cours') $sf_di
         ORDER BY d.created_at DESC LIMIT 5"
    );
} catch (Throwable $e) {}

// Une demande transporte parfois sa propre copie du circuit, figée à la
// soumission : elle prime sur la définition courante du type, qui a pu
// changer depuis.
foreach ($di_recents as &$_dr) {
    $_snap = json_decode($_dr['workflow_snapshot'] ?? 'null', true);
    $_i    = (int)($_dr['etape_actuelle'] ?? 0);
    if (is_array($_snap) && isset($_snap[$_i]['label']) && $_snap[$_i]['label'] !== '') {
        $_dr['etape_lbl'] = $_snap[$_i]['label'];
    }
    if (is_array($_snap) && !empty($_snap[$_i]['role'])) {
        $_dr['etape_role'] = $_snap[$_i]['role'];
    }

    // "En attente chez" doit afficher le département, pas le libellé de l'étape :
    // le N+1 est un rôle dynamique — n1_user_id est déjà résolu à la soumission
    // (includes/demandes.php), on affiche donc le département où CE N+1 est
    // marqué is_n1=1, plutôt que celui du demandeur (à défaut, on retombe dessus).
    // Les autres rôles (RAF/DAF/DG/IT/Administration) ont un département fixe
    // configurable dans Admin → Rôles demandes (di_roles.departement_id).
    $_dept_lbl = null;
    try {
        if (($_dr['etape_role'] ?? null) === 'n1') {
            $_n1_id = !empty($_dr['n1_user_id']) ? (int)$_dr['n1_user_id'] : (int)$_dr['demandeur_id'];
            $_dept_lbl = db_fetch_value(
                "SELECT d.label FROM user_departements ud JOIN departements d ON d.id = ud.departement_id
                 WHERE ud.user_id = ? AND ud.is_n1 = 1 LIMIT 1", [$_n1_id]
            );
            if (!$_dept_lbl) {
                $_dept_lbl = db_fetch_value(
                    "SELECT d.label FROM user_departements ud JOIN departements d ON d.id = ud.departement_id
                     WHERE ud.user_id = ? LIMIT 1", [(int)$_dr['demandeur_id']]
                );
            }
        } elseif (!empty($_dr['etape_role'])) {
            $_dept_lbl = db_fetch_value(
                "SELECT d.label FROM di_roles r JOIN departements d ON d.id = r.departement_id
                 WHERE r.code = ?", [$_dr['etape_role']]
            );
        }
    } catch (Throwable $e) {}
    $_dr['etape_lbl'] = $_dept_lbl ?: ($_dr['etape_lbl'] ?? null);
}
unset($_dr);

// ── ÉVOLUTION 6 MOIS
$evol = db_fetch_all(
    "SELECT DATE_FORMAT(date_point,'%Y-%m') AS mois,
            SUM(total_engins) AS engins,
            SUM(total_plaques) AS plaques
     FROM op_points_journaliers
     WHERE date_point >= (CURRENT_DATE - INTERVAL 6 MONTH) AND statut != 'brouillon' $sf
     GROUP BY DATE_FORMAT(date_point,'%Y-%m') ORDER BY mois ASC"
);

// ── POINTS EN ATTENTE
$pts_attente = db_fetch_all(
    "SELECT p.date_point, p.type_point, s.nom AS site, CONCAT(u.prenom,' ',u.nom) AS coord
     FROM op_points_journaliers p
     JOIN sites s ON s.id=p.site_id
     LEFT JOIN users u ON u.id=p.created_by
     WHERE p.statut='en_attente_validation' $sf_p
     ORDER BY p.date_point DESC LIMIT 8"
);

// ── JS DATA
$js_evol_labels  = json_encode(array_map(fn($r) => ($mc[substr($r['mois'],5,2)]??'').' '.substr($r['mois'],2,2), $evol));
$js_evol_engins  = json_encode(array_map(fn($r) => (int)$r['engins'], $evol));
$js_evol_plaques = json_encode(array_map(fn($r) => (int)$r['plaques'], $evol));
$js_statuts      = json_encode([(int)($ops['points_valides']??0),(int)($ops['points_attente']??0),(int)($ops['points_rejetes']??0)]);
// Films modal labels/values provisoires (tous sites) — sera recalculé après $cov_types_by_site
$js_films_labels   = json_encode(array_keys($films_par_type));
$js_films_values   = json_encode(array_values($films_par_type));
$films_modal_title = 'Films utilisés par type de bobine';
$films_modal_sub   = h($mois_display);
$js_bobines      = json_encode([(int)($bobines_stats['en_cours']??0),(int)($bobines_stats['en_stock']??0),(int)($bobines_stats['epuisees']??0)]);
$js_cmds         = json_encode([(int)($cmd_stats['en_attente']??0),(int)(($cmd_stats['a_livrer']??0)+($cmd_stats['en_route']??0)),(int)($cmd_stats['livrees_mois']??0)]);
$pmma_by_type = [];
foreach ($pmma_detail as $d) $pmma_by_type[$d['type_pmma']][] = ['site'=>$d['nom'],'qty'=>(int)$d['quantite'],'seuil'=>(int)$d['seuil']];
$js_pmma_labels = json_encode(array_map(fn($r)=>$r['type_pmma'], $pmma_par_type));
$js_pmma_totals = json_encode(array_map(fn($r)=>(int)$r['total'], $pmma_par_type));
$js_pmma_bas    = json_encode(array_map(fn($r)=>(int)$r['nb_bas'], $pmma_par_type));
$js_pmma_detail = json_encode(array_values(array_map(fn($r)=>$pmma_by_type[$r['type_pmma']]??[], $pmma_par_type)));
$riv_type_detail = [[], []]; $riv_total_gonfl = 0; $riv_total_eclat = 0;
foreach ($rps as $site => $types) {
    $g = (int)($types['gonflable'] ?? 0); $e = (int)($types['eclate'] ?? 0);
    $riv_total_gonfl += $g; $riv_total_eclat += $e;
    $riv_type_detail[0][] = ['site'=>$site,'qty'=>$g];
    $riv_type_detail[1][] = ['site'=>$site,'qty'=>$e];
}
$js_riv_labels = json_encode(['Gonflable','Éclaté']);
$js_riv_totals = json_encode([$riv_total_gonfl,$riv_total_eclat]);
$js_riv_detail = json_encode($riv_type_detail);
$pmma_ch_h     = max(140, count($pmma_par_type) * 46);

// Palette sites (couleurs identifiables par site)
$SC = ['#1B75BC','#3B4FBE','#7c3aed','#0891b2','#16a34a','#d97706','#e11d48'];

// ── DONNÉES MENSUELLES PAR SITE (widget performance)
$site_monthly_raw = db_fetch_all(
    "SELECT s.nom AS site_nom,
            DATE_FORMAT(p.date_point,'%Y-%m') AS mois,
            COALESCE(SUM(fu.films_utilises),0) AS films,
            COALESCE(SUM(p.total_engins),0) AS engins,
            COALESCE(ROUND(AVG(NULLIF(p.moyenne_prod,0)),1),0) AS moy_vh
     FROM sites s
     JOIN op_points_journaliers p ON p.site_id=s.id
          AND DATE_FORMAT(p.date_point,'%Y')=?
          AND p.statut != 'brouillon'
     LEFT JOIN op_films_utilises fu ON fu.point_id=p.id
     WHERE s.actif=1 $sf_s
     GROUP BY s.id, s.nom, DATE_FORMAT(p.date_point,'%Y-%m')
     ORDER BY s.id, mois",
    [$annee]
);
$site_monthly_by_name = [];
foreach ($site_monthly_raw as $r) {
    if ($r['mois']) $site_monthly_by_name[$r['site_nom']][$r['mois']] = [
        'films'  => (int)$r['films'],
        'engins' => (int)$r['engins'],
        'moy_vh' => (float)$r['moy_vh'],
    ];
}
// Films restants par site (si op_bobines a site_id)
$films_rest_by_site = [];
try {
    foreach (db_fetch_all(
        "SELECT s.nom, COALESCE(SUM(b.films_restants),0) AS fr
         FROM sites s LEFT JOIN op_bobines b ON b.site_id=s.id AND b.statut IN ('en_cours','en_stock')
         WHERE s.actif=1 $sf_s GROUP BY s.id, s.nom"
    ) as $r) $films_rest_by_site[$r['nom']] = (int)$r['fr'];
} catch (Throwable $e) {}
$films_mois_by_site = [];
foreach ($films_par_site as $fs) $films_mois_by_site[$fs['nom']] = (int)$fs['films'];
$pfw_sites_json = [];
foreach ($prod_par_site as $i => $s) {
    $fr = $films_rest_by_site[$s['nom']] ?? 0;
    if (!$fr) $fr = (int)round(($bobines_stats['films_restants']??0) / max(1, count($prod_par_site)));
    $pfw_sites_json[] = [
        'name'       => $s['nom'],
        'color'      => $SC[$i % count($SC)],
        'moy_vh'     => (float)$s['moy_vh'],
        'films_mois' => $films_mois_by_site[$s['nom']] ?? 0,
        'films_rest' => $fr,
        'monthly'    => (object)($site_monthly_by_name[$s['nom']] ?? []),
    ];
}
$js_pfw_sites    = json_encode($pfw_sites_json);
$pfw_quarter_def = $periode === 'annuel'
    ? 1
    : max(1, (int)ceil((int)substr($periode_du, 5, 2) / 3));

// ══════════════════════════════════════════════════════════
//  KPI BUSINESS — service, gâche matière, écart de consommation,
//  couverture de stock, fiabilité, productivité, mix produit
// ══════════════════════════════════════════════════════════
$df   = "DATE_FORMAT(date_point,'$date_fmt')=?";     // filtre période sur la table des points
$df_p = "DATE_FORMAT(p.date_point,'$date_fmt')=?";   // idem quand la table est aliasée p

// Affichage en nombre entier. Une valeur non nulle qui tomberait à zéro
// par arrondi s'affiche « < 1 » : un faux zéro se lirait comme l'absence
// de problème, ce qui serait faux pour une gâche de 0,4 %.
if (!function_exists('ent')) {
    function ent(?float $v, bool $signe = false): string {
        if ($v === null) return '—';
        $r = (int)round($v);
        if ($r === 0 && $v >  0.0001) return '&lt; 1';
        if ($r === 0 && $v < -0.0001) return '&gt; -1';
        return ($signe && $r > 0 ? '+' : '') . number_format($r, 0, ',', ' ');
    }
}

// Répartit des parts en pourcentages entiers dont la somme vaut exactement
// 100 (méthode du plus grand reste). Arrondir chaque part séparément peut
// faire dépasser 100 au total — invisible sur un indicateur seul, mais
// flagrant dès que plusieurs pourcentages s'affichent côte à côte, comme
// le mix produit.
if (!function_exists('pct_entiers')) {
    function pct_entiers(array $valeurs, float $total): array {
        $n = count($valeurs);
        if ($total <= 0) return array_fill(0, $n, 0);
        $bruts = array_map(fn($v) => $v / $total * 100, $valeurs);
        $bas   = array_map(fn($p) => (int)floor($p), $bruts);
        $reste = 100 - array_sum($bas);
        $ordre = range(0, $n - 1);
        usort($ordre, fn($a, $b) => ($bruts[$b] - $bas[$b]) <=> ($bruts[$a] - $bas[$a]));
        for ($i = 0; $i < $reste; $i++) $bas[$ordre[$i]]++;
        return $bas;
    }
}
// Affiche l'entier apportionné, avec la même règle que ent() pour une part
// non nulle qui tomberait à zéro : « < 1 » plutôt qu'un zéro trompeur.
if (!function_exists('pct_txt')) {
    function pct_txt(float $n, int $pctInt): string {
        if ($n <= 0) return '0';
        if ($pctInt === 0) return '&lt; 1';
        return number_format($pctInt, 0, ',', ' ');
    }
}

// Chaque requête est isolée : si une colonne manque encore en base (migration
// non passée), l'indicateur concerné affiche « — » au lieu de casser la page.
$biz = null;
try {
$biz = db_fetch_one(
    "SELECT COALESCE(SUM(total_plaques),0)              AS posees,
            COALESCE(SUM(non_poses_concessionnaires),0) AS np_conc,
            COALESCE(SUM(non_poses_usagers),0)          AS np_usag,
            COALESCE(SUM(nb_vp),0)                      AS nb_vp,
            COALESCE(SUM(nb_camion),0)                  AS nb_camion,
            COALESCE(SUM(nb_semi),0)                    AS nb_semi,
            COALESCE(SUM(nb_moto),0)                    AS nb_moto,
            COALESCE(SUM(rivets_utilises),0)            AS riv_ok,
            COALESCE(SUM(rivets_endommages),0)          AS riv_ko,
            COALESCE(SUM(nb_heures_travail),0)          AS heures
     FROM op_points_journaliers
     WHERE $df AND statut <> 'brouillon' $sf",
    [$periode_val]
);
} catch (Throwable $e) {}

// 1 ── Taux de service : part de la demande réellement servie
$posees   = (int)($biz['posees'] ?? 0);
$np_conc  = (int)($biz['np_conc'] ?? 0);
$np_usag  = (int)($biz['np_usag'] ?? 0);
$np_total = $np_conc + $np_usag;
$demande  = $posees + $np_total;
$taux_service = $demande > 0 ? round($posees / $demande * 100, 1) : null;

// 2 ── Rendement matière : part de film et de rivets détruits
$gache = null;
try {
$gache = db_fetch_one(
    "SELECT COALESCE(SUM(fu.films_utilises),0)   AS ok,
            COALESCE(SUM(fu.films_endommages),0) AS ko
     FROM op_films_utilises fu
     JOIN op_points_journaliers p ON p.id = fu.point_id
     WHERE $df_p AND p.statut <> 'brouillon' $sf_p",
    [$periode_val]
);
} catch (Throwable $e) {}
$f_ok = (int)($gache['ok'] ?? 0);
$f_ko = (int)($gache['ko'] ?? 0);
$taux_gache     = ($f_ok + $f_ko) > 0 ? round($f_ko / ($f_ok + $f_ko) * 100, 2) : null;
$riv_ok         = (int)($biz['riv_ok'] ?? 0);
$riv_ko         = (int)($biz['riv_ko'] ?? 0);
$taux_gache_riv = ($riv_ok + $riv_ko) > 0 ? round($riv_ko / ($riv_ok + $riv_ko) * 100, 2) : null;

// 3 ── Écart consommation théorique vs réelle
//     Théorique = Σ (engins du type × plaques par engin), par série A/B/C/D.
$nb_vp  = (int)($biz['nb_vp'] ?? 0);   $nb_cam = (int)($biz['nb_camion'] ?? 0);
$nb_smi = (int)($biz['nb_semi'] ?? 0); $nb_mot = (int)($biz['nb_moto'] ?? 0);
$bareme_plq = ['A'=>2,'B'=>2,'C'=>2,'D'=>1];   // valeurs de repli si la table est vide
$bareme_riv = ['A'=>4,'B'=>4,'C'=>4,'D'=>2];
try {
    foreach (db_fetch_all(
        "SELECT serie_bobine,
                ROUND(AVG(nb_plaques)) AS nb_plaques,
                ROUND(AVG(nb_rivets))  AS nb_rivets
         FROM op_types_vehicule GROUP BY serie_bobine") as $t) {
        $s = strtoupper(trim((string)$t['serie_bobine']));
        if (isset($bareme_plq[$s])) {
            $bareme_plq[$s] = (int)$t['nb_plaques'];
            $bareme_riv[$s] = (int)$t['nb_rivets'];
        }
    }
} catch (Throwable $e) {}
$theo_plq = $nb_vp*$bareme_plq['A'] + $nb_cam*$bareme_plq['B'] + $nb_smi*$bareme_plq['C'] + $nb_mot*$bareme_plq['D'];
$ecart_plq     = $theo_plq > 0 ? $posees - $theo_plq : null;
$ecart_plq_pct = $theo_plq > 0 ? round(($posees - $theo_plq) / $theo_plq * 100, 1) : null;

// 4 ── Couverture de stock, en jours, site par site
$couv_raw = [];
try {
$couv_raw = db_fetch_all(
    "SELECT s.id, s.nom,
            COALESCE(st.restants,0) AS restants,
            COALESCE(c.films,0)     AS films,
            COALESCE(c.jours,0)     AS jours
     FROM sites s
     LEFT JOIN (
        SELECT p.site_id,
               SUM(fu.films_utilises)       AS films,
               COUNT(DISTINCT p.date_point) AS jours
        FROM op_points_journaliers p
        JOIN op_films_utilises fu ON fu.point_id = p.id
        WHERE $df_p AND p.statut <> 'brouillon'
        GROUP BY p.site_id
     ) c ON c.site_id = s.id
     LEFT JOIN (
        SELECT site_id, SUM(films_restants) AS restants
        FROM op_bobines WHERE statut IN ('en_stock','en_cours') GROUP BY site_id
     ) st ON st.site_id = s.id
     WHERE s.actif = 1 $sf_s
     ORDER BY s.nom",
    [$periode_val]
);
} catch (Throwable $e) {}
$couverture = [];
foreach ($couv_raw as $r) {
    $j  = (int)$r['jours'];
    $cj = $j > 0 ? (float)$r['films'] / $j : 0.0;
    $couverture[] = [
        'site_id'  => (int)$r['id'],
        'nom'      => $r['nom'],
        'restants' => (int)$r['restants'],
        'conso_j'  => $cj,
        'jours'    => $cj > 0 ? (int)floor((int)$r['restants'] / $cj) : null,
    ];
}
usort($couverture, fn($a,$b) => [$a['jours']===null?1:0, $a['jours']??0] <=> [$b['jours']===null?1:0, $b['jours']??0]);
$seuil_couv_bas = 15;
$couv_critiques = count(array_filter($couverture, fn($c) => $c['jours'] !== null && $c['jours'] < $seuil_couv_bas));

// Types de bobines par site (pour tooltip survol couverture)
$cov_types_by_site = [];
try {
    foreach (db_fetch_all(
        "SELECT b.site_id,
                b.type_code AS code,
                COALESCE(t.libelle, b.type_code) AS lbl,
                SUM(b.films_restants) AS films
         FROM op_bobines b
         LEFT JOIN op_types_bobines t ON t.code = b.type_code
         WHERE b.statut IN ('en_cours','en_stock') AND b.site_id IS NOT NULL $sf_b
         GROUP BY b.site_id, b.type_code, t.libelle
         ORDER BY b.site_id, films DESC"
    ) as $tr) {
        $cov_types_by_site[$tr['site_id']][] = [
            'code'  => $tr['code'],
            'lbl'   => $tr['lbl'],
            'films' => (int)$tr['films'],
        ];
    }
} catch (Throwable $e) {}
$js_cov_types = json_encode($cov_types_by_site);

// Films modal : consommation (tous sites) OU stock restant par type (site sélectionné)
if ($site_id && !empty($cov_types_by_site[$site_id])) {
    $_ftypes = $cov_types_by_site[$site_id];
    $js_films_labels   = json_encode(array_column($_ftypes, 'code'));
    $js_films_values   = json_encode(array_column($_ftypes, 'films'));
    $js_films_detail   = json_encode(array_map(fn($t) => [['site'=>$site_nom_sel,'films'=>$t['films']]], $_ftypes));
    $films_modal_title = 'Films disponibles par type';
    $films_modal_sub   = h($site_nom_sel) . ' · stock restant';
}
$films_ch_h = max(160, count(json_decode($js_films_labels, true)) * 46);

// 5 ── Fiabilité du stock : écarts d'inventaire + réconciliation EMUCI
$ec_ouverts = $bob_perdues = $rec_total = $rec_majeurs = $rec_ouverts = 0;
$ec_films = $bob_perdues_films = 0;
$taux_recon = null;
try {
    $fiab = db_fetch_one(
        "SELECT (SELECT COUNT(*) FROM ecarts_bobines eb JOIN op_bobines b ON b.id=eb.bobine_id WHERE eb.statut='ouvert' $sf_b) AS ec_ouverts,
                (SELECT COALESCE(SUM(ABS(eb.ecart)),0) FROM ecarts_bobines eb JOIN op_bobines b ON b.id=eb.bobine_id WHERE eb.statut='ouvert' $sf_b) AS ec_films,
                (SELECT COUNT(*)                        FROM op_bobines WHERE statut='perdue' $sf) AS bob_perdues,
                (SELECT COALESCE(SUM(films_restants),0) FROM op_bobines WHERE statut='perdue' $sf) AS bob_perdues_films"
    );
    $ec_ouverts        = (int)($fiab['ec_ouverts'] ?? 0);
    $ec_films          = (int)($fiab['ec_films'] ?? 0);
    $bob_perdues       = (int)($fiab['bob_perdues'] ?? 0);
    $bob_perdues_films = (int)($fiab['bob_perdues_films'] ?? 0);
} catch (Throwable $e) {}
try {
    $recon = db_fetch_one(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN statut_ecart = 'majeur' THEN 1 ELSE 0 END),0)                AS majeurs,
                COALESCE(SUM(CASE WHEN statut_ecart = 'majeur' AND ajuste = 0 THEN 1 ELSE 0 END),0) AS majeurs_ouverts
         FROM comparaisons_stock
         WHERE DATE_FORMAT(date_comparaison,'$date_fmt')=? $sf",
        [$periode_val]
    );
    $rec_total   = (int)($recon['total'] ?? 0);
    $rec_majeurs = (int)($recon['majeurs'] ?? 0);
    $rec_ouverts = (int)($recon['majeurs_ouverts'] ?? 0);
    $taux_recon  = $rec_total > 0 ? round(($rec_total - $rec_majeurs) / $rec_total * 100, 1) : null;
} catch (Throwable $e) {}
$has_risk = ($ec_ouverts > 0 || $bob_perdues > 0 || $rec_ouverts > 0);

// 6 ── Productivité réelle : plaques posées par heure travaillée
$heures      = (float)($biz['heures'] ?? 0);
$prod_reelle = $heures > 0 ? round($posees / $heures, 1) : null;

// 7 ── Mix produit
$mix_total = $nb_vp + $nb_cam + $nb_smi + $nb_mot;
$mix = [
    ['lbl'=>'Véhicules particuliers','n'=>$nb_vp, 'k'=>'a'],
    ['lbl'=>'Camions',               'n'=>$nb_cam,'k'=>'b'],
    ['lbl'=>'Semi-remorques',        'n'=>$nb_smi,'k'=>'c'],
    ['lbl'=>'Motos',                 'n'=>$nb_mot,'k'=>'d'],
];
// pct : proportion exacte, pour la largeur de barre (visuelle, n'a pas
// besoin de sommer à 100 pile). pct_int : entier apportionné, pour tout
// texte affiché — c'est lui qui garantit une somme de 100.
$mix_pcts = pct_entiers(array_column($mix, 'n'), (float)$mix_total);
foreach ($mix as $i => &$m) {
    $m['pct']     = $mix_total > 0 ? $m['n'] / $mix_total * 100 : 0;
    $m['pct_int'] = $mix_pcts[$i];
}
unset($m);

// ══════════════════════════════════════════════════════════
//  POINT DU PARC MATÉRIEL — opérationnel / maintenance / HS
//  L'état du parc est une photo du moment : il ne suit pas le
//  filtre mensuel de la page.
// ══════════════════════════════════════════════════════════
$eq_type = (int)($_GET['eq'] ?? 0);      // nomenclature_id, 0 = tous

// Trois états seulement : ce qui tourne, ce qui est récupérable,
// ce qui est mort. La nuance neuf/bon/usagé ne change pas la décision.
$eq_sel = "COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN e.etat IN ('neuf','bon','usage') THEN 1 ELSE 0 END),0) AS op,
           COALESCE(SUM(CASE WHEN e.etat = 'maintenance'           THEN 1 ELSE 0 END),0) AS mt,
           COALESCE(SUM(CASE WHEN e.etat = 'hs'                    THEN 1 ELSE 0 END),0) AS hs";
$eq_where  = "e.actif = 1 AND e.categorie = 'informatique'";
$eq_params = [];
// Le bloc suit le filtre site de la page, comme le reste de la vue.
if ($site_id > 0) { $eq_where .= " AND e.site_id = ?";        $eq_params[] = $site_id; }
if ($eq_type > 0) { $eq_where .= " AND e.nomenclature_id = ?"; $eq_params[] = $eq_type; }

$eq_tot = ['total'=>0,'op'=>0,'mt'=>0,'hs'=>0];
try {
    $r = db_fetch_one("SELECT $eq_sel FROM equipements e WHERE $eq_where", $eq_params);
    if ($r) $eq_tot = array_map('intval', $r);
} catch (Throwable $e) {}
$eq_parc  = $eq_tot['total'];
$eq_dispo = $eq_parc > 0 ? round($eq_tot['op'] / $eq_parc * 100, 1) : null;
$eq_immo  = $eq_tot['mt'] + $eq_tot['hs'];

// Sans filtre, l'axe porte les types d'équipement ; dès qu'un type
// est choisi, il bascule sur les sites — le forage attendu. Quand la
// page est déjà épinglée sur un site, cet axe n'aurait qu'une colonne :
// on reste alors sur les types.
$eq_par_site = ($eq_type > 0 && $site_id === 0);
$eq_series   = [];
try {
    $eq_series = $eq_par_site
        ? db_fetch_all(
            "SELECT COALESCE(s.nom,'Non affecté') AS lbl, $eq_sel
             FROM equipements e LEFT JOIN sites s ON s.id = e.site_id
             WHERE $eq_where GROUP BY s.nom ORDER BY total DESC LIMIT 8", $eq_params)
        : db_fetch_all(
            "SELECT COALESCE(n.libelle,'Sans type') AS lbl, $eq_sel
             FROM equipements e LEFT JOIN nomenclatures n ON n.id = e.nomenclature_id
             WHERE $eq_where GROUP BY n.libelle ORDER BY total DESC LIMIT 8", $eq_params);
} catch (Throwable $e) {}

// Échelle : un plafond rond au-dessus de la plus haute colonne
$eq_max = 0;
foreach ($eq_series as $s) $eq_max = max($eq_max, (int)$s['total']);
$eq_pas  = $eq_max <= 10 ? 2 : ($eq_max <= 50 ? 10 : ($eq_max <= 200 ? 25 : 100));
$eq_plaf = max($eq_pas, (int)(ceil($eq_max / $eq_pas) * $eq_pas));
$eq_grads = [];
for ($v = $eq_plaf; $v >= 0; $v -= $eq_pas) $eq_grads[] = $v;

// Types disponibles pour le filtre
$eq_types = [];
try {
    // Les comptes de la liste suivent le même périmètre que le graphe.
    $eq_types = db_fetch_all(
        "SELECT n.id, n.libelle, COUNT(e.id) AS n
         FROM nomenclatures n
         LEFT JOIN equipements e ON e.nomenclature_id = n.id AND e.actif = 1
              " . ($site_id > 0 ? "AND e.site_id = ?" : "") . "
         WHERE n.categorie = 'informatique'
         GROUP BY n.id, n.libelle HAVING COUNT(e.id) > 0 ORDER BY n.libelle",
        $site_id > 0 ? [$site_id] : []);
} catch (Throwable $e) {}
$eq_type_nom = '';
foreach ($eq_types as $t) if ((int)$t['id'] === $eq_type) $eq_type_nom = $t['libelle'];

// ══════════════════════════════════════════════════════════
//  ACHATS — VUE DIRECTION (fusionnée depuis dashboard_direction.php,
//  pour n'avoir qu'un seul dashboard PDG). Réutilise le filtre de
//  période déjà présent en haut de page (mois/année) plutôt que
//  d'ajouter une seconde barre de filtres — l'achat n'a pas de notion
//  de site, donc $site_id ne s'y applique pas.
// ══════════════════════════════════════════════════════════
$ach_visible = can('achats_dashboard', 'can_read');
$ach_perimetre_vide = false;
$ach_depense_totale = 0;
$ach_budget_dept = [];
$ach_kpis = null;
if ($ach_visible) {
    $ach_perimetre = ach_perimetre_departements($user);
    $ach_perimetre_vide = is_array($ach_perimetre) && empty($ach_perimetre);
    // n° 2.4 CR PDG : l'intervalle vient de la période choisie, sinon les
    // vues journalière et hebdomadaire retombaient sur le mois courant.
    $ach_du = $periode_du;
    $ach_au = $periode_au;
    if (!$ach_perimetre_vide) {
        [$ach_clause, $ach_pd] = ach_clause_departement($ach_perimetre, 'f');
        $ach_depense_totale = (int) db_fetch_value(
            "SELECT COALESCE(SUM(fl.montant_ttc),0) FROM feb_lignes fl JOIN feb f ON f.id=fl.feb_id
              WHERE fl.arbitrage='achat' AND f.date_soumission BETWEEN ? AND ?$ach_clause",
            array_merge([$ach_du, $ach_au], $ach_pd)
        );
        $ach_budget_dept = ach_budget_departements($ach_perimetre, $annee_filtre);
        $ach_kpis = ach_dashboard_kpis($user, $ach_perimetre, $ach_du, $ach_au);
    }
}

include __DIR__ . '/../templates/header.php';
?>
<?php include __DIR__ . '/../templates/dash_style.php'; ?>

<div class="pdg">

<!-- ══════════ TOP BAR ══════════ -->
<div class="pdg-topbar">
  <div>
    <div class="pdg-title">Vue Exécutive</div>
    <div class="pdg-sub">Express Multiservices CI · ERP EMUCI<?= $site_nom_sel ? ' · <strong style="color:var(--navy,#06033A)">'.h($site_nom_sel).'</strong>' : '' ?></div>
  </div>
  <div class="pdg-controls">
    <form method="get" id="pdg-filter-form" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <select name="site_id" class="month-inp" onchange="this.form.submit()" title="Filtrer par site"
              style="min-width:140px;max-width:220px;text-overflow:ellipsis">
        <option value="0"<?= $site_id===0 ? ' selected' : '' ?>>Tous les sites</option>
        <?php foreach ($sites_list as $_s): ?>
        <option value="<?= (int)$_s['id'] ?>"<?= $site_id===(int)$_s['id'] ? ' selected' : '' ?>>
          <?= h($_s['nom']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <select name="periode" class="month-inp" onchange="this.form.submit()" title="Type de période" style="min-width:118px">
        <option value="journalier"<?= $periode==='journalier' ? ' selected' : '' ?>>Journalier</option>
        <option value="hebdomadaire"<?= $periode==='hebdomadaire' ? ' selected' : '' ?>>Hebdomadaire</option>
        <option value="mensuel"<?= $periode==='mensuel' ? ' selected' : '' ?>>Mensuel</option>
        <option value="annuel"<?= $periode==='annuel' ? ' selected' : '' ?>>Annuel</option>
      </select>
      <?php if ($periode === 'annuel'): ?>
      <select name="annee" class="month-inp" onchange="this.form.submit()" title="Choisir l'année">
        <?php for ($_y = $annee_max; $_y >= $annee_min; $_y--): ?>
        <option value="<?= $_y ?>"<?= $annee_filtre===$_y ? ' selected' : '' ?>><?= $_y ?></option>
        <?php endfor; ?>
      </select>
      <?php elseif ($periode === 'mensuel'): ?>
      <input type="month" name="mois" value="<?= h($mois) ?>" class="month-inp" onchange="this.form.submit()" aria-label="Choisir le mois">
      <?php else: /* journalier et hebdomadaire se choisissent par une date :
           la semaine est celle qui contient le jour retenu. */ ?>
      <input type="date" name="jour" value="<?= h($jour) ?>" class="month-inp" onchange="this.form.submit()"
             aria-label="<?= $periode==='hebdomadaire' ? 'Choisir une date dans la semaine' : 'Choisir le jour' ?>">
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ══════════ PERFORMANCE BUSINESS ══════════ -->
<div class="biz">

  <div class="biz-hd">
    <div class="biz-hd-t">Performance business</div>
    <div class="biz-hd-s"><?= h($mois_display) ?><?= $site_nom_sel ? ' · '.h($site_nom_sel) : '' ?> · demande servie, pertes matière et couverture</div>
  </div>

  <div class="biz-lead">

    <!-- Taux de service -->
    <div class="biz-hero">
      <div class="biz-hero-lbl">Taux de service · demande servie</div>
      <?php if ($taux_service === null): ?>
        <div class="biz-hero-val">—</div>
        <div class="biz-hero-note">Aucun point journalier saisi sur la période. Le taux se calcule dès la première journée validée.</div>
      <?php else: ?>
        <div class="biz-hero-val" data-k="service" data-v="<?= $taux_service ?>" data-d="0"><?= ent($taux_service) ?><span class="biz-hero-u">%</span></div>
        <div class="biz-hero-note">
          <b><?= number_format($posees, 0, ',', ' ') ?></b> plaques posées sur <b><?= number_format($demande, 0, ',', ' ') ?></b> demandées.
          <?php if ($np_total > 0): ?>
            <b><?= number_format($np_total, 0, ',', ' ') ?></b> non posées, dont <?= number_format($np_conc, 0, ',', ' ') ?> côté concessionnaires
            et <?= number_format($np_usag, 0, ',', ' ') ?> côté usagers.
          <?php else: ?>
            Aucune plaque non posée déclarée.
          <?php endif; ?>
        </div>
        <?php $pc_ok = $demande > 0 ? $posees / $demande * 100 : 0; $pc_ko = 100 - $pc_ok; ?>
        <div class="biz-dem" role="img"
             aria-label="Demande : <?= number_format($posees,0,',',' ') ?> plaques posées, <?= number_format($np_total,0,',',' ') ?> non posées">
          <?php if ($pc_ok > 0): ?>
            <div class="biz-dem-s biz-dem-ok" data-b="dem-ok" data-p="<?= round($pc_ok,2) ?>" data-ax="w" style="width:<?= round($pc_ok,2) ?>%">
              <?php if ($pc_ok >= 26): ?><span class="biz-dem-lbl"><?= number_format($posees, 0, ',', ' ') ?> posées</span><?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($pc_ko > 0): ?>
            <div class="biz-dem-s biz-dem-ko" data-b="dem-ko" data-p="<?= round($pc_ko,2) ?>" data-ax="w" style="width:<?= round($pc_ko,2) ?>%">
              <?php if ($pc_ko >= 26): ?><span class="biz-dem-lbl"><?= number_format($np_total, 0, ',', ' ') ?> non posées</span><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="biz-dem-key">
          <span><span class="biz-dot" style="background:#86efac"></span>Posées <b><?= number_format($posees, 0, ',', ' ') ?></b></span>
          <span><span class="biz-dot" style="background:#fca5a5"></span>Non posées <b><?= number_format($np_total, 0, ',', ' ') ?></b></span>
          <span>Manque à gagner <b><?= ent($pc_ko) ?> %</b></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- Quatre métriques -->
    <div class="biz-mx">

      <div class="biz-m">
        <div class="biz-m-hd">
          <div class="biz-m-lbl">Gâche film</div>
          <?php if ($taux_gache !== null): ?>
            <span class="hc-badge <?= $taux_gache <= 3 ? 'hc-green' : ($taux_gache <= 6 ? 'hc-orange' : 'hc-red') ?>">
              <?= $taux_gache <= 3 ? 'maîtrisée' : ($taux_gache <= 6 ? 'à surveiller' : 'élevée') ?>
            </span>
          <?php endif; ?>
        </div>
        <?php if ($taux_gache === null): ?>
          <div class="biz-m-val biz-m-na">—</div>
          <div class="biz-m-sub">Aucune consommation de film enregistrée</div>
        <?php else: ?>
          <div class="biz-m-val" data-k="gache" data-v="<?= $taux_gache ?>" data-d="0"><?= ent($taux_gache) ?><span class="biz-m-u">%</span></div>
          <div class="biz-m-sub"><?= number_format($f_ko, 0, ',', ' ') ?> films détruits sur <?= number_format($f_ok + $f_ko, 0, ',', ' ') ?> engagés</div>
        <?php endif; ?>
      </div>

      <div class="biz-m">
        <div class="biz-m-hd">
          <div class="biz-m-lbl">Casse rivets</div>
          <?php if ($taux_gache_riv !== null): ?>
            <span class="hc-badge <?= $taux_gache_riv <= 3 ? 'hc-green' : ($taux_gache_riv <= 6 ? 'hc-orange' : 'hc-red') ?>">
              <?= $taux_gache_riv <= 3 ? 'maîtrisée' : ($taux_gache_riv <= 6 ? 'à surveiller' : 'élevée') ?>
            </span>
          <?php endif; ?>
        </div>
        <?php if ($taux_gache_riv === null): ?>
          <div class="biz-m-val biz-m-na">—</div>
          <div class="biz-m-sub">Aucun rivet posé sur la période</div>
        <?php else: ?>
          <div class="biz-m-val" data-k="rivets" data-v="<?= $taux_gache_riv ?>" data-d="0"><?= ent($taux_gache_riv) ?><span class="biz-m-u">%</span></div>
          <div class="biz-m-sub"><?= number_format($riv_ko, 0, ',', ' ') ?> rivets cassés sur <?= number_format($riv_ok + $riv_ko, 0, ',', ' ') ?> posés</div>
        <?php endif; ?>
      </div>

      <div class="biz-m">
        <div class="biz-m-hd"><div class="biz-m-lbl">Productivité réelle</div></div>
        <?php if ($prod_reelle === null): ?>
          <div class="biz-m-val biz-m-na">—</div>
          <div class="biz-m-sub">Heures de travail non renseignées</div>
        <?php else: ?>
          <div class="biz-m-val" data-k="prod" data-v="<?= $prod_reelle ?>" data-d="0"><?= ent($prod_reelle) ?><span class="biz-m-u">pl./h</span></div>
          <div class="biz-m-sub"><?= number_format($posees, 0, ',', ' ') ?> plaques sur <?= number_format($heures, 0, ',', ' ') ?> h travaillées</div>
        <?php endif; ?>
      </div>

      <div class="biz-m">
        <div class="biz-m-hd"><div class="biz-m-lbl">Écart conso.</div></div>
        <?php if ($ecart_plq_pct === null): ?>
          <div class="biz-m-val biz-m-na">—</div>
          <div class="biz-m-sub">Mix des engins non renseigné</div>
        <?php else: ?>
          <div class="biz-m-val" data-k="ecart" data-v="<?= $ecart_plq_pct ?>" data-d="0"><?= ent($ecart_plq_pct, true) ?><span class="biz-m-u">%</span></div>
          <div class="biz-m-sub"><?= number_format($posees, 0, ',', ' ') ?> posées vs <?= number_format($theo_plq, 0, ',', ' ') ?> attendues d'après le mix</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- Mix produit — toujours présent : un bloc qui disparaît fait croire
       à une erreur, là où il n'y a qu'une absence de saisie. -->
  <div class="biz-card">
    <div class="biz-card-hd">
      <div>
        <div class="biz-card-t">Mix produit</div>
        <div class="biz-card-s">
          <?= $mix_total > 0
              ? number_format($mix_total, 0, ',', ' ') . ' engins traités · pilote la demande en séries de bobines'
              : 'Aucun engin saisi sur la période' ?>
        </div>
      </div>
    </div>
    <?php if ($mix_total > 0): ?>
    <div class="biz-mix" role="img" aria-label="Répartition des engins : <?= h(implode(', ', array_map(fn($m) => $m['lbl'].' '.pct_txt($m['n'], $m['pct_int']).' %', $mix))) ?>">
      <?php foreach ($mix as $m): if ($m['n'] <= 0) continue; ?>
        <div class="biz-mix-s biz-<?= $m['k'] ?>" data-b="mix-<?= $m['k'] ?>" data-p="<?= round($m['pct'],2) ?>" data-ax="w" style="width:<?= round($m['pct'],2) ?>%">
          <?php if ($m['pct'] >= 6): ?><span class="biz-pct"><?= pct_txt($m['n'], $m['pct_int']) ?> %</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="biz-mix biz-mix-vide" role="img" aria-label="Aucun engin saisi : répartition indisponible"></div>
    <?php endif; ?>
    <div class="biz-mix-key">
      <?php foreach ($mix as $m): ?>
        <div>
          <div class="biz-mix-k-t"><span class="biz-dot biz-dot-<?= $m['k'] ?><?= $mix_total > 0 ? '' : ' biz-dot-off' ?>"></span><?= h($m['lbl']) ?></div>
          <?php if ($mix_total > 0): ?>
            <div class="biz-mix-k-v" data-k="mixv-<?= $m['k'] ?>" data-v="<?= (int)$m['n'] ?>" data-d="0"><?= number_format($m['n'], 0, ',', ' ') ?></div>
            <div class="biz-mix-k-p"><?= pct_txt($m['n'], $m['pct_int']) ?> % du volume</div>
          <?php else: ?>
            <div class="biz-mix-k-v biz-mix-k-off">—</div>
            <div class="biz-mix-k-p">aucune saisie</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="biz-split">

    <!-- Couverture de stock -->
    <div class="biz-card">
      <div class="biz-card-hd">
        <div>
          <div class="biz-card-t">Couverture de stock</div>
          <div class="biz-card-s">Jours de film restants au rythme observé · repère à <?= $seuil_couv_bas ?> j</div>
        </div>
        <?php if ($couv_critiques > 0): ?>
          <span class="alrt-pill alrt-warn"><i class="ph-duotone ph-warning-circle"></i>
            <?= $couv_critiques ?> site<?= $couv_critiques > 1 ? 's' : '' ?> sous <?= $seuil_couv_bas ?> j</span>
        <?php endif; ?>
      </div>
      <?php if (empty($couverture)): ?>
        <div class="biz-m-sub">Aucun site actif.</div>
      <?php else: ?>
      <div class="biz-cov"<?= count($couverture)>4 ? ' style="max-height:240px;overflow-y:auto;padding-right:6px;scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent;"' : '' ?>>
        <?php foreach ($couverture as $c):
          // Échelle de lecture : 60 jours d'autonomie remplissent la barre
          $pct  = $c['jours'] !== null ? min(100, $c['jours'] / 60 * 100) : 0;
          $bas  = $c['jours'] !== null && $c['jours'] < $seuil_couv_bas;
        ?>
        <div class="biz-cov-r" data-sid="<?= $c['site_id'] ?>">
          <div style="min-width:0">
            <div class="biz-cov-n"><?= h($c['nom']) ?></div>
            <div class="biz-cov-s">
              <?= number_format($c['restants'], 0, ',', ' ') ?> films<?= $c['conso_j'] > 0 ? ' · ' . ent($c['conso_j']) . ' /j' : '' ?>
            </div>
          </div>
          <div class="biz-cov-bar">
            <div class="biz-cov-f<?= $bas ? ' low' : '' ?>" data-b="cov-<?= (int)$c['site_id'] ?>" data-p="<?= round($pct,1) ?>" data-ax="w" style="width:<?= round($pct,1) ?>%"></div>
            <div class="biz-cov-mk" style="left:<?= round($seuil_couv_bas / 60 * 100, 1) ?>%"></div>
          </div>
          <div class="biz-cov-d">
            <?php if ($c['jours'] === null): ?>
              <span class="hc-badge" style="background:#f1f5f9;color:#5a6678">pas de conso.</span>
            <?php else: ?>
              <span class="biz-cov-num" data-k="covj-<?= (int)$c['site_id'] ?>" data-v="<?= (int)$c['jours'] ?>" data-d="0"><?= $c['jours'] ?></span><span class="biz-cov-u">j</span>
              <?php if ($bas): ?><span class="hc-badge hc-orange">réappro</span><?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fiabilité du stock -->
    <div class="biz-card">
      <div class="biz-card-hd">
        <div>
          <div class="biz-card-t">Fiabilité du stock</div>
          <div class="biz-card-s">Écarts d'inventaire et réconciliation EMUCI</div>
        </div>
      </div>
      <div class="biz-risk">

        <?php if ($ec_ouverts > 0): ?>
        <div class="biz-risk-r">
          <div class="biz-risk-i"><i class="ph-duotone ph-scales"></i></div>
          <div class="biz-risk-b">
            <div class="biz-risk-t">Écarts d'inventaire non résolus</div>
            <div class="biz-risk-s"><?= number_format($ec_films, 0, ',', ' ') ?> films d'écart cumulé, en attente d'arbitrage</div>
          </div>
          <div class="biz-risk-n" data-k="ec-ouverts" data-v="<?= (int)$ec_ouverts ?>" data-d="0"><?= $ec_ouverts ?></div>
        </div>
        <?php endif; ?>

        <?php if ($bob_perdues > 0): ?>
        <div class="biz-risk-r">
          <div class="biz-risk-i"><i class="ph-duotone ph-warning-octagon"></i></div>
          <div class="biz-risk-b">
            <div class="biz-risk-t">Bobines déclarées perdues</div>
            <div class="biz-risk-s"><?= number_format($bob_perdues_films, 0, ',', ' ') ?> films non consommés, sortis du stock</div>
          </div>
          <div class="biz-risk-n" data-k="bob-perdues" data-v="<?= (int)$bob_perdues ?>" data-d="0"><?= $bob_perdues ?></div>
        </div>
        <?php endif; ?>

        <?php if ($rec_ouverts > 0): ?>
        <div class="biz-risk-r">
          <div class="biz-risk-i"><i class="ph-duotone ph-arrows-left-right"></i></div>
          <div class="biz-risk-b">
            <div class="biz-risk-t">Écarts EMUCI majeurs non ajustés</div>
            <div class="biz-risk-s">Comparaisons EMUCI / ERP EMUCI au-delà du seuil, sans ajustement</div>
          </div>
          <div class="biz-risk-n" data-k="rec-ouverts" data-v="<?= (int)$rec_ouverts ?>" data-d="0"><?= $rec_ouverts ?></div>
        </div>
        <?php endif; ?>

        <?php if (!$has_risk): ?>
        <div class="biz-risk-ok">
          <div class="biz-risk-i"><i class="ph-duotone ph-check-circle"></i></div>
          <div class="biz-risk-b">
            <div class="biz-risk-t">Aucun écart ouvert</div>
            <div class="biz-risk-s">Inventaires et réconciliation EMUCI à jour.</div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($taux_recon !== null): ?>
        <div class="biz-risk-ft">
          <div class="biz-risk-b">
            <div class="biz-risk-t">Concordance EMUCI ↔ ERP EMUCI</div>
            <div class="biz-risk-s"><?= number_format($rec_total - $rec_majeurs, 0, ',', ' ') ?> comparaisons alignées sur <?= number_format($rec_total, 0, ',', ' ') ?></div>
          </div>
          <div class="biz-risk-n" data-k="recon" data-v="<?= $taux_recon ?>" data-d="0"><?= ent($taux_recon) ?> %</div>
        </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<!-- ══════════ PERFORMANCE PAR SITE + ÉVOLUTION ══════════ -->
<div class="pfw-evol-row">
<div class="pfw-card">
  <!-- Top : sélecteur site + filtres trimestre -->
  <div class="pfw-top">
    <div>
      <div class="pfw-site-lbl">Performance mensuelle par site</div>
      <div class="pfw-site-sel">
        <select id="pfwSiteSelect" onchange="pfwChangeSite(+this.value)" aria-label="Choisir le site à afficher">
          <?php foreach ($pfw_sites_json ? json_decode($js_pfw_sites, true) : [] as $i => $sj): ?>
          <option value="<?= $i ?>"><?= h($sj['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="pfw-site-arr">↓</span>
      </div>
    </div>
    <div class="pfw-quarters">
      <?php foreach ([1=>'T1',2=>'T2',3=>'T3',4=>'T4'] as $q => $ql): ?>
      <button class="pfw-q<?= $q===$pfw_quarter_def?' active':'' ?>" data-q="<?= $q ?>" onclick="pfwChangeQ(this)"><?= $ql ?></button>
      <?php endforeach; ?>
      <button onclick="document.getElementById('modal-ops').style.display='flex'"
        style="padding:4px 11px;border:1.5px solid #e2e8f0;border-radius:16px;background:#f8fafc;font-size:12px;font-weight:700;color:#06033A;cursor:pointer;font-family:inherit;white-space:nowrap;margin-left:4px">
        <i class="ph-duotone ph-arrows-out"></i> Détails
      </button>
    </div>
  </div>

  <!-- Corps : panneau coloré gauche + graphique droit -->
  <div class="pfw-body">
    <!-- Panneau coloré -->
    <div class="pfw-left" id="pfwLeft" style="background:#1B75BC">
      <div class="pfw-vert">Moy. mensuelle</div>
      <div class="pfw-stats">
        <div>
          <div class="pfw-stat-lbl">Moy. production</div>
          <div class="pfw-stat-val" id="pfwMoy">—</div>
        </div>
        <div>
          <div class="pfw-stat-lbl">Films utilisés</div>
          <div class="pfw-stat-val" id="pfwFilms">—</div>
        </div>
        <div>
          <div class="pfw-stat-lbl">Stock films rest.</div>
          <div class="pfw-stat-val" id="pfwStock">—</div>
        </div>
      </div>
    </div>
    <!-- Zone graphique -->
    <div class="pfw-right">
      <?php if (empty($prod_par_site)): ?>
      <div class="pfw-empty">Aucune donnée disponible</div>
      <?php else: ?>
      <div class="pfw-legend">
        <span class="pfw-leg-i"><span class="pfw-leg-sq" id="pfwLegFilmsSq" style="opacity:.55"></span>Films</span>
        <span class="pfw-leg-i"><span class="pfw-leg-sq" id="pfwLegEnginsSq"></span>Engins</span>
      </div>
      <canvas id="pfwChart" height="230"></canvas>
      <?php endif; ?>
    </div>
  </div>
</div>

  <!-- Évolution 6 mois -->
  <div class="ch-box">
    <div class="ch-ttl">Évolution engins posés</div>
    <div class="ch-sub">6 derniers mois</div>
    <canvas id="cEvol" height="170"></canvas>
  </div>
</div><!-- /.pfw-evol-row -->

<!-- ══════════ POINT DU PARC MATÉRIEL ══════════ -->
<div class="biz-card eq" style="margin-bottom:20px">
  <div class="eq-hd">
    <div>
      <div class="eq-t">Point du parc matériel</div>
      <div class="eq-s">
        État du parc informatique aujourd'hui<?= $eq_type > 0 ? ' · ' . h($eq_type_nom) : '' ?>
        <?= $eq_par_site ? ' · réparti par site' : '' ?>
      </div>
    </div>
    <div class="eq-tools">
      <div class="eq-leg">
        <span class="eq-leg-i"><span class="eq-sq sq-op"></span> Opérationnel</span>
        <span class="eq-leg-i"><span class="eq-sq sq-mt"></span> Maintenance</span>
        <span class="eq-leg-i"><span class="eq-sq sq-hs"></span> Hors service</span>
      </div>
      <?php if (!empty($eq_types)): ?>
      <!-- data-dash-filtre : ce filtre de bloc passe par l'échange animé,
           comme celui de la barre du haut. -->
      <form method="get" style="margin:0" data-dash-filtre>
        <input type="hidden" name="mois" value="<?= h($mois) ?>">
        <?php if ($site_id > 0): ?><input type="hidden" name="site_id" value="<?= $site_id ?>"><?php endif; ?>
        <select name="eq" class="eq-sel" aria-label="Filtrer par type d'équipement" onchange="this.form.submit()">
          <option value="0">Tous les équipements</option>
          <?php foreach ($eq_types as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $eq_type === (int)$t['id'] ? 'selected' : '' ?>>
              <?= h($t['libelle']) ?> (<?= (int)$t['n'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($eq_parc === 0): ?>
    <div class="eq-empty">
      <i class="ph-duotone ph-monitor"></i>
      <div class="eq-empty-t">Aucun équipement dans ce périmètre</div>
      <div class="eq-empty-s">
        <?= $eq_type > 0
            ? 'Aucun matériel de ce type. Repassez sur « Tous les équipements » pour voir le parc entier.'
            : 'Le parc informatique est vide. Les équipements saisis depuis la page Équipements apparaîtront ici avec leur état.' ?>
      </div>
    </div>
  <?php else: ?>
    <div class="eq-body">

      <div class="eq-stats">
        <div>
          <div class="eq-big-l">Taux de disponibilité</div>
          <div class="eq-big" data-k="dispo" data-v="<?= $eq_dispo ?>" data-d="0"><?= ent($eq_dispo) ?><span class="eq-big-u">%</span></div>
        </div>
        <div class="eq-r">
          <span class="eq-sq sq-op"></span><span class="eq-r-l">Opérationnels</span>
          <span class="eq-r-n"><?= number_format($eq_tot['op'], 0, ',', ' ') ?></span>
        </div>
        <div class="eq-r">
          <span class="eq-sq sq-mt"></span><span class="eq-r-l">En maintenance</span>
          <span class="eq-r-n"><?= number_format($eq_tot['mt'], 0, ',', ' ') ?></span>
        </div>
        <div class="eq-r">
          <span class="eq-sq sq-hs"></span><span class="eq-r-l">Hors service</span>
          <span class="eq-r-n"><?= number_format($eq_tot['hs'], 0, ',', ' ') ?></span>
        </div>
        <div class="eq-s" style="border-top:1px solid #f1f5f9;padding-top:10px">
          <?= number_format($eq_parc, 0, ',', ' ') ?> équipements au total<?php
            echo $eq_immo > 0
              ? ', dont ' . number_format($eq_immo, 0, ',', ' ') . ' immobilisé' . ($eq_immo > 1 ? 's' : '') . '.'
              : ', tous en service.'; ?>
        </div>
      </div>

      <?php if (empty($eq_series)): ?>
        <div class="eq-empty">
          <i class="ph-duotone ph-chart-bar"></i>
          <div class="eq-empty-t">Rien à représenter</div>
        </div>
      <?php else: ?>
        <?php
          $eq_ng = max(1, count($eq_grads) - 1);
          $eq_minw = 40 + count($eq_series) * 54;
        ?>
        <div class="eq-scroll">
        <div class="eq-chart" style="min-width:<?= $eq_minw ?>px">
          <div class="eq-yax" aria-hidden="true">
            <?php foreach ($eq_grads as $i => $g): ?>
              <span style="top:<?= round($i / $eq_ng * 100, 3) ?>%"><?= $g ?></span>
            <?php endforeach; ?>
          </div>
          <div class="eq-plot">
            <div class="eq-grid" aria-hidden="true">
              <?php foreach ($eq_grads as $i => $g): ?>
                <span style="top:<?= round($i / $eq_ng * 100, 3) ?>%"></span>
              <?php endforeach; ?>
            </div>
            <div class="eq-cols">
              <?php foreach ($eq_series as $s):
                $o = (int)$s['op']; $m = (int)$s['mt']; $x = (int)$s['hs'];
                $si = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$s['lbl']));
              ?>
              <div class="eq-col" role="img"
                   aria-label="<?= h($s['lbl']) ?> : <?= $o ?> opérationnels, <?= $m ?> en maintenance, <?= $x ?> hors service">
                <?php if ($x > 0): ?><div class="eq-seg seg-hs" data-b="eq-<?= $si ?>-hs" data-p="<?= round($x / $eq_plaf * 100, 2) ?>" data-ax="h" style="height:<?= round($x / $eq_plaf * 100, 2) ?>%"></div><?php endif; ?>
                <?php if ($m > 0): ?><div class="eq-seg seg-mt" data-b="eq-<?= $si ?>-mt" data-p="<?= round($m / $eq_plaf * 100, 2) ?>" data-ax="h" style="height:<?= round($m / $eq_plaf * 100, 2) ?>%"></div><?php endif; ?>
                <?php if ($o > 0): ?><div class="eq-seg seg-op" data-b="eq-<?= $si ?>-op" data-p="<?= round($o / $eq_plaf * 100, 2) ?>" data-ax="h" style="height:<?= round($o / $eq_plaf * 100, 2) ?>%"></div><?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="eq-xax">
            <?php foreach ($eq_series as $s):
              $st = (int)$s['total']; $so = (int)$s['op'];
              $sd = $st > 0 ? (int)round($so / $st * 100) : 0;
              $cl = $sd === 100 ? '' : ($sd >= 85 ? ' warn' : ' bad');
            ?>
              <div class="eq-xc">
                <div class="eq-xl" title="<?= h($s['lbl']) ?>"><?= h($s['lbl']) ?></div>
                <div class="eq-xn<?= $cl ?>"><?= $st ?><?= $sd < 100 ? ' · <b>' . $sd . ' %</b>' : '' ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        </div>
      <?php endif; ?>

    </div>
  <?php endif; ?>
</div>

<?php if ($ach_visible): ?>
<!-- ══════════ ACHATS — VUE DIRECTION ══════════ -->
<style>
.ach-hero{background:linear-gradient(135deg,#B45309 0%,#F59E0B 100%);border-radius:16px;padding:22px 26px;color:white;margin-bottom:20px}
.ach-hero-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:34px;font-weight:900}
.ach-hero-lbl{font-size:13px;opacity:.9;margin-top:4px}
.ach-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px}
.ach-kpi-tile{background:white;border:1px solid var(--border,#e2e8f0);border-radius:14px;padding:16px 18px}
.ach-kpi-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:24px;font-weight:900;color:var(--navy,#06033A);line-height:1}
.ach-kpi-lbl{font-size:12px;color:var(--muted,#94a3b8);margin-top:6px}
.ach-section-ttl{font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy,#06033A);margin:0 0 12px}
.ach-panel{background:white;border:1px solid var(--border,#e2e8f0);border-radius:16px;padding:18px 20px;margin-bottom:20px}
.ach-dept-row{display:grid;grid-template-columns:160px 1fr 90px;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border,#e2e8f0);font-size:13px}
.ach-dept-row:last-child{border-bottom:none}
.ach-dept-bar-track{height:10px;background:#e1e0d9;border-radius:5px;overflow:hidden}
.ach-dept-bar-fill{height:100%;border-radius:5px}
.ach-empty{padding:20px;text-align:center;color:var(--muted,#94a3b8);font-size:13px}
</style>
<div class="biz-card eq" style="margin-bottom:20px;padding:20px 22px">
  <div class="eq-hd" style="margin-bottom:16px">
    <div>
      <div class="eq-t">Achats — Vue direction</div>
      <div class="eq-s">Dépenses, délais et budget par département · <?= h($mois_display) ?></div>
    </div>
  </div>

  <?php if ($ach_perimetre_vide): ?>
    <div class="ach-empty">Aucun département dans votre périmètre.</div>
  <?php else: ?>

  <div class="ach-hero">
    <div class="ach-hero-val"><?= fmt_number((float)$ach_depense_totale) ?> XOF</div>
    <div class="ach-hero-lbl">Dépenses totales sur la période (<?= fmt_date($ach_du) ?> — <?= fmt_date($ach_au) ?>)</div>
  </div>

  <div class="ach-kpi-grid">
    <div class="ach-kpi-tile">
      <div class="ach-kpi-val">—</div>
      <div class="ach-kpi-lbl">Économies réalisées</div>
      <div style="font-size:11px;color:var(--muted,#94a3b8);margin-top:4px">Champ posé, calculé nul tant que les négociations ne sont pas historisées (V2).</div>
    </div>
    <div class="ach-kpi-tile">
      <div class="ach-kpi-val"><?= $ach_kpis['taux_livraison_temps'] !== null ? $ach_kpis['taux_livraison_temps'] . '%' : '—' ?></div>
      <div class="ach-kpi-lbl">Livraisons à temps et complètes</div>
    </div>
    <div class="ach-kpi-tile">
      <div class="ach-kpi-val"><?= $ach_kpis['taux_service_stock'] !== null ? $ach_kpis['taux_service_stock'] . '%' : '—' ?></div>
      <div class="ach-kpi-lbl">Taux de service sur stock</div>
    </div>
  </div>

  <div class="ach-section-ttl">Performance des délais (moyenne / médiane, jours)</div>
  <div class="ach-kpi-grid">
    <div class="ach-kpi-tile">
      <div class="ach-kpi-val"><?= $ach_kpis['delai_prise_charge']['moyenne'] ?? '—' ?> / <?= $ach_kpis['delai_prise_charge']['mediane'] ?? '—' ?></div>
      <div class="ach-kpi-lbl">Prise en charge</div>
    </div>
    <div class="ach-kpi-tile">
      <div class="ach-kpi-val"><?= $ach_kpis['delai_validation']['moyenne'] ?? '—' ?> / <?= $ach_kpis['delai_validation']['mediane'] ?? '—' ?></div>
      <div class="ach-kpi-lbl">Validation</div>
    </div>
    <div class="ach-kpi-tile">
      <div class="ach-kpi-val"><?= $ach_kpis['delai_livraison']['moyenne'] ?? '—' ?> / <?= $ach_kpis['delai_livraison']['mediane'] ?? '—' ?></div>
      <div class="ach-kpi-lbl">Livraison</div>
    </div>
  </div>

  <div class="ach-section-ttl">Budget consommé par département (exercice <?= $annee_filtre ?>)</div>
  <div class="ach-panel" style="margin-bottom:0">
    <?php if (empty($ach_budget_dept)): ?>
      <div class="ach-empty">Aucun département actif.</div>
    <?php else: foreach ($ach_budget_dept as $d):
      $ach_pct = $d['taux'] !== null ? min(100, $d['taux']) : 0;
      $ach_color = $d['taux'] === null ? '#94a3b8' : ($d['taux'] >= 100 ? '#dc2626' : ($d['taux'] >= 80 ? '#d97706' : '#16a34a'));
    ?>
      <div class="ach-dept-row">
        <div style="font-weight:700;color:var(--navy,#06033A)"><?= h($d['label']) ?></div>
        <div>
          <div class="ach-dept-bar-track"><div class="ach-dept-bar-fill" style="width:<?= $ach_pct ?>%;background:<?= $ach_color ?>"></div></div>
          <div style="font-size:11.5px;color:var(--muted,#94a3b8);margin-top:3px">
            <?= fmt_number((float)$d['engage']) ?> XOF engagés <?= $d['enveloppe'] > 0 ? '/ ' . fmt_number((float)$d['enveloppe']) . ' XOF' : '(non plafonné)' ?>
          </div>
        </div>
        <div style="text-align:right;font-weight:700;color:<?= $ach_color ?>"><?= $d['taux'] !== null ? $d['taux'] . '%' : '—' ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══════════ CHARTS ROW ══════════ -->
<div class="charts-row">
  <!-- Bobines donut -->
  <div class="ch-box">
    <div class="ch-ttl">État des bobines</div>
    <div class="ch-sub">Stock total · <?= ($bobines_stats['total']??0) ?> bobines</div>
    <div class="donut-wrap">
      <canvas id="cBobines" width="120" height="120" style="width:120px!important;flex-shrink:0"></canvas>
      <div class="leg-list">
        <div class="leg-item"><div class="leg-dot" style="background:#7c3aed"></div>En cours<span class="leg-val"><?= $bobines_stats['en_cours']??0 ?></span></div>
        <div class="leg-item"><div class="leg-dot" style="background:#1B75BC"></div>En stock<span class="leg-val"><?= $bobines_stats['en_stock']??0 ?></span></div>
        <div class="leg-item"><div class="leg-dot" style="background:#e2e8f0"></div>Épuisées<span class="leg-val"><?= $bobines_stats['epuisees']??0 ?></span></div>
        <div style="margin-top:6px;padding-top:6px;border-top:1px solid #f1f5f9;font-size:12px;color:#5a6678">
          Films restants : <strong style="color:#06033A"><?= number_format((int)($bobines_stats['films_restants']??0),0,',',' ') ?></strong>
        </div>
      </div>
    </div>
  </div>

  <!-- Commandes donut -->
  <div class="ch-box">
    <div class="ch-ttl">Commandes articles</div>
    <div class="ch-sub">État en temps réel</div>
    <div class="donut-wrap" style="margin-top:4px">
      <canvas id="cCmds" width="120" height="120" style="width:120px!important;flex-shrink:0"></canvas>
      <div class="leg-list">
        <div class="leg-item"><div class="leg-dot" style="background:#d97706"></div>En attente<span class="leg-val"><?= $cmd_stats['en_attente']??0 ?></span></div>
        <div class="leg-item"><div class="leg-dot" style="background:#1B75BC"></div>En livraison<span class="leg-val"><?= ($cmd_stats['a_livrer']??0)+($cmd_stats['en_route']??0) ?></span></div>
        <div class="leg-item"><div class="leg-dot" style="background:#16a34a"></div>Livrées <?= $periode_mot ?><span class="leg-val"><?= $cmd_stats['livrees_mois']??0 ?></span></div>
        <div style="margin-top:6px;padding-top:6px;border-top:1px solid #f1f5f9;font-size:12px;color:#5a6678">
          Rivets posés : <strong style="color:#06033A"><?= number_format((int)($ops['rivets_utilises']??0),0,',',' ') ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ BOTTOM ROW : ALERTES + DEMANDES ══════════ -->
<div class="bottom-row">

  <!-- Demandes internes -->
  <div class="card">
    <div class="card-ttl">Demandes internes</div>
    <div class="card-sub">Validation administrative en cours</div>
    <div class="kpi-mini">
      <div class="kpi-m">
        <div class="kpi-m-val" style="color:<?= $di_pending>0?'#d97706':'#15803d' ?>"><?= $di_pending ?></div>
        <div class="kpi-m-lbl">En attente</div>
      </div>
      <div class="kpi-m">
        <div class="kpi-m-val" style="color:#1B75BC"><?= $di_mois ?></div>
        <div class="kpi-m-lbl">Soumises <?= $periode_mot ?></div>
      </div>
      <div class="kpi-m">
        <div class="kpi-m-val" style="color:#15803d"><?= $di_approuv ?></div>
        <div class="kpi-m-lbl">Approuvées</div>
      </div>
      <div class="kpi-m">
        <div class="kpi-m-val" style="color:<?= $di_tx>=75?'#15803d':($di_tx>=40?'#d97706':'#dc2626') ?>"><?= $di_tx ?>%</div>
        <div class="kpi-m-lbl">Taux approbation</div>
      </div>
    </div>

    <?php if (!empty($di_recents)): ?>
    <table class="dtbl">
      <thead><tr><th>Réf.</th><th>Type</th><th>En attente chez</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($di_recents as $dr): ?>
      <tr onclick="location.href='<?= APP_URL ?>/pages/demandes.php?id=<?= (int)$dr['id'] ?>'" style="cursor:pointer;transition:.15s" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
        <td style="font-weight:700;color:#3B4FBE"><?= h($dr['numero'] ?? '—') ?></td>
        <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($dr['type_lbl']) ?></td>
        <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
            title="<?= h($dr['etape_lbl'] ?? '') ?>">
          <?php if (!empty($dr['etape_lbl'])): ?>
            <span class="d-statut ds-enc" style="font-weight:600"><?= h($dr['etape_lbl']) ?></span>
          <?php else: ?>
            <span style="color:#5a6678">—</span>
          <?php endif; ?>
        </td>
        <td><span class="d-statut <?= $dr['statut']==='en_cours'?'ds-enc':'ds-att' ?>"><?= $dr['statut']==='en_cours'?'En cours':'En attente' ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div style="text-align:center;padding:24px;color:#5a6678;font-size:13px">
      <i class="ph-duotone ph-check-circle" style="font-size:32px;display:block;margin-bottom:8px;color:#bbf7d0"></i>
      Aucune demande en attente
    </div>
    <?php endif; ?>
  </div>

  <!-- Points + Stock alertes -->
  <div class="card">
    <div class="card-ttl">Alertes &amp; Suivi opérationnel</div>
    <div class="card-sub">Points en attente · Stock bas</div>

    <?php if (!empty($pts_attente)): ?>
    <table class="atbl">
      <thead><tr><th>Site</th><th>Date</th><th>Point</th><th>Coordinateur</th></tr></thead>
      <tbody>
      <?php foreach ($pts_attente as $pt): ?>
      <tr>
        <td style="font-weight:700;color:var(--navy,#06033A)"><?= h($pt['site']) ?></td>
        <td style="color:#5a6678;white-space:nowrap"><?= fmt_date($pt['date_point']) ?></td>
        <td><span class="type-pill"><?= ['point_9h'=>'9h','point_13h'=>'13h','point_18h'=>'18h'][$pt['type_point']] ?? $pt['type_point'] ?></span></td>
        <td style="color:#5a6678"><?= h($pt['coord']??'—') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div style="text-align:center;padding:20px 0;color:#5a6678;font-size:13px;border-bottom:1px solid #f1f5f9;margin-bottom:14px">
      <i class="ph-duotone ph-check-circle" style="font-size:28px;display:block;margin-bottom:6px;color:#bbf7d0"></i>
      Aucun point en attente de validation
    </div>
    <?php endif; ?>

    <!-- Stock résumé rapide -->
    <div style="margin-top:<?= empty($pts_attente)?'0':'18px' ?>">
      <div style="font-size:12px;font-weight:700;color:#5a6678;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px">Résumé stock</div>
      <div class="stock-stat-row">
        <span style="color:#06033A">Bobines actives</span>
        <span class="stock-val" style="color:#7c3aed"><?= $bobines_stats['en_cours']??0 ?></span>
      </div>
      <div class="stock-stat-row">
        <span style="color:#06033A">Films restants (total)</span>
        <span class="stock-val" style="color:<?= ($bobines_stats['films_restants']??0)<500?'#dc2626':'#15803d' ?>"><?= number_format((int)($bobines_stats['films_restants']??0),0,',',' ') ?></span>
      </div>
      <div class="stock-stat-row">
        <span style="color:#06033A">Articles stock bas</span>
        <span class="stock-val" style="color:<?= $alertes_stock>0?'#dc2626':'#15803d' ?>"><?= $alertes_stock ?></span>
      </div>
      <div class="stock-stat-row">
        <span style="color:#06033A">Sites rivets sous seuil</span>
        <span class="stock-val" style="color:<?= $rivets_bas>0?'#dc2626':'#15803d' ?>"><?= $rivets_bas ?></span>
      </div>
      <div class="stock-stat-row">
        <span style="color:#06033A">Rivets gonflable total</span>
        <span class="stock-val" style="color:#0e7490"><?= number_format($riv_total_gonfl,0,',',' ') ?></span>
      </div>
      <div class="stock-stat-row">
        <span style="color:#06033A">Rivets éclaté total</span>
        <span class="stock-val" style="color:#06033A"><?= number_format($riv_total_eclat,0,',',' ') ?></span>
      </div>
    </div>

    <!-- PMMA + bouton films -->
    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
      <button onclick="openFilmsModal()" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 14px;background:#f5f3ff;border:1px solid #ede9fe;border-radius:10px;font-size:12px;font-weight:700;color:#7c3aed;cursor:pointer;white-space:nowrap">
        <i class="ph-duotone ph-chart-bar-horizontal"></i> Films par bobine
      </button>
      <button onclick="openPmmaModal()" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 14px;background:#ecfeff;border:1px solid #cffafe;border-radius:10px;font-size:12px;font-weight:700;color:#0e7490;cursor:pointer;white-space:nowrap">
        <i class="ph-duotone ph-printer"></i> PMMA par site
      </button>
    </div>
  </div>
</div>

</div><!-- /pdg -->

<!-- ════ MODALS ════ -->
<div id="modal-films" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(6,3,58,.5);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:18px;width:100%;max-width:680px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid #e2e8f0;flex-shrink:0">
      <div style="width:36px;height:36px;border-radius:12px;background:#f5f3ff;display:flex;align-items:center;justify-content:center;color:#7c3aed;font-size:18px"><i class="ph-duotone ph-chart-bar-horizontal"></i></div>
      <div>
        <div style="font-weight:800;font-size:14px;color:#06033A"><?= h($films_modal_title) ?></div>
        <div style="font-size:12px;color:#5a6678"><?= $films_modal_sub ?></div>
      </div>
      <button onclick="document.getElementById('modal-films').style.display='none'" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#5a6678;font-size:24px;line-height:1;padding:4px 8px">&times;</button>
    </div>
    <div style="overflow:auto;padding:22px"><div style="width:100%;position:relative"><canvas id="cFilms" height="<?= $films_ch_h ?>"></canvas></div></div>
  </div>
</div>

<div id="modal-pmma" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(6,3,58,.5);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:18px;width:100%;max-width:680px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid #e2e8f0;flex-shrink:0">
      <div style="width:36px;height:36px;border-radius:12px;background:#ecfeff;display:flex;align-items:center;justify-content:center;color:#0e7490;font-size:18px"><i class="ph-duotone ph-printer"></i></div>
      <div>
        <div style="font-weight:800;font-size:14px;color:#06033A">Stock PMMA par type</div>
        <div style="font-size:12px;color:#5a6678"><?= $site_nom_sel ? h($site_nom_sel) . ' · stock PMMA' : 'Survoler pour le détail par site' ?></div>
      </div>
      <button onclick="document.getElementById('modal-pmma').style.display='none'" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#5a6678;font-size:24px;line-height:1;padding:4px 8px">&times;</button>
    </div>
    <div style="overflow:auto;padding:22px">
      <?php if ($site_id && empty($pmma_par_type)): ?>
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:32px 20px;text-align:center">
        <div style="width:48px;height:48px;border-radius:14px;background:#f0f9ff;display:flex;align-items:center;justify-content:center;color:#0e7490;font-size:24px"><i class="ph-duotone ph-printer"></i></div>
        <div style="font-weight:700;font-size:14px;color:#06033A">PMMA non configuré pour ce site</div>
        <div style="font-size:12px;color:#64748b;max-width:340px">Le stock PMMA n'est pas encore enregistré pour <strong><?= h($site_nom_sel) ?></strong>. Contactez un administrateur pour configurer les niveaux PMMA de ce site.</div>
      </div>
      <?php else: ?>
      <div style="width:100%;position:relative"><canvas id="cPmma" height="<?= $pmma_ch_h ?>"></canvas></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div id="modal-ops" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(6,3,58,.5);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:18px;width:100%;max-width:900px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid #e2e8f0;flex-shrink:0">
      <div style="width:36px;height:36px;border-radius:12px;background:#eef0f8;display:flex;align-items:center;justify-content:center;color:#06033A;font-size:18px"><i class="ph-duotone ph-table"></i></div>
      <div>
        <div style="font-weight:800;font-size:14px;color:#06033A">Détails par site — Opérations</div>
        <div style="font-size:12px;color:#5a6678">Points journaliers · <?= h($mois_display) ?></div>
      </div>
      <button onclick="document.getElementById('modal-ops').style.display='none'" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#5a6678;font-size:24px;line-height:1;padding:4px 8px">&times;</button>
    </div>
    <div style="overflow:auto;padding:0 22px 22px">
      <table class="ptbl" style="margin-top:0">
        <thead><tr>
          <th style="text-align:left">Site</th>
          <th style="min-width:110px;text-align:left">Progression</th>
          <th>Engins</th><th>Plaques</th><th>Moy V/H</th><th>Points</th><th>Statut</th>
        </tr></thead>
        <tbody>
        <?php foreach($prod_par_site as $i=>$s):
          $pct = $max_engins > 0 ? round($s['engins'] / $max_engins * 100) : 0;
          $col = $SC[$i % count($SC)];
          $mvh = (float)$s['moy_vh'];
          $mvh_cls = $mvh>=10?'mvh-g':($mvh>=5?'mvh-o':'mvh-r');
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:8px;height:8px;border-radius:50%;background:<?= $col ?>;flex-shrink:0"></div>
              <div>
                <div style="font-weight:700;color:#06033A"><?= h($s['nom']) ?></div>
                <div style="font-size:12px;color:#5a6678"><?= h($s['type']??'') ?></div>
              </div>
            </div>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;background:#e2e8f0;border-radius:3px;height:5px;overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:3px"></div>
              </div>
              <span style="font-size:12px;color:#5a6678;min-width:30px"><?= $pct ?>%</span>
            </div>
          </td>
          <td style="font-weight:800;font-family:'Montserrat',sans-serif;color:#06033A"><?= number_format((int)$s['engins'],0,',',' ') ?></td>
          <td style="font-weight:700;color:#1B75BC"><?= number_format((int)$s['plaques'],0,',',' ') ?></td>
          <td><span class="mvh <?= $mvh_cls ?>"><?= $mvh ?></span></td>
          <td style="color:#5a6678"><?= $s['nb_points'] ?></td>
          <td>
            <?php if($s['en_attente']>0): ?>
              <span class="type-pill"><?= $s['en_attente'] ?> att.</span>
            <?php elseif($s['nb_points']>0): ?>
              <i class="ph-duotone ph-check-circle" style="color:#15803d;font-size:18px"></i>
            <?php else: ?>
              <span style="color:#5a6678">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="pdg-tip" style="display:none;position:fixed;z-index:3000;background:white;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 8px 24px rgba(0,0,0,.13);pointer-events:none;min-width:180px;font-size:12px;line-height:1.7"></div>

<script>
var evolLabels  = <?= $js_evol_labels ?>;
var evolEngins  = <?= $js_evol_engins ?>;
var evolPlaques = <?= $js_evol_plaques ?>;
var filmsLabels = <?= $js_films_labels ?>;
var filmsValues = <?= $js_films_values ?>;
var filmsDetail = <?= $js_films_detail ?>;
var bobinesData = <?= $js_bobines ?>;
var cmdsData    = <?= $js_cmds ?>;
var pmmaLabels  = <?= $js_pmma_labels ?>;
var pmmaTotals  = <?= $js_pmma_totals ?>;
var pmmaBas     = <?= $js_pmma_bas ?>;
var pmmaDetail  = <?= $js_pmma_detail ?>;
const DPR = window.devicePixelRatio || 1;

// Avancement du dessin des graphes, de 0 à 1.
// Ces graphes sont relatifs à leur propre échelle : un anneau ne montre que
// des rapports, une courbe se cale sur son propre maximum. Mettre toute la
// série à l'échelle laisse donc le tracé strictement identique — c'est
// pourquoi l'animation y était invisible. On anime la géométrie à la place,
// l'échelle restant calculée sur les valeurs finales.
var pdgProg = 1;

function fmtN(n) { return Number(n).toLocaleString('fr-FR'); }

// Largeur réellement disponible dans un conteneur. Sa boîte de bordure
// inclut padding et bordure : s'en servir rend le canvas plus large que
// la place offerte, la piste de grille s'élargit pour l'accueillir, et
// le redessin suivant lit une largeur encore plus grande. Emballement.
function largeurUtile(p, defaut) {
    if (!p) return defaut;
    const cs = getComputedStyle(p);
    const w = p.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    return w > 40 ? Math.round(w) : defaut;
}

function initCv(id) {
    const el = document.getElementById(id); if (!el) return null;
    // el.height est écrasé plus bas par la taille en pixels physiques
    // (hauteur x DPR). Relire l'attribut au redessin suivant donnerait
    // donc une hauteur déjà multipliée, et le canvas grandirait à chaque
    // passage jusqu'à sortir de son cadre. On mémorise la valeur voulue.
    if (!el.dataset.hCss) el.dataset.hCss = parseInt(el.getAttribute('height') || 200);
    const w  = largeurUtile(el.parentElement, 400);
    const h  = parseInt(el.dataset.hCss);
    el.width  = Math.round(w * DPR);
    el.height = Math.round(h * DPR);
    el.style.width  = Math.round(w) + 'px';
    el.style.height = h + 'px';
    const ctx = el.getContext('2d');
    ctx.scale(DPR, DPR);
    return {ctx, w: Math.round(w), h};
}

// ── Line/Bar combo chart (évolution)
function drawEvol() {
    const c = initCv('cEvol'); if (!c) return;
    const {ctx, w, h} = c;
    const pad = {t:20, r:12, b:32, l:40};
    const cW = w - pad.l - pad.r, cH = h - pad.t - pad.b;
    const n  = evolLabels.length;
    if (!n) {
        ctx.fillStyle='#5a6678'; ctx.font='12px DM Sans,sans-serif';
        ctx.textAlign='center'; ctx.textBaseline='middle';
        ctx.fillText('Aucune donnée', w/2, h/2); return;
    }
    // L'echelle se calcule sur les valeurs finales : l'axe ne bouge pas
    // pendant que la courbe monte. Seule la hauteur tracee suit pdgProg.
    const max = Math.max(...evolEngins, 1);
    const step = cW / Math.max(n - 1, 1);
    const P = Math.max(0, Math.min(1, pdgProg));

    // Grid lines
    ctx.strokeStyle = '#f1f5f9'; ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = pad.t + cH * (1 - i / 4);
        ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(w - pad.r, y); ctx.stroke();
        ctx.fillStyle = '#5a6678'; ctx.font = '10px DM Sans,sans-serif';
        ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        ctx.fillText(Math.round(max * i / 4), pad.l - 5, y);
    }

    // Area fill under line
    ctx.beginPath();
    evolEngins.forEach((v, i) => {
        const x = pad.l + i * step;
        const y = pad.t + cH * (1 - (v * P) / max);
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
    });
    ctx.lineTo(pad.l + (n-1) * step, pad.t + cH);
    ctx.lineTo(pad.l, pad.t + cH);
    ctx.closePath();
    const grad = ctx.createLinearGradient(0, pad.t, 0, pad.t + cH);
    grad.addColorStop(0, 'rgba(27,117,188,.18)');
    grad.addColorStop(1, 'rgba(27,117,188,.02)');
    ctx.fillStyle = grad;
    ctx.fill();

    // Line
    ctx.beginPath();
    ctx.strokeStyle = '#1B75BC'; ctx.lineWidth = 2.5; ctx.lineJoin = 'round';
    evolEngins.forEach((v, i) => {
        const x = pad.l + i * step;
        const y = pad.t + cH * (1 - (v * P) / max);
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
    });
    ctx.stroke();

    // Dots + values
    evolEngins.forEach((v, i) => {
        const x = pad.l + i * step;
        const y = pad.t + cH * (1 - (v * P) / max);
        ctx.beginPath(); ctx.arc(x, y, 4, 0, Math.PI*2);
        ctx.fillStyle = '#fff'; ctx.fill();
        ctx.strokeStyle = '#1B75BC'; ctx.lineWidth = 2; ctx.stroke();
        if (v > 0) {
            ctx.fillStyle = '#06033A'; ctx.font = 'bold 10px DM Sans,sans-serif';
            ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
            ctx.fillText(fmtN(Math.round(v * P)), x, y - 6);
        }
        ctx.fillStyle = '#5a6678'; ctx.font = '10px DM Sans,sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        ctx.fillText(evolLabels[i] || '', x, pad.t + cH + 6);
    });
}

// ── Donut
function drawDonut(id, values, colors) {
    const el = document.getElementById(id); if (!el) return;
    // Même précaution que dans initCv : l'attribut width est écrasé par
    // sz x DPR, le relire ferait enfler l'anneau à chaque redessin.
    if (!el.dataset.szCss) el.dataset.szCss = parseInt(el.getAttribute('width') || 120);
    const sz = parseInt(el.dataset.szCss);
    el.width = sz*DPR; el.height = sz*DPR;
    el.style.width = sz+'px'; el.style.height = sz+'px';
    const ctx = el.getContext('2d');
    ctx.scale(DPR, DPR);
    const cx=sz/2, cy=sz/2, R=sz/2-5, r=R*0.58;
    const total = values.reduce((a,b)=>a+b, 0);
    if (!total) {
        ctx.fillStyle='#f1f5f9';
        ctx.beginPath(); ctx.arc(cx,cy,R,0,Math.PI*2); ctx.fill();
        ctx.fillStyle='#fff';
        ctx.beginPath(); ctx.arc(cx,cy,r,0,Math.PI*2); ctx.fill();
        ctx.fillStyle='#5a6678'; ctx.font='bold 12px Montserrat,sans-serif';
        ctx.textAlign='center'; ctx.textBaseline='middle';
        ctx.fillText('0', cx, cy); return;
    }
    // Les parts gardent leurs proportions ; c'est le balayage qui progresse.
    const debut  = -Math.PI/2;
    const limite = debut + Math.max(0, Math.min(1, pdgProg)) * Math.PI*2;
    // Fond de l'anneau, pour que la couronne existe avant d'être remplie
    ctx.fillStyle = '#f1f5f9';
    ctx.beginPath(); ctx.arc(cx,cy,R,0,Math.PI*2); ctx.fill();
    let angle = debut;
    values.forEach((v,i) => {
        if (!v) return;
        const slice = (v/total)*Math.PI*2;
        const fin   = Math.min(angle + slice, limite);
        if (fin > angle) {
            ctx.fillStyle = colors[i];
            ctx.beginPath(); ctx.moveTo(cx,cy); ctx.arc(cx,cy,R,angle,fin); ctx.closePath(); ctx.fill();
        }
        angle += slice;
    });
    ctx.fillStyle='#fff';
    ctx.beginPath(); ctx.arc(cx,cy,r,0,Math.PI*2); ctx.fill();
    ctx.fillStyle='#06033A'; ctx.font='bold 14px Montserrat,sans-serif';
    ctx.textAlign='center'; ctx.textBaseline='middle';
    ctx.fillText(Math.round(total * Math.max(0, Math.min(1, pdgProg))), cx, cy);
}

// ── Horizontal bar (films + PMMA)
function drawHBar(id, labels, values, color, detail, basList) {
    const c = initCv(id); if (!c) return;
    const {ctx, w, h} = c;
    if (!labels.length) {
        ctx.fillStyle='#5a6678'; ctx.font='12px DM Sans,sans-serif';
        ctx.textAlign='center'; ctx.textBaseline='middle';
        ctx.fillText('Aucune donnée', w/2, h/2); return;
    }
    const lw = 120, rw = 46;
    const bArea = w - lw - rw;
    const rowH = h / labels.length;
    const max = Math.max(...values, 1);
    labels.forEach((lbl, i) => {
        const y   = i * rowH;
        const bh  = Math.min(rowH * 0.42, 20);
        const by  = y + (rowH - bh) / 2;
        const tot = values[i];
        const bw  = Math.max((tot/max)*bArea, tot>0?4:0);
        const low = basList && basList[i] > 0;
        ctx.fillStyle = low ? '#dc2626' : '#06033A';
        ctx.font = '11px DM Sans,sans-serif';
        ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        const short = lbl.length > 16 ? lbl.substring(0,16)+'…' : lbl;
        ctx.fillText(short, lw - 8, y + rowH/2);
        ctx.fillStyle = low ? '#dc2626' : color;
        if (tot > 0) ctx.fillRect(lw, by, bw, bh);
        ctx.fillStyle = '#64748b'; ctx.font = '10px DM Sans,sans-serif';
        ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        ctx.fillText(fmtN(tot), lw + bw + 4, y + rowH/2);
    });
}

// ── Tooltip
function showTip(e, html) {
    const tip = document.getElementById('pdg-tip');
    tip.innerHTML = html; tip.style.display = 'block';
    const tw=tip.offsetWidth, th=tip.offsetHeight;
    const x = e.clientX+14, y = e.clientY-10;
    tip.style.left = (x+tw>window.innerWidth-8 ? x-tw-28 : x)+'px';
    tip.style.top  = (y+th>window.innerHeight-8 ? y-th+20 : y)+'px';
}
function hideTip() { document.getElementById('pdg-tip').style.display='none'; }

// ══════ TOOLTIP COUVERTURE DE STOCK ══════
var covTypes = <?= $js_cov_types ?>;
(function(){
  const tip = document.createElement('div');
  tip.className = 'cov-tip';
  document.body.appendChild(tip);

  function fmt(n){ return Number(n).toLocaleString('fr-FR'); }

  document.querySelectorAll('.biz-cov-r[data-sid]').forEach(row => {
    row.addEventListener('mouseenter', e => {
      const sid  = row.dataset.sid;
      const rows = covTypes[sid];
      if (!rows || !rows.length) return;
      tip.innerHTML = rows.map(r =>
        `<div class="cov-tip-row"><span class="cov-tip-lbl"><b style="color:#fff;margin-right:6px">${r.code}</b>${r.lbl}</span><span class="cov-tip-val">${fmt(r.films)}</span></div>`
      ).join('');
      tip.style.opacity = '1';
      positionTip(e);
    });
    row.addEventListener('mousemove', positionTip);
    row.addEventListener('mouseleave', () => { tip.style.opacity = '0'; });
  });

  function positionTip(e){
    const tw = tip.offsetWidth, th = tip.offsetHeight;
    let x = e.clientX + 14, y = e.clientY - 10;
    if (x + tw > window.innerWidth  - 8) x = e.clientX - tw - 14;
    if (y + th > window.innerHeight - 8) y = e.clientY - th - 10;
    tip.style.left = x + 'px';
    tip.style.top  = y + 'px';
  }
})();

// ══════ WIDGET PERFORMANCE PAR SITE ══════
var pfwSites = <?= $js_pfw_sites ?>;
let pfwSiteIdx  = 0;
let pfwQuarter  = <?= $pfw_quarter_def ?>;
const pfwAnnee  = '<?= $annee ?>';
const pfwQMonths = {1:['01','02','03'],2:['04','05','06'],3:['07','08','09'],4:['10','11','12']};
const pfwMLbls   = {'01':'Jan','02':'Fév','03':'Mar','04':'Avr','05':'Mai','06':'Juin','07':'Juil','08':'Aoû','09':'Sep','10':'Oct','11':'Nov','12':'Déc'};

function pfwChangeSite(idx) { pfwSiteIdx = idx; pfwUpdate(); }
function pfwChangeQ(btn) {
    document.querySelectorAll('.pfw-q').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    pfwQuarter = +btn.dataset.q;
    pfwUpdate();
}

function hexRgba(hex, a) {
    const r=parseInt(hex.slice(1,3),16),g=parseInt(hex.slice(3,5),16),b=parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${a})`;
}
function rrect(ctx, x, y, w, h, r) {
    let tl, tr, br, bl;
    if (Array.isArray(r)) {
        [tl, tr, br, bl] = r.map(v => Math.min(v, w/2, h/2));
    } else {
        tl = tr = br = bl = Math.min(r, w/2, h/2);
    }
    ctx.beginPath();
    ctx.moveTo(x+tl, y);
    ctx.lineTo(x+w-tr, y);   ctx.arcTo(x+w, y,   x+w, y+tr,  tr);
    ctx.lineTo(x+w, y+h-br); ctx.arcTo(x+w, y+h, x+w-br, y+h, br);
    ctx.lineTo(x+bl, y+h);   ctx.arcTo(x, y+h,   x, y+h-bl,  bl);
    ctx.lineTo(x, y+tl);     ctx.arcTo(x, y,     x+tl, y,     tl);
    ctx.closePath();
}

function pfwUpdate() {
    if (!pfwSites.length) {
        // Plus aucun site dans le périmètre : on efface, sinon le tracé
        // précédent resterait affiché comme s'il était à jour.
        const vide = document.getElementById('pfwChart');
        if (vide) vide.getContext('2d').clearRect(0, 0, vide.width, vide.height);
        return;
    }
    // La liste des sites change avec le filtre. Un index gardé d'un
    // affichage précédent peut désigner un site qui n'existe plus :
    // pfwSites[idx] valait alors undefined et toute la mise à jour
    // échouait en silence, laissant le bloc sur ses anciennes données.
    if (!(pfwSiteIdx >= 0 && pfwSiteIdx < pfwSites.length)) pfwSiteIdx = 0;
    const sel = document.getElementById('pfwSiteSelect');
    if (sel && +sel.value !== pfwSiteIdx) sel.value = String(pfwSiteIdx);
    const site = pfwSites[pfwSiteIdx];
    if (!site) return;
    // Panneau gauche
    document.getElementById('pfwLeft').style.background = site.color;
    document.getElementById('pfwMoy').textContent   = site.moy_vh ? site.moy_vh + ' v/h' : '—';
    document.getElementById('pfwFilms').textContent = fmtN(site.films_mois);
    document.getElementById('pfwStock').textContent = fmtN(site.films_rest);
    // Graphique
    pfwDrawChart(site);
}

function pfwDrawChart(site) {
    const el = document.getElementById('pfwChart'); if (!el) return;
    const container = el.parentElement;
    const W = largeurUtile(container, 500);
    const H = 230;
    el.width  = Math.round(W * DPR);
    el.height = Math.round(H * DPR);
    el.style.width  = Math.round(W) + 'px';
    el.style.height = H + 'px';
    const ctx = el.getContext('2d');
    ctx.scale(DPR, DPR);

    const months = pfwQMonths[pfwQuarter];
    const enginVals = months.map(m => { const k=pfwAnnee+'-'+m; return site.monthly[k]?site.monthly[k].engins:0; });
    const filmVals  = months.map(m => { const k=pfwAnnee+'-'+m; return site.monthly[k]?site.monthly[k].films:0; });

    // Mise à jour légende HTML
    const lsq = document.getElementById('pfwLegFilmsSq');
    const esq = document.getElementById('pfwLegEnginsSq');
    if (lsq) lsq.style.background = site.color;
    if (esq) esq.style.background = site.color;

    const pad = {t:28, r:58, b:36, l:18};
    const cW = W - pad.l - pad.r;
    const cH = H - pad.t - pad.b;
    const n  = months.length;
    const slot = cW / n;
    const gap  = 10;
    const bW   = Math.min(slot * 0.55, 80);
    const b1W  = (bW - gap) / 2; // films (hatched)
    const b2W  = (bW - gap) / 2; // engins (solid)
    const col  = site.color;

    const maxE = Math.max(...enginVals, 1);
    const maxF = Math.max(...filmVals,  1);

    // Grille + axe droit (engins)
    const yVals = [0, Math.round(maxE*0.33), Math.round(maxE*0.67), maxE];
    yVals.forEach((v, i) => {
        const y = pad.t + cH * (1 - i / (yVals.length-1));
        ctx.strokeStyle = '#f1f5f9'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(W-pad.r+6, y); ctx.stroke();
        ctx.fillStyle = '#cbd5e1'; ctx.font = '10px DM Sans,sans-serif';
        ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        ctx.fillText(fmtN(v), W-pad.r+10, y);
    });

    const PB = Math.max(0, Math.min(1, pdgProg));
    months.forEach((m, i) => {
        const bx = pad.l + slot*i + (slot - bW) / 2;
        const ev = enginVals[i];
        const fv = filmVals[i];

        // ── Bar films (hatché gauche)
        const fh = Math.max(4, (fv/maxF) * cH * PB);
        const fy = pad.t + cH - fh;
        // fond léger
        ctx.fillStyle = hexRgba(col, 0.12);
        rrect(ctx, bx, fy, b1W, fh, [6,6,0,0]); ctx.fill();
        // hachures diagonales
        ctx.save();
        rrect(ctx, bx, fy, b1W, fh, [6,6,0,0]); ctx.clip();
        ctx.strokeStyle = hexRgba(col, 0.45); ctx.lineWidth = 1.5;
        for (let d = -fh; d < b1W+fh; d += 9) {
            ctx.beginPath(); ctx.moveTo(bx+d, fy+fh); ctx.lineTo(bx+d+fh, fy); ctx.stroke();
        }
        ctx.restore();
        // pill valeur films
        if (fv > 0) {
            const lbl = fmtN(Math.round(fv * PB));
            ctx.font = 'bold 10px DM Sans,sans-serif';
            const lw = ctx.measureText(lbl).width + 12;
            const lh = 19; const lx = bx + (b1W-lw)/2; const ly = fy-lh-4;
            ctx.fillStyle = hexRgba(col, 0.75);
            rrect(ctx, lx, ly, lw, lh, 5); ctx.fill();
            ctx.fillStyle = '#fff'; ctx.textAlign='center'; ctx.textBaseline='middle';
            ctx.fillText(lbl, bx+b1W/2, ly+lh/2);
        }

        // ── Bar engins (solide droite)
        const ex = bx + b1W + gap;
        const eh = Math.max(4, (ev/maxE) * cH * PB);
        const ey = pad.t + cH - eh;
        ctx.fillStyle = ev > 0 ? col : '#f1f5f9';
        rrect(ctx, ex, ey, b2W, eh, [6,6,0,0]); ctx.fill();
        // pill valeur engins
        if (ev > 0) {
            const lbl = fmtN(Math.round(ev * PB));
            ctx.font = 'bold 10px DM Sans,sans-serif';
            const lw = ctx.measureText(lbl).width + 12;
            const lh = 19; const lx = ex + (b2W-lw)/2; const ly = ey-lh-4;
            ctx.fillStyle = col;
            rrect(ctx, lx, ly, lw, lh, 5); ctx.fill();
            ctx.fillStyle = '#fff'; ctx.textAlign='center'; ctx.textBaseline='middle';
            ctx.fillText(lbl, ex+b2W/2, ly+lh/2);
        }

        // Label mois centré sous la paire
        ctx.fillStyle = '#5a6678'; ctx.font = '11px DM Sans,sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        ctx.fillText(pfwMLbls[m], bx+bW/2, pad.t+cH+8);
    });

}

window.addEventListener('load', () => { if (pfwSites.length) setTimeout(pfwUpdate, 80); });
window.addEventListener('resize', () => { clearTimeout(window._prt); window._prt=setTimeout(pfwUpdate,200); });

function openFilmsModal() {
    document.getElementById('modal-films').style.display='flex';
    setTimeout(() => {
        drawHBar('cFilms', filmsLabels, filmsValues, '#7c3aed', filmsDetail, null);
        const ef = document.getElementById('cFilms');
        if (ef && filmsLabels.length) {
            ef.style.cursor='crosshair';
            ef.addEventListener('mousemove', e => {
                const rect=ef.getBoundingClientRect();
                const idx=Math.floor((e.clientY-rect.top)/(rect.height/filmsLabels.length));
                if (idx>=0 && idx<filmsLabels.length) {
                    const d=filmsDetail[idx]||[];
                    let html=`<div style="font-weight:700;color:#06033A;margin-bottom:6px">${filmsLabels[idx]}</div>`;
                    d.forEach(s=>{html+=`<div style="display:flex;justify-content:space-between;gap:16px"><span>${s.site}</span><span style="font-weight:700">${fmtN(s.films)}</span></div>`;});
                    if(!d.length) html+=`<div style="color:#5a6678">Aucune donnée</div>`;
                    showTip(e, html);
                } else hideTip();
            });
            ef.addEventListener('mouseleave', hideTip);
        }
    }, 40);
}

function openPmmaModal() {
    document.getElementById('modal-pmma').style.display='flex';
    setTimeout(() => {
        drawHBar('cPmma', pmmaLabels, pmmaTotals, '#0891b2', pmmaDetail, pmmaBas);
        const ep = document.getElementById('cPmma');
        if (ep && pmmaLabels.length) {
            ep.style.cursor='crosshair';
            ep.addEventListener('mousemove', e => {
                const rect=ep.getBoundingClientRect();
                const idx=Math.floor((e.clientY-rect.top)/(rect.height/pmmaLabels.length));
                if (idx>=0 && idx<pmmaLabels.length) {
                    const d=pmmaDetail[idx]||[];
                    let html=`<div style="font-weight:700;color:#06033A;margin-bottom:6px">${pmmaLabels[idx]}</div>`;
                    d.forEach(s=>{
                        const low=s.qty<s.seuil;
                        html+=`<div style="display:flex;justify-content:space-between;gap:14px;color:${low?'#dc2626':'#374151'}">
                            <span>${s.site||'—'}</span><span style="font-weight:700">${fmtN(s.qty)}</span></div>`;
                    });
                    if(pmmaBas[idx]>0) html+=`<div style="margin-top:5px;color:#dc2626;font-size:12px;font-weight:600">⚠ ${pmmaBas[idx]} site(s) stock bas</div>`;
                    showTip(e, html);
                } else hideTip();
            });
            ep.addEventListener('mouseleave', hideTip);
        }
    }, 40);
}

function render() {
    drawEvol();
    drawDonut('cBobines', bobinesData, ['#7c3aed','#1B75BC','#e2e8f0']);
    drawDonut('cCmds',    cmdsData,    ['#d97706','#1B75BC','#16a34a']);
}

window.addEventListener('load',   () => { setTimeout(render, 80); });
window.addEventListener('resize', () => { clearTimeout(window._rt); window._rt=setTimeout(render,200); });
</script>

<!-- Données des graphes, relues telles quelles lors d'un changement de
     filtre : c'est ce qui évite de recharger la page pour les mettre à jour. -->
<script type="application/json" id="pdg-data">
{"evolLabels":<?= $js_evol_labels ?>,"evolEngins":<?= $js_evol_engins ?>,
 "evolPlaques":<?= $js_evol_plaques ?>,"filmsLabels":<?= $js_films_labels ?>,
 "filmsValues":<?= $js_films_values ?>,"filmsDetail":<?= $js_films_detail ?>,
 "bobinesData":<?= $js_bobines ?>,"cmdsData":<?= $js_cmds ?>,
 "pmmaLabels":<?= $js_pmma_labels ?>,"pmmaTotals":<?= $js_pmma_totals ?>,
 "pmmaBas":<?= $js_pmma_bas ?>,"pmmaDetail":<?= $js_pmma_detail ?>,
 "covTypes":<?= $js_cov_types ?>,"pfwSites":<?= $js_pfw_sites ?>,
 "pfwQuarterDef":<?= (int)$pfw_quarter_def ?>}
</script>

<?php include __DIR__ . '/../templates/dash_anim.php'; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
