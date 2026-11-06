<?php
// ============================================================
//  includes/reception_commande.php — mouvements de stock des commandes,
//  réception ligne par ligne et traitement des écarts de réception
// ============================================================
//  À la réception (pages/commandes.php, action 'receptionner'), le
//  coordinateur déclare pour chaque ligne ce qu'il a réellement reçu :
//  la totalité, une partie (quantité) ou rien ; pour une ligne bobine ou
//  vignette, il coche les rouleaux arrivés. Le site n'est crédité que du
//  reçu. Chaque manque ouvre un écart (commande_ecarts_reception) que le
//  gestionnaire de stock — le GSB pour les rouleaux — tranche :
//    - restitue : la quantité revient au dépôt d'où elle était sortie
//                 (pour un rouleau : il a été retrouvé au dépôt) ;
//    - renvoye  : la quantité revient au dépôt, puis une nouvelle commande
//                 « À préparer » reprend les manquants. Un rouleau absent,
//                 lui, passe « Perdue » : le renvoi en fait choisir un autre ;
//    - perte    : rien ne revient ; un rouleau passe « Perdue ».
//  Sans la migration (sql/migration_reception_par_ligne*.sql), la
//  réception reste celle d'avant : tout le livré est crédité.
//
//  Les mouvements de stock d'une ligne (débit du dépôt à l'expédition,
//  crédit du site à la réception, remise au dépôt) passent tous par
//  rcp_stock() / rcp_mouvement() : une seule règle par type d'article.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/referentiels.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/commande_bobines.php';
require_once __DIR__ . '/achats.php';

const RCP_DECISIONS = ['restitue' => 'Remis en stock au dépôt', 'renvoye' => 'Renvoyé (nouvelle commande)', 'perte' => 'Passé en perte'];

function rcp_disponible(): bool {
    static $ok = null;
    return $ok ??= db_colonne_existe('commande_lignes', 'quantite_recue')
                && db_colonne_existe('commande_ecarts_reception', 'id');
}

// ── Mouvements de stock d'une ligne de commande ────────────────

/** Rivet gonflable ou éclaté d'après le libellé de la ligne (accents ignorés). */
function rcp_type_rivet(string $libelle): string {
    $n = strtolower(str_replace(['é','è','ê','ë','É','È','Ê','Ë'], 'e', $libelle));
    return str_contains($n, 'eclat') ? 'eclate' : 'gonflable';
}

/** Type de PMMA du catalogue d'après le libellé (à défaut, le libellé sans « PMMA »). */
function rcp_type_pmma(string $libelle): string {
    return resoudre_type_pmma($libelle) ?? trim(preg_replace('/^PMMA\s*/i', '', $libelle));
}

/**
 * Où vit le stock d'une ligne : null si la ligne n'a pas de stock suivi
 * (bobine, équipement, article en saisie libre, PMMA sans type).
 * $l : ligne de commande_lignes (type_article, article_id, libelle, unite).
 */
function rcp_cle_stock(array $l): ?array {
    switch ($l['type_article'] ?? '') {
        case 'article': return !empty($l['article_id']) ? ['article', (int)$l['article_id']] : null;
        case 'rivet':   return ['rivet', rcp_type_rivet((string)$l['libelle'])];
        case 'pmma':    $t = rcp_type_pmma((string)$l['libelle']); return $t !== '' ? ['pmma', $t] : null;
    }
    return null;
}

/** Quantité de la ligne dans l'unité du stock (une ligne PMMA en cartons se compte en unités). */
function rcp_unites(array $l, int $q): int {
    $c = rcp_cle_stock($l);
    return $c && $c[0] === 'pmma' ? pmma_qte_en_unites($c[1], $l['unite'] ?? null, $q) : $q;
}

/** Stock actuel de la ligne sur un site, dans l'unité du stock ; null si non suivi. */
function rcp_stock(array $l, int $site_id, bool $verrou = false): ?int {
    $c = rcp_cle_stock($l);
    if (!$c) return null;
    $fu = $verrou ? ' FOR UPDATE' : '';
    $sql = ['article' => "SELECT quantite FROM stock_site WHERE article_id=? AND site_id=?$fu",
            'rivet'   => "SELECT quantite FROM op_stock_rivets WHERE type_rivet=? AND site_id=?$fu",
            'pmma'    => "SELECT quantite FROM stock_pmma_site WHERE type_pmma=? AND site_id=?$fu"][$c[0]];
    return (int)(db_fetch_value($sql, [$c[1], $site_id]) ?? 0);
}

