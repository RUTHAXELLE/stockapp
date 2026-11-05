<?php
// ============================================================
//  includes/equipements_trouves.php — Équipements trouvés à l'inventaire
//
//  Pendant un inventaire d'équipements en cours, le coordinateur déclare
//  un équipement présent sur son site mais absent du système. Rien n'est
//  créé à la déclaration : le gestionnaire de stock décide avant la validation de l'inventaire.
//    • accepte   : la fiche équipement est créée sur le site ;
//    • transfere : l'équipement est déjà connu (même n° de série
//                  fabricant) sur un autre site — on le rattache au site
//                  de l'inventaire au lieu d'en créer un double ;
//    • refuse    : motif obligatoire.
//  Une déclaration en attente bloque la validation de l'inventaire.
//  Table : sql/migration_inventaire_equipements_trouves(_mysql).sql.
//  SQL commun MySQL / PostgreSQL.
// ============================================================
require_once __DIR__ . '/db.php';

const EQT_ETATS = ['neuf' => 'Neuf', 'bon' => 'Bon', 'usage' => 'Usagé', 'endommage' => 'Endommagé', 'hs' => 'HS'];

// Durée d'amortissement par catégorie (même barème OHADA que
// pages/equipements.php).
const EQT_DUREES = ['informatique' => 36, 'mobilier' => 120, 'vehicule' => 60, 'operationnel' => 60];

// La table n'existe qu'après la migration : tant qu'elle manque, la
// fonction est simplement absente de l'écran.
function eqt_disponible(): bool {
    return db_colonne_existe('inventaire_equipements_trouves', 'id');
}

// ── Équipement actif déjà connu sous ce n° de série fabricant, ou null.
function eqt_rechercher_existant(string $numero_serie): ?array {
    $ns = trim($numero_serie);
    if ($ns === '') return null;
    return db_fetch_one(
        "SELECT e.id, e.numero_serie_interne, e.site_id, s.nom AS site_nom
         FROM equipements e LEFT JOIN sites s ON s.id = e.site_id
         WHERE e.actif = 1 AND LOWER(TRIM(e.numero_serie_origine)) = LOWER(?)
         ORDER BY e.id LIMIT 1",
        [$ns]
    ) ?: null;
}

// ── Déclarations d'un inventaire, avec l'équipement connu le cas échéant.
function eqt_lister(int $inv_id): array {
    return db_fetch_all(
        "SELECT t.*, n.libelle AS type_libelle, n.categorie AS type_categorie,
                ex.numero_serie_interne AS existant_numero, ex.site_id AS existant_site_id,
                sx.nom AS existant_site_nom,
                eq.numero_serie_interne AS equipement_numero,
                CONCAT(u.prenom, ' ', u.nom) AS declare_par_nom
         FROM inventaire_equipements_trouves t
         JOIN nomenclatures n ON n.id = t.nomenclature_id
         LEFT JOIN equipements ex ON ex.id = t.equipement_existant_id
         LEFT JOIN sites sx ON sx.id = ex.site_id
         LEFT JOIN equipements eq ON eq.id = t.equipement_id
         LEFT JOIN users u ON u.id = t.declare_par
         WHERE t.inventaire_id = ?
         ORDER BY t.id",
        [$inv_id]
    );
}

function eqt_nb_en_attente(int $inv_id): int {
    return (int)db_fetch_value(
        "SELECT COUNT(*) FROM inventaire_equipements_trouves WHERE inventaire_id=? AND statut='en_attente'",
        [$inv_id]
    );
}

