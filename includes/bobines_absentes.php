<?php
// ============================================================
//  includes/bobines_absentes.php — bobines absentes à l'inventaire
//
//  1. Au comptage, le coordinateur déclare « Absente » une bobine que
//     l'ERP place sur son site mais qu'il ne trouve pas (souvent : l'import
//     BI la met sur ce site). À la VALIDATION de l'inventaire — pas au clic,
//     un brouillon ne touche pas au stock — elle part à l'entrepôt principal
//     au statut 'absente' : hors de tout stock disponible (les listes de
//     l'ERP ne retiennent que en_stock / en_cours), quantité ERP inchangée.
//  2. Le coordinateur qui la retrouve l'ajoute à l'inventaire de son site
//     (bab_rechercher / bab_ajouter), puis la compte comme les autres. Sont
//     proposées les bobines absentes, puis celles d'autres sites pas encore
//     déclarées absentes : l'ordre des inventaires ne bloque rien.
//  3. À la validation, la bobine retrouvée quitte son site (entrepôt ou
//     autre) pour celui de l'inventaire, avec la quantité comptée ; statut
//     selon la règle habituelle (entamée = en utilisation, pleine = en stock).
//  4. Deux sites qui la comptent présente dans la même campagne : la
//     seconde validation n'applique pas la ligne et alerte le GSB.
//
//  Tant que sql/migration_bobines_absentes(_mysql).sql n'est pas passée,
//  rien de tout cela n'est proposé (bab_actif()).
// ============================================================
require_once __DIR__ . '/db.php';

/** Colonnes de la migration présentes ? */
function bab_actif(): bool {
    static $actif = null;
    return $actif ??= db_colonne_existe('inventaire_details_bobines', 'absente')
                   && db_colonne_existe('op_bobines', 'absente_site_id');
}

/** Entrepôt principal : le premier entrepôt créé (celui des commandes de bobines). */
function bab_entrepot_id(): int {
    return (int)(db_fetch_value("SELECT id FROM sites WHERE type='entrepot' ORDER BY id LIMIT 1") ?? 0);
}

/** Une ligne d'inventaire bobines a-t-elle été saisie (comptée ou déclarée absente) ? */
function bab_ligne_saisie(array $l): bool {
    return (int)$l['stock_physique'] > 0 || (int)$l['ecart'] !== 0 || !empty($l['absente']);
}

/** Campagne commune : même session d'inventaire, ou sans session, à 7 jours ou moins. */
function bab_meme_campagne(array $a, array $b): bool {
    if (!empty($a['session_id']) && !empty($b['session_id'])) return (int)$a['session_id'] === (int)$b['session_id'];
    return abs(strtotime((string)$a['date_inventaire']) - strtotime((string)$b['date_inventaire'])) <= 7 * 86400;
}

/**
 * Bobines qu'un site peut ajouter à son inventaire : absentes d'abord, puis
 * celles d'autres sites (y compris l'entrepôt) encore en stock / en
 * utilisation, ou déclarées perdues. Ni celles déjà dans l'inventaire, ni
 * celles réservées par une commande en cours.
 */
function bab_rechercher(array $inv, string $q, int $limite = 30, ?int $bobine_id = null): array {
    $q = trim($q);
    $params = [(int)$inv['site_id'], (int)$inv['id']];
    $filtre = '';
    if ($q !== '') { $filtre = " AND UPPER(b.numero) LIKE ?"; $params[] = '%' . strtoupper($q) . '%'; }
    if ($bobine_id !== null) { $filtre .= " AND b.id = ?"; $params[] = $bobine_id; }
    $libre = '';
    if (is_file(__DIR__ . '/commande_bobines.php')) {
        require_once __DIR__ . '/commande_bobines.php';
        $libre = ' AND ' . cmdbob_sql_libre('b');
    }
    return db_fetch_all(
        "SELECT b.id, b.numero, b.type_code, b.statut, b.absente_le,
                s.nom AS site_nom, sa.nom AS absente_site_nom
         FROM op_bobines b
         LEFT JOIN sites s  ON s.id  = b.site_id
         LEFT JOIN sites sa ON sa.id = b.absente_site_id
         WHERE b.statut IN ('absente','en_stock','en_cours','perdue')
           AND (b.site_id IS NULL OR b.site_id <> ?)
           AND NOT EXISTS (SELECT 1 FROM inventaire_details_bobines d WHERE d.inventaire_id = ? AND d.bobine_id = b.id)
           $filtre $libre
         ORDER BY CASE b.statut WHEN 'absente' THEN 0 WHEN 'perdue' THEN 2 ELSE 1 END, b.numero
         LIMIT " . (int)$limite,
        $params
    );
}

