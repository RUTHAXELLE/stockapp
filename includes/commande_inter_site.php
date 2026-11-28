<?php
// ============================================================
//  includes/commande_inter_site.php
//  Sorties diverses (sql/migration_commandes_inter_site.sql) : un site A est
//  servi par un autre site de production B plutôt que par un dépôt.
//   1. A commande normalement ; le superviseur valide ;
//   2. le gestionnaire de stock, ligne par ligne, fait sortir tout ou partie
//      de la quantité chez un ou plusieurs sites : chaque site reçoit une
//      commande liée (commande_origine_id, ligne_origine_id) ; le reste part
//      du dépôt comme d'habitude ;
//   3. B accepte (ou refuse, la quantité revient alors à la commande
//      d'origine), puis son coordinateur (ou le GSB) choisit les bobines,
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
    // Les colonnes de la sortie diverse arrivent avec la même migration.
    return $a ??= db_colonne_existe('commandes', 'commande_origine_id')
               && db_colonne_existe('commande_lignes', 'ligne_origine_id');
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

/**
 * Inter-site, une ligne de rouleaux se compte en films (ou vignettes) : le
 * site fournisseur cède souvent des rouleaux entamés, et « 2 bobines » livrées
 * ne diraient pas qu'il n'en est parti que 650 films. Une ligne commandée en
 * BOBINE est donc convertie : N × contenance du type, unité UNITE.
 * $l : type ou type_article, article_id, quantite, unite. Renvoie la ligne convertie.
 */
function cis_ligne_en_films(array $l): array {
    $t = $l['type'] ?? $l['type_article'] ?? '';
    if (cmdbob_est_rouleau($t) && cmdbob_en_bobines($l)) {
        $l['quantite'] = cmdbob_films_demandes($l);
        $l['unite']    = 'UNITE';
    }
    return $l;
}

/**
 * Quantité d'une ligne enfant (en films si rouleaux) ramenée dans l'unité de
 * la ligne d'origine : une ligne commandée en BOBINE a été convertie en films.
 */
function cis_qte_vers_origine(array $origine, int $qte_enfant): int {
    if (cmdbob_est_rouleau($origine['type_article'] ?? '') && cmdbob_en_bobines($origine)) {
        $fb = max(1, cmdbob_films_bobine($origine));
        return intdiv($qte_enfant, $fb);
    }
    return $qte_enfant;
}

/** Stock de la ligne chez un site, pour le gestionnaire : films restants cessibles, ou stock suivi. */
function cis_stock_site(array $l, int $site_id): int {
    if (cmdbob_est_rouleau($l['type_article'] ?? '')) {
        if (empty($l['article_id'])) return 0;
        return array_sum(array_map(fn($b) => (int)$b['stock_systeme'], cis_bobines_cessibles($l, $site_id)));
    }
    return (int)(rcp_stock($l, $site_id) ?? 0);
}

/** Sorties diverses issues d'une ligne : numéro, site, quantité, état. */
function cis_sorties_ligne(int $ligne_id): array {
    if (!cis_actif()) return [];
    $r = [];
    foreach (db_fetch_all(
        "SELECT c.id, c.numero_commande, c.statut, c.accepte_at, c.site_source_id, s.nom AS site_nom,
                cl.quantite, cl.unite, cl.quantite_livree, cl.type_article
         FROM commande_lignes cl
         JOIN commandes c ON c.id = cl.commande_id
         LEFT JOIN sites s ON s.id = c.site_source_id
         WHERE cl.ligne_origine_id = ? ORDER BY c.id", [$ligne_id]) as $x) {
        // Commande de reprise (sortie diverse refusée après l'expédition) :
        // elle part du dépôt, sans acceptation.
        $reprise = empty($x['site_source_id']);
        $etat = match (true) {
            $reprise && $x['statut'] === 'en_attente_livraison' => 'à expédier',
            $x['statut'] === 'rejete' => 'refusée',
            $x['statut'] === 'annule' => 'annulée',
            $x['statut'] === 'en_attente_livraison' && empty($x['accepte_at']) => 'à accepter',
            $x['statut'] === 'en_attente_livraison' => 'acceptée',
            $x['statut'] === 'en_cours_livraison' => 'enlevée',
            in_array($x['statut'], ['recu', 'livre'], true) => 'reçue',
            default => $x['statut'],
        };
        $unite = cmdbob_est_rouleau($x['type_article']) && strtoupper((string)$x['unite']) === 'UNITE'
            ? ($x['type_article'] === 'vignette' ? 'vignettes' : 'films') : $x['unite'];
        $r[] = ['commande_id' => (int)$x['id'], 'numero' => $x['numero_commande'],
                'site' => $reprise ? 'le dépôt' : $x['site_nom'],
                'quantite' => (int)$x['quantite'], 'unite' => $unite, 'etat' => $etat, 'reprise' => $reprise];
    }
    return $r;
}

/** Libellé court des sorties diverses d'une ligne (traçabilité dans le détail et le BL). */
function cis_libelle_sorties(array $sorties): string {
    return implode(' ; ', array_map(fn($s) => "{$s['quantite']} {$s['unite']} " . ($s['reprise'] ? 'repris par ' : 'chez ')
        . "{$s['site']} ({$s['numero']}, {$s['etat']})", $sorties));
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