// ── Enregistre une déclaration. $d : nomenclature_id, marque, modele,
//    numero_serie_origine, etat, emplacement, notes. Lève une Exception
//    si les données sont incomplètes ou si l'équipement est déjà sur le
//    site (il suffit alors de le cocher « Trouvé » dans la liste).
function eqt_declarer(array $inv, array $d, ?string $photo, int $user_id): int {
    if (($inv['statut'] ?? '') !== 'brouillon') throw new Exception('Inventaire non modifiable.');
    if (empty($inv['site_id'])) throw new Exception("L'inventaire n'est rattaché à aucun site.");

    $nom_id = (int)($d['nomenclature_id'] ?? 0);
    if (!$nom_id || !db_fetch_value("SELECT id FROM nomenclatures WHERE id=?", [$nom_id]))
        throw new Exception("Type d'équipement obligatoire.");
    $etat = (string)($d['etat'] ?? 'bon');
    if (!isset(EQT_ETATS[$etat])) throw new Exception('État invalide.');
    $marque = trim((string)($d['marque'] ?? ''));
    $modele = trim((string)($d['modele'] ?? ''));
    $ns     = trim((string)($d['numero_serie_origine'] ?? ''));
    if ($marque === '' && $modele === '' && $ns === '')
        throw new Exception('Renseignez au moins la marque, le modèle ou le numéro de série.');

    $existant = eqt_rechercher_existant($ns);
    if ($existant && (int)$existant['site_id'] === (int)$inv['site_id'])
        throw new Exception("Cet équipement est déjà enregistré sur ce site ({$existant['numero_serie_interne']}) : cochez-le « Trouvé » dans la liste.");
    if ($ns !== '' && db_fetch_value(
            "SELECT COUNT(*) FROM inventaire_equipements_trouves
             WHERE inventaire_id=? AND statut='en_attente' AND LOWER(TRIM(numero_serie_origine))=LOWER(?)",
            [$inv['id'], $ns]))
        throw new Exception('Un équipement avec ce numéro de série est déjà déclaré dans cet inventaire.');

    db_query(
        "INSERT INTO inventaire_equipements_trouves
         (inventaire_id, nomenclature_id, marque, modele, numero_serie_origine, etat, emplacement, photo, notes,
          equipement_existant_id, statut, declare_par)
         VALUES (?,?,?,?,?,?,?,?,?,?,'en_attente',?)",
        [$inv['id'], $nom_id, $marque ?: null, $modele ?: null, $ns ?: null, $etat,
         trim((string)($d['emplacement'] ?? '')) ?: null, $photo,
         trim((string)($d['notes'] ?? '')) ?: null, $existant['id'] ?? null, $user_id]
    );
    return (int)db_last_id();
}

// ── Prochain n° interne « CODE-EMUCI-NNNN » pour un type (même règle que
//    _prochain_numero_serie() dans pages/equipements.php, en PHP pour
//    rester commun aux deux bases).
function eqt_prochain_numero(int $nom_id): string {
    $code    = db_fetch_value("SELECT code FROM nomenclatures WHERE id=?", [$nom_id]) ?: 'EQP';
    $prefixe = $code . '-EMUCI-';
    $seq = 0;
    foreach (db_fetch_all("SELECT numero_serie_interne FROM equipements WHERE numero_serie_interne LIKE ?", [$prefixe . '%']) as $r) {
        $suffixe = substr($r['numero_serie_interne'], strlen($prefixe));
        if (ctype_digit($suffixe)) $seq = max($seq, (int)$suffixe);
    }
    return $prefixe . str_pad((string)($seq + 1), 4, '0', STR_PAD_LEFT);
}

