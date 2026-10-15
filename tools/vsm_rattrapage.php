<?php
// ============================================================
//  tools/vsm_rattrapage.php — Rattrapage de la validation stock jour
//
//  Depuis le 15/10/2026, la comparaison du stock est déclenchée par la
//  validation du point 18h et par l'import EMUCI (includes/
//  validation_stock.php), plus par l'ouverture de la page du GSB. Un site
//  dont la chaîne était déjà complète avant la mise à jour ne verrait
//  passer aucun de ces deux événements : ce script compare, pour chaque
//  site, le DERNIER point 18h validé resté sans validation de stock — celui
//  qui bloque le coordinateur. Rejouable : un site validé n'est plus repris,
//  un site en écart est réexaminé sans nouvelle alerte au GSB.
//
//  Usage (depuis la racine de l'application, une fois après le déploiement) :
//      sudo -u www-data php tools/vsm_rattrapage.php
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('CLI_MODE', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/validation_stock.php';

$libelles = [
    'valide_auto'  => 'validé automatiquement (0 écart), coordinateur notifié',
    'ecart'        => 'écart(s) : GSB alerté',
    'en_attente'   => 'import EMUCI attendu',
    'perime'       => 'des points plus récents existent : laissé au GSB',
    'sans_bobine'  => 'aucune bobine sur le point',
    'decision_gsb' => 'déjà décidé par le GSB',
];
$resultats = vsm_rattrapage(null);
if (!$resultats) {
    echo "Rien à rattraper : aucun site n'a de dernier point 18h validé sans validation de stock.\n";
    exit(0);
}
foreach ($resultats as $r) {
    $nom = (string)db_fetch_value("SELECT nom FROM sites WHERE id=?", [$r['site_id']]);
    printf("%-30s %s  %s%s\n", $nom, date('d/m/Y', strtotime($r['date'])),
        $r['statut'] === 'ecart' && empty($r['alertes']) ? 'écart(s) : GSB déjà alerté' : ($libelles[$r['statut']] ?? $r['statut']),
        $r['statut'] === 'ecart' ? " ({$r['nb_ecarts']})" : '');
}
echo count($resultats) . " site(s) examiné(s).\n";
