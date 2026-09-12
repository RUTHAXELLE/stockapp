<?php
// ============================================================
//  pages/simulation_stocks.php  —  Simulation & projection bobines
//  n° 2.6 du CR de réunion PDG.
//
//  Outil de projection, pas un rapport : il ne lit que l'historique et
//  n'écrit jamais rien. Aucune simulation ne doit pouvoir modifier un
//  stock réel — c'est la règle qui a guidé toute la page.
//
//  Deux cas d'usage demandés au CR :
//   1. « J'ai N bobines, jusqu'à quand puis-je travailler ? »
//   2. « J'ouvre un site de plus, mon stock encaisse-t-il ? Sinon,
//      combien commander pour tenir jusqu'à telle date ? »
//
//  Le stock est suivi en films, les questions se posent en bobines :
//  la conversion passe par films_par_bobine_moyen(), calculé sur le
//  parc réel plutôt que sur une constante.
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/consommation.php';

require_auth();
require_permission('simulation_stocks', 'can_read');

$user        = current_user();
$page_title  = 'Simulation & projection';
$active_page = 'simulation_stocks';

// ============================================================
//  PARAMÈTRES DE SIMULATION
//  Tout vient de l'URL : la page est rejouable et partageable par lien,
//  et aucune saisie n'a besoin d'être stockée.
// ============================================================
// Mode de simulation — le choix est explicite plutot que deduit du
// remplissage des champs : projeter un stock et projeter une ouverture
// de site sont deux questions distinctes, et les melanger obligeait
// l'utilisateur a deviner ce que la page allait calculer.
$MODES = [
    'stock'     => ['Projection du stock',      'Jusqu\'à quand le stock permet-il de travailler ?'],
    'ouverture' => ['Ouverture de site',        'Le stock actuel absorbe-t-il un ou plusieurs sites de plus ?'],
    'les_deux'  => ['Les deux',                 'Projeter une quantité choisie, sites supplémentaires compris.'],
];
$mode = $_GET['mode'] ?? 'stock';
if (!isset($MODES[$mode])) $mode = 'stock';
$avec_stock     = ($mode === 'stock' || $mode === 'les_deux');
$avec_ouverture = ($mode === 'ouverture' || $mode === 'les_deux');

$f_site      = (int)($_GET['site'] ?? 0);
$fenetre     = max(7, min(365, (int)($_GET['fenetre'] ?? 30)));   // historique retenu
$horizon     = (int)($_GET['horizon'] ?? 6);                      // mois projetés
if (!in_array($horizon, [3, 6, 12, 24], true)) $horizon = 6;

$conso_auto  = conso_moy_site($f_site, $fenetre);                 // films/jour observés
$conso_saisie = $_GET['conso'] ?? '';
$conso_jour  = ($conso_saisie !== '' && is_numeric($conso_saisie))
             ? max(0.0, (float)$conso_saisie)
             : $conso_auto;
$conso_manuelle = ($conso_saisie !== '' && is_numeric($conso_saisie));

// ── Perimetre matiere de la projection de stock
// La quantite ne se saisit plus globalement : chaque format a son propre
// conditionnement (500 ou 2000 films), et multiplier un nombre de bobines
// par une moyenne du parc donnait un stock projete qui ne correspondait a
// rien — 11 bobines x 929 = 10 219 films la ou le stock reel en compte
// 9 600. La projection se fait donc format par format, quantite editable,
// et l'agregat est la somme de ces lignes.
$formats_base  = conso_stock_par_format($f_site, $fenetre);
$pmma_base     = conso_stock_par_pmma($f_site, $fenetre);
$formats_dispo = array_keys($formats_base);
$pmma_dispo    = array_keys($pmma_base);

// Libelle lisible d'un format. Les ecrans affichaient le code type (A001)
// et la serie (A) : deux identifiants internes qui ne disent rien a qui
// lit la projection. « Auto — Privee » porte la meme information.
// Le code reste la valeur transmise par le formulaire, c'est la cle.
$fmt_lbl = function (string $code) use ($formats_base): string {
    $f = $formats_base[$code] ?? null;
    if (!$f) return $code;
    $v = $f['version'] ?? '';
    return $f['format'] . ($v !== '' && $v !== '—' ? ' — ' . $v : '');
};
$fmt_lbls = function (array $codes) use ($fmt_lbl): string {
    return implode(', ', array_map($fmt_lbl, $codes));
};

// Bobines et vignettes se comptent separement. Le mot employe dans les
// totaux suit donc le perimetre retenu : parler de « bobines » quand la
// selection ne contient que des vignettes serait faux, et l'inverse
// aussi. Les deux ensemble se disent « bobines et vignettes ».
$mot_unites = function (array $codes) use ($formats_base): string {
    $cats = [];
    foreach ($codes as $c)
        if (isset($formats_base[$c])) $cats[$formats_base[$c]['categorie']] = true;
    if (count($cats) === 1) return libelle_categorie(array_key_first($cats), true);
    return 'bobines et vignettes';
};

$formats_stock = isset($_GET['fmt_stock']) && is_array($_GET['fmt_stock'])
               ? array_values(array_intersect($_GET['fmt_stock'], $formats_dispo))
               : $formats_dispo;
$pmma_stock    = isset($_GET['pmma_stock']) && is_array($_GET['pmma_stock'])
               ? array_values(array_intersect($_GET['pmma_stock'], $pmma_dispo))
               : $pmma_dispo;
if (!$formats_stock) $formats_stock = $formats_dispo;
if (!$pmma_stock)    $pmma_stock    = $pmma_dispo;

// Quantites projetees, par format et par type. Vide = stock reel.
$qte_fmt_saisie  = (isset($_GET['qte_fmt'])  && is_array($_GET['qte_fmt']))  ? $_GET['qte_fmt']  : [];
$qte_pmma_saisie = (isset($_GET['qte_pmma']) && is_array($_GET['qte_pmma'])) ? $_GET['qte_pmma'] : [];

$stock_films_reel = 0;
$bobines_defaut   = 0;
foreach ($formats_base as $code => $f) {
    $stock_films_reel += $f['films_restants'];
    $bobines_defaut   += $f['bobines'];
}
$films_bobine = films_par_bobine_moyen($f_site);   // encore utilise pour les libelles

// ── Nouveaux sites — ignores si le mode ne les couvre pas
$nb_sites_new   = $avec_ouverture ? max(0, min(20, (int)($_GET['sites_new'] ?? 1))) : 0;

// Formats de bobines et types de PMMA prevus sur les nouveaux sites.
// Sans ce choix, la charge etait repartie sur TOUS les formats existants
// au prorata — une hypothese commode mais fausse des qu'un site n'est
// pas equipe comme les autres. Par defaut : tout est coche, ce qui
// reproduit le comportement precedent.
$formats_new = isset($_GET['fmt_new']) && is_array($_GET['fmt_new'])
             ? array_values(array_intersect($_GET['fmt_new'], $formats_dispo))
             : $formats_dispo;
$pmma_new    = isset($_GET['pmma_new']) && is_array($_GET['pmma_new'])
             ? array_values(array_intersect($_GET['pmma_new'], $pmma_dispo))
             : $pmma_dispo;
