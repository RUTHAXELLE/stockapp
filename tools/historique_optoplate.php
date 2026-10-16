<?php
// ============================================================
//  tools/historique_optoplate.php — Historique de production dans le dashboard
//
//  Le dashboard et la vue PDG calculent tout à partir des points journaliers
//  saisis dans l'ERP : la production d'avant la mise en service n'y figure
//  pas. Ce script la reconstitue à partir des plaques OptoPlate déjà
//  importées (écran Import EMUCI) : UN POINT PAR SITE ET PAR JOUR, avec
//  les engins et plaques par type de véhicule, les réimpressions
//  Duplicata / Post-Immat (même règle de classement que la comparaison du
//  superviseur, includes/optoplate_comparaison.php) et les films par
//  bobine lorsque la bobine existe dans l'ERP.
//
//  Garde-fous :
//   · SIMULATION par défaut : rien n'est écrit sans --appliquer ;
//   · aucun mouvement de stock : les points s'écrivent directement, sans
//     passer par l'enregistrement normal qui déduit films, rivets et PMMA ;
//   · type de point « historique » : ni le blocage des coordinateurs ni la
//     validation du stock (qui ne regardent que le point 18h) ne les voient ;
//   · un jour qui a déjà un point dans l'ERP (n'importe quel type) n'est
//     jamais touché ;
//   · rejouable : un jour déjà reconstitué est ignoré, --refaire le recrée.
//  Limites : OptoPlate ne connaît ni les heures, ni les rivets, ni le PMMA :
//  ils restent vides dans l'historique (le V/H n'est pas calculé).
//
//  La même reconstitution est proposée dans l'écran Import EMUCI (onglet
//  OptoPlate, encadré « Alimenter le dashboard ») : la logique est dans
//  includes/historique_optoplate.php, ce script n'en est que l'affichage.
//
//  Usage (depuis la racine de l'application) :
//      sudo -u www-data php tools/historique_optoplate.php            (simulation)
//      sudo -u www-data php tools/historique_optoplate.php --appliquer
//  Options : --du=AAAA-MM-JJ  --au=AAAA-MM-JJ  --site=ID  --refaire
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('CLI_MODE', true);
ini_set('memory_limit', '1G');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/historique_optoplate.php';

$o = getopt('', ['du:', 'au:', 'site:', 'appliquer', 'refaire']);
$appliquer = isset($o['appliquer']);
$refaire   = isset($o['refaire']);
$site_seul = isset($o['site']) ? (int)$o['site'] : 0;
$date_ok   = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

foreach (['du', 'au'] as $k) {
    if (isset($o[$k]) && !$date_ok($o[$k])) { fwrite(STDERR, "Option --$k : date attendue au format AAAA-MM-JJ.
"); exit(1); }
}

if ($err = historique_optoplate_prerequis()) { fwrite(STDERR, $err . "
"); exit(1); }

$premiere = db_fetch_value("SELECT DATE(MIN(date_installation)) FROM import_optoplate WHERE site_id IS NOT NULL");
if (!$premiere) {
    echo "Aucune plaque OptoPlate importée : importer d'abord l'export OptoPlate (Import EMUCI).
";
    exit(0);
}
$du = $o['du'] ?? $premiere;
$au = $o['au'] ?? date('Y-m-d', strtotime('-1 day'));   // la journée en cours n'est pas terminée
if ($du > $au) { fwrite(STDERR, "Période vide : $du est après $au.
"); exit(1); }

echo ($appliquer ? "APPLICATION" : "SIMULATION (rien n'est écrit)") . " : du $du au $au"
   . ($site_seul ? ", site n°$site_seul" : ', tous les sites') . ($refaire ? ', jours reconstitués refaits' : '') . "

";

try {
    $r = historique_optoplate($du, $au, $appliquer, $site_seul, $refaire);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
}
$stats = $r['stats'];

// ── Compte rendu
if (!$stats['points']) {
    echo "Rien à " . ($appliquer ? 'écrire' : 'reconstituer') . " : "
       . "{$stats['ignores_reels']} jour(s) ont déjà un point dans l'ERP, {$stats['ignores_hist']} déjà reconstitué(s).
";
    exit(0);
}
echo str_pad('Mois', 9) . str_pad('Points', 9) . str_pad('Engins', 10) . "Plaques
";
foreach ($r['par_mois'] as $m => $v) {
    echo str_pad($m, 9) . str_pad($v['points'], 9) . str_pad($v['engins'], 10) . $v['plaques'] . "
";
}
echo str_pad('TOTAL', 9) . str_pad($stats['points'], 9) . str_pad($stats['engins'], 10) . $stats['plaques'] . "

";

echo "Par site :
";
foreach ($r['par_site'] as $v) {
    printf("  %-30s %4d jours  %6d engins  dont %d duplicata/post-immat
", $v['nom'], $v['points'], $v['engins'], $v['dup_pi']);
}
echo "
";
if ($stats['ignores_reels']) echo "Jours ignorés, déjà saisis dans l'ERP : {$stats['ignores_reels']}
";
if ($stats['ignores_hist'])  echo "Jours ignorés, déjà reconstitués (utiliser --refaire pour les recréer) : {$stats['ignores_hist']}
";
if ($refaire && $stats['refaits']) echo "Jours reconstitués puis refaits : {$stats['refaits']}
";
if ($r['sans_site'])         echo "ATTENTION : {$r['sans_site']} plaque(s) d'un site inconnu ne sont dans aucun point (créer le site dans l'écran Sites puis réimporter).
";
if ($stats['vehicules_autre']) echo "ATTENTION : {$stats['vehicules_autre']} véhicule(s) de catégorie inconnue comptés en VP.
";
if ($stats['plaques_sans_bobine']) {
    $pct = round($stats['plaques_sans_bobine'] / max(1, $stats['plaques_total']) * 100);
    echo "Films : {$stats['plaques_sans_bobine']} plaque(s) sur {$stats['plaques_total']} ($pct %) sur des bobines absentes de l'ERP : "
       . "les films par bobine du dashboard seront en dessous des engins.
  Bobines les plus concernées : "
       . implode(', ', array_map(fn($n, $v) => "$n ($v)", array_keys($r['bobines_inconnues']), $r['bobines_inconnues'])) . "
";
}
if ($appliquer) {
    audit_log(null, 'CREATE', 'operations', 0,
        "Reconstitution historique OptoPlate $du au $au : {$stats['points']} point(s), {$stats['engins']} engins");
    echo "
Terminé : {$stats['points']} point(s) historique(s) écrits. Aucun stock modifié.
";
} else {
    echo "
Simulation terminée, rien n'a été écrit. Pour appliquer : relancer avec --appliquer.
";
}
