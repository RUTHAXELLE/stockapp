<?php
// ============================================================
//  includes/referentiels.php
//  Accès aux capacités de conditionnement déclarées en base.
//
//  Avant cette couche, « une bobine contient 500 films » existait en
//  quatre exemplaires : un littéral dans l'INSERT de operations/bobines.php,
//  la règle « WSL/TL = 2000, sinon 500 » recopiée dans import_bobines.php
//  et import_emuci.php, et un repli dans consommation.php. Quatre copies
//  d'une même règle métier finissent toujours par diverger, et l'écart
//  serait invisible : rien ne signale un stock initial faux.
//
//  Les valeurs vivent maintenant dans op_types_bobines.films_par_bobine
//  et op_types_pmma.unites_par_carton, éditables par
//  pages/admin/referentiels_operations.php.
//
//  Les lectures sont mises en cache pour la durée de la requête : un
//  import de lot demande la capacité une fois par ligne, ce qui ferait
//  autant d'allers-retours pour une valeur qui ne bouge pas.
// ============================================================

/**
 * Films contenus dans une bobine neuve du type donné.
 * Repli à 500 (format standard) si le type est inconnu au catalogue.
 */
function capacite_bobine(string $type_code, int $defaut = 500): int {
    static $cache = [];
    $k = strtoupper(trim($type_code));
    if ($k === '') return $defaut;
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = _ref_valeur(
            "SELECT films_par_bobine FROM op_types_bobines WHERE UPPER(code) = ?", [$k]);
    }
    return $cache[$k] ?? $defaut;
}

/**
 * Lecture tolérante d'une capacité : renvoie null si la valeur est absente
 * OU si la colonne n'existe pas encore.
 *
 * Le code part en production dès le push (Render redéploie sur `main`)
 * tandis que la migration se lance à la main sur Neon : entre les deux, la
 * colonne peut manquer. Sans ce filet, la création d'une bobine et les deux
 * imports tomberaient en erreur fatale pendant cette fenêtre — vérifié en
 * rejouant le code contre une base non migrée.
 */
function _ref_valeur(string $sql, array $params): ?int {
    try {
        $v = db_fetch_value($sql, $params);
    } catch (Throwable $e) {
        return null;
    }
    return ($v !== null && (int)$v > 0) ? (int)$v : null;
}

/**
 * Capacité d'une série entière (A, B, C, D, TL, WSL).
 * Utile aux imports, qui connaissent la série avant le type exact.
 * Prend le maximum : si une série mêle deux conditionnements, sous-estimer
 * la capacité créerait des bobines au stock initial trop faible, ce que
 * personne ne verrait — l'inverse se remarque au premier inventaire.
 */
function capacite_bobine_serie(string $serie, int $defaut = 500): int {
    static $cache = [];
    $k = strtoupper(trim($serie));
    if ($k === '') return $defaut;
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = _ref_valeur(
            "SELECT MAX(films_par_bobine) FROM op_types_bobines WHERE UPPER(TRIM(serie)) = ?", [$k]);
    }
    return $cache[$k] ?? $defaut;
}

/**
 * Unités de PMMA contenues dans un carton du type donné.
 *
 * Le stock et la saisie restent comptés en unités : cette valeur ne sert
 * qu'à convertir un besoin en nombre de cartons à commander. Elle ne doit
 * jamais entrer dans une déduction de stock.
 */
function capacite_carton_pmma(string $type_pmma, int $defaut = 25): int {
    static $cache = [];
    $k = trim($type_pmma);
    if ($k === '') return $defaut;
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = _ref_valeur(
            "SELECT unites_par_carton FROM op_types_pmma WHERE code = ?", [$k]);
    }
    return $cache[$k] ?? $defaut;
}

/**
 * Catalogue PMMA actif : code => [libelle, serie, unites_par_carton, seuil_defaut].
 * Retourne un tableau vide si la table n'existe pas encore, pour qu'une
 * installation dont la migration n'est pas passée continue de fonctionner.
 */
function types_pmma_actifs(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (db_fetch_all(
            "SELECT code, libelle, serie, unites_par_carton, seuil_defaut
               FROM op_types_pmma WHERE actif = 1 ORDER BY code") as $r) {
            $cache[$r['code']] = [
                'libelle'           => $r['libelle'],
                'serie'             => $r['serie'],
                'unites_par_carton' => (int)$r['unites_par_carton'],
                'seuil_defaut'      => (int)$r['seuil_defaut'],
            ];
        }
    } catch (Throwable $e) {
        // Table absente : le reste de l'application n'en dépend pas.
    }
    return $cache;
}

