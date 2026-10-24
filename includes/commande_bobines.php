<?php
// ============================================================
//  includes/commande_bobines.php
//  Bobines commandées depuis les Commandes générales (pages/commandes.php),
//  ligne « bobine » — fusion de « Commande bobines » (24/10/2026, option B) :
//   1. le coordinateur commande N bobines d'un TYPE, avec ses autres articles ;
//   2. le superviseur valide la ligne ;
//   3. le GSB choisit les bobines précises (en stock à l'entrepôt, entamées
//      ou non) — la somme de leurs quantités système doit égaler la quantité
//      demandée (cmdbob_controle) ; elles restent à l'entrepôt, réservées par
//      la ligne (commande_ligne_bobines) ;
//   4. le gestionnaire stock expédie la commande (impossible avant l'étape 3) ;
//   5. à la réception, les bobines passent au site, EN STOCK — elles passent
//      ensuite en utilisation par une demande d'utilisation.
//  Tant que sql/migration_commande_ligne_bobines.sql n'est pas passée, une
//  ligne bobine garde l'ancien fonctionnement (aucune affectation).
// ============================================================
require_once __DIR__ . '/db.php';

/** Une bobine compte 500 films à l'origine — certaines démarrent à 497, 498 ou 499. */
const CMDBOB_FILMS_BOBINE = 500;
const CMDBOB_MANQUE_INITIAL_MAX = 3;

function cmdbob_actif(): bool {
    static $actif = null;
    return $actif ??= db_colonne_existe('commande_ligne_bobines', 'bobine_id');
}

function cmdbob_entrepot_id(): int {
    return (int)(db_fetch_value("SELECT id FROM sites WHERE type='entrepot' LIMIT 1") ?? 0);
}

/** Code du type de bobine d'une ligne (commande_lignes.article_id = op_types_bobines.id). */
function cmdbob_type_code(int $article_id): ?string {
    $c = db_fetch_value("SELECT code FROM op_types_bobines WHERE id=?", [$article_id]);
    return $c !== null && $c !== false ? (string)$c : null;
}

/**
 * Condition SQL : la bobine (alias $a) n'est réservée par aucune commande en
 * cours — ni une ligne bobine des Commandes, ni une ancienne « Commande
 * bobines » expédiée et pas encore réceptionnée.
 */
function cmdbob_sql_libre(string $a = 'b'): string {
    $sql = "NOT EXISTS (SELECT 1 FROM commandes_bobines_lignes cbl
                        JOIN commandes_bobines cb ON cb.id = cbl.commande_id
                        WHERE cbl.bobine_id = $a.id AND cbl.statut = 'assigne' AND cb.statut = 'expedie')";
    if (cmdbob_actif())
        $sql .= " AND NOT EXISTS (SELECT 1 FROM commande_ligne_bobines clb
                                  WHERE clb.bobine_id = $a.id AND clb.statut = 'assigne')";
    return $sql;
}

/** Bobines réservées par ligne d'une commande : [ligne_id => [['id'=>, 'numero'=>, 'stock_systeme'=>, 'qte_initiale'=>], ...]]. */
function cmdbob_par_ligne(int $cmd_id, string $statut = 'assigne'): array {
    if (!cmdbob_actif()) return [];
    $r = [];
    foreach (db_fetch_all(
        "SELECT clb.commande_ligne_id, clb.bobine_id, clb.numero_bobine, b.stock_systeme, b.qte_initiale
         FROM commande_ligne_bobines clb
         LEFT JOIN op_bobines b ON b.id = clb.bobine_id
         WHERE clb.commande_id=? AND clb.statut=? ORDER BY clb.numero_bobine", [$cmd_id, $statut]) as $x) {
        $r[(int)$x['commande_ligne_id']][] = ['id' => (int)$x['bobine_id'], 'numero' => $x['numero_bobine'],
                                              'stock_systeme' => (int)$x['stock_systeme'],
                                              'qte_initiale'  => (int)$x['qte_initiale']];
    }
    return $r;
}

/** La ligne est commandée en bobines (unité BOBINE) ; sinon en films (unité UNITE). */
function cmdbob_en_bobines(array $l): bool {
    return strtoupper(trim((string)($l['unite'] ?? ''))) === 'BOBINE';
}

/** Films demandés par la ligne : N bobines = N × 500 films. */
function cmdbob_films_demandes(array $l): int {
    return (int)$l['quantite'] * (cmdbob_en_bobines($l) ? CMDBOB_FILMS_BOBINE : 1);
}

/**
 * Contrôle du choix du GSB : la somme des quantités système des bobines
 * choisies doit égaler les films demandés. Une bobine qui démarre à 497, 498
 * ou 499 compte pour une bobine de 500 : son manque d'origine est toléré.
 * $bobines : lignes avec stock_systeme et qte_initiale.
 * Renvoie null si le choix est bon, sinon le message à afficher.
 */
function cmdbob_controle(array $l, array $bobines): ?string {
    $cible  = cmdbob_films_demandes($l);
    $somme  = 0;
    $tolere = 0;
    foreach ($bobines as $b) {
        $somme  += (int)$b['stock_systeme'];
        $tolere += min(CMDBOB_MANQUE_INITIAL_MAX, max(0, CMDBOB_FILMS_BOBINE - (int)$b['qte_initiale']));
    }
    if ($somme > $cible)
        return "Les bobines choisies totalisent $somme films : c'est " . ($somme - $cible)
             . " de plus que les $cible films demandés.";
    if ($somme < $cible - $tolere)
        return "Les bobines choisies totalisent $somme films : il manque " . ($cible - $somme)
             . " films pour atteindre les $cible demandés.";
    return null;
}

/** Quantité livrée d'une ligne bobine, dans son unité : la commande en bobines, ou les films des bobines choisies. */
function cmdbob_qte_livree(array $l, array $bobines): int {
    if (cmdbob_en_bobines($l)) return (int)$l['quantite'];
    return array_sum(array_map(fn($b) => (int)$b['stock_systeme'], $bobines));
}

/** Nombre de lignes bobine validées d'une commande pour lesquelles le GSB n'a encore rien choisi. */
function cmdbob_lignes_a_choisir(int $cmd_id): int {
    if (!cmdbob_actif()) return 0;
    return (int)db_fetch_value(
        "SELECT COUNT(*) FROM commande_lignes cl
         WHERE cl.commande_id=? AND cl.type_article='bobine' AND cl.statut_ligne='valide'
           AND NOT EXISTS (SELECT 1 FROM commande_ligne_bobines clb
                           WHERE clb.commande_ligne_id = cl.id AND clb.statut = 'assigne')", [$cmd_id]);
}