// ── Décision du gestionnaire de stock sur une déclaration en attente. $decision :
//    accepte | transfere | refuse. $opt : motif (refus), prix_achat et
//    date_acquisition (acceptation, facultatifs). Retourne un message.
//    À appeler dans la transaction de l'appelant.
function eqt_traiter(array $inv, int $trouve_id, string $decision, array $opt, int $user_id): string {
    if (($inv['statut'] ?? '') !== 'brouillon') throw new Exception('Inventaire non modifiable.');
    $t = db_fetch_one(
        "SELECT t.*, n.categorie AS type_categorie FROM inventaire_equipements_trouves t
         JOIN nomenclatures n ON n.id = t.nomenclature_id
         WHERE t.id=? AND t.inventaire_id=? AND t.statut='en_attente'",
        [$trouve_id, $inv['id']]
    );
    if (!$t) throw new Exception('Déclaration introuvable ou déjà traitée.');
    $site_id = (int)$inv['site_id'];
    $libelle = trim(($t['marque'] ?? '') . ' ' . ($t['modele'] ?? '')) ?: ($t['numero_serie_origine'] ?? '');

    if ($decision === 'refuse') {
        $motif = trim((string)($opt['motif'] ?? ''));
        if ($motif === '') throw new Exception('Le motif du refus est obligatoire.');
        db_query("UPDATE inventaire_equipements_trouves SET statut='refuse', motif_refus=?, traite_par=?, traite_at=NOW() WHERE id=?",
            [$motif, $user_id, $trouve_id]);
        return 'Déclaration refusée.';
    }

    // L'équipement connu se recherche de nouveau : il a pu changer de site
    // depuis la déclaration, ou être enregistré entre-temps.
    $existant = eqt_rechercher_existant((string)($t['numero_serie_origine'] ?? ''));

    if ($decision === 'transfere') {
        if (!$existant) throw new Exception("Aucun équipement connu sous ce numéro de série : acceptez la déclaration pour créer la fiche.");
        $eid = (int)$existant['id'];
        if ((int)$existant['site_id'] !== $site_id) {
            $notes = "Trouvé à l'inventaire équipements #{$inv['id']} du " . date('d/m/Y', strtotime($inv['date_inventaire']));
            db_query(
                "INSERT INTO mouvements_equipements (equipement_id, type, site_source_id, site_dest_id, notes, created_by)
                 VALUES (?, 'transfert', ?, ?, ?, ?)",
                [$eid, $existant['site_id'] ?: null, $site_id, $notes, $user_id]
            );
            // Il est sur le site : plus d'utilisateur ni d'emplacement de
            // l'ancien site ; un statut de circulation (en transit, en
            // attente d'affectation…) n'a plus lieu d'être.
            db_query(
                "UPDATE equipements SET site_id=?, utilisateur_id=NULL, emplacement=?,
                        statut_stock = CASE WHEN statut_stock IN ('en_utilisation','hs') THEN statut_stock ELSE 'affecte' END
                 WHERE id=?",
                [$site_id, $t['emplacement'], $eid]
            );
        }
        // Signalé manquant sur son ancien site : il est retrouvé.
        db_query("UPDATE ecarts_equipements SET statut='resolu', resolu_at=NOW(), resolu_par=? WHERE equipement_id=? AND statut='ouvert'",
            [$user_id, $eid]);
        db_query("UPDATE inventaire_equipements_trouves SET statut='transfere', equipement_id=?, equipement_existant_id=?, traite_par=?, traite_at=NOW() WHERE id=?",
            [$eid, $eid, $user_id, $trouve_id]);
        return "Équipement {$existant['numero_serie_interne']} transféré" . ($existant['site_nom'] ? " depuis {$existant['site_nom']}" : '') . '.';
    }

    if ($decision !== 'accepte') throw new Exception('Décision inconnue.');
    if ($existant)
        throw new Exception("Ce numéro de série est déjà enregistré ({$existant['numero_serie_interne']}"
            . ($existant['site_nom'] ? ", {$existant['site_nom']}" : '') . ') : transférez-le plutôt que de créer un double.');

    $categorie = $t['type_categorie'] ?: 'operationnel';
    $duree     = EQT_DUREES[$categorie] ?? 60;
    $date_acq  = trim((string)($opt['date_acquisition'] ?? ''));
    if ($date_acq !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_acq)) throw new Exception("Date d'acquisition invalide.");
    $prix      = max(0, (float)($opt['prix_achat'] ?? 0));
    $nsi       = eqt_prochain_numero((int)$t['nomenclature_id']);
    $chrono    = (int)db_fetch_value("SELECT COALESCE(MAX(numero_chrono),0)+1 FROM equipements");
    $statut    = $t['etat'] === 'hs' ? 'hs' : 'affecte';
    $obs       = "Trouvé à l'inventaire équipements #{$inv['id']} du " . date('d/m/Y', strtotime($inv['date_inventaire']))
               . ($t['notes'] ? ' — ' . $t['notes'] : '');

    db_query(
        "INSERT INTO equipements
         (numero_serie_interne, numero_chrono, numero_serie_origine, nomenclature_id, categorie, marque, modele,
          site_id, emplacement, etat, statut_stock, date_acquisition, date_fin_cycle, prix_achat,
          duree_amortissement_mois, observations, actif, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)",
        [$nsi, $chrono, $t['numero_serie_origine'], $t['nomenclature_id'], $categorie, $t['marque'], $t['modele'],
         $site_id, $t['emplacement'], $t['etat'], $statut, $date_acq ?: null,
         $date_acq ? date('Y-m-d', strtotime("$date_acq +$duree months")) : null, $prix, $duree, $obs, $user_id]
    );
    $eid = (int)db_last_id();
    db_query(
        "INSERT INTO mouvements_equipements (equipement_id, type, site_dest_id, notes, created_by)
         VALUES (?, 'entree', ?, ?, ?)",
        [$eid, $site_id, $obs, $user_id]
    );
    db_query("UPDATE inventaire_equipements_trouves SET statut='accepte', equipement_id=?, traite_par=?, traite_at=NOW() WHERE id=?",
        [$eid, $user_id, $trouve_id]);
    return "Fiche $nsi créée" . ($libelle ? " ($libelle)" : '') . '.';
}