/**
 * Ajoute (q > 0) ou retire (q < 0) q, dans l'unité de la ligne, au stock
 * d'un site. $global : un article suit aussi articles.stock_global, le
 * stock des dépôts (débité à l'expédition, rendu à la remise en stock,
 * inchangé à la réception sur site).
 */
function rcp_mouvement(array $l, int $site_id, int $q, bool $global = false): void {
    $c = rcp_cle_stock($l);
    if (!$c || $q === 0) return;
    $u = rcp_unites($l, abs($q)) * ($q < 0 ? -1 : 1);
    if ($u < 0) {
        // Retrait : UPDATE, pas l'UPSERT — les quantités sont UNSIGNED et
        // MySQL refuse une valeur négative dans VALUES même quand la ligne
        // existe. L'appelant a vérifié le stock (rcp_stock()).
        $sql = ['article' => "UPDATE stock_site SET quantite = quantite - ? WHERE article_id=? AND site_id=?",
                'rivet'   => "UPDATE op_stock_rivets SET quantite = quantite - ? WHERE type_rivet=? AND site_id=?",
                'pmma'    => "UPDATE stock_pmma_site SET quantite = quantite - ? WHERE type_pmma=? AND site_id=?"][$c[0]];
        db_query($sql, [-$u, $c[1], $site_id]);
        if ($global && $c[0] === 'article')
            db_query("UPDATE articles SET stock_global = GREATEST(0, stock_global - ?) WHERE id=?", [-$u, $c[1]]);
        return;
    }
    if ($c[0] === 'article') {
        db_query("INSERT INTO stock_site (article_id,site_id,quantite) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)", [$c[1], $site_id, $u]);
        if ($global)
            db_query("UPDATE articles SET stock_global = GREATEST(0, stock_global + ?) WHERE id=?", [$u, $c[1]]);
    } elseif ($c[0] === 'rivet') {
        db_query("INSERT INTO op_stock_rivets (site_id,type_rivet,quantite) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)", [$site_id, $c[1], $u]);
    } else {
        db_query("INSERT INTO stock_pmma_site (site_id,type_pmma,quantite) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)", [$site_id, $c[1], $u]);
    }
}

// ── Libellé du statut d'une ligne ──────────────────────────────

/**
 * Statut affiché d'une ligne de commande, [libellé, couleur], pour le bon
 * PDF comme pour la fenêtre de détail. Une ligne validée n'est « livrée »
 * que si elle part (ou est arrivée) en totalité : livrée à 0 → « Non
 * livré », livrée en partie → « Livré partiellement » ; à la réception, un
 * manque donne « Non reçu » / « Reçu partiel ».
 */
function rcp_statut_ligne(string $cmd_statut, array $l): array {
    $sl = $l['statut_ligne'] ?? '';
    $fixes = ['rejete' => ['Rejeté', '#dc2626'], 'modifie' => ['Modifié', '#d97706'],
              'modification_demandee' => ['À corriger', '#5b21b6']];
    if (isset($fixes[$sl])) return $fixes[$sl];
    if ($sl !== 'valide') return ['', '#555'];

    $dem  = (int)($l['quantite'] ?? 0);
    $liv  = ($l['quantite_livree'] ?? null) !== null ? (int)$l['quantite_livree'] : null;
    $rec  = ($l['quantite_recue'] ?? null) !== null ? (int)$l['quantite_recue'] : null;
    $part = in_array($cmd_statut, ['en_cours_livraison', 'recu', 'livre'], true);
    if (!$part || $liv === null) return ['Validé', '#15803d'];
    if ($liv === 0)              return ['Non livré', '#dc2626'];
    if ($rec !== null && $rec < $liv) return [$rec === 0 ? 'Non reçu' : 'Reçu partiel', '#b45309'];
    if ($liv < $dem)             return ['Livré partiellement', '#d97706'];
    return $cmd_statut === 'en_cours_livraison' ? ['En livraison', '#1d4ed8'] : ['Livré', '#15803d'];
}

// ── Réception ligne par ligne ──────────────────────────────────

/**
 * Lignes à déclarer à la réception, avec ce qui a été expédié :
 * [['id','libelle','type_article','unite','livre','bobines'=>[...]], ...].
 */