/** Ajoute une bobine retrouvée à l'inventaire (brouillon) du site. Renvoie l'id de la ligne. */
function bab_ajouter(array $inv, int $bobine_id): int {
    if (($inv['statut'] ?? '') !== 'brouillon') throw new Exception('Inventaire non modifiable.');
    if (!bab_rechercher($inv, '', 1, $bobine_id)) throw new Exception("Cette bobine ne peut pas être ajoutée : déjà dans l'inventaire, déjà sur ce site ou réservée par une commande.");
    $b = db_fetch_one("SELECT id, site_id, films_restants FROM op_bobines WHERE id=?", [$bobine_id]);
    $conso_moy = function_exists('conso_moy_bobine') ? conso_moy_bobine($bobine_id) : 0;
    $ecart_connu = (int)db_fetch_value("SELECT COALESCE(SUM(ecart),0) FROM ecarts_bobines WHERE bobine_id=? AND statut='ouvert'", [$bobine_id]);
    db_query(
        "INSERT INTO inventaire_details_bobines
         (inventaire_id,bobine_id,stock_systeme,qte_temps_reel,stock_physique,ecart,ecart_connu_avant,conso_quotidienne_moy,ajoutee,site_origine_id)
         VALUES (?,?,?,?,0,0,?,?,1,?)",
        [(int)$inv['id'], $bobine_id, (int)$b['films_restants'], (int)$b['films_restants'], $ecart_connu,
         round($conso_moy, 2), $b['site_id'] !== null ? (int)$b['site_id'] : null]
    );
    $id = (int)db_last_id();
    db_query("UPDATE inventaires_bobines SET nb_bobines = nb_bobines + 1 WHERE id=?", [(int)$inv['id']]);
    return $id;
}

/** Retire une bobine ajoutée par erreur (inventaire en brouillon). */
function bab_retirer(array $inv, int $detail_id): void {
    if (($inv['statut'] ?? '') !== 'brouillon') throw new Exception('Inventaire non modifiable.');
    $n = db_query("DELETE FROM inventaire_details_bobines WHERE id=? AND inventaire_id=? AND ajoutee=1",
                  [$detail_id, (int)$inv['id']])->rowCount();
    if (!$n) throw new Exception("Seule une bobine ajoutée à l'inventaire peut être retirée.");
    db_query("UPDATE inventaires_bobines SET nb_bobines = nb_bobines - 1 WHERE id=? AND nb_bobines > 0", [(int)$inv['id']]);
}

/** Notifie les gestionnaires de stock bobines (notify_role() n'existe pas sur toutes les branches). */
function bab_notifier_gsb(string $titre, string $message, string $lien): void {
    foreach (db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                           WHERE r.slug='gestionnaire_stock_bobines' AND u.actif=1") as $u) {
        db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                 [(int)$u['id'], 'info', $titre, $message, $lien]);
    }
}

/**
 * Un autre site a-t-il déjà compté cette bobine PRÉSENTE dans un inventaire
 * validé de la même campagne ? Renvoie le nom du site, ou null. Pour une
 * ligne d'origine ($retrouvee_seulement), seule une bobine retrouvée là-bas
 * (ligne ajoutée) fait conflit : reçue par une commande, elle a pu y être
 * comptée légitimement.
 */