// Un choix vide n'a pas de sens : on retombe sur l'ensemble plutot que
// de projeter une ouverture qui ne consommerait rien.
if (!$formats_new) $formats_new = $formats_dispo;
if (!$pmma_new)    $pmma_new    = $pmma_dispo;
$conso_site_new = max(0.0, (float)($_GET['conso_new'] ?? 0));
// Sans estimation saisie, on prend la consommation moyenne d'un site
// existant : plus honnête qu'un zéro qui rendrait l'ajout indolore.
if ($nb_sites_new > 0 && $conso_site_new <= 0) {
    $sites_actifs = (int) db_fetch_value(
        "SELECT COUNT(DISTINCT site_id) FROM consommations_bobines
          WHERE date_conso >= (CURRENT_DATE - (? || ' DAY')::interval)", [$fenetre]);
    $conso_site_new = $sites_actifs > 0 ? conso_moy_site(0, $fenetre) / $sites_actifs : 0.0;
    $conso_new_estimee = true;
} else {
    $conso_new_estimee = false;
}

// ── Consommation PMMA d'un nouveau site, en unites/jour.
// Les nouveaux sites consomment aussi du PMMA : l'ignorer laissait la
// projection PMMA inchangee par une ouverture, ce qui est faux.
$pmma_conso_saisie = $_GET['conso_pmma_new'] ?? '';
$pmma_new_estimee  = false;
if ($pmma_conso_saisie !== '' && is_numeric($pmma_conso_saisie)) {
    $conso_pmma_new = max(0.0, (float)$pmma_conso_saisie);
} else {
    $sites_pmma = (int) db_fetch_value(
        "SELECT COUNT(DISTINCT p.site_id)
           FROM op_pmma_utilises pu JOIN op_points_journaliers p ON p.id = pu.point_id
          WHERE p.date_point >= (CURRENT_DATE - (? || ' DAY')::interval)
            AND p.statut <> 'brouillon'", [$fenetre]);
    $pmma_total = 0.0;
    foreach (conso_stock_par_pmma(0, $fenetre) as $x) $pmma_total += $x['conso'];
    $conso_pmma_new  = $sites_pmma > 0 ? $pmma_total / $sites_pmma : 0.0;
    $pmma_new_estimee = true;
}
if (!$avec_ouverture) $conso_pmma_new = 0.0;

// ============================================================
//  CALCUL
// ============================================================
// ── PROJECTION PAR FORMAT — base de tout le reste
// $formats porte, pour chaque format : sa quantite projetee (saisie ou
// stock reel), sa consommation, et plus bas la charge des nouveaux sites.
$formats = $formats_base;
foreach ($formats as $code => $f) {
    $retenu_stock = in_array($code, $formats_stock, true);
    $saisie = $qte_fmt_saisie[$code] ?? '';
    $bob    = ($avec_stock && $saisie !== '' && is_numeric($saisie))
            ? max(0, (int)$saisie)
            : $f['bobines'];
    // Une quantite saisie s'exprime en bobines : on la convertit avec le
    // conditionnement propre au format, pas avec une moyenne du parc.
    $films  = ($avec_stock && $saisie !== '' && is_numeric($saisie))
            ? $bob * $f['films_par_bobine']
            : $f['films_restants'];
    if (!$retenu_stock) { $bob = 0; $films = 0; }
    $formats[$code]['retenu_stock']    = $retenu_stock;
    $formats[$code]['bobines_projete'] = $bob;
    $formats[$code]['films_projete']   = $films;
}

// Agregat : somme des formats retenus, et non un produit moyen.
$stock_films = 0; $nb_bobines = 0; $conso_base_sel = 0.0;
foreach ($formats as $f) {
    if (!$f['retenu_stock']) continue;
    $stock_films    += $f['films_projete'];
    $nb_bobines     += $f['bobines_projete'];
    $conso_base_sel += $f['conso'];
}
// La consommation retenue suit le meme perimetre : ne garder que les
// formats projetes tout en comptant la consommation de tous fausserait
// l'autonomie.
if (!$conso_manuelle && count($formats_stock) < count($formats_dispo)) {
    $conso_jour = $conso_base_sel;
}

// L'autonomie ne se calcule plus globalement : bobines et vignettes ont
// chacune la leur, plus bas dans $cats. Un chiffre unique melangeait deux
// echeances sans rapport et pouvait annoncer six mois alors qu'une des
// deux categories s'arrete dans trois semaines.
$jours_cible = (int) round($horizon * 30.44);            // mois moyen grégorien

// ── PROJECTION PAR FORMAT
// Deux articles distincts cohabitent ici : les bobines de films (series
// A, B, C, D) et les vignettes (TL Reservoir, WSL Pare-brise). Aucun ne
// remplace l'autre, et a l'interieur d'une categorie un format n'en
// remplace pas un autre non plus : op_types_vehicule relie chaque type
// de vehicule a une serie. Une projection globale peut donc annoncer des
// semaines d'autonomie alors que le format Moto est deja epuise et que
// cette production s'arrete. La contrainte reelle est le format qui
// arrive a echeance en premier, toutes categories confondues.
//
// $formats porte deja les quantites projetees calculees plus haut : le
// recharger ici les ecraserait et l'agregat ne correspondrait plus au
// detail affiche.

// La charge des nouveaux sites ne porte que sur les formats retenus.
// Repartition au prorata de leur consommation actuelle ; si aucun des
// formats choisis n'a d'historique — cas d'un format neuf, justement
// introduit par l'ouverture — on repartit a parts egales, faute de
// mieux, plutot que de ne rien affecter du tout.
$charge_new  = $nb_sites_new * $conso_site_new;
$base_sel    = 0.0;
foreach ($formats_new as $code) $base_sel += $formats[$code]['conso'] ?? 0;
$nb_sel      = max(1, count($formats_new));

foreach ($formats as $code => $f) {
    $retenu = in_array($code, $formats_new, true);
    $part   = !$retenu ? 0
            : ($base_sel > 0 ? $f['conso'] / $base_sel : 1 / $nb_sel);
    $c      = $f['conso'] + $charge_new * $part;
    $formats[$code]['retenu']     = $retenu;
    $formats[$code]['charge_new'] = $charge_new * $part;

    // Un format ecarte de la projection de stock reste affiche avec son
    // stock reel : c'est une ligne d'information, pas une projection.
    $stock_ligne = $f['retenu_stock'] ? $f['films_projete'] : $f['films_restants'];
    $bob_ligne   = $f['retenu_stock'] ? $f['bobines_projete'] : $f['bobines'];
    $formats[$code]['films_ligne']   = $stock_ligne;
    $formats[$code]['bobines_ligne'] = $bob_ligne;

    $j    = $c > 0 ? (int) floor($stock_ligne / $c) : null;
    $formats[$code]['conso_projetee'] = $c;
    $formats[$code]['jours_projetes'] = $j;
    $formats[$code]['date_projetee']  = $j !== null ? date('Y-m-d', strtotime("+$j days")) : null;
    $formats[$code]['couvre']         = $j !== null && $j >= $jours_cible;
    $manque_films = $j !== null ? max(0, (int)ceil($c * $jours_cible) - $stock_ligne) : 0;
    $formats[$code]['bobines_manquantes'] = (int) ceil($manque_films / $f['films_par_bobine']);
}

// L'impact d'une ouverture (jours perdus) et le format contraignant se
// calculent eux aussi par categorie : voir $cats plus bas.

// ============================================================
//  AGREGATION PAR CATEGORIE
// ============================================================
//  Une bobine de films et une vignette ne se remplacent pas : une
//  autonomie unique calculee sur les deux est une moyenne entre deux
//  echeances sans rapport. Elle peut annoncer six mois alors que les
//  vignettes s'arretent dans trois semaines, et que la production
//  correspondante s'arrete avec elles.
//
//  Chaque categorie porte donc sa propre projection : son stock, sa
//  consommation, son autonomie, son format contraignant et ce qu'il faut
//  commander. Les totaux tous articles confondus restent calcules pour
//  les exports, mais ne servent plus de conclusion.
//
//  La consommation d'une categorie est la somme de celle de ses formats,
//  et non conso_moy_site() qui ne sait pas distinguer les deux. Les deux
//  grandeurs peuvent legerement diverger, leur denominateur n'etant pas
//  identique : la premiere compte les jours depuis la premiere conso de
//  chaque format, la seconde depuis la premiere conso du site. La somme
//  des formats est retenue parce qu'elle est coherente avec le detail
//  affiche juste en dessous.
$CATS = ['bobine' => 'Bobines de films', 'vignette' => 'Vignettes'];
$cats = [];

// Base observee par categorie, avant toute saisie manuelle.
$obs_cat = ['bobine' => 0.0, 'vignette' => 0.0];
foreach ($formats as $code => $f)
    if ($f['retenu_stock']) $obs_cat[$f['categorie']] += $f['conso'];
$obs_total = array_sum($obs_cat);

foreach ($CATS as $cle => $titre) {
    $codes   = [];
    $retenus = [];
    foreach ($formats as $code => $f) {
        if ($f['categorie'] !== $cle) continue;
        $codes[] = $code;
        if ($f['retenu_stock']) $retenus[] = $code;
    }
    if (!$codes) continue;                     // categorie absente du perimetre

    $stock = 0; $unites = 0; $charge = 0.0;
    foreach ($retenus as $code) {
        $stock  += $formats[$code]['films_projete'];
        $unites += $formats[$code]['bobines_projete'];
        $charge += $formats[$code]['charge_new'];
    }

    // Une consommation saisie a la main porte sur tout le perimetre : on
    // la repartit au prorata de l'observe, faute de savoir ce que
    // l'utilisateur attribuait a chaque categorie. A defaut d'historique,
    // parts egales entre les categories presentes.
    if ($conso_manuelle) {
        $c_obs = $obs_total > 0
               ? $conso_jour * ($obs_cat[$cle] / $obs_total)
               : $conso_jour / max(1, count($CATS));
    } else {
        $c_obs = $obs_cat[$cle];
    }
    $c_tot = $c_obs + $charge;

    $j     = $c_tot > 0 ? (int) floor($stock / $c_tot) : null;
    $requis = (int) ceil($c_tot * $jours_cible);
    $manque = max(0, $requis - $stock);
    // Conditionnement moyen de la categorie, pas du parc : convertir un
    // deficit de vignettes avec la moyenne des bobines n'aurait pas de sens.
    $fpu    = $unites > 0 ? (int) round($stock / $unites) : 0;
    if ($fpu <= 0) {
        $fpu = 0; $n = 0;
        foreach ($retenus as $code) { $fpu += $formats[$code]['films_par_bobine']; $n++; }
        $fpu = $n ? (int) round($fpu / $n) : 500;
    }

    $crit = null;
    foreach ($retenus as $code) {
        if ($formats[$code]['jours_projetes'] === null) continue;
        if ($crit === null
            || $formats[$code]['jours_projetes'] < $formats[$crit]['jours_projetes']) $crit = $code;
    }

    $sans_new = ($avec_ouverture && $nb_sites_new > 0 && $c_obs > 0)
              ? (int) floor($stock / $c_obs) : null;

    // Une courbe par categorie : superposer deux stocks sans rapport sur
    // un meme axe laisserait croire a un total.
    $courbe_cat = [];
    if ($c_tot > 0) {
        for ($d = 0; $d <= $jours_cible; $d += 7) {
            $courbe_cat[] = ['date'  => date('Y-m-d', strtotime("+$d days")),
                             'films' => max(0, (int) round($stock - $c_tot * $d))];
        }
    }

    $cats[$cle] = [
        'titre'        => $titre,
        'mot'          => libelle_categorie($cle, true),
        'codes'        => $codes,
        'retenus'      => $retenus,
        'stock'        => $stock,
        'unites'       => $unites,
        'conso_obs'    => $c_obs,
        'charge_new'   => $charge,
        'conso_totale' => $c_tot,
        'jours'        => $j,
        'date_epuis'   => $j !== null ? date('Y-m-d', strtotime("+$j days")) : null,
        'deficit'      => $manque,
        'par_unite'    => $fpu,
        'a_commander'  => (int) ceil($manque / max(1, $fpu)),
        'couvre'       => $j !== null && $j >= $jours_cible,
        'critique'     => $crit,
        'jours_sans_new' => $sans_new,
        'jours_perdus' => ($sans_new !== null && $j !== null) ? $sans_new - $j : null,
        'courbe'       => $courbe_cat,
    ];
}

// ── PROJECTION PMMA — meme raisonnement, un type ne remplace pas un
// autre, et la charge des nouveaux sites ne porte que sur les types
// retenus pour ces sites.
$pmma_formats = $pmma_base;
$charge_pmma  = $nb_sites_new * $conso_pmma_new;
$base_pmma    = 0.0;
foreach ($pmma_new as $t) $base_pmma += $pmma_formats[$t]['conso'] ?? 0;
$nb_pmma_sel  = max(1, count($pmma_new));

foreach ($pmma_formats as $t => $p) {
    $retenu = in_array($t, $pmma_new, true);
    $part   = !$retenu ? 0
            : ($base_pmma > 0 ? $p['conso'] / $base_pmma : 1 / $nb_pmma_sel);
    $c      = $p['conso'] + $charge_pmma * $part;

    // Quantite projetee : saisie de l'utilisateur si presente, sinon le
    // stock reel du type. Le PMMA se compte a l'unite, aucune conversion.
    $retenu_stock = in_array($t, $pmma_stock, true);
    $saisie_p = $qte_pmma_saisie[$t] ?? '';
    $q = ($avec_stock && $saisie_p !== '' && is_numeric($saisie_p))
       ? max(0, (int)$saisie_p)
       : $p['quantite'];
    if (!$retenu_stock) $q = $p['quantite'];   // ligne informative

    $j = $c > 0 ? (int) floor($q / $c) : null;
    $pmma_formats[$t]['retenu']         = $retenu;
    $pmma_formats[$t]['retenu_stock']   = $retenu_stock;
    $pmma_formats[$t]['qte_projetee']   = $q;
    $pmma_formats[$t]['charge_new']     = $charge_pmma * $part;
    $pmma_formats[$t]['conso_projetee'] = $c;
    $pmma_formats[$t]['jours_projetes'] = $j;
    $pmma_formats[$t]['date_projetee']  = $j !== null ? date('Y-m-d', strtotime("+$j days")) : null;
    // Quantite a prevoir pour tenir l'horizon avec cette charge
    $pmma_formats[$t]['manque'] = $j !== null
        ? max(0, (int)ceil($c * $jours_cible) - $q) : 0;
}

$pmma_critique = null;
foreach ($pmma_formats as $t => $p) {
    if ($p['jours_projetes'] === null || !$p['retenu_stock']) continue;
    if ($pmma_critique === null
        || $p['jours_projetes'] < $pmma_formats[$pmma_critique]['jours_projetes']) {
        $pmma_critique = $t;
    }
}

// La courbe d'evolution est calculee par categorie dans $cats : additionner
// un stock de bobines et un stock de vignettes sur un meme axe produirait
// un total qui ne correspond a aucune realite d'approvisionnement.

$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
$site_nom   = '';
foreach ($sites_list as $s) if ((int)$s['id'] === $f_site) $site_nom = $s['nom'];
$conso_sites = conso_moy_par_site($fenetre);


// ============================================================
//  EXPORTS
// ============================================================
$export = trim($_GET['export'] ?? '');
if ($export !== '' && !can('simulation_stocks', 'can_export')) {
    http_response_code(403); exit('Export non autorisé pour ce profil.');
}

$resume = [
    ['Mode de simulation',          $MODES[$mode][0]],
    ['Périmètre',                   $site_nom ?: 'Tous les sites'],
    ['Formats projetés',            count($formats_stock) === count($formats_dispo)
        ? 'tous (' . $fmt_lbls($formats_dispo) . ')'
        : $fmt_lbls($formats_stock)],
    ['Types de PMMA projetés',      count($pmma_stock) === count($pmma_dispo)
        ? 'tous (' . implode(', ', $pmma_dispo) . ')'
        : implode(', ', $pmma_stock)],
    ['Stock réel du périmètre',     fmt_number($bobines_defaut) . ' '
                                    . $mot_unites($formats_dispo) . ', '
                                    . fmt_number($stock_films_reel) . ' films'],
    ['Consommation saisie',         $conso_manuelle
                                    ? number_format($conso_jour, 1, ',', ' ')
                                      . ' films/jour, répartie entre catégories au prorata de l’observé'
                                    : 'aucune — consommation observée par catégorie'],
    ['Nouveaux sites simulés',      $nb_sites_new . ($nb_sites_new ? ' × ' . number_format($conso_site_new, 1, ',', ' ') . ' films/jour' : '')],
    ['Formats prévus sur ces sites', $nb_sites_new ? $fmt_lbls($formats_new) : '—'],
    ['Catégories concernées',       $nb_sites_new ? $mot_unites($formats_new) : '—'],
    ['PMMA prévu sur ces sites',    $nb_sites_new
        ? implode(', ', $pmma_new) . ' — ' . number_format($conso_pmma_new, 1, ',', ' ') . ' unités/jour et par site'
        : '—'],
    ['Horizon cible',               $horizon . ' mois (' . $jours_cible . ' jours)'],
    ['PMMA le plus contraint (périmètre projeté)', $pmma_critique !== null
        ? $pmma_critique . ' — ' . fmt_number($pmma_formats[$pmma_critique]['jours_projetes'])
          . ' jours (' . fmt_date($pmma_formats[$pmma_critique]['date_projetee']) . ')'
          . ($nb_sites_new > 0
             ? (!empty($pmma_formats[$pmma_critique]['retenu'])
                ? ' — prévu sur les nouveaux sites'
                : ' — hors sélection nouveaux sites')
             : '')
        : (count($pmma_stock) < count($pmma_dispo)
           ? 'aucun type retenu n’a de consommation observée'
           : 'aucune consommation PMMA')],
];

// Chaque categorie apporte ses propres conclusions. Les empiler dans un
// meme tableau plutot que de les moyenner est tout l'objet de la
// separation : une autonomie unique melangeait deux echeances sans
// rapport.
foreach ($cats as $C) {
    $t = strtoupper($C['titre']);
    $resume[] = ["── $t", ''];
    $resume[] = ["  Unités projetées",     fmt_number($C['unites'])
                                           . ' (' . fmt_number($C['stock']) . ' films)'];
    $resume[] = ["  Consommation retenue", number_format($C['conso_obs'], 1, ',', ' ') . ' films/jour'
                                           . ($C['charge_new'] > 0.05
                                              ? ' + ' . number_format($C['charge_new'], 1, ',', ' ')
                                                . ' des nouveaux sites' : '')];
    $resume[] = ["  Autonomie",            $C['jours'] !== null
                                           ? $C['jours'] . ' jours (' . fmt_date($C['date_epuis']) . ')'
                                           : 'indéterminée'];
    $resume[] = ["  Couvre l’horizon",     $C['couvre'] ? 'Oui' : 'Non'];
    $resume[] = ["  À commander",          $C['couvre'] ? '0'
                                           : fmt_number($C['a_commander']) . ' ' . $C['mot']
                                             . ' (' . fmt_number($C['deficit']) . ' films)'];
    $resume[] = ["  Format le plus contraint", $C['critique'] !== null
        ? $fmt_lbl($C['critique']) . ' : '
          . fmt_number($formats[$C['critique']]['jours_projetes']) . ' jours ('
          . fmt_date($formats[$C['critique']]['date_projetee']) . ')'
        : 'aucun format retenu n’a de consommation observée'];
    if ($C['jours_perdus'] !== null)
        $resume[] = ["  Jours perdus par l'ouverture", fmt_number((int)$C['jours_perdus'])];
}

// Detail par format, repris dans les deux exports : un agregat sans son
// detail par format ferait perdre l'information qui compte.
$detail_formats = [];
foreach ($formats as $code => $f) {
    $detail_formats[] = [
        $f['format'] . ($f['retenu_stock'] ? '' : ' (hors projection)'),
        libelle_categorie($f['categorie']),
        $f['version'], $f['bobines_ligne'], $f['films_ligne'],
        number_format($f['conso_projetee'], 1, ',', ' '),
        $f['jours_projetes'] !== null ? $f['jours_projetes'] : '—',
        $f['date_projetee'] ? fmt_date($f['date_projetee']) : '—',
        $f['jours_projetes'] === null ? '—' : $f['bobines_manquantes'],
    ];
}

if ($export === 'xlsx') {
    $sp = new Spreadsheet(); $sh = $sp->getActiveSheet()->setTitle('Simulation');
    $sh->setCellValue('A1', 'Paramètre'); $sh->setCellValue('B1', 'Valeur');
    $sh->getStyle('A1:B1')->applyFromArray([
        'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
        'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'06033A']],
        'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $r = 2;
    foreach ($resume as [$k, $v]) { $sh->setCellValue("A$r", $k); $sh->setCellValue("B$r", $v); $r++; }
    $r += 1;
    // setCellValueByColumnAndRow() a disparu en PhpSpreadsheet 2.0 ; le
    // projet est en 5.9. La notation [colonne, ligne] la remplace.
    $ent = ['Format','Catégorie','Version','Unités','Films','Films/jour','Autonomie (j)','Épuisement','À commander'];
    foreach ($ent as $i => $t) $sh->setCellValue([$i+1, $r], $t);
    $sh->getStyle("A$r:I$r")->getFont()->setBold(true);
    $r++;
    foreach ($detail_formats as $lig) {
        foreach ($lig as $i => $v) $sh->setCellValue([$i+1, $r], $v);
        $r++;
    }
    foreach ($cats as $C) {
        if (empty($C['courbe'])) continue;
        $r += 1;
        $sh->setCellValue("A$r", 'Date'); $sh->setCellValue("B$r", 'Films restants — ' . $C['titre']);
        $sh->getStyle("A$r:B$r")->getFont()->setBold(true);
        $r++;
        foreach ($C['courbe'] as $pt) {
            $sh->setCellValue("A$r", $pt['date']); $sh->setCellValue("B$r", $pt['films']); $r++;
        }
    }
    foreach (['A','B'] as $c) $sh->getColumnDimension($c)->setAutoSize(true);
    audit_log($user['id'],'READ','simulation_stocks',0,
        "Export XLSX — $nb_bobines unité(s) sur " . count($cats) . " catégorie(s), horizon $horizon mois");
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="simulation_stocks.xlsx"');
    header('Cache-Control: max-age=0');
    (new XlsxWriter($sp))->save('php://output');
    exit;
}

if ($export === 'pdf') {
    ob_start(); ?>
    <style>
      body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#1a1a2e}
      h1{font-size:16px;color:#06033A;margin:0 0 3px}
      .meta{font-size:9.5px;color:#64748b;margin-bottom:14px}
      table{width:100%;border-collapse:collapse;margin-bottom:16px}
      th{background:#06033A;color:#fff;font-size:9px;padding:6px 8px;text-align:left}
      td{border-bottom:1px solid #e2e8f0;padding:6px 8px}
      td.k{color:#64748b;width:45%}
      .concl{background:#f0f4ff;border-left:3px solid #1B75BC;padding:10px 13px;font-size:11.5px}
    </style>
    <h1>Simulation de couverture — bobines</h1>
    <div class="meta">
      <?= h($site_nom ?: 'Tous les sites') ?> · généré le <?= date('d/m/Y à H:i') ?> · ERP EMUCI
    </div>
    <?php if (empty($cats)): ?>
    <div class="concl">Aucun stock de bobine ni de vignette sur ce périmètre.</div>
    <?php endif; ?>
    <?php foreach ($cats as $C): ?>
    <div class="concl">
      <strong><?= h($C['titre']) ?></strong> —
      <?php if ($C['jours'] === null): ?>
        aucune consommation observée sur la période, la projection ne peut pas être calculée.
      <?php else: ?>
        <?= fmt_number($C['unites']) ?> <?= h($C['mot']) ?> = utilisation jusqu'au
        <?= h(fmt_date($C['date_epuis'])) ?> au rythme actuel, soit <?= $C['jours'] ?> jours.
        <?php if (!$C['couvre']): ?>
          Pour tenir <?= $horizon ?> mois, il manque <?= fmt_number($C['a_commander']) ?>
          <?= h($C['mot']) ?>.
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <table>
      <thead><tr><th>Paramètre</th><th>Valeur</th></tr></thead>
      <tbody>
      <?php foreach ($resume as [$k,$v]): ?>
        <tr><td class="k"><?= h($k) ?></td><td><?= h($v) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!empty($detail_formats)): ?>
    <h1 style="font-size:13px;margin:14px 0 4px">Détail par format</h1>
    <table>
      <thead><tr>
        <th>Format</th><th>Cat.</th><th>Version</th><th>Unités</th><th>Films</th>
        <th>Films/j</th><th>Autonomie</th><th>Épuisement</th><th>À commander</th>
      </tr></thead>
      <tbody>
      <?php foreach ($detail_formats as $lig): ?>
        <tr><?php foreach ($lig as $v): ?><td><?= h($v) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();
    $opt = new Options(); $opt->set('isHtml5ParserEnabled', true); $opt->set('defaultFont','DejaVu Sans');
    $pdf = new Dompdf($opt); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','portrait'); $pdf->render();
    audit_log($user['id'],'READ','simulation_stocks',0,
        "Export PDF — $nb_bobines unité(s) sur " . count($cats) . " catégorie(s)");
    $pdf->stream('simulation_stocks.pdf', ['Attachment'=>true]);
    exit;
}

include __DIR__ . '/../templates/header.php';
?>
<style>
.sim-grid{display:grid;grid-template-columns:330px minmax(0,1fr);gap:22px;align-items:start}
@media(max-width:980px){.sim-grid{grid-template-columns:minmax(0,1fr)}}
.sim-form{background:white;border:1px solid var(--border);border-radius:14px;padding:20px;position:sticky;top:88px}
.sim-form h3{font-family:'Plus Jakarta Sans',sans-serif;font-size:14.5px;font-weight:800;color:var(--navy);margin:0 0 4px}
.sim-form .hint{font-size:12px;color:var(--muted);margin:0 0 16px;line-height:1.5}
.sim-sec{font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:700;letter-spacing:.09em;
  text-transform:uppercase;color:var(--muted);margin:18px 0 9px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.sim-sec:first-of-type{margin-top:0}
.sim-f{margin-bottom:12px}
.sim-f label{display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:4px}
.sim-f input,.sim-f select{width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:9px;
  font-size:13.5px;outline:none;box-sizing:border-box}
.sim-f .sub{font-size:11.5px;color:var(--muted);margin-top:3px;line-height:1.45}
.sim-fixe{padding:9px 12px;background:var(--lighter);border-radius:9px;font-size:14px;color:var(--navy)}
.sim-modes{display:flex;flex-direction:column;gap:7px;margin-bottom:4px}
.sim-mode{display:block;border:1.5px solid var(--border);border-radius:10px;padding:9px 12px;
  cursor:pointer;transition:border-color .15s,background .15s}
.sim-mode:hover{background:var(--lighter)}
.sim-mode.on{border-color:var(--blue);background:var(--primary-l,#eaf3fb)}
.sim-mode input{margin-right:7px}
.sim-mode-t{font-size:13.5px;font-weight:700;color:var(--navy)}
.sim-mode-d{display:block;font-size:11.5px;color:var(--muted);margin-top:2px;line-height:1.4;padding-left:21px}
.sim-cat{margin-bottom:30px}
.sim-cat-t{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:12px;
  padding-bottom:9px;border-bottom:2px solid var(--border);
  font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)}
.sim-cat-t em{font-style:normal;font-size:12px;font-weight:600;color:var(--muted)}
.sim-verdict{border-radius:16px;padding:22px 24px;margin-bottom:18px;color:white}
.sim-verdict.ok{background:linear-gradient(135deg,#0f6b3f,#1e8449)}
.sim-verdict.ko{background:linear-gradient(135deg,#8c2c22,#c0392b)}
.sim-verdict.na{background:linear-gradient(135deg,#3a4a63,#64748b)}
.sim-verdict .t{font-family:'Plus Jakarta Sans',sans-serif;font-size:21px;font-weight:800;line-height:1.25;margin-bottom:6px}
.sim-verdict .s{font-size:13.5px;opacity:.92;line-height:1.55}
.sim-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(158px,1fr));gap:12px;margin-bottom:18px}
.sim-kpi{background:white;border:1px solid var(--border);border-radius:13px;padding:14px 16px;border-left:4px solid var(--blue)}
.sim-kpi.warn{border-left-color:#f39c12}
.sim-kpi.crit{border-left-color:var(--danger)}
.sim-kpi-v{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:900;color:var(--navy);line-height:1}
.sim-kpi-l{font-size:11.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:5px}
.sim-card{background:white;border:1px solid var(--border);border-radius:14px;padding:18px 20px;margin-bottom:18px}
.sim-card h4{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:800;color:var(--navy);margin:0 0 3px}
.sim-card .sc-sub{font-size:12px;color:var(--muted);margin-bottom:14px}
table.sim-t{width:100%;border-collapse:collapse;font-size:13px}
table.sim-t td{padding:7px 0;border-bottom:1px solid var(--border)}
table.sim-t tr:last-child td{border-bottom:none}
table.sim-t td:first-child{color:var(--muted)}
table.sim-t td:last-child{text-align:right;font-weight:700;color:var(--navy);font-variant-numeric:tabular-nums}
table.sim-fmt{width:100%;border-collapse:collapse;font-size:13px}
table.sim-fmt th{text-align:left;font-size:10.5px;font-weight:700;letter-spacing:.06em;
  text-transform:uppercase;color:var(--muted);padding:0 10px 7px 0;border-bottom:1px solid var(--border);white-space:nowrap}
table.sim-fmt th.n,table.sim-fmt td.n{text-align:right}
table.sim-fmt td{padding:9px 10px 9px 0;border-bottom:1px solid var(--border);
  color:var(--navy);font-variant-numeric:tabular-nums;white-space:nowrap}
table.sim-fmt tr:last-child td{border-bottom:none}
table.sim-fmt tr.crit td{background:#fdf3f2}
table.sim-fmt td.manque{color:var(--danger-d);font-weight:800}
.tag-crit{display:inline-block;margin-left:7px;padding:1px 7px;border-radius:9px;
  background:var(--danger);color:#fff;font-size:10px;font-weight:700;
  text-transform:uppercase;letter-spacing:.05em;vertical-align:1px}
.sim-alerte{margin-top:14px;background:#fdf3f2;border-left:3px solid var(--danger);
  border-radius:0 9px 9px 0;padding:11px 14px;font-size:13px;line-height:1.55;color:var(--navy)}
.tag-new{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:9px;
  background:var(--blue);color:#fff;font-size:10px;font-weight:700;
  text-transform:uppercase;letter-spacing:.05em;vertical-align:1px}
table.sim-fmt em.ajout{font-style:normal;color:var(--blue-deep,#0E5A94);font-size:11.5px;
  font-weight:700;margin-left:5px}
/* Menu déroulant à choix multiple.
   Les formats et les types de PMMA se comptent en dizaines : une rangée de
   pastilles à cocher débordait du panneau de paramètres. Un déroulant garde
   une hauteur fixe quel que soit le nombre de références. */
.sim-dd{position:relative}
.sim-dd-b{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;
  padding:8px 11px;border:1.5px solid var(--border);border-radius:9px;background:white;
  font-size:13.5px;font-family:inherit;color:var(--navy);text-align:left;cursor:pointer}
.sim-dd-b:hover{border-color:var(--blue)}
.sim-dd.open .sim-dd-b{border-color:var(--blue);border-radius:9px 9px 0 0}
.sim-dd.open .sim-dd-b i{transform:rotate(180deg)}
.sim-dd-b i{color:var(--muted);transition:transform .15s;flex:none}
.sim-dd-txt{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sim-dd-p{display:none;position:absolute;z-index:30;left:0;right:0;top:100%;
  background:white;border:1.5px solid var(--blue);border-top:none;border-radius:0 0 9px 9px;
  max-height:266px;overflow-y:auto;box-shadow:0 10px 26px rgba(6,3,58,.13)}
.sim-dd.open .sim-dd-p{display:block}
/* Ouverture vers le haut pour les déroulants en bas de formulaire : sinon
   le panneau dépasse la fenêtre et son pied devient inatteignable. */
.sim-dd.haut .sim-dd-p{top:auto;bottom:100%;border-top:1.5px solid var(--blue);
  border-bottom:none;border-radius:9px 9px 0 0;box-shadow:0 -10px 26px rgba(6,3,58,.13)}
.sim-dd.haut.open .sim-dd-b{border-radius:0 0 9px 9px}
.sim-dd-h{display:flex;align-items:center;gap:8px;padding:7px 11px;
  border-bottom:1px solid var(--border);background:var(--lighter);position:sticky;top:0}
.sim-dd-h button{border:none;background:none;padding:0;font-family:inherit;font-size:11.5px;
  font-weight:700;color:var(--blue);cursor:pointer;text-decoration:underline}
.sim-dd-c{margin-left:auto;font-size:10.5px;color:var(--muted)}
.sim-dd-i{display:flex;align-items:center;gap:8px;padding:7px 11px;border-bottom:1px solid var(--border)}
.sim-dd-i:last-child{border-bottom:none}
.sim-dd-i:hover{background:var(--lighter)}
.sim-dd-i>label{display:flex;align-items:center;gap:8px;flex:1;min-width:0;cursor:pointer;margin:0}
/* width:auto est indispensable : .sim-f input impose width:100%, ce qui
   étirait la case à cocher sur toute la ligne et écrasait le libellé. */
.sim-f .sim-dd-i input[type=checkbox]{flex:none;width:auto;margin:0}
.sim-dd-n{font-size:13px;font-weight:700;color:var(--navy);line-height:1.25;min-width:0}
.sim-dd-n em{display:block;font-style:normal;font-weight:500;font-size:11px;color:var(--muted)}
/* .sim-f input impose width:100% : il faut au moins autant de spécificité,
   sinon le champ quantité prend toute la largeur de la ligne. */
.sim-f .sim-dd-q{flex:none;width:74px;padding:5px 7px;border:1.5px solid var(--border);
  border-radius:7px;font-size:12.5px;text-align:right;
  font-variant-numeric:tabular-nums;box-sizing:border-box}
.sim-dd-q:disabled{background:var(--lighter);color:var(--muted)}
.sim-dd-vide{padding:11px;font-size:12.5px;color:var(--muted)}
/* Pied du panneau : collé en bas même quand la liste défile, sinon il
   faudrait dérouler jusqu'en bas pour trouver le bouton de validation. */
.sim-dd-f{display:flex;justify-content:flex-end;gap:7px;padding:8px 11px;
  border-top:1px solid var(--border);background:white;position:sticky;bottom:0}
.sim-dd-f button{border:1.5px solid var(--border);background:white;border-radius:8px;
  padding:5px 13px;font-family:inherit;font-size:12.5px;font-weight:700;
  color:var(--navy);cursor:pointer}
/* Le :not(.dd-ok) est indispensable. Sans lui, « .sim-dd-f button:hover »
   (spécificité 0,2,1) l'emportait au survol sur « .sim-dd-f .dd-ok »
   (0,2,0) pour le fond — mais pas pour la couleur du texte, qui restait
   blanche : le bouton Valider devenait blanc sur blanc, donc illisible
   au moment précis où on le vise. */
.sim-dd-f button:not(.dd-ok):hover{background:var(--lighter)}
.sim-dd-f .dd-ok{background:var(--primary-d);border-color:var(--primary-d);color:white}
.sim-dd-f button.dd-ok:hover{background:var(--primary-d);filter:brightness(1.12)}
tr.hors td{opacity:.55}
.tag-cat{display:inline-block;padding:1px 8px;border-radius:9px;font-size:10.5px;
  font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.tag-cat.bobine{background:var(--primary-l,#eaf3fb);color:var(--primary-d,#3D4FD1)}
.tag-cat.vignette{background:var(--secondary-l,#E8F5FF);color:#0E5A94}
.tag-hors{display:inline-block;margin-left:7px;padding:1px 7px;border-radius:9px;
  background:var(--lighter);color:var(--muted);font-size:10px;font-weight:700;
  text-transform:uppercase;letter-spacing:.05em;vertical-align:1px}
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <h2 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">
      <i class="ph ph-trend-up" aria-hidden="true"></i> Simulation & projection des stocks
    </h2>
    <p style="font-size:13px;color:var(--muted);margin-top:4px">
      Outil de projection — aucune donnée n'est modifiée, quelle que soit la simulation lancée.
    </p>
  </div>
  <?php if(can('simulation_stocks','can_export')): ?>
  <div style="display:flex;gap:8px">
    <a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'xlsx']))) ?>">
      <i class="ph-duotone ph-microsoft-excel-logo"></i> Excel</a>
    <a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'pdf']))) ?>">
      <i class="ph-duotone ph-file-pdf"></i> PDF</a>
  </div>
  <?php endif; ?>
</div>

<div class="sim-grid">

  <!-- ══ PARAMÈTRES ══ -->
  <form method="GET" class="sim-form">
    <h3>Paramètres</h3>
    <p class="hint">Les valeurs par défaut viennent de votre stock et de votre historique réels.</p>

    <div class="sim-sec">Que voulez-vous simuler ?</div>
    <div class="sim-modes">
      <?php foreach ($MODES as $k => [$lbl, $desc]): ?>
      <label class="sim-mode <?= $mode === $k ? 'on' : '' ?>">
        <input type="radio" name="mode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?>
               onchange="soumettre(this.form)">
        <span class="sim-mode-t"><?= h($lbl) ?></span>
        <span class="sim-mode-d"><?= h($desc) ?></span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="sim-sec">Périmètre</div>
    <div class="sim-f">
      <label>Site</label>
      <select name="site" onchange="simSite(this)">
        <option value="0">Tous les sites</option>
        <?php foreach($sites_list as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $f_site===(int)$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sim-f">
      <label>Historique retenu</label>
      <select name="fenetre" onchange="simSite(this)">
        <?php foreach([15=>'15 derniers jours',30=>'30 derniers jours',60=>'60 derniers jours',90=>'90 derniers jours'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $fenetre===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <div class="sub">Période sur laquelle la consommation moyenne est mesurée.</div>
    </div>

    <?php if ($avec_stock): ?>
    <div class="sim-sec">Stock à projeter</div>
    <div class="sim-f">
      <label>Formats retenus — bobines &amp; vignettes</label>
      <div class="sim-dd">
        <button type="button" class="sim-dd-b" onclick="ddOuvrir(this)">
          <span class="sim-dd-txt"></span><i class="ph ph-caret-down" aria-hidden="true"></i>
        </button>
        <div class="sim-dd-p">
          <?php if (empty($formats_dispo)): ?>
            <div class="sim-dd-vide">Aucune bobine en stock sur ce périmètre.</div>
          <?php else: ?>
          <div class="sim-dd-h">
            <button type="button" onclick="ddTout(this,1)">Tout</button>
            <button type="button" onclick="ddTout(this,0)">Aucun</button>
            <span class="sim-dd-c">quantité en unités</span>
          </div>
          <?php foreach ($formats_base as $code => $f): ?>
          <div class="sim-dd-i">
            <label>
              <input type="checkbox" name="fmt_stock[]" value="<?= h($code) ?>"
                     data-lbl="<?= h($fmt_lbl($code)) ?>"
                     <?= in_array($code, $formats_stock, true) ? 'checked' : '' ?>
                     onchange="ddMaj(this)">
              <span class="sim-dd-n"><?= h($f['format']) ?>
                <em><?= h(libelle_categorie($f['categorie'])) ?> · <?= h($f['version']) ?>
                  · <?= fmt_number($f['films_par_bobine']) ?> films par <?= h(libelle_categorie($f['categorie'])) ?></em>
              </span>
            </label>
            <input type="number" class="sim-dd-q" min="0" name="qte_fmt[<?= h($code) ?>]"
                   placeholder="<?= (int)$f['bobines'] ?>"
                   value="<?= h((string)($qte_fmt_saisie[$code] ?? '')) ?>">
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="sub">Bobines de films et vignettes figurent dans la même liste, chacune
        étiquetée : ce sont deux articles distincts, aucun ne remplace l'autre. Décochez un
        format pour le sortir de la projection. La quantité est en unités (une bobine, une
        vignette) : laissée vide, c'est le stock réel qui est repris. Chaque format est
        converti avec <strong>son propre</strong> conditionnement.<br>
        Stock réel du périmètre : <strong><?= fmt_number($bobines_defaut) ?></strong>
        <?= h($mot_unites($formats_dispo)) ?>
        (<?= fmt_number($stock_films_reel) ?> films).</div>
    </div>
    <div class="sim-f">
      <label>Types de PMMA retenus</label>
      <div class="sim-dd">
        <button type="button" class="sim-dd-b" onclick="ddOuvrir(this)">
          <span class="sim-dd-txt"></span><i class="ph ph-caret-down" aria-hidden="true"></i>
        </button>
        <div class="sim-dd-p">
          <?php if (empty($pmma_dispo)): ?>
            <div class="sim-dd-vide">Aucun stock PMMA sur ce périmètre.</div>
          <?php else: ?>
          <div class="sim-dd-h">
            <button type="button" onclick="ddTout(this,1)">Tout</button>
            <button type="button" onclick="ddTout(this,0)">Aucun</button>
            <span class="sim-dd-c">quantité en unités</span>
          </div>
          <?php foreach ($pmma_base as $t => $p): ?>
          <div class="sim-dd-i">
            <label>
              <input type="checkbox" name="pmma_stock[]" value="<?= h($t) ?>"
                     <?= in_array($t, $pmma_stock, true) ? 'checked' : '' ?>
                     onchange="ddMaj(this)">
              <span class="sim-dd-n"><?= h($t) ?>
                <em><?= fmt_number($p['quantite']) ?> en stock · seuil <?= fmt_number($p['seuil']) ?></em>
              </span>
            </label>
            <input type="number" class="sim-dd-q" min="0" name="qte_pmma[<?= h($t) ?>]"
                   placeholder="<?= (int)$p['quantite'] ?>"
                   value="<?= h((string)($qte_pmma_saisie[$t] ?? '')) ?>">
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="sub">Le PMMA se compte à l'unité. Vide = stock réel du type.</div>
    </div>
    <?php else: ?>
    <div class="sim-sec">Stock de référence</div>
    <div class="sim-f">
      <div class="sim-fixe">
        <strong><?= fmt_number($bobines_defaut) ?></strong> bobine(s) ·
        <?= fmt_number($stock_films_reel) ?> films
      </div>
      <div class="sub">Votre stock réel. En mode ouverture de site, la question porte sur
        ce que vous détenez aujourd'hui — pour projeter une autre quantité, choisissez « Les deux ».</div>
    </div>
    <?php endif; ?>

    <div class="sim-sec">Consommation & horizon</div>
    <div class="sim-f">
      <label>Consommation journalière</label>
      <input type="number" name="conso" step="0.1" min="0" value="<?= $conso_manuelle ? h($conso_saisie) : '' ?>"
             placeholder="<?= number_format($conso_auto, 1, '.', '') ?> (observée)">
      <div class="sub">Laisser vide pour utiliser la moyenne observée
        (<strong><?= number_format($conso_auto, 1, ',', ' ') ?></strong> films/jour).</div>
    </div>
    <div class="sim-f">
      <label>Horizon cible</label>
      <select name="horizon">
        <?php foreach([3=>'3 mois',6=>'6 mois',12=>'1 an',24=>'2 ans'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $horizon===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($avec_ouverture): ?>
    <div class="sim-sec">Sites à ouvrir</div>
    <div class="sim-f">
      <label>Nombre de nouveaux sites</label>
      <input type="number" name="sites_new" min="1" max="20" value="<?= max(1, $nb_sites_new) ?>">
    </div>
    <div class="sim-f">
      <label>Films par jour et par nouveau site</label>
      <input type="number" name="conso_new" step="0.1" min="0"
             value="<?= $nb_sites_new && !$conso_new_estimee ? h((string)$conso_site_new) : '' ?>">
      <div class="sub">Vide = moyenne d'un site existant
        (<strong><?= number_format($conso_site_new, 1, ',', ' ') ?></strong> films/jour).</div>
    </div>

    <div class="sim-f">
      <label>Formats prévus sur ces sites</label>
      <div class="sim-dd">
        <button type="button" class="sim-dd-b" onclick="ddOuvrir(this)">
          <span class="sim-dd-txt"></span><i class="ph ph-caret-down" aria-hidden="true"></i>
        </button>
        <div class="sim-dd-p">
          <?php if (empty($formats_dispo)): ?>
            <div class="sim-dd-vide">Aucun format en stock sur ce périmètre.</div>
          <?php else: ?>
          <div class="sim-dd-h">
            <button type="button" onclick="ddTout(this,1)">Tout</button>
            <button type="button" onclick="ddTout(this,0)">Aucun</button>
          </div>
          <?php foreach ($formats_base as $code => $f): ?>
          <div class="sim-dd-i">
            <label>
              <input type="checkbox" name="fmt_new[]" value="<?= h($code) ?>"
                     data-lbl="<?= h($fmt_lbl($code)) ?>"
                     <?= in_array($code, $formats_new, true) ? 'checked' : '' ?>
                     onchange="ddMaj(this)">
              <span class="sim-dd-n"><?= h($f['format']) ?>
                <em><?= h(libelle_categorie($f['categorie'])) ?> · <?= h($f['version']) ?></em></span>
            </label>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="sub">La charge des nouveaux sites ne pèse que sur les formats cochés,
        répartie au prorata de leur consommation actuelle.</div>
    </div>

    <div class="sim-f">
      <label>Unités de PMMA par jour et par nouveau site</label>
      <input type="number" name="conso_pmma_new" step="0.1" min="0"
             value="<?= $pmma_new_estimee ? '' : h((string)$conso_pmma_new) ?>">
      <div class="sub">Vide = moyenne d'un site existant
        (<strong><?= number_format($conso_pmma_new, 1, ',', ' ') ?></strong> unités/jour).</div>
    </div>

    <div class="sim-f">
      <label>Types de PMMA à prévoir</label>
      <div class="sim-dd">
        <button type="button" class="sim-dd-b" onclick="ddOuvrir(this)">
          <span class="sim-dd-txt"></span><i class="ph ph-caret-down" aria-hidden="true"></i>
        </button>
        <div class="sim-dd-p">
          <?php if (empty($pmma_dispo)): ?>
            <div class="sim-dd-vide">Aucun stock PMMA sur ce périmètre.</div>
          <?php else: ?>
          <div class="sim-dd-h">
            <button type="button" onclick="ddTout(this,1)">Tout</button>
            <button type="button" onclick="ddTout(this,0)">Aucun</button>
          </div>
          <?php foreach ($pmma_base as $t => $p): ?>
          <div class="sim-dd-i">
            <label>
              <input type="checkbox" name="pmma_new[]" value="<?= h($t) ?>"
                     <?= in_array($t, $pmma_new, true) ? 'checked' : '' ?>
                     onchange="ddMaj(this)">
              <span class="sim-dd-n"><?= h($t) ?>
                <em><?= fmt_number($p['quantite']) ?> en stock</em></span>
            </label>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <button class="btn btn-primary" style="width:100%;margin-top:6px" type="submit">
      <i class="ph ph-play" aria-hidden="true"></i> Lancer la simulation
    </button>
    <a class="btn btn-secondary" style="width:100%;margin-top:8px;text-align:center" href="simulation_stocks.php">
      Réinitialiser
    </a>
  </form>

  <!-- ══ RÉSULTATS ══ -->
  <div>
    <?php if (empty($cats)): ?>
    <div class="sim-verdict na">
      <div class="t">Aucun stock sur ce périmètre</div>
      <div class="s">Ni bobine ni vignette n'est enregistrée pour ce site.
        Changez de périmètre pour lancer une projection.</div>
    </div>
    <?php endif; ?>

    <?php /* Une section complète par catégorie : bobines et vignettes ne se
             remplacent pas, une conclusion commune serait une moyenne entre
             deux échéances sans rapport. */ ?>
    <?php foreach ($cats as $cle => $C): ?>
    <div class="sim-cat">
      <div class="sim-cat-t">
        <span class="tag-cat <?= $cle ?>"><?= h($C['mot']) ?></span>
        <?= h($C['titre']) ?>
        <em><?= count($C['retenus']) ?> format<?= count($C['retenus']) > 1 ? 's' : '' ?>
          sur <?= count($C['codes']) ?> dans le périmètre</em>
      </div>

      <?php if ($C['jours'] === null): ?>
      <div class="sim-verdict na">
        <div class="t">Projection impossible pour les <?= h($C['mot']) ?></div>
        <div class="s">Aucune consommation n'a été enregistrée sur les <?= $fenetre ?> derniers
          jours pour cette catégorie. Saisissez une consommation journalière à la main pour
          simuler malgré tout.</div>
      </div>
      <?php else: ?>
      <div class="sim-verdict <?= $C['couvre'] ? 'ok' : 'ko' ?>">
        <div class="t">
          <?php if ($mode === 'ouverture' && $C['jours_perdus'] !== null): ?>
            <?= $nb_sites_new ?> site<?= $nb_sites_new > 1 ? 's' : '' ?> de plus =
            autonomie <?= h($C['mot']) ?> ramenée à <?= fmt_number($C['jours']) ?> jours
          <?php else: ?>
            <?= fmt_number($C['unites']) ?> <?= h($C['mot']) ?> =
            utilisation jusqu'au <?= h(fmt_date($C['date_epuis'])) ?>
          <?php endif; ?>
        </div>
        <div class="s">
          <?php if ($mode === 'ouverture' && $C['jours_sans_new'] !== null): ?>
            Sans ouverture, ce stock tiendrait <strong><?= fmt_number($C['jours_sans_new']) ?> jours</strong>.
            Avec <?= $nb_sites_new ?> site<?= $nb_sites_new > 1 ? 's' : '' ?>
            supplémentaire<?= $nb_sites_new > 1 ? 's' : '' ?>, il tient
            <strong><?= fmt_number($C['jours']) ?> jours</strong> — soit
            <strong><?= fmt_number((int)$C['jours_perdus']) ?> jours perdus</strong>.
          <?php else: ?>
            Au rythme de <?= number_format($C['conso_totale'], 1, ',', ' ') ?> films/jour,
            ce stock tient <strong><?= fmt_number($C['jours']) ?> jours</strong>.
          <?php endif; ?>
          <?php if ($C['couvre']): ?>
            Il couvre l'horizon de <?= $horizon ?> mois demandé.
          <?php else: ?>
            Il <strong>ne couvre pas</strong> l'horizon de <?= $horizon ?> mois :
            il manque <strong><?= fmt_number($C['a_commander']) ?> <?= h($C['mot']) ?></strong>
            (<?= fmt_number($C['deficit']) ?> films) à commander.
          <?php endif; ?>
          <?php if ($C['critique'] !== null
                    && $formats[$C['critique']]['jours_projetes'] < $C['jours']): ?>
            <br><br>Ce chiffre agrège les formats de la catégorie. Le format
            <strong><?= h($formats[$C['critique']]['format']) ?></strong> s'épuise dès
            <strong><?= h(fmt_date($formats[$C['critique']]['date_projetee'])) ?></strong>,
            soit <?= fmt_number($formats[$C['critique']]['jours_projetes']) ?> jours —
            c'est lui qui arrêtera la production correspondante.
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="sim-kpis">
        <div class="sim-kpi">
          <div class="sim-kpi-v"><?= fmt_number($C['stock']) ?></div>
          <div class="sim-kpi-l">Films projetés</div>
        </div>
        <div class="sim-kpi">
          <div class="sim-kpi-v"><?= number_format($C['conso_totale'], 1, ',', ' ') ?></div>
          <div class="sim-kpi-l">Films / jour</div>
        </div>
        <div class="sim-kpi <?= $C['jours'] !== null && $C['jours'] < 30 ? 'crit' : ($C['couvre'] ? '' : 'warn') ?>">
          <div class="sim-kpi-v"><?= $C['jours'] !== null ? fmt_number($C['jours']) : '—' ?></div>
          <div class="sim-kpi-l">Jours d'autonomie</div>
        </div>
        <div class="sim-kpi <?= $C['couvre'] ? '' : 'crit' ?>">
          <div class="sim-kpi-v"><?= $C['couvre'] ? '0' : fmt_number($C['a_commander']) ?></div>
          <div class="sim-kpi-l"><?= h($C['mot']) ?> à commander</div>
        </div>
        <?php if ($C['jours_perdus'] !== null): ?>
        <div class="sim-kpi <?= $C['jours_perdus'] > 0 ? 'crit' : '' ?>">
          <div class="sim-kpi-v">−<?= fmt_number((int)$C['jours_perdus']) ?></div>
          <div class="sim-kpi-l">Jours perdus par l'ouverture</div>
        </div>
        <?php endif; ?>
      </div>

      <div class="sim-card">
        <h4>Détail par format</h4>
        <div class="sc-sub">
          Un format n'en remplace pas un autre : chaque type de véhicule dépend d'une série
          précise. C'est le format qui s'épuise en premier qui arrête la production, pas la
          moyenne de la catégorie. Le total ci-dessus est la somme de ces lignes.
          <?php if (count($C['retenus']) < count($C['codes'])): ?>
          <br>Les formats marqués « hors projection » ne sont pas retenus dans votre périmètre :
          ils restent affichés avec leur stock réel, à titre indicatif, et ne comptent ni dans
          le total ni dans la contrainte.
          <?php endif; ?>
          <?php if ($nb_sites_new > 0): ?>
          <br>Seuls les formats marqués « nouveaux sites » reçoivent la charge supplémentaire.
          <?php endif; ?>
        </div>
        <div style="overflow-x:auto">
        <table class="sim-fmt">
          <thead><tr>
            <th>Format</th><th>Version</th>
            <th class="n">Unités</th><th class="n">Films</th>
            <th class="n">Films / jour</th><th class="n">Autonomie</th>
            <th>Épuisement</th><th class="n">À commander</th>
          </tr></thead>
          <tbody>
          <?php foreach ($C['codes'] as $code): $f = $formats[$code];
            $crit = ($code === $C['critique']);
            $hors = !$f['retenu_stock']; ?>
          <tr class="<?= $crit ? 'crit' : ($hors ? 'hors' : '') ?>">
            <td>
              <strong><?= h($f['format']) ?></strong>
              <?php if ($crit): ?><span class="tag-crit">contrainte</span><?php endif; ?>
              <?php if ($hors): ?><span class="tag-hors">hors projection</span><?php endif; ?>
              <?php if ($nb_sites_new > 0 && !empty($f['retenu'])): ?><span class="tag-new">nouveaux sites</span><?php endif; ?>
            </td>
            <td style="color:var(--muted)"><?= h($f['version']) ?></td>
            <td class="n"><?= fmt_number($f['bobines_ligne']) ?></td>
            <td class="n"><?= fmt_number($f['films_ligne']) ?></td>
            <td class="n">
              <?= number_format($f['conso_projetee'], 1, ',', ' ') ?>
              <?php if (!empty($f['charge_new']) && $f['charge_new'] > 0.05): ?>
              <em class="ajout">+<?= number_format($f['charge_new'], 1, ',', ' ') ?></em>
              <?php endif; ?>
            </td>
            <td class="n"><?= $f['jours_projetes'] !== null ? fmt_number($f['jours_projetes']).' j' : '—' ?></td>
            <td><?= $f['date_projetee'] ? h(fmt_date($f['date_projetee'])) : '<span style="color:var(--muted)">pas de consommation</span>' ?></td>
            <td class="n <?= $f['bobines_manquantes'] > 0 ? 'manque' : '' ?>">
              <?= $f['jours_projetes'] === null ? '—' : ($f['bobines_manquantes'] > 0 ? fmt_number($f['bobines_manquantes']) : '0') ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php if ($C['critique'] !== null && !$formats[$C['critique']]['couvre']): ?>
        <div class="sim-alerte">
          <strong><?= h($formats[$C['critique']]['format']) ?></strong> s'épuise le premier, dans
          <strong><?= fmt_number($formats[$C['critique']]['jours_projetes']) ?> jours</strong>
          (<?= h(fmt_date($formats[$C['critique']]['date_projetee'])) ?>) — bien avant l'horizon
          de <?= $horizon ?> mois. Les véhicules traités avec ce format ne pourront plus l'être
          à partir de cette date, quel que soit le stock des autres formats.
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($C['courbe'])): ?>
      <div class="sim-card">
        <h4>Évolution du stock — <?= h($C['mot']) ?></h4>
        <div class="sc-sub">Projection linéaire au rythme retenu, sur l'horizon de <?= $horizon ?> mois.</div>
        <canvas class="sim-chart" data-pts="<?= h(json_encode($C['courbe'])) ?>" height="190"></canvas>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- ══ PROJECTION PMMA ══ -->
    <div class="sim-card">
      <h4>Par type de PMMA</h4>
      <div class="sc-sub">Même raisonnement : un type de PMMA ne remplace pas un autre.
        <?php if ($nb_sites_new > 0): ?>
        Les types non retenus pour les nouveaux sites restent affichés avec leur consommation
        actuelle — c'est souvent l'un d'eux qui reste le plus contraint.
        <?php endif; ?>
      </div>
      <?php if (empty($pmma_formats)): ?>
        <div style="color:var(--muted);font-size:13.5px">Aucun stock PMMA sur ce périmètre.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="sim-fmt">
        <thead><tr>
          <th>Type</th><th class="n">Stock</th><th class="n">Seuil</th>
          <th class="n">Unités / jour</th><th class="n">Autonomie</th><th>Épuisement</th>
          <th class="n">À prévoir</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pmma_formats as $t => $p):
          $crit = ($t === $pmma_critique);
          $hors = !$p['retenu_stock'];
          $sous = $p['qte_projetee'] < $p['seuil']; ?>
        <tr class="<?= $crit ? 'crit' : ($hors ? 'hors' : '') ?>">
          <td><strong><?= h($t) ?></strong>
            <?php if ($crit): ?><span class="tag-crit">contrainte</span><?php endif; ?>
            <?php if ($hors): ?><span class="tag-hors">hors projection</span><?php endif; ?>
            <?php if ($nb_sites_new > 0 && !empty($p['retenu'])): ?><span class="tag-new">nouveaux sites</span><?php endif; ?>
          </td>
          <td class="n <?= $sous ? 'manque' : '' ?>"><?= fmt_number($p['qte_projetee']) ?></td>
          <td class="n" style="color:var(--muted)"><?= fmt_number($p['seuil']) ?></td>
          <td class="n">
            <?= number_format($p['conso_projetee'], 1, ',', ' ') ?>
            <?php if (!empty($p['charge_new']) && $p['charge_new'] > 0.05): ?>
            <em class="ajout">+<?= number_format($p['charge_new'], 1, ',', ' ') ?></em>
            <?php endif; ?>
          </td>
          <td class="n"><?= $p['jours_projetes'] !== null ? fmt_number($p['jours_projetes']).' j' : '—' ?></td>
          <td><?= $p['date_projetee'] ? h(fmt_date($p['date_projetee'])) : '<span style="color:var(--muted)">pas de consommation</span>' ?></td>
          <td class="n <?= !empty($p['manque']) ? 'manque' : '' ?>">
            <?= $p['jours_projetes'] === null ? '—' : fmt_number($p['manque']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>


    <div class="sim-card">
      <h4>Détail du calcul</h4>
      <div class="sc-sub">Chaque valeur retenue, pour que le résultat puisse être contesté.</div>
      <table class="sim-t">
        <?php foreach ($resume as [$k,$v]): ?>
        <tr><td><?= h($k) ?></td><td><?= h($v) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if (!$f_site && count($conso_sites) > 1): ?>
    <div class="sim-card">
      <h4>Consommation observée par site</h4>
      <div class="sc-sub">Sur les <?= $fenetre ?> derniers jours — utile pour estimer un nouveau site.</div>
      <table class="sim-t">
        <?php foreach ($conso_sites as $sid => $c): if ($c['conso'] <= 0) continue; ?>
        <tr><td><?= h($c['nom']) ?></td><td><?= number_format($c['conso'], 1, ',', ' ') ?> films/j</td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
// ── Menus déroulants à choix multiple
// Le libellé du bouton doit dire ce qui est retenu sans ouvrir le panneau :
// « Tous les formats (3) » ou la liste quand elle tient, sinon un compte.
// ── Position de lecture conservee d'une soumission a l'autre
// Le formulaire est un GET : chaque changement de site, de fenetre, de
// mode ou de selection recharge la page, et le navigateur repart en haut.
// L'utilisateur qui ajustait un parametre a mi-page perdait sa place a
// chaque essai. history.scrollRestoration ne couvre que le retour
// arriere, pas une nouvelle navigation : il faut memoriser la position.
var CLE_SCROLL = 'sim_stocks_scroll';

function memoriserScroll(){
  try { sessionStorage.setItem(CLE_SCROLL, String(window.scrollY || 0)); } catch (e) {}
}

// form.submit() appele par script ne declenche PAS l'evenement submit :
// chaque chemin de soumission doit passer par ici.
function soumettre(form){
  memoriserScroll();
  form.submit();
}

function restaurerScroll(){
  var y;
  try { y = sessionStorage.getItem(CLE_SCROLL); sessionStorage.removeItem(CLE_SCROLL); }
  catch (e) { return; }
  if (y === null) return;
  y = parseInt(y, 10);
  if (isNaN(y) || y <= 0) return;
  // Deux passes : la premiere des que le DOM est la, la seconde apres le
  // chargement des polices, qui decale la hauteur du contenu.
  window.scrollTo(0, y);
  window.addEventListener('load', function(){ window.scrollTo(0, y); });
}

function ddPanneau(el){ return el.closest('.sim-dd'); }

function ddTexte(dd){
  var cases = dd.querySelectorAll('input[type=checkbox]');
  var lbl   = dd.querySelector('.sim-dd-txt');
  if (!lbl) return;
  if (!cases.length){ lbl.textContent = 'Aucune référence'; return; }
  // data-lbl porte le libelle lisible ; sa valeur reste le code, qui est
  // la cle transmise au formulaire mais ne veut rien dire a l'ecran.
  var pris = [];
  cases.forEach(function(c){ if (c.checked) pris.push(c.dataset.lbl || c.value); });
  if (pris.length === 0)                 lbl.textContent = 'Aucun — tout sera repris';
  else if (pris.length === cases.length) lbl.textContent = 'Tous (' + cases.length + ')';
  else if (pris.length <= 2)             lbl.textContent = pris.join(', ');
  else                                   lbl.textContent = pris.length + ' sur ' + cases.length;
}

// Une quantité saisie sur une ligne décochée ne serait jamais lue : on
// désactive le champ pour que l'écran ne promette pas ce qu'il ignore.
function ddQte(dd){
  dd.querySelectorAll('.sim-dd-i').forEach(function(li){
    var c = li.querySelector('input[type=checkbox]');
    var q = li.querySelector('.sim-dd-q');
    if (c && q) q.disabled = !c.checked;
  });
}

function ddMaj(el){ var dd = ddPanneau(el); ddTexte(dd); ddQte(dd); }

function ddTout(btn, on){
  var dd = ddPanneau(btn);
  dd.querySelectorAll('input[type=checkbox]').forEach(function(c){ c.checked = !!on; });
  ddTexte(dd); ddQte(dd);
}

// État du panneau à l'ouverture, pour qu'« Annuler » puisse le rendre tel
// qu'il était plutôt que de laisser des cases cochées par erreur.
function ddPhoto(dd){
  var etat = [];
  dd.querySelectorAll('.sim-dd-i').forEach(function(li){
    var c = li.querySelector('input[type=checkbox]');
    var q = li.querySelector('.sim-dd-q');
    etat.push([c ? c.checked : false, q ? q.value : null]);
  });
  dd._photo = etat;
}

function ddAnnuler(btn){
  var dd = ddPanneau(btn);
  if (dd._photo) {
    dd.querySelectorAll('.sim-dd-i').forEach(function(li, i){
      var c = li.querySelector('input[type=checkbox]');
      var q = li.querySelector('.sim-dd-q');
      if (!dd._photo[i]) return;
      if (c) c.checked = dd._photo[i][0];
      if (q && dd._photo[i][1] !== null) q.value = dd._photo[i][1];
    });
  }
  ddTexte(dd); ddQte(dd);
  dd.classList.remove('open');
}

// « Valider » relance la simulation : sans cela il fallait refermer le
// panneau puis descendre chercher le bouton en bas du formulaire.
function ddValider(btn){
  var dd = ddPanneau(btn);
  dd.classList.remove('open');
  if (btn.form) soumettre(btn.form);
}

function ddOuvrir(btn){
  var dd = ddPanneau(btn), ouvert = dd.classList.contains('open');
  document.querySelectorAll('.sim-dd.open').forEach(function(o){ o.classList.remove('open'); });
  if (ouvert) return;
  ddPhoto(dd);
  dd.classList.add('open');
  dd.classList.remove('haut');
  // Mesure après ouverture : un panneau qui déborde en bas se retourne,
  // à condition qu'il y ait plus de place au-dessus du bouton.
  var p = dd.querySelector('.sim-dd-p'),
      r = p.getBoundingClientRect(),
      b = btn.getBoundingClientRect();
  if (r.bottom > window.innerHeight - 8 && b.top > r.height + 8) dd.classList.add('haut');
}

// Le pied est identique dans les quatre panneaux : le poser ici évite de
// répéter le même bloc de balisage quatre fois dans le formulaire.
function ddPied(dd){
  if (!dd.querySelector('.sim-dd-i')) return;          // panneau vide
  var p = dd.querySelector('.sim-dd-p');
  var f = document.createElement('div');
  f.className = 'sim-dd-f';
  f.innerHTML = '<button type="button" onclick="ddAnnuler(this)">Annuler</button>'
              + '<button type="button" class="dd-ok" onclick="ddValider(this)">Valider</button>';
  p.appendChild(f);
}

document.addEventListener('click', function(e){
  if (e.target.closest('.sim-dd')) return;
  document.querySelectorAll('.sim-dd.open').forEach(function(o){ o.classList.remove('open'); });
});
// Échap annule, comme partout ailleurs : refermer en gardant des cases
// cochées par accident serait la mauvaise surprise.
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape')
    document.querySelectorAll('.sim-dd.open').forEach(function(o){
      ddAnnuler(o.querySelector('.sim-dd-b'));
    });
});

// Changer de site ou de fenêtre change les références disponibles : garder
// les quantités saisies pour l'ancien périmètre donnerait une projection
// silencieusement fausse.
function simSite(el){
  el.form.querySelectorAll('.sim-dd-q').forEach(function(q){ q.value = ''; });
  soumettre(el.form);
}

document.querySelectorAll('.sim-dd').forEach(function(dd){
  ddPied(dd); ddTexte(dd); ddQte(dd);
});

// Le bouton « Lancer la simulation » et la touche Entree passent par
// l'evenement submit, que form.submit() ne declenche pas — d'ou les deux
// mecanismes.
var formSim = document.querySelector('.sim-form');
if (formSim) formSim.addEventListener('submit', memoriserScroll);
restaurerScroll();
</script>

<script>
// Une courbe par catégorie : le stock de bobines et celui de vignettes ne
// s'additionnent pas, les superposer sur un même axe laisserait croire à
// un total. Chaque canvas porte ses propres points en data-pts.
document.querySelectorAll('canvas.sim-chart').forEach(function(cv){
  let pts;
  try { pts = JSON.parse(cv.dataset.pts || '[]'); } catch (e) { return; }
  if (!pts.length) return;
  const dpr = window.devicePixelRatio || 1;
  function dessiner(){
    // La taille CSS doit être fixée explicitement : sans elle, l'élément
    // prend pour dimensions les attributs width/height, multipliés par le
    // devicePixelRatio. Sur un écran HiDPI le graphique sortait de sa
    // carte et n'occupait que la moitié haute de sa propre surface.
    cv.style.width = '100%';
    cv.style.height = '190px';
    const h = 190, w = cv.clientWidth || cv.parentElement.clientWidth;
    cv.width = w * dpr; cv.height = h * dpr;
    const g = cv.getContext('2d'); g.scale(dpr, dpr);
    g.clearRect(0, 0, w, h);

    const padL = 52, padR = 12, padT = 12, padB = 26;
    const cw = w - padL - padR, ch = h - padT - padB;
    const maxY = Math.max(...pts.map(p => p.films), 1);
    const x = i => padL + (cw * i) / Math.max(pts.length - 1, 1);
    const y = v => padT + ch - (ch * v) / maxY;

    // grille + graduations
    g.strokeStyle = '#e2e8f0'; g.fillStyle = '#94a3b8';
    g.font = '10px system-ui'; g.textAlign = 'right'; g.lineWidth = 1;
    for (let i = 0; i <= 4; i++){
      const v = maxY * i / 4, yy = y(v);
      g.beginPath(); g.moveTo(padL, yy); g.lineTo(w - padR, yy); g.stroke();
      g.fillText(Math.round(v).toLocaleString('fr-FR'), padL - 7, yy + 3);
    }
    // aire + courbe
    g.beginPath(); g.moveTo(x(0), y(pts[0].films));
    pts.forEach((p, i) => g.lineTo(x(i), y(p.films)));
    g.lineTo(x(pts.length - 1), y(0)); g.lineTo(x(0), y(0)); g.closePath();
    g.fillStyle = 'rgba(27,117,188,.13)'; g.fill();

    g.beginPath(); g.moveTo(x(0), y(pts[0].films));
    pts.forEach((p, i) => g.lineTo(x(i), y(p.films)));
    g.strokeStyle = '#1B75BC'; g.lineWidth = 2.2; g.stroke();

    // dates aux extrémités et au point de rupture
    g.fillStyle = '#64748b'; g.font = '10.5px system-ui';
    const fmt = d => new Date(d).toLocaleDateString('fr-FR', {day:'2-digit', month:'short'});
    g.textAlign = 'left';   g.fillText(fmt(pts[0].date), padL, h - 8);
    g.textAlign = 'right';  g.fillText(fmt(pts[pts.length-1].date), w - padR, h - 8);

    const rupture = pts.findIndex(p => p.films === 0);
    if (rupture > 0){
      g.strokeStyle = '#c0392b'; g.setLineDash([4,3]); g.lineWidth = 1.4;
      g.beginPath(); g.moveTo(x(rupture), padT); g.lineTo(x(rupture), padT + ch); g.stroke();
      g.setLineDash([]);
      g.fillStyle = '#c0392b'; g.textAlign = 'center';
      g.fillText('rupture', x(rupture), padT + 10);
    }
  }
  dessiner();
  window.addEventListener('resize', dessiner);
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
