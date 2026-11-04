<?php
// ============================================================
//  includes/articles_equipements.php
//
//  Certaines familles d'articles ne vont pas dans le stock d'articles du
//  site qui les reçoit (réception d'une commande, ou Distribution depuis la
//  page Articles) mais dans son stock d'ÉQUIPEMENTS — une fiche par unité,
//  avec numéro de série, affectée au site :
//    « Matériel et outillage industriel » -> équipements opérationnels
//    « Consommables informatiques »       -> équipements informatiques
//
//  Chaque article concerné est rattaché une fois pour toutes à un type
//  d'équipement (articles.nomenclature_id) ; s'il n'en a pas, le type est
//  créé à la première arrivée, au nom de l'article.
// ============================================================
require_once __DIR__ . '/db.php';

/** Famille d'articles => [catégorie d'équipement, préfixe du code type, durée OHADA en mois]. */
const ART_FAMILLES_EQUIPEMENT = [
    'matériel et outillage industriel' => ['operationnel', 'OP', 60],   // 5 ans
    'consommables informatiques'       => ['informatique', 'IN', 36],   // 3 ans
];

/** Catégorie d'équipement de l'article ('operationnel', 'informatique'), ou null s'il reste un article. */
function art_categorie_equipement(int $article_id): ?string {
    static $cache = [];
    if (!$article_id) return null;
    if (!array_key_exists($article_id, $cache)) {
        $fam = db_fetch_value(
            "SELECT f.libelle FROM articles a JOIN familles_achat f ON f.id = a.famille_id WHERE a.id = ?",
            [$article_id]);
        $cle = $fam !== null ? mb_strtolower(trim((string)$fam)) : '';
        $cache[$article_id] = ART_FAMILLES_EQUIPEMENT[$cle][0] ?? null;
    }
    return $cache[$article_id];
}

function _art_regle_equipement(string $categorie): array {
    foreach (ART_FAMILLES_EQUIPEMENT as $r) if ($r[0] === $categorie) return $r;
    return [$categorie, 'EQ', 60];
}

/** Type d'équipement de l'article dans la catégorie donnée, créé au besoin. */
function art_nomenclature_equipement(int $article_id, string $categorie): int {
    [, $prefixe_code, $duree] = _art_regle_equipement($categorie);
    // Sans la migration (articles.nomenclature_id), le type est retrouvé par
    // son nom à chaque fois : la page marche avant que la base soit à jour.
    $avec_col = db_colonne_existe('articles', 'nomenclature_id');
    $a = db_fetch_one("SELECT id, libelle" . ($avec_col ? ", nomenclature_id" : "") . " FROM articles WHERE id=?", [$article_id]);
    if (!$a) throw new Exception("Article #$article_id introuvable.");
    if (!empty($a['nomenclature_id'])
        && db_fetch_value("SELECT COUNT(*) FROM nomenclatures WHERE id=? AND categorie=?", [(int)$a['nomenclature_id'], $categorie])) {
        return (int)$a['nomenclature_id'];
    }
    // Un type du même nom existe déjà dans la catégorie : on le reprend
    // plutôt que d'en créer un doublon.
    $nom_id = (int)(db_fetch_value(
        "SELECT id FROM nomenclatures WHERE categorie=? AND LOWER(TRIM(libelle)) = LOWER(TRIM(?)) ORDER BY id LIMIT 1",
        [$categorie, $a['libelle']]) ?? 0);
    if (!$nom_id) {
        $base = $prefixe_code . str_pad((string)$article_id, 4, '0', STR_PAD_LEFT);
        $code = $base; $n = 1;
        while (db_fetch_value("SELECT COUNT(*) FROM nomenclatures WHERE code=?", [$code])) {
            $code = substr($base, 0, 8) . '-' . $n++;
        }
        db_query(
            "INSERT INTO nomenclatures (code, categorie, libelle, description, duree_amortissement_mois)
             VALUES (?, ?, ?, ?, ?)",
            [$code, $categorie, mb_substr($a['libelle'], 0, 150), "Créé automatiquement depuis l'article #$article_id", $duree]);
        $nom_id = (int)db_last_id();
    }
    if ($avec_col) db_query("UPDATE articles SET nomenclature_id=? WHERE id=?", [$nom_id, $article_id]);
    return $nom_id;
}

/**
 * Crée $qte fiches d'équipement (catégorie de la famille de l'article)
 * affectées au site. Retourne les numéros de série créés.
 */
function art_creer_equipements(int $article_id, int $site_id, int $qte, ?int $user_id, string $origine): array {
    $categorie = art_categorie_equipement($article_id);
    if ($qte <= 0 || $categorie === null) return [];
    [, , $duree] = _art_regle_equipement($categorie);
    $nom_id = art_nomenclature_equipement($article_id, $categorie);
    $code   = db_fetch_value("SELECT code FROM nomenclatures WHERE id=?", [$nom_id]) ?: 'EQP';
    $prix   = (float)(db_fetch_value("SELECT prix_unitaire FROM articles WHERE id=?", [$article_id]) ?? 0);
    $date   = date('Y-m-d');
    $fin    = date('Y-m-d', strtotime("$date +$duree months"));

    // Numéro « CODE-EMUCI-NNNN », séquence par type (même règle que
    // _prochain_numero_serie() dans pages/equipements.php).
    $prefixe = $code . '-EMUCI-';
    $seq = 0;
    foreach (db_fetch_all("SELECT numero_serie_interne FROM equipements WHERE numero_serie_interne LIKE ?", [$prefixe . '%']) as $r) {
        $suffixe = substr($r['numero_serie_interne'], strlen($prefixe));
        if (ctype_digit($suffixe)) $seq = max($seq, (int)$suffixe);
    }
    $chrono = (int)db_fetch_value("SELECT COALESCE(MAX(numero_chrono),0) FROM equipements");

    $numeros = [];
    for ($i = 0; $i < $qte; $i++) {
        $nsi = $prefixe . str_pad((string)++$seq, 4, '0', STR_PAD_LEFT);
        db_query(
            "INSERT INTO equipements
             (numero_serie_interne, numero_chrono, nomenclature_id, categorie, site_id, etat, statut_stock,
              date_acquisition, date_fin_cycle, prix_achat, duree_amortissement_mois, observations, actif, created_by)
             VALUES (?,?,?,?,?,'neuf','affecte',?,?,?,?,?,1,?)",
            [$nsi, ++$chrono, $nom_id, $categorie, $site_id, $date, $fin, $prix, $duree, $origine, $user_id]);
        $eid = (int)db_last_id();
        db_query(
            "INSERT INTO mouvements_equipements (equipement_id, type, site_dest_id, notes, created_by)
             VALUES (?, 'entree', ?, ?, ?)",
            [$eid, $site_id, $origine, $user_id]);
        $numeros[] = $nsi;
    }
    return $numeros;
}
