<?php
// ============================================================
//  includes/reception_commande.php — réception d'une commande ligne
//  par ligne et traitement des écarts de réception
// ============================================================
//  À la réception (pages/commandes.php, action 'receptionner'), le
//  coordinateur déclare pour chaque ligne ce qu'il a réellement reçu :
//  la totalité, une partie (quantité) ou rien ; pour une ligne bobine ou
//  vignette, il coche les rouleaux arrivés. Le site n'est crédité que du
//  reçu. Chaque manque ouvre un écart (commande_ecarts_reception) que le
//  gestionnaire de stock — le GSB pour les rouleaux — tranche :
//    - restitue : la quantité revient au dépôt d'où elle était sortie ;
//    - renvoye  : idem, puis une nouvelle commande « À préparer » reprend
//                 les manquants (elle repasse par l'expédition normale) ;
//    - perte    : rien ne revient ; un rouleau passe « Perdue ».
//  Sans la migration (sql/migration_reception_par_ligne*.sql), la
//  réception reste celle d'avant : tout le livré est crédité.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/referentiels.php';
require_once __DIR__ . '/commande_bobines.php';
require_once __DIR__ . '/achats.php';

const RCP_DECISIONS = ['restitue' => 'Remis en stock au dépôt', 'renvoye' => 'Renvoyé (nouvelle commande)', 'perte' => 'Passé en perte'];

function rcp_disponible(): bool {
    static $ok = null;
    return $ok ??= db_colonne_existe('commande_lignes', 'quantite_recue')
                && db_colonne_existe('commande_ecarts_reception', 'id');
}

/** Rivet gonflable ou éclaté d'après le libellé de la ligne (même règle que l'expédition). */
function rcp_type_rivet(string $libelle): string {
    $n = strtolower(str_replace(['é','è','ê','ë','É','È','Ê','Ë'], 'e', $libelle));
    return str_contains($n, 'eclat') ? 'eclate' : 'gonflable';
}

/**
 * Lignes à déclarer à la réception, avec ce qui a été expédié :
 * [['id','libelle','type_article','unite','livre','bobines'=>[...]], ...].
 */
function rcp_lignes_a_recevoir(int $cmd_id): array {
    $bob = cmdbob_par_ligne($cmd_id);
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
 * Une ligne absente de la saisie est réputée reçue en totalité.
 * Renvoie [ligne_id => ['recu' => int, 'motif' => ?str, 'bobines_manquantes' => [...], 'manque' => int]].
 * Lève une Exception au premier problème.
 */
function rcp_preparer(int $cmd_id, array $saisie): array {
    $plan = [];
    foreach (rcp_lignes_a_recevoir($cmd_id) as $l) {
        $lid   = $l['id'];
        $s     = $saisie[$lid] ?? null;
        $motif = trim((string)($s['motif'] ?? ''));
        if ($l['bobines']) {
            $recus = $s === null ? array_column($l['bobines'], 'id')
                                 : array_map('intval', (array)($s['bobines'] ?? []));
            $ok = $manq = [];
            foreach ($l['bobines'] as $b) {
                if (in_array((int)$b['id'], $recus, true)) $ok[] = $b; else $manq[] = $b;
            }
            if ($manq && $motif === '')
                throw new Exception("Motif obligatoire pour « {$l['libelle']} » : " . count($manq) . " rouleau(x) non reçu(s).");
            $en_bob = cmdbob_en_bobines($l);
            $recu   = $en_bob ? count($ok) : array_sum(array_column($ok, 'stock_systeme'));
            $plan[$lid] = ['recu' => $recu, 'motif' => $manq ? $motif : null, 'bobines_manquantes' => $manq,
                           'manque' => $l['livre'] - $recu, 'en_bobines' => $en_bob];
            continue;
        }
        $recu = $s === null ? $l['livre'] : $s['quantite_recue'] ?? null;
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
                    [$cmd_id, $lid, (int)$b['id'], !empty($p['en_bobines']) ? 1 : (int)$b['stock_systeme'], $p['motif'], $user_id]);
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
        "SELECT e.*, cl.libelle, cl.type_article, cl.unite, cl.depot_source_id, ds.nom AS depot_nom,
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

/** Gestionnaires à prévenir d'un écart ; le GSB en plus si des rouleaux manquent. */
function rcp_notifier_ecarts(array $cmd, int $nb, bool $avec_rouleaux): void {
    $roles = ['gestionnaire_stock', 'admin', 'superadmin'];
    if ($avec_rouleaux) $roles[] = 'gestionnaire_stock_bobines';
    $in    = implode(',', array_fill(0, count($roles), '?'));
    $site  = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$cmd['site_id']]) ?? '';
    foreach (db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug IN ($in) AND u.actif=1", $roles) as $u) {
        db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
            [$u['id'], 'info', "⚠️ Écart de réception — {$cmd['numero_commande']}",
             "$site a réceptionné la commande avec $nb manque(s) : à remettre en stock, renvoyer ou passer en perte.",
             '/pages/commandes.php']);
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
    $q   = (int)$e['quantite'];
    if (!$dep || $q <= 0) return;
    $l = db_fetch_one("SELECT * FROM commande_lignes WHERE id=?", [$e['commande_ligne_id']]);
    if ($l['type_article'] === 'article' && $l['article_id']) {
        db_query("INSERT INTO stock_site (article_id,site_id,quantite) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)", [(int)$l['article_id'], $dep, $q]);
        db_query("UPDATE articles SET stock_global = stock_global + ? WHERE id=?", [$q, (int)$l['article_id']]);
    } elseif ($l['type_article'] === 'rivet') {
        db_query("INSERT INTO op_stock_rivets (site_id,type_rivet,quantite) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)", [$dep, rcp_type_rivet($l['libelle']), $q]);
    } elseif ($l['type_article'] === 'pmma') {
        $type = resoudre_type_pmma($l['libelle']) ?? trim(preg_replace('/^PMMA\s*/i', '', $l['libelle']));
        if ($type === '') return;
        db_query("INSERT INTO stock_pmma_site (site_id,type_pmma,quantite) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)",
            [$dep, $type, pmma_qte_en_unites($type, $l['unite'] ?? null, $q)]);
    }
}