function rcp_lignes_a_recevoir(int $cmd_id): array {
    $bob = cmdbob_par_ligne($cmd_id);
    // Films de chaque rouleau au moment de l'expédition, figés par
    // emettre_livraison (commande_ligne_bobines.films_expedies) : c'est sur
    // eux que le livré a été calculé ; le stock_systeme actuel a pu bouger
    // depuis (import OptoTrace). À défaut (expédiée avant la migration), le
    // stock_systeme actuel.
    $exp = $bob && db_colonne_existe('commande_ligne_bobines', 'films_expedies')
        ? array_column(db_fetch_all("SELECT bobine_id, films_expedies FROM commande_ligne_bobines
                                     WHERE commande_id=? AND statut='assigne'", [$cmd_id]), 'films_expedies', 'bobine_id')
        : [];
    foreach ($bob as &$_rouleaux) {
        foreach ($_rouleaux as &$_b)
            $_b['films_expedies'] = isset($exp[$_b['id']]) ? (int)$exp[$_b['id']] : (int)$_b['stock_systeme'];
        unset($_b);
    }
    unset($_rouleaux);
    $out = [];
    foreach (db_fetch_all("SELECT * FROM commande_lignes WHERE commande_id=? ORDER BY id", [$cmd_id]) as $l) {
        $lid = (int)$l['id'];
        if (cmdbob_est_rouleau($l['type_article'])) {
            if (empty($bob[$lid])) continue;
            $out[] = ['id' => $lid, 'libelle' => $l['libelle'], 'type_article' => $l['type_article'],
                      'unite' => $l['unite'], 'livre' => (int)$l['quantite_livree'], 'bobines' => $bob[$lid]];
        } elseif ((int)$l['quantite_livree'] > 0) {
            $out[] = ['id' => $lid, 'libelle' => $l['libelle'], 'type_article' => $l['type_article'],
                      'unite' => $l['unite'], 'livre' => (int)$l['quantite_livree'], 'bobines' => []];
        }
    }
    return $out;
}

/**
 * Contrôle la déclaration du coordinateur, sans rien écrire.
 * $saisie : [ligne_id => ['quantite_recue' => int, 'motif' => str, 'bobines' => [ids reçus]]].
 * Chaque ligne à recevoir doit y figurer : une saisie absente (écran pas
 * encore chargé, appel qui a échoué) ne vaut pas « tout reçu ».
 * Renvoie [ligne_id => ['recu' => int, 'motif' => ?str, 'bobines_manquantes' => [...], 'manque' => int]].
 * Lève une Exception au premier problème.
 */
function rcp_preparer(int $cmd_id, array $saisie): array {
    $plan = [];
    foreach (rcp_lignes_a_recevoir($cmd_id) as $l) {
        $lid   = $l['id'];
        $s     = $saisie[$lid] ?? null;
        if ($s === null)
            throw new Exception("Réception de « {$l['libelle']} » non renseignée : rechargez la page et recommencez.");
        $motif = trim((string)($s['motif'] ?? ''));
        if ($l['bobines']) {
            $recus = array_map('intval', (array)($s['bobines'] ?? []));
            $manq  = array_values(array_filter($l['bobines'], fn($b) => !in_array((int)$b['id'], $recus, true)));
            if ($manq && $motif === '')
                throw new Exception("Motif obligatoire pour « {$l['libelle']} » : " . count($manq) . " rouleau(x) non reçu(s).");
            // Le reçu se compte dans l'unité de la ligne, à partir du livré :
            // un rouleau manquant retire une bobine (ligne en bobines) ou ses
            // films (ligne en films). Compter les rouleaux cochés donnait plus
            // que le livré quand le GSB avait servi des rouleaux entamés.
            $en_bob = cmdbob_en_bobines($l);
            $moins  = $en_bob ? count($manq) : array_sum(array_column($manq, 'films_expedies'));
            $recu   = max(0, $l['livre'] - $moins);
            // Part du manque portée par chaque rouleau absent, plafonnée au
            // manque de la ligne : des rouleaux entamés servis en surnombre ne
            // font pas renvoyer plus que la commande.
            $reste = $l['livre'] - $recu;
            foreach ($manq as &$_m) {
                $_m['quantite_ecart'] = min($en_bob ? 1 : (int)$_m['films_expedies'], $reste);
                $reste -= $_m['quantite_ecart'];
            }
            unset($_m);
            $plan[$lid] = ['recu' => $recu, 'motif' => $manq ? $motif : null, 'bobines_manquantes' => $manq,
                           'manque' => $l['livre'] - $recu, 'en_bobines' => $en_bob];
            continue;
        }
        $recu = $s['quantite_recue'] ?? null;
        if (!is_numeric($recu) || (int)$recu != $recu || (int)$recu < 0 || (int)$recu > $l['livre'])
            throw new Exception("Quantité reçue invalide pour « {$l['libelle']} » : entre 0 et {$l['livre']}.");
        $recu = (int)$recu;
        if ($recu < $l['livre'] && $motif === '')
            throw new Exception("Motif obligatoire pour « {$l['libelle']} » : reçu $recu sur {$l['livre']}.");
        $plan[$lid] = ['recu' => $recu, 'motif' => $recu < $l['livre'] ? $motif : null,
                       'bobines_manquantes' => [], 'manque' => $l['livre'] - $recu];
    }
    return $plan;
}

/**
 * Enregistre la déclaration (dans la transaction de l'appelant) : quantité
 * reçue par ligne, rouleaux manquants mis de côté (statut 'non_recu' : ils ne
 * passent pas au site et restent réservés tant que l'écart est ouvert), un
 * écart par manque. Renvoie le nombre d'écarts ouverts.
 */
function rcp_appliquer(int $cmd_id, array $plan, int $user_id): int {
    $n = 0;
    foreach ($plan as $lid => $p) {
        db_query("UPDATE commande_lignes SET quantite_recue=?, motif_reception=? WHERE id=? AND commande_id=?",
            [$p['recu'], $p['motif'], $lid, $cmd_id]);
        db_query("UPDATE distribution_lignes SET quantite_recue=? WHERE commande_ligne_id=?", [$p['recu'], $lid]);
        if ($p['bobines_manquantes']) {
            foreach ($p['bobines_manquantes'] as $b) {
                db_query("UPDATE commande_ligne_bobines SET statut='non_recu'
                          WHERE commande_id=? AND commande_ligne_id=? AND bobine_id=? AND statut='assigne'",
                    [$cmd_id, $lid, (int)$b['id']]);
                db_query("INSERT INTO commande_ecarts_reception (commande_id,commande_ligne_id,bobine_id,quantite,motif,declare_par)
                          VALUES (?,?,?,?,?,?)",
                    [$cmd_id, $lid, (int)$b['id'], (int)$b['quantite_ecart'], $p['motif'], $user_id]);
                $n++;
            }
        } elseif ($p['manque'] > 0) {
            db_query("INSERT INTO commande_ecarts_reception (commande_id,commande_ligne_id,quantite,motif,declare_par)
                      VALUES (?,?,?,?,?)", [$cmd_id, $lid, $p['manque'], $p['motif'], $user_id]);
            $n++;
        }
    }
    return $n;
}

/** Écarts d'une commande, avec la ligne et le rouleau concernés. */
function rcp_ecarts(int $cmd_id): array {
    if (!rcp_disponible()) return [];
    return db_fetch_all(
        "SELECT e.*, cl.libelle, cl.type_article, cl.article_id, cl.unite, cl.depot_source_id, ds.nom AS depot_nom,
                b.numero AS bobine_numero, cr.numero_commande AS renvoi_numero,
                CONCAT(ut.prenom,' ',ut.nom) AS traite_par_nom
         FROM commande_ecarts_reception e
         JOIN commande_lignes cl ON cl.id = e.commande_ligne_id
         LEFT JOIN sites ds ON ds.id = cl.depot_source_id
         LEFT JOIN op_bobines b ON b.id = e.bobine_id
         LEFT JOIN commandes cr ON cr.id = e.commande_renvoi_id
         LEFT JOIN users ut ON ut.id = e.traite_par
         WHERE e.commande_id=? ORDER BY e.id", [$cmd_id]);
}

/** Nombre d'écarts ouverts par commande : [commande_id => n]. */
function rcp_nb_ouverts(array $cmd_ids): array {
    if (!rcp_disponible() || !$cmd_ids) return [];
    $ids = implode(',', array_map('intval', $cmd_ids));
    return array_column(db_fetch_all(
        "SELECT commande_id, COUNT(*) AS n FROM commande_ecarts_reception
         WHERE statut='ouvert' AND commande_id IN ($ids) GROUP BY commande_id"), 'n', 'commande_id');
}

/**
 * Notifie les utilisateurs actifs d'un rôle : notify_role() là où elle existe
 * (elle couvre aussi les Support IT au sous-rôle équivalent), sinon le rôle seul.
 */
function rcp_notifier_role(string $role, string $titre, string $msg, string $lien): void {
    if (function_exists('notify_role')) { notify_role($role, $titre, $msg, $lien); return; }
    foreach (db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug=? AND u.actif=1", [$role]) as $u)
        notif_create('info', $titre, $msg, (int)$u['id'], $lien);
}

/** Gestionnaires à prévenir d'un écart ; le GSB en plus si des rouleaux manquent. */
function rcp_notifier_ecarts(array $cmd, int $nb, bool $avec_rouleaux): void {
    $site  = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$cmd['site_id']]) ?? '';
    $titre = "⚠️ Écart de réception — {$cmd['numero_commande']}";
    $msg   = "$site a réceptionné la commande avec $nb manque(s) : à remettre en stock, renvoyer ou passer en perte.";
    $roles = ['gestionnaire_stock', 'admin', 'superadmin'];
    if ($avec_rouleaux) $roles[] = 'gestionnaire_stock_bobines';
    foreach ($roles as $r) rcp_notifier_role($r, $titre, $msg, '/pages/commandes.php');
}

/**
 * L'écart peut-il être « remis en stock » ? Un rouleau, oui (retrouvé au
 * dépôt) ; une ligne, seulement si elle a un stock suivi et un dépôt
 * d'origine — sinon la décision annoncerait une remise qui n'a pas lieu.
 */
function rcp_restituable(array $e): bool {
    return !empty($e['bobine_id']) || (!empty($e['depot_source_id']) && rcp_cle_stock($e) !== null);
}

/**
 * Un rouleau non reçu, qui ne réapparaîtra pas : « Perdue », réservation
 * close, et le même mouvement 'perte' que la déclaration de perte de
 * pages/operations/bobines.php, pour que les pertes se comptent pareil.
 */
function rcp_rouleau_perdu(array $e, string $note, int $user_id): void {
    db_query("UPDATE commande_ligne_bobines SET statut='perdu'
              WHERE commande_id=? AND bobine_id=? AND statut='non_recu'", [$e['commande_id'], $e['bobine_id']]);
    db_query("UPDATE op_bobines SET statut='perdue', notes_perte=? WHERE id=?", [$note, $e['bobine_id']]);
    $films = (int)db_fetch_value("SELECT films_restants FROM op_bobines WHERE id=?", [$e['bobine_id']]);
    db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
              VALUES (?,?,?,?,0,?,?)", [$e['bobine_id'], 'perte', $films, $films, "PERTE : $note", $user_id]);
}

/**
 * Perte en transport d'une ligne (rivets, PMMA, article) : le dépôt a été
 * débité à l'expédition et le site n'a rien reçu, le stock est donc juste ;
 * la perte est tracée là où se lisent les pertes et écarts de chaque
 * matière — écarts rivets / PMMA (source 'reception', résolus), mouvement
 * 'perte' des articles.
 */
function rcp_tracer_perte(array $e, array $cmd, string $notes, int $user_id): void {
    $c = rcp_cle_stock($e);
    $q = (int)$e['quantite'];
    if (!$c || $q <= 0) return;
    $motif = "Perte en transport (commande {$cmd['numero_commande']})" . ($notes !== '' ? " : $notes" : '');
    if ($c[0] === 'rivet' || $c[0] === 'pmma') {
        $u = rcp_unites($e, $q);
        $t = $c[0] === 'rivet' ? ['ecarts_rivets', 'type_rivet'] : ['ecarts_pmma', 'type_pmma'];
        db_query("INSERT INTO {$t[0]} (site_id,{$t[1]},date_constat,stock_systeme,stock_physique,ecart,motif,source,statut,resolu_at,resolu_par,created_by)
                  VALUES (?,?,CURRENT_DATE,?,0,?,?,'reception','resolu',NOW(),?,?)",
            [$cmd['site_id'], $c[1], $u, -$u, $motif, $user_id, $user_id]);
    } elseif (db_colonne_existe('mouvements_stock', 'type_mouvement')) {
        db_query("INSERT INTO mouvements_stock (article_id,site_id,type_mouvement,quantite,solde_apres,reference,notes,created_by)
                  VALUES (?,?,'perte',?,?,?,?,?)",
            [$c[1], $cmd['site_id'], $q, rcp_stock($e, (int)$cmd['site_id']) ?? 0, $cmd['numero_commande'], $motif, $user_id]);
    }
}

/** Remet au dépôt d'origine la quantité d'un écart (transaction de l'appelant). */
function rcp_remettre_en_stock(array $e, int $user_id, string $cmd_numero): void {
    if ($e['bobine_id']) {
        db_query("UPDATE commande_ligne_bobines SET statut='restitue'
                  WHERE commande_id=? AND bobine_id=? AND statut='non_recu'", [$e['commande_id'], $e['bobine_id']]);
        $st = (int)db_fetch_value("SELECT stock_systeme FROM op_bobines WHERE id=?", [$e['bobine_id']]);
        db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                  VALUES (?,?,?,?,?,?,?)",
            [$e['bobine_id'], 'transfert', 0, $st, $st, "Non reçue sur site (commande $cmd_numero) : remise en stock", $user_id]);
        return;
    }
    $dep = (int)($e['depot_source_id'] ?? 0);
    if (!$dep || (int)$e['quantite'] <= 0) return;
    $l = db_fetch_one("SELECT * FROM commande_lignes WHERE id=?", [$e['commande_ligne_id']]);
    if ($l) rcp_mouvement($l, $dep, (int)$e['quantite'], true);
}

/**
 * Tranche les écarts ouverts d'une commande. $decisions : [ecart_id => 'restitue'|'renvoye'|'perte'].
 * $peut_articles : gestionnaire de stock ; $peut_rouleaux : GSB. Les écarts
 * non cités restent ouverts. Renvoie ['nb' => n, 'renvoi' => numéro ou null, 'resume' => texte].
 */
function rcp_traiter(int $cmd_id, array $decisions, string $notes, array $user, bool $peut_articles, bool $peut_rouleaux): array {
    if (!rcp_disponible()) throw new Exception('Migration reception_par_ligne non jouée sur cette base.');
    $cmd = db_fetch_one("SELECT * FROM commandes WHERE id=?", [$cmd_id]);
    if (!$cmd) throw new Exception('Commande introuvable.');
    $notes   = trim($notes);
    $ecarts  = array_column(rcp_ecarts($cmd_id), null, 'id');
    $a_faire = [];
    foreach ($decisions as $eid => $dec) {
        if ($dec === '' || $dec === null) continue;
        $e = $ecarts[(int)$eid] ?? null;
        if (!$e || $e['statut'] !== 'ouvert') throw new Exception("Écart #$eid introuvable ou déjà traité.");
        if (!isset(RCP_DECISIONS[$dec])) throw new Exception("Décision inconnue pour « {$e['libelle']} ».");
        $rouleau = (bool)$e['bobine_id'];
        if ($rouleau && !$peut_rouleaux) throw new Exception("Les rouleaux non reçus sont traités par le GSB (« {$e['libelle']} »).");
        if (!$rouleau && !$peut_articles) throw new Exception("Cet écart est traité par le gestionnaire de stock (« {$e['libelle']} »).");
        if ($dec === 'perte' && $notes === '') throw new Exception('Précisez la raison de la perte dans le commentaire.');
        if ($dec === 'restitue' && !rcp_restituable($e))
            throw new Exception("« {$e['libelle']} » n'a pas de stock suivi au dépôt : rien à y remettre. Choisissez Renvoyer ou Perte.");
        $a_faire[(int)$eid] = $dec;
    }
    if (!$a_faire) throw new Exception('Choisissez une décision pour au moins un écart.');

    $renvoi_num = null;
    $renvoi_id  = null;
    db_begin();
    try {
        $renvois = [];   // commande_ligne_id => quantité à renvoyer
        foreach ($a_faire as $eid => $dec) {
            // L'écart passe d'abord « traité », sous condition qu'il soit
            // encore ouvert : de deux traitements simultanés (double clic,
            // deux gestionnaires), un seul bouge le stock.
            $maj = db_query("UPDATE commande_ecarts_reception SET statut=?, traite_par=?, traite_at=NOW(), notes_traitement=?
                             WHERE id=? AND statut='ouvert'", [$dec, $user['id'], $notes ?: null, $eid]);
            if ($maj->rowCount() !== 1) throw new Exception('Cet écart vient d\'être traité par quelqu\'un d\'autre : rechargez la page.');
            $e = $ecarts[$eid];
            if ($e['bobine_id'] && $dec !== 'restitue') {
                // Rouleau absent : perdu, qu'on le renvoie (un autre rouleau
                // sera choisi) ou non. Le remettre disponible laisserait
                // rechoisir un rouleau introuvable.
                rcp_rouleau_perdu($e, "Non reçue sur site (commande {$cmd['numero_commande']})"
                    . ($dec === 'renvoye' ? ' : remplacée par une commande de renvoi' : '') . ($notes !== '' ? " — $notes" : ''),
                    (int)$user['id']);
            } elseif ($dec === 'perte') {
                rcp_tracer_perte($e, $cmd, $notes, (int)$user['id']);
            } else {
                rcp_remettre_en_stock($e, (int)$user['id'], $cmd['numero_commande']);
            }
            if ($dec === 'renvoye')
                $renvois[(int)$e['commande_ligne_id']] = ($renvois[(int)$e['commande_ligne_id']] ?? 0) + (int)$e['quantite'];
        }

        $renvois = array_filter($renvois, fn($q) => $q > 0);
        if ($renvois) {
            $renvoi_num = ach_numero_commande();
            db_query("INSERT INTO commandes (numero_commande,site_id,statut,notes,created_by,valide_par,valide_at)
                      VALUES (?,?,'en_attente_livraison',?,?,?,NOW())",
                [$renvoi_num, $cmd['site_id'], "Renvoi des manquants de la commande {$cmd['numero_commande']}",
                 $cmd['created_by'], $user['id']]);
            $renvoi_id = (int)db_last_id();
            foreach ($renvois as $lid => $q) {
                $l = db_fetch_one("SELECT * FROM commande_lignes WHERE id=?", [$lid]);
                db_query("INSERT INTO commande_lignes (commande_id,type_article,article_id,libelle,quantite,unite,prix_unitaire,statut_ligne)
                          VALUES (?,?,?,?,?,?,?,'valide')",
                    [$renvoi_id, $l['type_article'], $l['article_id'], $l['libelle'], $q, $l['unite'], $l['prix_unitaire'] ?? 0]);
            }
            $ids = implode(',', array_map('intval', array_keys(array_filter($a_faire, fn($d) => $d === 'renvoye'))));
            db_query("UPDATE commande_ecarts_reception SET commande_renvoi_id=? WHERE id IN ($ids)", [$renvoi_id]);
            audit_log($user['id'], 'CREATE', 'commandes', $renvoi_id, "Commande $renvoi_num : renvoi des manquants de {$cmd['numero_commande']}");
        }

        $resume = [];
        foreach (array_count_values($a_faire) as $d => $n) $resume[] = "$n " . mb_strtolower(RCP_DECISIONS[$d]);
        $resume = implode(', ', $resume) . ($renvoi_num ? " — commande $renvoi_num à expédier" : '');
        audit_log($user['id'], 'UPDATE', 'commandes', $cmd_id, "Écarts de réception traités : $resume" . ($notes !== '' ? " ($notes)" : ''));

        $dest = array_column(db_fetch_all(
            "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
             WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [$cmd['site_id']]), 'id');
        if ($cmd['created_by']) $dest[] = (int)$cmd['created_by'];
        foreach (array_unique(array_map('intval', $dest)) as $uid)
            notif_create('info', "Écart de réception traité — {$cmd['numero_commande']}", ucfirst($resume) . '.', $uid, '/pages/commandes.php');
        if ($renvoi_id && cmdbob_lignes_a_choisir($renvoi_id) > 0)
            rcp_notifier_role('gestionnaire_stock_bobines', "🎞️ Commande $renvoi_num : bobines à choisir",
                "Renvoi des rouleaux non reçus de {$cmd['numero_commande']}.", '/pages/commandes.php');
        db_commit();
    } catch (Exception $ex) { db_rollback(); throw $ex; }
    return ['nb' => count($a_faire), 'renvoi' => $renvoi_num, 'resume' => $resume];
}