function bab_comptee_ailleurs(int $bobine_id, array $inv, bool $retrouvee_seulement = false): ?string {
    foreach (db_fetch_all(
        "SELECT i.id, i.session_id, i.date_inventaire, s.nom AS site_nom
         FROM inventaire_details_bobines d
         JOIN inventaires_bobines i ON i.id = d.inventaire_id
         LEFT JOIN sites s ON s.id = i.site_id
         WHERE d.bobine_id = ? AND i.statut = 'valide' AND i.site_id <> ? AND i.id <> ?
           AND d.absente = 0 AND (d.stock_physique > 0 OR d.ecart <> 0)" . ($retrouvee_seulement ? " AND d.ajoutee = 1" : ""),
        [$bobine_id, (int)$inv['site_id'], (int)$inv['id']]) as $o) {
        if (bab_meme_campagne($o, $inv)) return (string)($o['site_nom'] ?? ('inventaire #' . $o['id']));
    }
    return null;
}

/** Validation : la bobine déclarée absente part à l'entrepôt principal, statut 'absente'. */
function bab_declarer_absente(array $l, array $inv, array $bob, int $user_id): void {
    $entrepot = bab_entrepot_id();
    if (!$entrepot) throw new Exception("Aucun entrepôt défini (Admin → Sites) : impossible d'y renvoyer une bobine absente.");
    $site_nom = (string)(db_fetch_value("SELECT nom FROM sites WHERE id=?", [(int)$inv['site_id']]) ?? '');
    db_query(
        "UPDATE op_bobines SET site_id=?, statut='absente', absente_site_id=?, absente_le=?, absente_inventaire_id=? WHERE id=?",
        [$entrepot, (int)$inv['site_id'], $inv['date_inventaire'], (int)$inv['id'], (int)$l['bobine_id']]
    );
    $fr = (int)$bob['films_restants'];
    db_query(
        "INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,ref_id,created_by)
         VALUES (?,?,?,?,?,?,?,?)",
        [(int)$l['bobine_id'], 'transfert', 0, $fr, $fr,
         "Déclarée absente — inventaire #{$inv['id']} ($site_nom) : renvoyée à l'entrepôt principal", (int)$inv['id'], $user_id]
    );
}

/** Validation : la bobine retrouvée passe au site de l'inventaire (après l'ajustement de quantité). */
function bab_affecter_retrouvee(array $l, array $inv, array $bob, int $nouveau, int $user_id): void {
    $site_nom = (string)(db_fetch_value("SELECT nom FROM sites WHERE id=?", [(int)$inv['site_id']]) ?? '');
    $origine  = $bob['statut'] === 'absente' ? 'absente' : ('venant de ' . ($bob['site_nom'] ?? 'site inconnu'));
    db_query(
        "UPDATE op_bobines SET site_id=?, absente_site_id=NULL, absente_le=NULL, absente_inventaire_id=NULL WHERE id=?",
        [(int)$inv['site_id'], (int)$l['bobine_id']]
    );
    db_query(
        "INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,ref_id,created_by)
         VALUES (?,?,?,?,?,?,?,?)",
        [(int)$l['bobine_id'], 'transfert', 0, $nouveau, $nouveau,
         "Retrouvée à l'inventaire #{$inv['id']} ($site_nom) — $origine", (int)$inv['id'], $user_id]
    );
}

/** Bobines absentes (page Bobines, onglet Absentes). */
function bab_lister(int $site_absence = 0): array {
    if (!bab_actif()) return [];
    $p = [];
    $w = '';
    if ($site_absence) { $w = ' AND b.absente_site_id=?'; $p[] = $site_absence; }
    return db_fetch_all(
        "SELECT b.*, sa.nom AS absente_site_nom, s.nom AS site_nom
         FROM op_bobines b
         LEFT JOIN sites sa ON sa.id = b.absente_site_id
         LEFT JOIN sites s  ON s.id  = b.site_id
         WHERE b.statut='absente' $w
         ORDER BY b.absente_le, b.numero", $p);
}