/**
 * Tranche les écarts ouverts d'une commande. $decisions : [ecart_id => 'restitue'|'renvoye'|'perte'].
 * $peut_articles : gestionnaire de stock ; $peut_rouleaux : GSB. Les écarts
 * non cités restent ouverts. Renvoie ['nb' => n, 'renvoi' => numéro ou null].
 */
function rcp_traiter(int $cmd_id, array $decisions, string $notes, array $user, bool $peut_articles, bool $peut_rouleaux): array {
    if (!rcp_disponible()) throw new Exception('Migration reception_par_ligne non jouée sur cette base.');
    $cmd = db_fetch_one("SELECT * FROM commandes WHERE id=?", [$cmd_id]);
    if (!$cmd) throw new Exception('Commande introuvable.');
    $ouverts = array_column(array_filter(rcp_ecarts($cmd_id), fn($e) => $e['statut'] === 'ouvert'), null, 'id');
    $a_faire = [];
    foreach ($decisions as $eid => $dec) {
        if ($dec === '' || $dec === null) continue;
        $e = $ouverts[(int)$eid] ?? null;
        if (!$e) throw new Exception("Écart #$eid introuvable ou déjà traité.");
        if (!isset(RCP_DECISIONS[$dec])) throw new Exception("Décision inconnue pour « {$e['libelle']} ».");
        $rouleau = (bool)$e['bobine_id'];
        if ($rouleau && !$peut_rouleaux) throw new Exception("Les rouleaux non reçus sont traités par le GSB (« {$e['libelle']} »).");
        if (!$rouleau && !$peut_articles) throw new Exception("Cet écart est traité par le gestionnaire de stock (« {$e['libelle']} »).");
        if ($dec === 'perte' && trim($notes) === '') throw new Exception('Précisez la raison de la perte dans le commentaire.');
        $a_faire[(int)$eid] = $dec;
    }
    if (!$a_faire) throw new Exception('Choisissez une décision pour au moins un écart.');

    $renvoi_num = null;
    db_begin();
    try {
        $renvois = [];   // commande_ligne_id => quantité à renvoyer
        foreach ($a_faire as $eid => $dec) {
            $e = $ouverts[$eid];
            if ($dec === 'perte') {
                if ($e['bobine_id']) {
                    db_query("UPDATE commande_ligne_bobines SET statut='perdu'
                              WHERE commande_id=? AND bobine_id=? AND statut='non_recu'", [$cmd_id, $e['bobine_id']]);
                    db_query("UPDATE op_bobines SET statut='perdue', notes_perte=? WHERE id=?",
                        ["Non reçue sur site (commande {$cmd['numero_commande']}) : " . trim($notes), $e['bobine_id']]);
                }
            } else {
                rcp_remettre_en_stock($e, (int)$user['id'], $cmd['numero_commande']);
                if ($dec === 'renvoye')
                    $renvois[(int)$e['commande_ligne_id']] = ($renvois[(int)$e['commande_ligne_id']] ?? 0) + (int)$e['quantite'];
            }
            db_query("UPDATE commande_ecarts_reception SET statut=?, traite_par=?, traite_at=NOW(), notes_traitement=? WHERE id=?",
                [$dec, $user['id'], trim($notes) ?: null, $eid]);
        }

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
        foreach (array_count_values($a_faire) as $d => $n) $resume[] = "$n " . strtolower(RCP_DECISIONS[$d]);
        $resume = implode(', ', $resume) . ($renvoi_num ? " — commande $renvoi_num à expédier" : '');
        audit_log($user['id'], 'UPDATE', 'commandes', $cmd_id, "Écarts de réception traités : $resume" . (trim($notes) ? " ($notes)" : ''));

        $dest = array_column(db_fetch_all(
            "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
             WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [$cmd['site_id']]), 'id');
        if ($cmd['created_by']) $dest[] = (int)$cmd['created_by'];
        foreach (array_unique(array_map('intval', $dest)) as $uid) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$uid, 'info', "Écart de réception traité — {$cmd['numero_commande']}", ucfirst($resume) . '.', '/pages/commandes.php']);
        }
        if ($renvoi_num && cmdbob_lignes_a_choisir($renvoi_id) > 0) {
            foreach (db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                                   WHERE r.slug='gestionnaire_stock_bobines' AND u.actif=1") as $g) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$g['id'], 'info', "🎞️ Commande $renvoi_num : bobines à choisir",
                     "Renvoi des rouleaux non reçus de {$cmd['numero_commande']}.", '/pages/commandes.php']);
            }
        }
        db_commit();
    } catch (Exception $ex) { db_rollback(); throw $ex; }
    return ['nb' => count($a_faire), 'renvoi' => $renvoi_num, 'resume' => $resume];
}