/**
 * Catégorie d'une série : 'bobine' ou 'vignette'.
 *
 * Les séries A, B, C et D sont des bobines de films ; TL (Réservoir) et
 * WSL (Pare-brise) sont des vignettes. Ce ne sont pas deux variantes du
 * même article : l'application leur donne déjà deux écrans distincts
 * (pages/operations/bobines.php?categorie=vignette), et les confondre
 * dans un total revient à additionner des choses non substituables.
 *
 * Le partage vit ici plutôt que recopié : bobines.php portait cette
 * liste seul, et tout écran qui l'ignorait parlait de « bobines WSL ».
 */
function categorie_serie(?string $serie): string {
    $s = strtoupper(trim((string)$serie));
    // 'T' et 'W' sont les valeurs abrégées portées par op_bobines.serie,
    // char(1) ne pouvant contenir 'TL' ni 'WSL'.
    return in_array($s, ['TL', 'WSL', 'T', 'W'], true) ? 'vignette' : 'bobine';
}

/** Libellé de la catégorie, au singulier ou au pluriel. */
function libelle_categorie(string $categorie, bool $pluriel = false): string {
    $c = $categorie === 'vignette' ? 'vignette' : 'bobine';
    return $pluriel ? $c . 's' : $c;
}

/**
 * Libellé lisible du format d'une bobine à partir de sa série.
 *
 * Le mapping est celui de migration_types_bobines_format_version.sql, qui
 * a rempli op_types_bobines.format à partir de la série. Il sert ici de
 * repli quand cette colonne n'est pas disponible — et pour les codes qui
 * ne figurent pas au catalogue. Une série inconnue est rendue telle
 * quelle : mieux vaut afficher « X » qu'un tiret qui masque l'anomalie.
 */
function libelle_format_serie(?string $serie): string {
    static $m = ['A'=>'Auto', 'B'=>'Carré', 'C'=>'Moto', 'D'=>'MotoII',
                 'T'=>'Réservoir', 'TL'=>'Réservoir',
                 'W'=>'Pare-brise', 'WSL'=>'Pare-brise'];
    $s = strtoupper(trim((string)$serie));
    return $m[$s] ?? ($s !== '' ? $s : '—');
}

/**
 * Libellé lisible de la version, déduit du dernier chiffre du code type.
 * Même origine que ci-dessus : le mapping 1=Privée … 6=Temporaire est
 * constant sur toutes les séries.
 */
function libelle_version_code(?string $code): string {
    static $m = ['1'=>'Privée', '2'=>'Transport Publique',
                 '3'=>'Institution Internationale', '4'=>'Diplomatique',
                 '5'=>'Gouvernementale', '6'=>'Temporaire'];
    return $m[substr(trim((string)$code), -1)] ?? '—';
}

/**
 * Ramène un libellé libre au code exact du catalogue PMMA, ou null.
 *
 * La réception de commande fabrique le type en retirant le préfixe « PMMA »
 * du libellé de la ligne commandée (pages/commandes.php). Ce libellé est
 * saisi à la main : « PMMA Type A » et « PMMA TYPE B » cohabitent déjà en
 * base, et chaque variante crée une ligne de stock distincte pour la même
 * matière — stock éclaté, seuil d'alerte jamais atteint, consommation
 * répartie sur deux lignes.
 *
 * La comparaison ignore la casse, les espaces multiples, les tirets et un
 * préfixe « PMMA » éventuel, des deux côtés. En cas d'échec on retourne
 * null plutôt qu'un code approché : rattacher une livraison au mauvais
 * type serait pire que de la laisser sur son libellé d'origine.
 */
function resoudre_type_pmma(string $libelle): ?string {
    $norme = function (string $s): string {
        $s = mb_strtoupper(trim($s));
        $s = preg_replace('/^PMMA[\s\-_]*/u', '', $s);
        return preg_replace('/[\s\-_]+/u', '', $s);
    };
    $cible = $norme($libelle);
    if ($cible === '') return null;
    foreach (types_pmma_actifs() as $code => $_) {
        if ($norme($code) === $cible) return $code;
    }
    return null;
}

/**
 * Nombre de cartons à commander pour couvrir un besoin exprimé en unités.
 * Toujours arrondi au carton supérieur : on ne commande pas un demi-carton.
 */
function cartons_pour(int $unites, string $type_pmma): int {
    if ($unites <= 0) return 0;
    return (int) ceil($unites / max(1, capacite_carton_pmma($type_pmma)));
}
