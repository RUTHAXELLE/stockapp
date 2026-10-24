<?php
// ============================================================
//  includes/commande_bobines.php
//  Bobines commandées depuis les Commandes générales (pages/commandes.php),
//  ligne « bobine » — fusion de « Commande bobines » (24/10/2026, option B) :
//   1. le coordinateur commande N bobines d'un TYPE, avec ses autres articles ;
//   2. le superviseur valide la ligne ;
//   3. le GSB choisit les bobines précises (en stock à l'entrepôt) — elles
//      restent à l'entrepôt, réservées par la ligne (commande_ligne_bobines) ;
//   4. le gestionnaire stock expédie la commande (impossible avant l'étape 3) ;
//   5. à la réception, les bobines passent au site, EN STOCK — elles passent
//      ensuite en utilisation par une demande d'utilisation.
//  Tant que sql/migration_commande_ligne_bobines.sql n'est pas passée, une
//  ligne bobine garde l'ancien fonctionnement (aucune affectation).
// ============================================================
require_once __DIR__ . '/db.php';

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

/** Bobines réservées par ligne d'une commande : [ligne_id => [['id'=>, 'numero'=>], ...]]. */
function cmdbob_par_ligne(int $cmd_id, string $statut = 'assigne'): array {
    if (!cmdbob_actif()) return [];
    $r = [];
    foreach (db_fetch_all(
        "SELECT clb.commande_ligne_id, clb.bobine_id, clb.numero_bobine
         FROM commande_ligne_bobines clb
         WHERE clb.commande_id=? AND clb.statut=? ORDER BY clb.numero_bobine", [$cmd_id, $statut]) as $x) {
        $r[(int)$x['commande_ligne_id']][] = ['id' => (int)$x['bobine_id'], 'numero' => $x['numero_bobine']];
    }
    return $r;
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
