<?php
// ============================================================
//  includes/commande_inter_site.php
//  Commandes inter-sites (sql/migration_commandes_inter_site.sql) : un site A
//  commande à un autre site de production B plutôt qu'à un dépôt.
//   1. le coordinateur de A crée la commande, site fournisseur B, motif ;
//      il voit seulement si B a de quoi servir chaque ligne (« disponible /
//      insuffisant »), jamais ses quantités ;
//   2. le superviseur valide ligne par ligne, comme toute commande ;
//   3. le coordinateur de B (ou le GSB) choisit les bobines précises chez B,
//      entamées comprises, hors bobines d'un point de B pas encore validé ;
//   4. le gestionnaire de stock envoie un chauffeur : l'enlèvement débite le
//      stock de B (pas le stock central) ; le BL part avec le chauffeur ;
//   5. A réceptionne ligne par ligne ; un manque remis en stock revient à B.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/commande_bobines.php';
require_once __DIR__ . '/reception_commande.php';
require_once __DIR__ . '/articles_equipements.php';

/** Types de ligne qu'un site peut céder à un autre (pas d'équipement, pas de saisie libre). */
const CIS_TYPES = ['bobine', 'vignette', 'rivet', 'pmma', 'article'];

function cis_actif(): bool {
    static $a = null;
    return $a ??= db_colonne_existe('commandes', 'site_source_id');
}

/** Sites qui peuvent fournir un autre site : sites de production actifs, hors $exclu. */
function cis_sites_sources(int $exclu = 0): array {
    return db_fetch_all(
        "SELECT id, nom FROM sites
         WHERE actif = 1 AND type NOT IN ('entrepot','magasin','siege') AND id <> ?
         ORDER BY nom", [$exclu]);
}

function cis_est_source_valide(int $site_source, int $site_dest): bool {
    foreach (cis_sites_sources($site_dest) as $s) if ((int)$s['id'] === $site_source) return true;
    return false;
}

/**
 * Condition SQL : bobine (alias $a) cessible par son site — en stock ou en
 * utilisation, avec du stock, réservée par aucune commande, et absente de
 * tout point journalier pas encore validé (ses films ne sont pas encore
 * retirés : la céder fausserait le point).
 */
function cis_sql_bobine_cessible(string $a = 'b'): string {
    return "$a.statut IN ('en_stock','en_cours') AND $a.films_restants > 0
            AND " . cmdbob_sql_libre($a) . "
            AND NOT EXISTS (SELECT 1 FROM op_films_utilises fu
                            JOIN op_points_journaliers p ON p.id = fu.point_id
                            WHERE fu.bobine_id = $a.id AND p.statut <> 'valide')";
}

/** Bobines du type de la ligne que le site $site_source peut céder. */
function cis_bobines_cessibles(array $l, int $site_source): array {
    $code = cmdbob_type_code((int)$l['article_id']);
    return db_fetch_all(
        // Films réellement restants (ERP) sous le nom stock_systeme : c'est
        // la quantité que compte le choix, comme à l'entrepôt.
        "SELECT b.id, b.numero, b.films_restants AS stock_systeme, b.qte_initiale, b.statut FROM op_bobines b
         WHERE b.type_code=? AND b.site_id=? AND " . cis_sql_bobine_cessible('b') . "
         ORDER BY b.films_restants DESC, b.numero", [$code, $site_source]);
}

/**
 * Le site source peut-il servir la ligne ? Seule réponse donnée au
 * demandeur : vrai (« disponible ») ou faux (« insuffisant »).
 * $l : type_article, article_id, libelle, quantite, unite.
 */
function cis_disponible(array $l, int $site_source): bool {
    $q = max(0, (int)($l['quantite'] ?? 0));
    if (cmdbob_est_rouleau($l['type_article'] ?? '')) {
        if (empty($l['article_id'])) return false;
        $films = array_sum(array_map(fn($b) => (int)$b['stock_systeme'], cis_bobines_cessibles($l, $site_source)));
        return $films >= cmdbob_films_demandes($l);
    }
    $stock = rcp_stock($l, $site_source);
    return $stock !== null && $stock >= rcp_unites($l, $q);
}

/** Message d'erreur si la ligne ne peut pas faire partie d'une commande inter-site, sinon null. */
function cis_controle_ligne(array $l): ?string {
    $lib = trim((string)($l['libelle'] ?? '')) ?: 'une ligne';
    $t   = $l['type'] ?? $l['type_article'] ?? 'article';
    if (!in_array($t, CIS_TYPES, true))
        return "« $lib » : un transfert entre sites ne porte que sur les bobines, vignettes, rivets, PMMA et articles du catalogue.";
    if ($t === 'article') {
        if (empty($l['article_id'])) return "« $lib » : choisissez un article du catalogue.";
        if (art_categorie_equipement((int)$l['article_id']))
            return "« $lib » est un équipement : il se transfère par une affectation, pas par une commande inter-site.";
    }
    return null;
}

/** Le site est-il un dépôt (entrepôt, magasin) ? Seul un dépôt suit le stock central des articles. */
function cis_est_depot(int $site_id): bool {
    return in_array(db_fetch_value("SELECT type FROM sites WHERE id=?", [$site_id]), ['entrepot', 'magasin'], true);
}

/** Utilisateurs coordinateurs actifs d'un site. */
function cis_coordinateurs(int $site_id): array {
    return array_map('intval', array_column(db_fetch_all(
        "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
         WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [$site_id]), 'id'));
}
