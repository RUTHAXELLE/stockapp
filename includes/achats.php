<?php
// ============================================================
//  includes/achats.php — Helpers du module Achats
// ============================================================

// ach_creer_feb() appelle audit_log() : on ne compte pas sur l'appelant
// pour l'avoir inclus, sinon tout nouveau point d'entrée (script, tâche
// planifiée, test) tombe sur un fatal « undefined function ».
require_once __DIR__ . '/audit.php';

// Le circuit de visas FEB (ach_lancer_validation, ach_viser) réutilise tel
// quel le moteur "Demandes internes" — di_can_validate() porte déjà la
// règle du département et celle du N+1, di_next_step() l'avancement.
// Aucune règle de droits n'est réécrite ici.
require_once __DIR__ . '/demandes.php';

// ── Libellés et couleurs de statuts ──────────────────────────
// Préparés pour les écrans FEB (lot suivant, pas encore créés) — aucun
// écran de ce lot n'en dépend, mais les futurs écrans réutiliseront ces
// tableaux plutôt que de redéfinir des libellés/couleurs par page.
function ach_statuts_feb(): array {
    return [
        'brouillon'         => ['label' => 'Brouillon',              'bg' => '#F1F5F9', 'color' => '#475569'],
        'en_attente_n1'     => ['label' => 'En attente du N+1',       'bg' => '#FFF7ED', 'color' => '#B45309'],
        'soumise'           => ['label' => 'Soumise',                 'bg' => '#DBEAFE', 'color' => '#1D4ED8'],
        'prise_en_charge'   => ['label' => 'Prise en charge',         'bg' => '#E0E7FF', 'color' => '#3730A3'],
        'en_validation'     => ['label' => 'En validation',           'bg' => '#FEF3C7', 'color' => '#92400E'],
        'confirmee'         => ['label' => 'Confirmée',               'bg' => '#D1FAE5', 'color' => '#065F46'],
        'cloturee'          => ['label' => 'Clôturée',                'bg' => '#F1F5F9', 'color' => '#475569'],
        'rejetee'           => ['label' => 'Rejetée',                 'bg' => '#FEE2E2', 'color' => '#991B1B'],
    ];
}

function ach_statuts_suivi(): array {
    return [
        'en_attente'   => ['label' => 'En attente',   'bg' => '#F1F5F9', 'color' => '#475569'],
        'commande'     => ['label' => 'Commandé',     'bg' => '#DBEAFE', 'color' => '#1D4ED8'],
        'en_retard'    => ['label' => 'En retard',    'bg' => '#FEE2E2', 'color' => '#991B1B'],
        'livree'       => ['label' => 'Livrée',       'bg' => '#FEF3C7', 'color' => '#92400E'],
        'receptionnee' => ['label' => 'Réceptionnée', 'bg' => '#D1FAE5', 'color' => '#065F46'],
    ];
}

// ── Qui peut déposer une FEB — même règle que di_peut_creer() pour les
//    demandes internes : le lecteur (PDG) consulte et valide, mais ne
//    dépose pas.
function ach_peut_creer(?array $user = null): bool {
    $user = $user ?? current_user();
    return ($user['role_slug'] ?? '') !== 'lecteur';
}

// ── Qui exerce le métier d'acheteur : prise en charge, arbitrage, offres,
//    lancement de la validation (étapes 2 à 4 du processus).
//
//    Pourquoi une règle métier et non une permission : le module « achats »
//    est une seule case dans la matrice, et son droit can_update est
//    partagé par sept rôles — dont RAF, DAF et le PDG, qui en ont besoin
//    pour mes_visas.php (leur visa passe par le même droit). Le retirer
//    casserait les visas ; il faut donc distinguer ici ce que la matrice ne
//    sait pas exprimer : un valideur n'est pas un acheteur.
//
//    Un administrateur reste inclus — il doit pouvoir débloquer une FEB
//    dont l'acheteur est absent (réattribution, réouverture).
function ach_est_acheteur(?array $user = null): bool {
    $user = $user ?? current_user();
    return in_array($user['role_slug'] ?? '', ['superviseur_achat', 'admin', 'superadmin'], true);
}

// ── Barrière d'écran pour les pages réservées au service Achats. Même
//    forme que require_permission() : 403 rendu, exécution arrêtée.
function ach_require_acheteur(?array $user = null): void {
    if (!ach_est_acheteur($user)) {
        http_response_code(403);
        include __DIR__ . '/../templates/403.php';
        exit;
    }
}

// ── Lecture d'un paramètre achat_parametres, avec valeur par défaut ──
function ach_param(string $cle, $defaut = null) {
    $v = db_fetch_value("SELECT valeur FROM achat_parametres WHERE cle = ?", [$cle]);
    return $v !== null && $v !== false ? $v : $defaut;
}

// ── Palier de validation applicable à un montant ─────────────
// Retourne la ligne complète (bornes, libellé, signataires déjà décodés)
// ou null si aucun palier actif ne couvre ce montant. borne_max NULL =
// pas de plafond, donc dernier palier trouvé par bornes croissantes.
function ach_palier_pour_montant(int $montant): ?array {
    $row = db_fetch_one(
        "SELECT * FROM achat_paliers
          WHERE actif = 1
            AND borne_min <= ?
            AND (borne_max IS NULL OR borne_max >= ?)
          ORDER BY ordre
          LIMIT 1",
        [$montant, $montant]
    );
    if (!$row) return null;
    $row['signataires'] = json_decode($row['signataires'], true) ?? [];
    return $row;
}

// ── RG-13 : la grille des paliers actifs doit couvrir toute la plage de
//    montants sans trou ni chevauchement, sinon ach_lancer_validation()
//    pourrait un jour tomber sur un montant que plus aucun palier ne
//    couvre. Contrôlée à l'enregistrement ET à la désactivation d'un
//    palier (pages/achats/param_paliers.php) — les deux peuvent ouvrir un
//    trou. $exclude_id : le palier en cours d'édition/désactivation, à
//    remplacer par $candidat (sa version modifiée, actif ou non) plutôt
//    que par la ligne encore en base.
function ach_paliers_couvrent_tout(?int $exclude_id = null, ?array $candidat = null): array {
    $paliers = db_fetch_all("SELECT id, borne_min, borne_max, libelle, actif FROM achat_paliers WHERE actif = 1");
    if ($exclude_id !== null) {
        $paliers = array_values(array_filter($paliers, fn($p) => (int)$p['id'] !== $exclude_id));
    }
    if ($candidat !== null && !empty($candidat['actif'])) {
        $paliers[] = $candidat;
    }
    if (!$paliers) {
        return ['ok' => false, 'message' => 'La grille des paliers actifs est vide — aucun montant ne serait couvert.'];
    }

    usort($paliers, fn($a, $b) => $a['borne_min'] <=> $b['borne_min']);

    if ((int)$paliers[0]['borne_min'] !== 0) {
        return ['ok' => false, 'message' => "La grille doit commencer à 0 (le palier le plus bas commence à {$paliers[0]['borne_min']})."];
    }
    for ($i = 1; $i < count($paliers); $i++) {
        $prec_max = $paliers[$i - 1]['borne_max'];
        $cur_min  = (int)$paliers[$i]['borne_min'];
        if ($prec_max === null) {
            return ['ok' => false, 'message' => "Le palier « {$paliers[$i-1]['libelle']} » n'a pas de plafond : aucun palier ne peut le suivre."];
        }
        $prec_max = (int)$prec_max;
        if ($cur_min > $prec_max + 1) {
            return ['ok' => false, 'message' => "Trou dans la grille entre " . ($prec_max + 1) . " et " . ($cur_min - 1) . " XOF."];
        }
        if ($cur_min <= $prec_max) {
            return ['ok' => false, 'message' => "Chevauchement entre « {$paliers[$i-1]['libelle']} » et « {$paliers[$i]['libelle']} »."];
        }
    }
    if ($paliers[count($paliers) - 1]['borne_max'] !== null) {
        return ['ok' => false, 'message' => 'Le dernier palier doit rester sans plafond (borne haute vide) pour couvrir tous les montants.'];
    }
    return ['ok' => true, 'message' => 'Grille complète, sans trou ni chevauchement.'];
}

// ── Numéro de FEB suivant pour un exercice donné ─────────────
// S'appuie sur la transaction de l'appelant : PostgreSQL ne supporte pas
// les transactions imbriquées, et cette fonction est destinée à être
// appelée depuis ach_creer_feb() qui gère déjà sa propre transaction
// (en-tête + lignes + pièces jointes). Une transaction locale n'est ouverte
// que si aucune n'est déjà en cours, pour rester utilisable isolément
// (tests, scripts) sans jamais imbriquer de BEGIN.
// Verrou explicite (SELECT ... FOR UPDATE) : sans lui, deux FEB soumises au
// même instant peuvent lire le même dernier_numero et repartir avec un
// numéro identique.
function ach_numero_feb(int $exercice): string {
    $pdo         = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        db_query(
            "INSERT INTO feb_compteurs (exercice, dernier_numero) VALUES (?, 0)
             ON CONFLICT (exercice) DO NOTHING",
            [$exercice]
        );
        $row = db_fetch_one(
            "SELECT dernier_numero FROM feb_compteurs WHERE exercice = ? FOR UPDATE",
            [$exercice]
        );
        $suivant = (int)($row['dernier_numero'] ?? 0) + 1;
        db_query(
            "UPDATE feb_compteurs SET dernier_numero = ? WHERE exercice = ?",
            [$suivant, $exercice]
        );
        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }
    return sprintf('FEB-%d-%04d', $exercice, $suivant);
}

// Même motif que ach_numero_feb() ci-dessus, compteur par jour plutôt que
// par exercice — cf. migration_achats_24_commande_compteurs.sql. Remplace
// le tirage aléatoire de pages/commandes.php, sujet à collision sur
// l'index unique commandes.numero_commande.
function ach_numero_commande(): string {
    $pdo         = get_db();
    $jour        = date('Y-m-d');
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        db_query(
            "INSERT INTO commande_compteurs (jour, dernier_numero) VALUES (?, 0)
             ON CONFLICT (jour) DO NOTHING",
            [$jour]
        );
        $row = db_fetch_one(
            "SELECT dernier_numero FROM commande_compteurs WHERE jour = ? FOR UPDATE",
            [$jour]
        );
        $suivant = (int)($row['dernier_numero'] ?? 0) + 1;
        db_query(
            "UPDATE commande_compteurs SET dernier_numero = ? WHERE jour = ?",
            [$suivant, $jour]
        );
        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }
    return sprintf('CMD-%s-%04d', date('Ymd'), $suivant);
}

// ── Urgence FEB : priorité de traitement, pas une note — trois niveaux,
//    jamais un entier nu exposé à l'écran. Aligné sur le DEFAULT 0 de
//    feb.urgence (0 = Normale).
function ach_urgences(): array {
    return [
        0 => 'Normale',
        1 => 'Urgente',
        2 => 'Critique',
    ];
}

// ── Stock consolidé des sites "magasin" pour un article — c'est le seul
//    stock qu'un acheteur peut réellement mobiliser pour couvrir une FEB.
//    articles.stock_global (utilisé ailleurs dans l'appli, hors Achats) agrège
//    TOUS les sites, y compris les sites terrain (type 'saisie'/'pose'/'mixte')
//    et les sites 'siège' : du stock physiquement affecté à un site
//    d'intervention, ou simplement administratif, n'est pas transférable en
//    pratique pour servir une autre demande. L'arbitrage stock/achat compare
//    donc au stock des seuls sites de type 'magasin', pas au total entreprise.
function ach_stock_magasin(int $article_id): int {
    return (int) db_fetch_value(
        "SELECT COALESCE(SUM(ss.quantite), 0)
           FROM stock_site ss
           JOIN sites s ON s.id = ss.site_id
          WHERE ss.article_id = ? AND s.type = 'magasin' AND s.actif = 1",
        [$article_id]
    );
}

// ── Débite le stock magasin — contrepartie exacte de ach_stock_magasin(),
//    qui somme les sites typés « magasin » pour décider l'arbitrage. Sans ce
//    débit, expédier une commande interne ne retirait rien du magasin (seul
//    articles.stock_global descendait, cf. pages/commandes.php) : les mêmes
//    unités physiques restaient arbitrables sur la FEB suivante.
//
//    Un manque n'est pas bloquant : l'expédition constate un mouvement déjà
//    fait sur le terrain, refuser de l'enregistrer ne remettrait pas la
//    marchandise en rayon. Il est donc retourné pour être dit à l'écran et
//    dans l'audit — jamais absorbé en silence.
function ach_debiter_stock_magasin(int $article_id, int $quantite): array {
    if ($quantite <= 0) return ['debite' => 0, 'manquant' => 0, 'sites' => []];

    // Même filtre que ach_stock_magasin(), verrou posé sur les lignes de
    // stock : deux expéditions simultanées du même article ne doivent pas
    // lire le même disponible avant de le débiter chacune de leur côté.
    $sites = db_fetch_all(
        "SELECT ss.site_id, ss.quantite
           FROM stock_site ss
           JOIN sites s ON s.id = ss.site_id
          WHERE ss.article_id = ? AND s.type = 'magasin' AND s.actif = 1 AND ss.quantite > 0
          ORDER BY ss.quantite DESC, ss.site_id
          FOR UPDATE OF ss",
        [$article_id]
    );

    $reste = $quantite;
    $pris  = [];
    foreach ($sites as $s) {
        if ($reste <= 0) break;
        $q = min($reste, (int)$s['quantite']);
        db_query(
            "UPDATE stock_site SET quantite = quantite - ? WHERE article_id = ? AND site_id = ?",
            [$q, $article_id, (int)$s['site_id']]
        );
        $pris[(int)$s['site_id']] = $q;
        $reste -= $q;
    }

    return ['debite' => $quantite - $reste, 'manquant' => $reste, 'sites' => $pris];
}

// ── Crédite le stock d'un département — traçabilité de « qui détient quoi »
//    quand plusieurs départements partagent un même site (le siège, en
//    particulier). Purement informatif : n'entre jamais dans l'arbitrage
//    stock/achat, qui reste sur ach_stock_magasin().
function ach_crediter_stock_departement(int $article_id, int $departement_id, int $quantite): void {
    db_query(
        "INSERT INTO stock_departement (article_id, departement_id, quantite) VALUES (?,?,?)
         ON CONFLICT (article_id, departement_id) DO UPDATE SET quantite = stock_departement.quantite + ?, updated_at = CURRENT_TIMESTAMP",
        [$article_id, $departement_id, $quantite, $quantite]
    );
}

// ── Rattache (ou retire) une nomenclature à une ligne DAI — au plus tôt à
//    sa réception (pages/achats/receptions.php), jamais à la création de
//    la FEB : une ligne DAI n'a le plus souvent pas d'article_id (saisie
//    libre), et même quand elle en a un, ce n'est pas la même chose qu'une
//    nomenclature (referentiel equipements, distinct du référentiel achats
//    articles). Décision du 2026-08-20 : rattachement optionnel, sans lui
//    la ligne reste réceptionnable, elle ne produit simplement aucun
//    exemplaire equipements (cf. ach_receptionner()).
function ach_lier_nomenclature_ligne(int $feb_ligne_id, ?int $nomenclature_id, array $user): void {
    $ligne = db_fetch_one("SELECT * FROM feb_lignes WHERE id=?", [$feb_ligne_id]);
    if (!$ligne) throw new AchValidationException('Ligne introuvable.');
    if ($ligne['type_achat'] !== 'DAI') {
        throw new AchValidationException("Le rattachement à une nomenclature n'a de sens que pour une ligne DAI (immobilisation).");
    }
    if ($nomenclature_id) {
        $existe = db_fetch_value("SELECT 1 FROM nomenclatures WHERE id=?", [$nomenclature_id]);
        if (!$existe) throw new AchValidationException('Nomenclature introuvable.');
    }
    db_query("UPDATE feb_lignes SET nomenclature_id=? WHERE id=?", [$nomenclature_id ?: null, $feb_ligne_id]);
    audit_log((int)$user['id'], 'UPDATE', 'achats_suivi', $feb_ligne_id,
        $nomenclature_id ? "Nomenclature rattachée à la ligne « {$ligne['designation']} »" : "Rattachement nomenclature retiré sur la ligne « {$ligne['designation']} »");
}

// ── Exception de validation FEB — porte le champ fautif pour que l'écran
//    place le message à côté du champ concerné, pas dans une bannière
//    générale (cf. Bloc 4 de la spécification).
class AchValidationException extends Exception {
    public string $field;
    public function __construct(string $message, string $field = '') {
        parent::__construct($message);
        $this->field = $field;
    }
}

// ── Créer ou mettre à jour le brouillon d'une FEB, et éventuellement la
//    soumettre. Sur le modèle de di_creer() : une seule transaction,
//    en-tête puis lignes puis pièces jointes ; db_rollback() sur exception.
//
//    $feb_id   : null pour une création, id existant pour mettre à jour un
//                brouillon (l'appelant a déjà vérifié la propriété et le
//                statut brouillon — cette fonction ne le refait pas).
//    $entete   : ['site_id'=>?int, 'departement_id'=>?int, 'fonction'=>?string,
//                 'urgence'=>int, 'objet'=>string]
//    $lignes   : tableau de ['designation','article_id','quantite','unite',
//                 'famille_id','code_analytique','type_achat']
//    $soumettre: false = enregistrer le brouillon (contrôles allégés),
//                true  = soumettre (contrôles complets + numérotation).
//
//    Retourne ['id'=>int, 'numero'=>?string, 'statut'=>string].
// ── Code analytique composé — RG-05 : jamais saisi à la main, toujours
//    dérivé du site et du service portés par la FEB (RG-06) et de la
//    famille de la ligne. Chaîne vide si l'un des trois manque encore
//    (brouillon incomplet) — l'appelant décide s'il bloque ou tolère.
function ach_code_analytique(array $feb, int $famille_id): string {
    $site_id        = !empty($feb['site_id']) ? (int)$feb['site_id'] : 0;
    $departement_id = !empty($feb['departement_id']) ? (int)$feb['departement_id'] : 0;
    if (!$site_id || !$departement_id || !$famille_id) return '';

    $site_code = db_fetch_value("SELECT code FROM sites WHERE id=?", [$site_id]);
    $dept_code = db_fetch_value("SELECT code FROM departements WHERE id=?", [$departement_id]);
    $fam_code  = db_fetch_value("SELECT code FROM familles_achat WHERE id=?", [$famille_id]);
    if (!$site_code || !$dept_code || !$fam_code) return '';

    $norm = static fn(string $s): string => strtoupper(str_replace(' ', '', trim($s)));
    return $norm($site_code) . '/' . $norm($dept_code) . '/' . $norm($fam_code);
}

function ach_creer_feb(array $user, ?int $feb_id, array $entete, array $lignes, bool $soumettre): array {
    $uid = (int)$user['id'];

    $objet = trim($entete['objet'] ?? '');
    if ($objet === '') throw new AchValidationException("L'objet est obligatoire.", 'objet');
    if (mb_strlen($objet) > 255) throw new AchValidationException("L'objet est trop long (255 caractères maximum).", 'objet');

    $urgence = (int)($entete['urgence'] ?? 0);
    if (!array_key_exists($urgence, ach_urgences())) $urgence = 0;

    $site_id        = !empty($entete['site_id']) ? (int)$entete['site_id'] : null;
    $departement_id = !empty($entete['departement_id']) ? (int)$entete['departement_id'] : null;
    $fonction       = trim($entete['fonction'] ?? '') ?: null;

    // ── Lignes : nettoyage, puis contrôles qui s'appliquent à CHAQUE
    //    enregistrement (brouillon compris) — plafond de lignes et codes
    //    analytiques sont des limites structurelles de la FEB, pas des
    //    conditions de complétude qu'on tolère en brouillon.
    //    Le code analytique n'est jamais lu depuis $l (RG-05) : il est
    //    recomposé ici à partir du site/service de l'en-tête et de la
    //    famille de la ligne — aucune saisie libre ne peut plus l'atteindre.
    $max_lignes = (int)ach_param('max_lignes_feb', 14);
    $lignes_ok  = [];
    $familles_distinctes = [];
    $n = 0;
    foreach ($lignes as $l) {
        $designation = trim($l['designation'] ?? '');
        if ($designation === '') continue;  // ligne vide laissée de côté par l'utilisateur
        $n++;
        $quantite = (int)($l['quantite'] ?? 1);
        if ($quantite < 1) throw new AchValidationException("Quantité invalide à la ligne $n : elle doit être strictement positive.", "ligne_{$n}_quantite");

        $famille_id = !empty($l['famille_id']) ? (int)$l['famille_id'] : null;
        if ($famille_id && !in_array($famille_id, $familles_distinctes, true)) {
            $familles_distinctes[] = $famille_id;
        }
        $code = $famille_id ? ach_code_analytique(['site_id' => $site_id, 'departement_id' => $departement_id], $famille_id) : '';

        $lignes_ok[] = [
            'designation'     => $designation,
            'article_id'      => !empty($l['article_id']) ? (int)$l['article_id'] : null,
            'quantite'        => $quantite,
            'unite'           => trim($l['unite'] ?? '') ?: null,
            'famille_id'      => $famille_id,
            'code_analytique' => $code ?: null,
            'type_achat'      => trim($l['type_achat'] ?? '') ?: null,
        ];
    }
    if (count($lignes_ok) > $max_lignes) {
        throw new AchValidationException("Trop de lignes : $max_lignes maximum (paramètre « max_lignes_feb », modifiable dans Achats → Paramètres généraux).", 'lignes');
    }
    // RG-02 : trois codes analytiques au maximum par FEB. Le site et le
    // service étant constants sur toute la FEB (RG-06), deux lignes n'ont
    // de codes différents que si leurs familles diffèrent — la limite
    // devient donc, en pratique, une limite de trois familles distinctes.
    if (count($familles_distinctes) > 3) {
        throw new AchValidationException(
            'Trois familles distinctes au maximum par FEB (donc trois codes analytiques) — créez une seconde FEB pour la suite.',
            'lignes'
        );
    }

    // ── Contrôles supplémentaires, uniquement à la soumission — un
    //    brouillon reste volontairement tolérant sur la complétude.
    if ($soumettre) {
        if (count($lignes_ok) === 0) {
            throw new AchValidationException('Ajoutez au moins une ligne avant de soumettre.', 'lignes');
        }
        if (!$site_id || !$departement_id) {
            throw new AchValidationException('Site et service sont obligatoires : ils composent le code analytique de chaque ligne.', 'entete');
        }
        foreach ($lignes_ok as $i => $l) {
            $num = $i + 1;
            if (!$l['famille_id']) throw new AchValidationException("Famille obligatoire à la ligne $num.", "ligne_{$num}_famille");
            if (!$l['type_achat']) throw new AchValidationException("Type d'achat obligatoire à la ligne $num.", "ligne_{$num}_type");
        }
    }

    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        $now         = date('Y-m-d H:i:s');
        $exercice    = (int)date('Y');
        $nom         = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
        $creation    = ($feb_id === null);

        if ($creation) {
            db_query(
                "INSERT INTO feb (numero, exercice, demandeur_id, site_id, departement_id, fonction, urgence, objet, statut, montant_total, date_creation)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'brouillon', 0, ?)",
                [$exercice, $uid, $site_id, $departement_id, $fonction, $urgence, $objet, $now]
            );
            $feb_id = (int) db_last_id('feb_id_seq');
            audit_log($uid, 'CREATE', 'achats', $feb_id, "Création brouillon FEB — objet : $objet");
        } else {
            db_query(
                "UPDATE feb SET site_id=?, departement_id=?, fonction=?, urgence=?, objet=? WHERE id=?",
                [$site_id, $departement_id, $fonction, $urgence, $objet, $feb_id]
            );
            // Remplacement complet des lignes plutôt qu'un diff — même
            // approche que la restauration de brouillon de
            // point_journalier.php : plus simple, et c'est justement ce
            // chemin-là qui avait un historique de bugs sur ce projet.
            db_query("DELETE FROM feb_lignes WHERE feb_id=?", [$feb_id]);
            if (!$soumettre) {
                audit_log($uid, 'UPDATE', 'achats', $feb_id, "Modification brouillon FEB — objet : $objet");
            }
        }

        $num_ligne = 0;
        foreach ($lignes_ok as $l) {
            $num_ligne++;
            // lot = code_analytique (RG-08) : pas de second identifiant à
            // maintenir en parallèle, cf. ach_lots_feb().
            db_query(
                "INSERT INTO feb_lignes (feb_id, numero_ligne, designation, article_id, quantite, unite, famille_id, code_analytique, lot, type_achat)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$feb_id, $num_ligne, $l['designation'], $l['article_id'], $l['quantite'],
                 $l['unite'], $l['famille_id'], $l['code_analytique'], $l['code_analytique'], $l['type_achat']]
            );
        }

        $statut = 'brouillon';
        $numero = null;
        if ($soumettre) {
            $numero = ach_numero_feb($exercice);
            $montant_total = (int) db_fetch_value("SELECT COALESCE(SUM(montant_ttc),0) FROM feb_lignes WHERE feb_id=?", [$feb_id]);

            // Visa du supérieur hiérarchique AVANT prise en charge par les
            // achats (pas au lancement de la validation, cf. ach_lancer_validation()
            // qui ne le resollicite plus) : sans son aval, les achats ne
            // doivent même pas voir la demande. La personne est résolue et
            // figée sur feb.n1_user_id ici, pour la même raison que le reste
            // du figeage RG-11 — un changement de responsable en cours de
            // circuit ne doit pas déplacer une signature déjà attendue.
            $n1_user_id = $departement_id ? ach_n1_du_departement((int)$departement_id, $uid) : null;
            $statut_apres = $n1_user_id ? 'en_attente_n1' : 'soumise';

            db_query(
                "UPDATE feb SET numero=?, statut=?, date_soumission=?, montant_total=?, n1_user_id=? WHERE id=?",
                [$numero, $statut_apres, $now, $montant_total, $n1_user_id, $feb_id]
            );
            $statut = $statut_apres;
            audit_log($uid, 'UPDATE', 'achats', $feb_id, "Soumission FEB $numero");

            if ($n1_user_id) {
                db_query(
                    "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'FEB à endosser', ?, ?)",
                    [$n1_user_id, "FEB $numero soumise par $nom — $objet — en attente de votre aval.", '/pages/achats/mes_visas.php']
                );
            } else {
                // Pas de N+1 désigné pour ce département : rien à endosser,
                // la FEB va directement à la file d'attente Achats — type
                // 'info', seul type applicatif autorisé par la contrainte
                // d'énumération sur notifications.type.
                $achats = db_fetch_all(
                    "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                     WHERE r.slug='superviseur_achat' AND u.actif=1"
                );
                foreach ($achats as $a) {
                    db_query(
                        "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'Nouvelle FEB', ?, ?)",
                        [(int)$a['id'], "FEB $numero soumise par $nom — $objet", '/pages/achats/mes_feb.php']
                    );
                }
            }
        } else {
            $numero = db_fetch_value("SELECT numero FROM feb WHERE id=?", [$feb_id]);
        }

        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    return ['id' => $feb_id, 'numero' => $numero, 'statut' => $statut];
}

// ── Ancienneté en heures ouvrées, depuis une date de soumission — c'est
//    elle qui pilote la priorité dans la file d'attente Achats (Bloc 1),
//    pas la date brute : une FEB soumise vendredi 17h n'attend « que » 1h
//    ouvrée lundi 9h, pas 64h d'horloge murale.
//    Fenêtre fixe 8h-18h, du lundi au vendredi : aucun paramètre dédié
//    n'existe dans achat_parametres pour la faire varier.
function ach_anciennete_heures_ouvrees(string $depuis): float {
    $debut_h = 8;
    $fin_h   = 18;
    try {
        $debut = new DateTime($depuis);
    } catch (Exception $e) {
        return 0.0;
    }
    $fin = new DateTime();
    if ($debut >= $fin) return 0.0;

    $total = 0.0;
    $jour    = (clone $debut)->setTime(0, 0, 0);
    $dernier = (clone $fin)->setTime(0, 0, 0);
    while ($jour <= $dernier) {
        if ((int)$jour->format('N') <= 5) {  // 1 (lundi) à 5 (vendredi)
            $fenetre_debut = (clone $jour)->setTime($debut_h, 0, 0);
            $fenetre_fin   = (clone $jour)->setTime($fin_h, 0, 0);
            $bloc_debut = max($fenetre_debut, $debut);
            $bloc_fin   = min($fenetre_fin, $fin);
            if ($bloc_fin > $bloc_debut) {
                $total += ($bloc_fin->getTimestamp() - $bloc_debut->getTimestamp()) / 3600;
            }
        }
        $jour->modify('+1 day');
    }
    return round($total, 1);
}

// ── Prise en charge exclusive d'une FEB par un acheteur ──────
//    Le verrou est l'UPDATE conditionnel lui-même : WHERE acheteur_id IS
//    NULL AND statut='soumise' rend la course impossible par construction,
//    sans colonne de verrou ni verrou consultatif. Un SELECT puis un UPDATE
//    séparés laisseraient une fenêtre ouverte entre les deux — c'est
//    justement ce que ce test doit exclure (Bloc 5, point 35).
function ach_prendre_en_charge(int $feb_id, array $user): bool {
    // Seul le service Achats prend en charge (étape 2 du processus).
    // Contrôlé ici en plus de l'écran : cette fonction est le point d'entrée
    // de toute la suite du traitement — quiconque devient acheteur_id peut
    // ensuite arbitrer, saisir des offres et lancer la validation.
    if (!ach_est_acheteur($user)) {
        throw new AchValidationException("Seul le service Achats peut prendre une FEB en charge.");
    }
    $uid = (int)$user['id'];
    $now = date('Y-m-d H:i:s');

    $stmt = db_query(
        "UPDATE feb SET acheteur_id=?, date_prise_charge=?, statut='prise_en_charge'
          WHERE id=? AND acheteur_id IS NULL AND statut='soumise'",
        [$uid, $now, $feb_id]
    );
    if ($stmt->rowCount() === 0) return false;

    audit_log($uid, 'UPDATE', 'achats', $feb_id, 'Prise en charge de la FEB');

    $feb = db_fetch_one("SELECT numero, demandeur_id FROM feb WHERE id=?", [$feb_id]);
    if ($feb && $feb['demandeur_id']) {
        $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
        db_query(
            "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'FEB prise en charge', ?, ?)",
            [(int)$feb['demandeur_id'], "Votre FEB {$feb['numero']} est prise en charge par $nom.", '/pages/achats/mes_feb.php']
        );
    }
    return true;
}

// ── Restitution : l'acheteur qui détient la FEB la relâche — retour en
//    soumise, acheteur_id et date_prise_charge effacés, pour repasser dans
//    la section « à prendre en charge » de la file d'attente.
function ach_restituer_feb(int $feb_id, array $user): bool {
    $uid = (int)$user['id'];

    $stmt = db_query(
        "UPDATE feb SET acheteur_id=NULL, date_prise_charge=NULL, statut='soumise'
          WHERE id=? AND acheteur_id=? AND statut='prise_en_charge'",
        [$feb_id, $uid]
    );
    if ($stmt->rowCount() === 0) return false;

    audit_log($uid, 'UPDATE', 'achats', $feb_id, 'Restitution de la FEB');
    return true;
}

// ── Réattribution par un administrateur d'une FEB déjà prise en charge —
//    pour les cas d'absence de l'acheteur titulaire. Réservé à l'appelant
//    (contrôle du rôle admin/superadmin fait par la page, pas ici).
function ach_reattribuer_feb(int $feb_id, int $nouvel_acheteur_id, array $user): bool {
    $uid = (int)$user['id'];
    $now = date('Y-m-d H:i:s');

    $stmt = db_query(
        "UPDATE feb SET acheteur_id=?, date_prise_charge=?
          WHERE id=? AND statut='prise_en_charge'",
        [$nouvel_acheteur_id, $now, $feb_id]
    );
    if ($stmt->rowCount() === 0) return false;

    audit_log($uid, 'UPDATE', 'achats', $feb_id, "Réattribution de la FEB à l'utilisateur #$nouvel_acheteur_id");
    return true;
}

// ── Bascule des lignes arbitrées « stock » vers une commande interne.
//    Une seule transaction : en-tête commande puis lignes, db_rollback()
//    sur exception — sur le modèle de ach_creer_feb().
//    Retourne l'id de la commande créée, ou lève une AchValidationException
//    si rien n'est basculable (droits, statut, garde-fou, aucune ligne
//    « stock »).
function ach_basculer_vers_commande(int $feb_id, array $user): ?int {
    $uid = (int)$user['id'];

    $feb = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$feb) throw new AchValidationException('FEB introuvable.');
    if ((int)$feb['acheteur_id'] !== $uid) throw new AchValidationException("Cette FEB n'est pas prise en charge par vous.");
    if ($feb['statut'] !== 'prise_en_charge') throw new AchValidationException("Cette FEB n'est plus en cours de traitement.");
    if (!$feb['site_id']) throw new AchValidationException('Impossible de créer la commande : aucun site renseigné sur la FEB.');

    // Garde-fou : une FEB ne peut être basculée qu'une fois — contrôle sur
    // l'existence d'une commandes portant déjà ce feb_id.
    $deja = db_fetch_value("SELECT id FROM commandes WHERE feb_id=?", [$feb_id]);
    if ($deja) throw new AchValidationException('Cette FEB a déjà été basculée vers une commande interne.');

    // article_id IS NOT NULL par sécurité redondante : une ligne en saisie
    // libre n'est jamais arbitrable côté écran, mais ne doit jamais pouvoir
    // atteindre ce point si un choix « stock » s'y glissait quand même.
    $lignes_stock = db_fetch_all(
        "SELECT * FROM feb_lignes WHERE feb_id=? AND arbitrage='stock' AND article_id IS NOT NULL ORDER BY numero_ligne",
        [$feb_id]
    );
    if (!$lignes_stock) throw new AchValidationException("Aucune ligne arbitrée « stock » à basculer.");

    $demandeur     = $feb['demandeur_id'] ? db_fetch_one("SELECT prenom, nom FROM users WHERE id=?", [$feb['demandeur_id']]) : null;
    $nom_demandeur = trim(($demandeur['prenom'] ?? '') . ' ' . ($demandeur['nom'] ?? ''));

    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        $num   = ach_numero_commande();
        $notes = "Issue de la FEB {$feb['numero']} — demandeur $nom_demandeur";
        db_query(
            "INSERT INTO commandes (numero_commande, site_id, statut, notes, created_by, feb_id) VALUES (?,?,'en_attente',?,?,?)",
            [$num, $feb['site_id'], $notes, $uid, $feb_id]
        );
        $cmd_id = (int) db_last_id();

        foreach ($lignes_stock as $l) {
            $article = db_fetch_one("SELECT type_article, unite FROM articles WHERE id=?", [$l['article_id']]);
            db_query(
                "INSERT INTO commande_lignes (commande_id, type_article, article_id, libelle, quantite, unite, statut_ligne, feb_ligne_id)
                 VALUES (?,?,?,?,?,?,'en_attente',?)",
                [$cmd_id, $article['type_article'] ?? 'article', $l['article_id'], $l['designation'], $l['quantite'],
                 $l['unite'] ?: ($article['unite'] ?? 'unité'), $l['id']]
            );
        }

        // FEB entièrement servie sur stock ? Aucune ligne restée en 'achat'
        // → clôture ; sinon la FEB reste en prise_en_charge et poursuit son
        // chemin sur les seules lignes concernées (Bloc suivant, hors de ce
        // lot).
        $reste_achat = (int) db_fetch_value(
            "SELECT COUNT(*) FROM feb_lignes WHERE feb_id=? AND arbitrage='achat'", [$feb_id]
        );
        if ($reste_achat === 0) {
            db_query("UPDATE feb SET statut='cloturee', date_cloture=NOW() WHERE id=?", [$feb_id]);
        }

        audit_log($uid, 'UPDATE', 'achats', $feb_id, "Bascule vers commande interne $num — " . count($lignes_stock) . ' ligne(s)');

        if ($feb['demandeur_id']) {
            $msg = $reste_achat === 0
                ? "Votre FEB {$feb['numero']} est servie sur stock — commande $num."
                : "Votre FEB {$feb['numero']} est partiellement servie sur stock — commande $num.";
            db_query(
                "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'FEB servie sur stock', ?, ?)",
                [(int)$feb['demandeur_id'], $msg, '/pages/commandes.php']
            );
        }

        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    return $cmd_id;
}

// ── Lots d'une FEB, pour le comparatif d'offres (pages/achats/feb_traitement.php).
//    Un lot = un code analytique (RG-08, cf. ach_creer_feb()) : pas de
//    second identifiant à maintenir en parallèle, le regroupement se fait
//    directement sur feb_lignes.lot.
//    Les lignes arbitrées « stock » en sortent : on ne demande pas d'offre
//    pour ce qu'on ne va pas acheter — un lot entièrement servi sur stock
//    n'apparaît donc pas dans le résultat.
function ach_lots_feb(int $feb_id): array {
    $lignes = db_fetch_all(
        "SELECT * FROM feb_lignes
          WHERE feb_id=? AND lot IS NOT NULL AND lot <> '' AND arbitrage <> 'stock'
          ORDER BY lot, numero_ligne",
        [$feb_id]
    );
    $lots = [];
    foreach ($lignes as $l) {
        $lot = $l['lot'];
        if (!isset($lots[$lot])) {
            $lots[$lot] = ['lot' => $lot, 'lignes' => [], 'nb_articles' => 0, 'somme_quantites' => 0];
        }
        $lots[$lot]['lignes'][]         = $l;
        $lots[$lot]['nb_articles']     += 1;
        $lots[$lot]['somme_quantites'] += (int)$l['quantite'];
    }
    return array_values($lots);
}

// ── Recalcule feb.montant_total à partir des lignes — appelé à chaque
//    changement d'offre retenue ou de montant de ligne (point 26).
function ach_recalculer_montant_total(int $feb_id): void {
    $total = (int) db_fetch_value("SELECT COALESCE(SUM(montant_ttc),0) FROM feb_lignes WHERE feb_id=?", [$feb_id]);
    db_query("UPDATE feb SET montant_total=? WHERE id=?", [$total, $feb_id]);
}

// ── Retient une offre pour son lot — une seule transaction :
//    1) une seule offre retenue par lot (remise à faux des autres, jamais
//       un simple UPDATE ciblé qui laisserait une ancienne retenue en place) ;
//    2) report du fournisseur retenu sur les lignes du lot, sauf celles
//       marquées en dérogation (RG-09 : le fournisseur retenu peut différer
//       d'une ligne à l'autre, une dérogation manuelle ne doit jamais être
//       écrasée par un report en masse) ;
//    3) audit de l'ancienne et de la nouvelle offre retenue (autorisé tant
//       que la FEB reste en prise_en_charge — même contrôle que le reste de
//       feb_traitement.php, refait ici pour rester utilisable isolément).
// ── Pièces obligatoires du dossier de conformité fournisseur.
//    Doit rester aligné sur FOURN_DOCS (pages/achats/param_fournisseurs.php),
//    qui porte la même liste pour le formulaire de saisie — la règle métier
//    vit ici parce qu'elle est appliquée hors de cet écran.
const ACH_DOCS_OBLIGATOIRES = [
    'doc_rccm' => 'RCCM',
    'doc_dfe'  => 'DFE / identification fiscale',
    'doc_rib'  => 'RIB / attestation bancaire',
    'doc_pirl' => "PIRL / pièce d'identité du représentant légal",
];

// ── Un marché ne peut être attribué qu'à un fournisseur au dossier complet.
//    Décision du 18/08. La règle ne porte que sur l'attribution : saisir et
//    comparer les offres d'un fournisseur incomplet reste permis, c'est le
//    moment de retenir l'une d'elles qui est bloqué — sinon l'acheteur ne
//    pourrait plus consulter le marché avant d'avoir réuni les pièces.
function ach_fournisseur_conforme(int $fournisseur_id): array {
    $f = db_fetch_one("SELECT raison_sociale, " . implode(', ', array_keys(ACH_DOCS_OBLIGATOIRES))
                    . " FROM fournisseurs WHERE id=?", [$fournisseur_id]);
    if (!$f) return ['ok' => false, 'nom' => "#$fournisseur_id", 'manquants' => ['fournisseur introuvable']];

    $manquants = [];
    foreach (ACH_DOCS_OBLIGATOIRES as $col => $label) {
        if (trim((string)($f[$col] ?? '')) === '') $manquants[] = $label;
    }
    return ['ok' => !$manquants, 'nom' => $f['raison_sociale'], 'manquants' => $manquants];
}

// ── Lève une exception nommant les pièces qui manquent — l'acheteur doit
//    savoir quoi réclamer, pas seulement que c'est refusé.
function ach_exiger_fournisseur_conforme(int $fournisseur_id): void {
    $c = ach_fournisseur_conforme($fournisseur_id);
    if ($c['ok']) return;
    throw new AchValidationException(
        "Dossier de conformité incomplet pour « {$c['nom']} » — pièce(s) manquante(s) : "
        . implode(', ', $c['manquants'])
        . ". Complétez le dossier dans Achats → Fournisseurs avant de lui attribuer le marché."
    );
}

function ach_retenir_offre_lot(int $offre_id, array $user): array {
    $uid = (int)$user['id'];

    $offre = db_fetch_one("SELECT * FROM feb_offres WHERE id=?", [$offre_id]);
    if (!$offre) throw new AchValidationException('Offre introuvable.');
    $feb_id = (int)$offre['feb_id'];
    $lot    = $offre['lot'];

    $feb = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$feb || (int)$feb['acheteur_id'] !== $uid || $feb['statut'] !== 'prise_en_charge') {
        throw new AchValidationException("Cette FEB n'est pas (ou plus) en cours de traitement par vous.");
    }

    // Retenir une offre, c'est attribuer le marché — le dossier du
    // fournisseur doit être complet à cet instant.
    ach_exiger_fournisseur_conforme((int)$offre['fournisseur_id']);

    $ancienne = db_fetch_one("SELECT id, fournisseur_id FROM feb_offres WHERE feb_id=? AND lot=? AND retenue=1", [$feb_id, $lot]);

    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        db_query("UPDATE feb_offres SET retenue=0 WHERE feb_id=? AND lot=?", [$feb_id, $lot]);
        db_query("UPDATE feb_offres SET retenue=1 WHERE id=?", [$offre_id]);
        // RG-09 : une ligne dérogée garde son fournisseur choisi à la main.
        // On compte les deux populations — l'appelant doit pouvoir dire la
        // vérité à l'écran, y compris « reporté sur 0 ligne ».
        //
        // Le montant de l'offre porte sur tout le lot, jamais ligne par
        // ligne (feb_offres n'a qu'un seul montant_ttc) : il est réparti au
        // prorata des quantités sur les lignes non dérogées, le reliquat
        // d'arrondi posé sur la dernière — ach_verifier_comparatif() exige
        // une somme des lignes strictement égale au montant de l'offre, donc
        // un simple report proportionnel arrondi laisserait échouer ce
        // contrôle par un XOF ici ou là sans cette dernière ligne de rattrapage.
        $lignes_a_reporter = db_fetch_all(
            "SELECT id, quantite FROM feb_lignes WHERE feb_id=? AND lot=? AND fournisseur_derogation=0 ORDER BY numero_ligne",
            [$feb_id, $lot]
        );
        $reportees = count($lignes_a_reporter);
        if ($lignes_a_reporter) {
            $total_qte = array_sum(array_column($lignes_a_reporter, 'quantite')) ?: 1;
            $montant_offre = (int)$offre['montant_ttc'];
            $reparti = 0;
            foreach ($lignes_a_reporter as $i => $l) {
                $dernier = ($i === count($lignes_a_reporter) - 1);
                $part = $dernier ? ($montant_offre - $reparti) : (int) round($montant_offre * ((int)$l['quantite'] / $total_qte));
                $reparti += $part;
                db_query(
                    "UPDATE feb_lignes SET fournisseur_id=?, montant_ttc=? WHERE id=?",
                    [$offre['fournisseur_id'], $part, $l['id']]
                );
            }
        }
        $derogees  = (int) db_fetch_value(
            "SELECT COUNT(*) FROM feb_lignes WHERE feb_id=? AND lot=? AND fournisseur_derogation=1",
            [$feb_id, $lot]
        );
        ach_recalculer_montant_total($feb_id);

        $msg = $ancienne
            ? "Changement d'offre retenue sur le lot $lot : offre #{$ancienne['id']} → offre #$offre_id"
            : "Offre retenue sur le lot $lot : offre #$offre_id";
        audit_log($uid, 'UPDATE', 'achats', $feb_id, $msg);

        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    return ['reportees' => $reportees, 'derogees' => $derogees];
}

// ── Vérifie la cohérence du comparatif avant de poursuivre : par lot ayant
//    une offre retenue, la somme des montants de ligne doit égaler le
//    montant de l'offre (point 36) ; par ligne, un type d'achat doit être
//    renseigné (Bloc 4, point 29). Ne modifie rien — laisse l'appelant
//    décider quoi faire du résultat.
function ach_verifier_comparatif(int $feb_id): array {
    foreach (ach_lots_feb($feb_id) as $lot) {
        $offre = db_fetch_one("SELECT * FROM feb_offres WHERE feb_id=? AND lot=? AND retenue=1", [$feb_id, $lot['lot']]);
        // Un lot arbitré « achat » sans aucune offre retenue laissait passer
        // la validation avec des lignes à 0 XOF et sans fournisseur (montant
        // jamais réconcilié faute d'offre à comparer) — désormais bloquant,
        // pas seulement vérifié quand une offre existe déjà.
        if (!$offre) {
            return ['ok' => false, 'message' =>
                "Lot {$lot['lot']} : aucune offre retenue — comparez au moins une offre fournisseur avant de poursuivre."];
        }
        // RG-09 : une ligne dérogée suit son propre fournisseur/montant, pas
        // celui de l'offre retenue du lot — l'exclure de ce total, sinon
        // toute dérogation fait mécaniquement échouer le comparatif dès que
        // son montant diffère de sa part dans l'offre (signalé en recette,
        // lot ADM/OPERATION/FOURN_BUR : 427 000 XOF de lignes contre 2 000
        // XOF d'offre DMD, la ligne dérogée à 425 000 XOF chez INFOSOLUCES
        // comptée à tort dans la comparaison).
        $lignes_suivies  = array_filter($lot['lignes'], fn($l) => !(int)$l['fournisseur_derogation']);
        $lignes_derogees = array_filter($lot['lignes'], fn($l) => (int)$l['fournisseur_derogation']);
        $somme = array_sum(array_map(fn($l) => (int)$l['montant_ttc'], $lignes_suivies));
        if ($somme !== (int)$offre['montant_ttc']) {
            return ['ok' => false, 'message' =>
                "Lot {$lot['lot']} : la somme des lignes suivant l'offre retenue ($somme XOF) ne correspond pas au montant de cette offre ({$offre['montant_ttc']} XOF)."];
        }
        // Une ligne dérogée reste remise à zéro (cf. deroger_fournisseur_ligne)
        // tant que personne n'a saisi le prix réellement proposé — sans ce
        // contrôle, elle serait exclue de la somme ci-dessus ET jamais
        // vérifiée nulle part ailleurs.
        foreach ($lignes_derogees as $l) {
            if ((int)$l['montant_ttc'] <= 0) {
                return ['ok' => false, 'message' =>
                    "La ligne « {$l['designation']} » (lot {$lot['lot']}) est dérogée mais son montant n'a pas été saisi."];
            }
        }
        foreach ($lot['lignes'] as $l) {
            if (!$l['type_achat']) {
                return ['ok' => false, 'message' => "La ligne « {$l['designation']} » (lot {$lot['lot']}) n'a pas de type d'achat."];
            }
        }
    }
    return ['ok' => true, 'message' => 'Comparatif vérifié : tout est cohérent.'];
}

// ── Décode les colonnes JSON d'une FEB portant le circuit — même rôle que
//    di_get() pour di_demandes, nécessaire car workflow_snapshot/signatures/
//    historique reviennent en texte JSON brut de PDO (jsonb côté colonne,
//    mais PDO ne décode jamais lui-même).
function ach_feb_decode(array $feb): array {
    foreach (['workflow_snapshot', 'signatures', 'historique'] as $k) {
        $feb[$k] = json_decode($feb[$k] ?? 'null', true) ?? [];
    }
    return $feb;
}

// ── Notifie tous les porteurs d'un code d'étape — même logique que
//    di_notify_role() (rôles ERP mappés + membres du département lié dans
//    di_roles), mais avec un lien vers l'écran de visas Achats : di_notify()
//    pointe en dur vers /pages/demandes.php, impropre ici.
function ach_notifier_role(string $roleCode, string $message, ?int $febId = null): void {
    $lien = '/pages/achats/mes_visas.php';
    $notified = [];
    foreach (db_fetch_all("SELECT id FROM users WHERE actif=1") as $u) {
        $uid = (int)$u['id'];
        if (in_array($roleCode, di_user_roles($uid), true)) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Visa FEB',?,?)", [$uid, $message, $lien]);
            $notified[] = $uid;
        }
    }
    $dept_id = db_fetch_value("SELECT departement_id FROM di_roles WHERE code=?", [$roleCode]);
    if ($dept_id) {
        foreach (db_fetch_all("SELECT user_id FROM user_departements WHERE departement_id=?", [(int)$dept_id]) as $u) {
            $uid = (int)$u['user_id'];
            if (!in_array($uid, $notified, true)) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Visa FEB',?,?)", [$uid, $message, $lien]);
                $notified[] = $uid;
            }
        }
    }
}

// ── Comptes SYSCOHADA connus (classes 60-65 et 24) — seeded par
//    migration_achats_08_syscohada.sql. Sert uniquement à l'avertissement
//    (non bloquant) de pages/achats/param_familles.php : un compte hors
//    liste n'empêche pas l'enregistrement, il est juste signalé.
function ach_comptes_syscohada_connus(): array {
    return array_column(db_fetch_all("SELECT DISTINCT compte_comptable FROM familles_achat WHERE compte_comptable IS NOT NULL"), 'compte_comptable');
}

// ── Situation budgétaire d'un couple département/famille pour un exercice
//    (Bloc 2). feb.departement_id filtre directement : il est constant sur
//    la FEB (un seul département, celui du demandeur — Bloc 0, point 1),
//    aucune jointure supplémentaire n'est nécessaire.
//    $exclure_feb_id : ignore les lignes de cette FEB dans le total — utile
//    pour afficher « la place disponible avant cette FEB », notamment sur
//    mes_visas.php (Bloc 5) où la FEB en cours de visa ne doit pas se
//    compter elle-même dans l'engagement affiché au signataire.
function ach_budget_situation(int $departement_id, int $famille_id, int $exercice, ?int $exclure_feb_id = null): array {
    $params = [$departement_id, $famille_id, $exercice];
    $exclude_sql = '';
    if ($exclure_feb_id) { $exclude_sql = ' AND f.id != ?'; $params[] = $exclure_feb_id; }

    $row = db_fetch_one(
        "SELECT
            COALESCE(SUM(CASE WHEN f.statut='confirmee'     THEN fl.montant_ttc ELSE 0 END),0) AS engage,
            COALESCE(SUM(CASE WHEN f.statut='en_validation' THEN fl.montant_ttc ELSE 0 END),0) AS reserve
         FROM feb_lignes fl
         JOIN feb f ON f.id = fl.feb_id
         WHERE f.departement_id=? AND fl.famille_id=? AND f.exercice=? AND fl.arbitrage='achat'
           AND f.statut IN ('confirmee','en_validation') $exclude_sql",
        $params
    );
    $engage  = (int)$row['engage'];
    $reserve = (int)$row['reserve'];

    // Rejet ou réouverture (ach_reprendre_feb_rejetee / ach_admin_reouvrir_validation)
    // font sortir la FEB de ('confirmee','en_validation') : la libération est
    // gratuite, portée par ce seul filtre de statut — rien d'autre à écrire.
    $ligne = db_fetch_one(
        "SELECT * FROM lignes_budgetaires WHERE departement_id=? AND famille_id=? AND exercice=? AND actif=1",
        [$departement_id, $famille_id, $exercice]
    );
    $enveloppe = ($ligne && $ligne['enveloppe'] !== null) ? (int)$ligne['enveloppe'] : null;

    return [
        'departement_id' => $departement_id, 'famille_id' => $famille_id, 'exercice' => $exercice,
        'engage' => $engage, 'reserve' => $reserve, 'total_engage' => $engage + $reserve,
        'ligne_budgetaire' => $ligne, 'enveloppe' => $enveloppe,
        'disponible' => $enveloppe !== null ? $enveloppe - ($engage + $reserve) : null,
    ];
}

// ── Contrôle budgétaire d'une FEB, famille par famille, contre le budget
//    de SON département (Bloc 3). Appelée à deux points seulement :
//    lancement de la validation et dernière signature (point 19) — jamais
//    aux étapes intermédiaires.
//    Verrou explicite (SELECT ... FOR UPDATE) sur chaque ligne budgétaire
//    concernée : sans lui, deux FEB lancées au même instant sur la même
//    enveloppe peuvent toutes deux lire un engagement encore bas et passer
//    ensemble au-delà du plafond. Le verrou n'a d'effet que tenu dans la
//    même transaction que le changement de statut — c'est aux appelants
//    (ach_lancer_validation, ach_viser) de l'ouvrir avant d'appeler cette
//    fonction et de la fermer juste après l'UPDATE sur feb.
//    Lève une AchValidationException en cas de dépassement bloquant ;
//    retourne la liste des avertissements (dépassements en mode "alerte",
//    ou familles sans ligne budgétaire — point 17) sinon.
//
// ── Cycle de vie du budget par département × exercice — retour observations
//    spec (2026-08-17). RAF/DAF saisissent les lignes (brouillon) et
//    soumettent ; le PDG valide. La validation EST la clôture : un seul
//    geste verrouille les lignes et en fait la référence de contrôle —
//    pas d'étape de clôture séparée (décision explicite de l'utilisateur).
//    Un couple département/exercice sans ligne dans budget_validations est
//    en brouillon implicite : évite de devoir pré-créer une ligne de suivi
//    pour chaque département avant même la première saisie.
function ach_budget_validation(int $departement_id, int $exercice): array {
    $row = db_fetch_one(
        "SELECT * FROM budget_validations WHERE departement_id=? AND exercice=?",
        [$departement_id, $exercice]
    );
    return $row ?: [
        'departement_id' => $departement_id, 'exercice' => $exercice, 'statut' => 'brouillon',
        'soumis_par' => null, 'soumis_le' => null, 'valide_par' => null, 'valide_le' => null,
        'motif_rejet' => null, 'rejete_par' => null, 'rejete_le' => null,
    ];
}

// ── Une ligne budgétaire n'est modifiable/désactivable que tant que le
//    budget de son département/exercice n'a pas été soumis ou validé.
function ach_budget_editable(int $departement_id, int $exercice): bool {
    return in_array(ach_budget_validation($departement_id, $exercice)['statut'], ['brouillon', 'rejete'], true);
}

// ── Qui propose (saisit/soumet) un budget — RAF/DAF nouvellement admis
//    pour cette seule tâche, plus les rôles qui géraient déjà achats_param.
function ach_est_proposant_budget(?array $user = null): bool {
    $user = $user ?? current_user();
    return in_array($user['role_slug'] ?? '', ['raf', 'daf', 'admin', 'superadmin', 'superviseur_achat'], true);
}

// ── Qui valide (verrouille) un budget soumis — le PDG, rôle technique
//    'lecteur' (cf. migration_achats_03_permissions.sql), plus admin en
//    secours. Jamais via can_update sur achats_param : un lecteur ne
//    dépose/ne modifie jamais rien directement (ach_peut_creer()), la
//    validation est une action métier distincte, pas une écriture libre.
function ach_est_validateur_budget(?array $user = null): bool {
    $user = $user ?? current_user();
    return in_array($user['role_slug'] ?? '', ['lecteur', 'admin', 'superadmin'], true);
}

function ach_soumettre_budget(int $departement_id, int $exercice, array $user): void {
    if (!ach_est_proposant_budget($user)) throw new AchValidationException("Vous n'êtes pas habilité à soumettre un budget.");
    $depts = ach_perimetre_departements($user);
    if (is_array($depts) && !in_array($departement_id, $depts, true)) {
        throw new AchValidationException("Ce département n'est pas dans votre périmètre.");
    }
    $v = ach_budget_validation($departement_id, $exercice);
    if (!in_array($v['statut'], ['brouillon', 'rejete'], true)) {
        throw new AchValidationException('Ce budget est déjà soumis ou validé.');
    }
    $nb = (int) db_fetch_value(
        "SELECT COUNT(*) FROM lignes_budgetaires WHERE departement_id=? AND exercice=? AND actif=1",
        [$departement_id, $exercice]
    );
    if ($nb === 0) throw new AchValidationException('Aucune ligne budgétaire active à soumettre pour ce département et cet exercice.');

    // Le statut est revérifié dans le DO UPDATE, pas seulement plus haut :
    // entre la lecture et l'écriture, le PDG a pu valider. Sans ce garde,
    // une soumission concurrente ramenait un budget validé à « soumis ».
    // Même motif conditionnel que ach_prendre_en_charge() et ach_viser().
    $st = db_query(
        "INSERT INTO budget_validations (departement_id, exercice, statut, soumis_par, soumis_le)
         VALUES (?,?,'soumis',?,NOW())
         ON CONFLICT (departement_id, exercice) DO UPDATE SET
             statut = 'soumis', soumis_par = ?, soumis_le = NOW(), motif_rejet = NULL
         WHERE budget_validations.statut IN ('brouillon', 'rejete')",
        [$departement_id, $exercice, (int)$user['id'], (int)$user['id']]
    );
    if ($st->rowCount() === 0) {
        throw new AchValidationException("Ce budget vient d'être traité par quelqu'un d'autre — rechargez l'écran.");
    }
    audit_log((int)$user['id'], 'UPDATE', 'achats_param', $departement_id,
        "Budget soumis pour validation — département #$departement_id, exercice $exercice");

    $dept_label = db_fetch_value("SELECT label FROM departements WHERE id=?", [$departement_id]);
    foreach (db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug='lecteur' AND u.actif=1") as $pdg) {
        db_query(
            "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'Budget à valider', ?, ?)",
            [(int)$pdg['id'], "Budget $exercice de $dept_label soumis à votre validation.", '/pages/achats/param_budget.php?exercice=' . $exercice]
        );
    }
}

function ach_valider_budget(int $departement_id, int $exercice, array $user): void {
    if (!ach_est_validateur_budget($user)) throw new AchValidationException("Vous n'êtes pas habilité à valider un budget.");
    $v = ach_budget_validation($departement_id, $exercice);
    if ($v['statut'] !== 'soumis') throw new AchValidationException("Ce budget n'est pas en attente de validation.");

    // statut='soumis' repris dans le WHERE : le contrôle ci-dessus a lu un
    // état qui a pu changer depuis. Une validation et un rejet lancés en
    // même temps passaient tous les deux, avec deux notifications
    // contradictoires au proposant et un état final tiré au sort par
    // l'ordre de commit.
    $st = db_query(
        "UPDATE budget_validations SET statut='valide', valide_par=?, valide_le=NOW()
          WHERE departement_id=? AND exercice=? AND statut='soumis'",
        [(int)$user['id'], $departement_id, $exercice]
    );
    if ($st->rowCount() === 0) {
        throw new AchValidationException("Ce budget vient d'être traité par quelqu'un d'autre — rechargez l'écran.");
    }
    audit_log((int)$user['id'], 'UPDATE', 'achats_param', $departement_id,
        "Budget validé et verrouillé — département #$departement_id, exercice $exercice");

    if ($v['soumis_par']) {
        $dept_label = db_fetch_value("SELECT label FROM departements WHERE id=?", [$departement_id]);
        db_query(
            "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'Budget validé', ?, ?)",
            [(int)$v['soumis_par'], "Le budget $exercice de $dept_label est validé et verrouillé.", '/pages/achats/param_budget.php?exercice=' . $exercice]
        );
    }
}

function ach_rejeter_budget(int $departement_id, int $exercice, string $motif, array $user): void {
    if (!ach_est_validateur_budget($user)) throw new AchValidationException("Vous n'êtes pas habilité à rejeter un budget.");
    $motif = trim($motif);
    if ($motif === '') throw new AchValidationException('Le motif de rejet est obligatoire.');
    $v = ach_budget_validation($departement_id, $exercice);
    if ($v['statut'] !== 'soumis') throw new AchValidationException("Ce budget n'est pas en attente de validation.");

    // Même garde conditionnel qu'à la validation — cf. ach_valider_budget().
    $st = db_query(
        "UPDATE budget_validations SET statut='rejete', motif_rejet=?, rejete_par=?, rejete_le=NOW()
          WHERE departement_id=? AND exercice=? AND statut='soumis'",
        [$motif, (int)$user['id'], $departement_id, $exercice]
    );
    if ($st->rowCount() === 0) {
        throw new AchValidationException("Ce budget vient d'être traité par quelqu'un d'autre — rechargez l'écran.");
    }
    audit_log((int)$user['id'], 'UPDATE', 'achats_param', $departement_id,
        "Budget rejeté — département #$departement_id, exercice $exercice : $motif");

    if ($v['soumis_par']) {
        $dept_label = db_fetch_value("SELECT label FROM departements WHERE id=?", [$departement_id]);
        db_query(
            "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'Budget rejeté', ?, ?)",
            [(int)$v['soumis_par'], "Le budget $exercice de $dept_label est rejeté : $motif", '/pages/achats/param_budget.php?exercice=' . $exercice]
        );
    }
}

// ── Réouverture administrative d'un budget déjà validé — même logique
//    d'exception que ach_admin_reouvrir_validation() pour les FEB : porte
//    de sortie tracée pour corriger une erreur après coup, réservée à
//    l'administration, jamais un simple retour arrière silencieux.
function ach_admin_reouvrir_budget(int $departement_id, int $exercice, array $user): void {
    if (!in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true)) {
        throw new AchValidationException('Réservé à un administrateur.');
    }
    $v = ach_budget_validation($departement_id, $exercice);
    if ($v['statut'] !== 'valide') throw new AchValidationException("Ce budget n'est pas verrouillé.");

    db_query("UPDATE budget_validations SET statut='brouillon' WHERE departement_id=? AND exercice=?", [$departement_id, $exercice]);
    audit_log((int)$user['id'], 'UPDATE', 'achats_param', $departement_id,
        "Réouverture administrative d'un budget validé — département #$departement_id, exercice $exercice");
}

function ach_controle_budget(int $feb_id): array {
    $feb = db_fetch_one("SELECT departement_id, exercice, numero FROM feb WHERE id=?", [$feb_id]);
    if (!$feb || !$feb['departement_id']) return ['avertissements' => []];
    $dept_id  = (int)$feb['departement_id'];
    $exercice = (int)$feb['exercice'];

    // Référence de contrôle = le budget VALIDÉ (verrouillé par le PDG), pas
    // un brouillon ou une soumission encore modifiable — un plafond qui
    // peut changer la veille du contrôle n'en est pas un.
    if (ach_budget_validation($dept_id, $exercice)['statut'] !== 'valide') {
        return ['avertissements' => ["Budget $exercice non validé pour ce département — dépense non contrôlée."]];
    }

    $familles = db_fetch_all(
        "SELECT famille_id, SUM(montant_ttc) AS montant FROM feb_lignes
          WHERE feb_id=? AND arbitrage='achat' AND famille_id IS NOT NULL
          GROUP BY famille_id",
        [$feb_id]
    );

    $avertissements = [];
    foreach ($familles as $f) {
        $famille_id    = (int)$f['famille_id'];
        $montant_ligne = (int)$f['montant'];

        $ligne_budget = db_fetch_one(
            "SELECT * FROM lignes_budgetaires
              WHERE departement_id=? AND famille_id=? AND exercice=? AND actif=1
              FOR UPDATE",
            [$dept_id, $famille_id, $exercice]
        );
        if (!$ligne_budget) {
            $fam_nom = db_fetch_value("SELECT libelle FROM familles_achat WHERE id=?", [$famille_id]);
            $avertissements[] = "Aucune ligne budgétaire pour « $fam_nom » sur ce département — non contrôlé.";
            continue;
        }
        if ($ligne_budget['enveloppe'] === null || $ligne_budget['comportement'] === 'aucun') continue;

        $situation   = ach_budget_situation($dept_id, $famille_id, $exercice, $feb_id);
        $total_apres = $situation['total_engage'] + $montant_ligne;
        if ($total_apres > (int)$ligne_budget['enveloppe']) {
            $depassement = $total_apres - (int)$ligne_budget['enveloppe'];
            $dept_code = db_fetch_value("SELECT code FROM departements WHERE id=?", [$dept_id]);
            $fam_nom   = db_fetch_value("SELECT libelle FROM familles_achat WHERE id=?", [$famille_id]);
            $msg = "Département $dept_code, famille $fam_nom : dépassement de " . number_format($depassement, 0, ',', ' ') . " XOF.";
            if ($ligne_budget['comportement'] === 'blocage') {
                throw new AchValidationException($msg);
            }
            $avertissements[] = $msg;
        }
    }
    return ['avertissements' => $avertissements];
}

// ── Lance la validation d'une FEB — calcule et fige le circuit (Bloc 2).
//    Réservé à l'acheteur qui détient la FEB, comme le reste du traitement.
function ach_lancer_validation(int $feb_id, array $user): array {
    $uid = (int)$user['id'];
    $feb = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$feb) throw new AchValidationException('FEB introuvable.');
    if ((int)$feb['acheteur_id'] !== $uid || $feb['statut'] !== 'prise_en_charge') {
        throw new AchValidationException("Cette FEB n'est pas (ou plus) en cours de traitement par vous.");
    }

    $verif = ach_verifier_comparatif($feb_id);
    if (!$verif['ok']) throw new AchValidationException($verif['message']);

    $nb_achat = (int) db_fetch_value("SELECT COUNT(*) FROM feb_lignes WHERE feb_id=? AND arbitrage='achat'", [$feb_id]);
    if ($nb_achat === 0) throw new AchValidationException('Aucune ligne à acheter ne subsiste — rien à faire signer.');

    // RG-10 : le circuit se calcule sur le montant total de la FEB, jamais
    // par ligne ni par lot.
    $montant = (int)$feb['montant_total'];
    $palier  = ach_palier_pour_montant($montant);
    if (!$palier) {
        // Symptôme d'une grille trouée (RG-13) : le message nomme le montant
        // pour qu'on aille corriger la grille, pas deviner pourquoi ça bloque.
        throw new AchValidationException("Aucun palier ne couvre le montant de $montant XOF — vérifiez la grille des paliers de validation.");
    }
    if (!$palier['signataires']) {
        throw new AchValidationException("Le palier « {$palier['libelle']} » n'a aucun signataire configuré.");
    }

    // RG-11 : le figeage a lieu ici, au lancement — jamais à la soumission,
    // puisque le montant n'est connu qu'une fois le comparatif terminé.
    $labels = array_column(db_fetch_all("SELECT code, label FROM di_roles"), 'label', 'code');
    $workflow = array_map(fn($code) => ['role' => $code, 'label' => $labels[$code] ?? $code], $palier['signataires']);

    // Le visa du supérieur hiérarchique n'est plus resollicité ici : il a
    // déjà endossé la demande avant même que les achats ne la prennent en
    // charge (ach_endosser_n1(), à la soumission — cf. ach_creer_feb()).
    // feb.n1_user_id garde la trace de qui, sans reparaître dans ce
    // circuit-ci, qui ne porte plus que les paliers RAF/DAF/PDG.
    $n1_user_id = $feb['n1_user_id'] ? (int)$feb['n1_user_id'] : null;

    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    // Complété, pas remplacé : l'endossement N+1 (ach_endosser_n1(), à la
    // soumission) y a déjà déposé une entrée que la fiche imprimable
    // (includes/pdf_achats.php) relit pour dater sa case « Supérieur
    // hiérarchique ».
    $historique   = json_decode($feb['historique'] ?? 'null', true) ?: [];
    $historique[] = ['action' => 'lancement_validation', 'par' => $uid, 'nom' => $nom,
        'commentaire' => "Palier « {$palier['libelle']} » — $montant XOF", 'date' => $now];

    // Bloc 3, point 19 : premier des deux points de contrôle budgétaire.
    // Le verrou posé par ach_controle_budget() (SELECT ... FOR UPDATE) doit
    // rester tenu jusqu'à l'UPDATE de statut, donc les deux dans la même
    // transaction.
    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        $controle = ach_controle_budget($feb_id);
        db_query(
            "UPDATE feb SET workflow_snapshot=?::jsonb, etape_actuelle=0, statut='en_validation',
                    date_lancement_validation=?, signatures='[]'::jsonb, historique=?::jsonb,
                    etape_rejet=NULL, motif_rejet=NULL, n1_user_id=?
              WHERE id=?",
            [json_encode($workflow, JSON_UNESCAPED_UNICODE), $now, json_encode($historique, JSON_UNESCAPED_UNICODE), $n1_user_id, $feb_id]
        );
        // Les avertissements budgétaires (mode "alerte", non bloquant) sont
        // ajoutés au message d'audit : c'est cette trace que le dashboard
        // (J9, Bloc 4) relit pour l'alerte "dépassements budgétaires".
        $msg_lancement = "Lancement de la validation — palier « {$palier['libelle']} », $montant XOF, " . count($workflow) . ' étape(s)';
        if ($controle['avertissements']) $msg_lancement .= ' — ' . implode(' ', $controle['avertissements']);
        audit_log($uid, 'UPDATE', 'achats', $feb_id, $msg_lancement);
        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    ach_notifier_etape($workflow[0], $n1_user_id, "FEB {$feb['numero']} en attente de votre visa — étape : {$workflow[0]['label']}.", $feb_id);

    return ['etapes' => count($workflow), 'palier' => $palier['libelle'], 'avertissements' => $controle['avertissements']];
}

// ── Accès à l'écran des visas. La matrice de permissions raisonne par
//    module, elle ne sait pas exprimer « supérieur hiérarchique d'un
//    département » : un responsable des Opérations est un simple
//    superviseur_operation, sans achats.can_update, et se voyait refuser
//    l'écran alors même qu'une FEB attendait son visa.
//    Même nature de problème que ach_est_acheteur(), résolu de la même
//    façon : une règle métier là où la matrice s'arrête.
//    L'écran ne montre que ce que ach_a_viser() rend — quelqu'un sans visa
//    en attente y trouve une liste vide, jamais la FEB d'un autre.
function ach_peut_ouvrir_visas(array $user): bool {
    if (can('achats', 'can_update')) return true;
    return ach_est_n1_quelque_part((int)$user['id']);
}

// ── Est-on N+1 d'au moins un département ? Extrait de ach_peut_ouvrir_visas()
//    pour être réutilisé ailleurs qu'aux visas — la réception (pages/achats/
//    receptions.php) doit s'ouvrir au N+1 pour son département sans qu'il
//    porte achats_suivi.can_read/can_create.
function ach_est_n1_quelque_part(int $user_id): bool {
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND is_n1=1",
        [$user_id]
    );
}

// ── Départements dont l'utilisateur est le N+1 — sert à scoper la
//    réception (une ligne par département, pas un id de site) au périmètre
//    réel du responsable, comme site_id le fait déjà pour un coordinateur
//    de site.
function ach_departements_n1(int $user_id): array {
    return array_map('intval', array_column(
        db_fetch_all("SELECT departement_id FROM user_departements WHERE user_id=? AND is_n1=1", [$user_id]),
        'departement_id'
    ));
}

// ── Supérieur hiérarchique d'un département, hors le demandeur lui-même.
//    La désignation vit dans user_departements.is_n1 — le même mécanisme que
//    les demandes internes, pas un second registre à tenir.
//    Le demandeur est exclu de la recherche : il ne peut pas se viser
//    (di_can_validate() refuse déjà le demandeur), et l'étape resterait
//    bloquée sans personne pour la lever. Un département dont le N+1 est
//    l'auteur de la demande n'a donc pas d'étape n1 — c'est voulu.
function ach_n1_du_departement(int $departement_id, int $demandeur_id): ?int {
    $id = db_fetch_value(
        "SELECT ud.user_id
           FROM user_departements ud
           JOIN users u ON u.id = ud.user_id AND u.actif = 1
          WHERE ud.departement_id = ? AND ud.is_n1 = 1 AND ud.user_id <> ?
          ORDER BY ud.user_id
          LIMIT 1",
        [$departement_id, $demandeur_id]
    );
    return $id ? (int)$id : null;
}

// ── Endossement N+1 avant prise en charge par les achats — porte distincte
//    du circuit de paliers (RAF/DAF/PDG) qui se joue plus tard, au lancement
//    de la validation (ach_lancer_validation() ne réinsère plus le N+1 dans
//    ce circuit-là : son aval ici suffit). Pas de workflow_snapshot ici,
//    volontairement : une seule personne, une seule décision, pas de circuit
//    à faire progresser — feb.n1_user_id suffit à savoir qui doit se
//    prononcer.
function ach_endosser_n1(int $feb_id, array $user, bool $accepte, string $commentaire = ''): bool {
    $uid = (int)$user['id'];
    if (!$accepte && trim($commentaire) === '') {
        throw new AchValidationException('Le motif de refus est obligatoire.');
    }

    $feb = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$feb) throw new AchValidationException('FEB introuvable.');
    if ($feb['statut'] !== 'en_attente_n1') throw new AchValidationException("Cette FEB n'attend pas votre aval.");
    if ((int)$feb['n1_user_id'] !== $uid) throw new AchValidationException("Vous n'êtes pas le supérieur hiérarchique désigné pour cette demande.");

    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    $historique   = json_decode($feb['historique'] ?? 'null', true) ?: [];
    $historique[] = ['action' => $accepte ? 'endosse_n1' : 'rejete_n1', 'par' => $uid, 'nom' => $nom,
        'commentaire' => $commentaire, 'date' => $now];
    $histJson = json_encode($historique, JSON_UNESCAPED_UNICODE);

    if (!$accepte) {
        // Retour au demandeur, pas à un statut intermédiaire : le refus du
        // N+1 porte sur le besoin lui-même, pas sur un défaut de forme que
        // les achats auraient à corriger — c'est donc bien un nouveau
        // brouillon à reprendre, comme un rejet de palier plus tard dans le
        // cycle renvoie à prise_en_charge.
        $stmt = db_query(
            "UPDATE feb SET statut='brouillon', motif_rejet=?, historique=?::jsonb
              WHERE id=? AND statut='en_attente_n1'",
            [$commentaire, $histJson, $feb_id]
        );
        if ($stmt->rowCount() === 0) return false;
        audit_log($uid, 'UPDATE', 'achats', $feb_id, "Refus du supérieur hiérarchique : $commentaire");
        ach_notifier_demandeur_feb($feb_id, "Votre FEB {$feb['numero']} n'a pas été endossée par votre supérieur hiérarchique : $commentaire");
        return true;
    }

    $stmt = db_query(
        "UPDATE feb SET statut='soumise', historique=?::jsonb WHERE id=? AND statut='en_attente_n1'",
        [$histJson, $feb_id]
    );
    if ($stmt->rowCount() === 0) return false;
    audit_log($uid, 'UPDATE', 'achats', $feb_id, 'Endossée par le supérieur hiérarchique — transmise aux Achats');
    ach_notifier_demandeur_feb($feb_id, "Votre FEB {$feb['numero']} a été endossée par votre supérieur hiérarchique et transmise aux Achats.");

    $achats = db_fetch_all(
        "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='superviseur_achat' AND u.actif=1"
    );
    foreach ($achats as $a) {
        db_query(
            "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'Nouvelle FEB', ?, ?)",
            [(int)$a['id'], "FEB {$feb['numero']} endossée par le N+1 — {$feb['objet']}", '/pages/achats/mes_feb.php']
        );
    }
    return true;
}

// ── FEB en attente de l'aval du N+1 connecté.
function ach_a_endosser(array $user): array {
    return db_fetch_all(
        "SELECT * FROM feb WHERE statut='en_attente_n1' AND n1_user_id=? ORDER BY date_soumission ASC",
        [(int)$user['id']]
    );
}

// ── Notifie les porteurs d'une étape de circuit.
//    L'étape n1 fait exception : aucun rôle ERP ne porte le code 'n1'
//    (di_user_roles() ne le donne qu'aux administrateurs), donc
//    ach_notifier_role() ne toucherait qu'eux. On notifie la personne
//    résolue et figée sur la FEB.
function ach_notifier_etape(array $etape, ?int $n1_user_id, string $message, int $feb_id): void {
    if (($etape['role'] ?? '') === 'n1') {
        if ($n1_user_id) {
            db_query(
                "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Visa FEB',?,?)",
                [$n1_user_id, $message, '/pages/achats/mes_visas.php']
            );
        }
        return;
    }
    ach_notifier_role($etape['role'], $message, $feb_id);
}

// ── Vise (accepte ou rejette) l'étape courante d'une FEB en validation.
//    Réutilise di_can_validate()/di_next_step() tels quels — aucune règle
//    de droits n'est réécrite (Bloc 3, point 16).
//    Le verrou est l'UPDATE conditionnel WHERE etape_actuelle=? (comme
//    ach_prendre_en_charge en J3) : deux signataires du même département
//    qui cliquent ensemble ne peuvent pas produire deux signatures — celui
//    qui arrive en second obtient rowCount=0 et se voit répondre false.
function ach_viser(int $feb_id, array $user, bool $accepte, string $commentaire = ''): bool {
    $uid = (int)$user['id'];
    if (!$accepte && trim($commentaire) === '') {
        throw new AchValidationException('Le motif de rejet est obligatoire.');
    }

    $row = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$row) throw new AchValidationException('FEB introuvable.');
    if ($row['statut'] !== 'en_validation') throw new AchValidationException("Cette FEB n'est pas en cours de validation.");
    $feb = ach_feb_decode($row);
    $cur = (int)$feb['etape_actuelle'];
    $wf  = $feb['workflow_snapshot'];

    $roles = di_user_roles($uid);
    // n1_user_id figé au lancement : sans lui, di_can_validate() retombe sur
    // « tout porteur du code n1 », que seuls les administrateurs possèdent —
    // le supérieur hiérarchique réel ne pourrait pas viser, et un
    // administrateur le pourrait à sa place.
    $n1_user_id = $feb['n1_user_id'] ? (int)$feb['n1_user_id'] : null;
    if (!di_can_validate($roles, $uid, $wf, $cur, (int)$feb['demandeur_id'], $n1_user_id)) {
        throw new AchValidationException('Vous ne pouvez pas viser cette étape.');
    }

    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    $etape_label = $wf[$cur]['label'] ?? $wf[$cur]['role'];

    $signatures   = $feb['signatures'];
    $signatures[] = ['etape' => $cur, 'etape_label' => $etape_label, 'user_id' => $uid, 'nom' => $nom,
        'action' => $accepte ? 'approuve' : 'rejete', 'commentaire' => $commentaire, 'date' => $now];
    $historique   = $feb['historique'];
    $historique[] = ['action' => $accepte ? 'valide' : 'rejete', 'etape' => $etape_label, 'par' => $uid,
        'nom' => $nom, 'commentaire' => $commentaire, 'date' => $now];
    $sigJson  = json_encode($signatures, JSON_UNESCAPED_UNICODE);
    $histJson = json_encode($historique, JSON_UNESCAPED_UNICODE);

    if (!$accepte) {
        $stmt = db_query(
            "UPDATE feb SET statut='rejetee', etape_rejet=?, motif_rejet=?, signatures=?::jsonb, historique=?::jsonb
              WHERE id=? AND etape_actuelle=?",
            [$cur, $commentaire, $sigJson, $histJson, $feb_id, $cur]
        );
        if ($stmt->rowCount() === 0) return false;
        audit_log($uid, 'UPDATE', 'achats', $feb_id, "Rejet à l'étape « $etape_label » : $commentaire");
        if ($feb['demandeur_id'])  ach_notifier_demandeur_feb($feb_id, "Votre FEB {$feb['numero']} a été rejetée à l'étape « $etape_label » : $commentaire");
        if ($feb['acheteur_id'])   db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','FEB rejetée',?,?)",
            [(int)$feb['acheteur_id'], "FEB {$feb['numero']} rejetée à l'étape « $etape_label » : $commentaire", '/pages/achats/file_attente.php']);
        return true;
    }

    $next = di_next_step($wf, $cur);
    if ($next === null) {
        // Bloc 3, point 19 : second des deux points de contrôle budgétaire —
        // d'autres FEB sur la même enveloppe ont pu être confirmées entre le
        // lancement de celle-ci et sa dernière signature. Verrou et UPDATE
        // dans la même transaction, comme au lancement.
        db_begin();
        try {
            $controle = ach_controle_budget($feb_id);
            $stmt = db_query(
                "UPDATE feb SET statut='confirmee', date_confirmation=?, signatures=?::jsonb, historique=?::jsonb
                  WHERE id=? AND etape_actuelle=?",
                [$now, $sigJson, $histJson, $feb_id, $cur]
            );
            if ($stmt->rowCount() === 0) { db_rollback(); return false; }
            // Bloc 1 (J6) : l'éclatement en lignes de suivi Sage a lieu ici,
            // dans la même transaction que le passage à confirmee — jamais en
            // dehors, sinon une FEB confirmée pourrait rester sans suivi si
            // l'appel suivant échouait.
            ach_eclater_suivi($feb_id);
            db_commit();
        } catch (Exception $e) {
            db_rollback();
            throw $e;
        }

        // J9, Bloc 1 : la fiche de validation se génère une seule fois, ici,
        // juste après la confirmation — jamais recalculée par la suite. Hors
        // de la transaction ci-dessus par choix : le rendu dompdf et
        // l'écriture disque n'ont rien de transactionnel, et les prolonger
        // sous le verrou budgétaire (SELECT ... FOR UPDATE, ach_controle_budget)
        // retiendrait ce verrou plus longtemps qu'il ne le faut. Un échec de
        // génération ne doit jamais défaire une confirmation déjà acquise —
        // il est journalisé et laissé rattrapable, pas fatal.
        try {
            require_once __DIR__ . '/pdf_achats.php';
            ach_generer_fiche_validation($feb_id);
        } catch (Throwable $e) {
            // Throwable, pas seulement Exception : une Error (classe
            // manquante, require_once en échec) ne doit pas non plus faire
            // sauter une confirmation déjà acquise.
            error_log("Échec génération fiche de validation FEB $feb_id : " . $e->getMessage());
        }

        $msg_confirmation = "Dernière signature obtenue (étape « $etape_label ») — FEB confirmée";
        if ($controle['avertissements']) $msg_confirmation .= ' — ' . implode(' ', $controle['avertissements']);
        audit_log($uid, 'UPDATE', 'achats', $feb_id, $msg_confirmation);
        ach_notifier_demandeur_feb($feb_id, "Votre FEB {$feb['numero']} est entièrement signée.");
        if ($feb['acheteur_id']) db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','FEB confirmée',?,?)",
            [(int)$feb['acheteur_id'], "FEB {$feb['numero']} entièrement signée.", '/pages/achats/file_attente.php']);
        return true;
    }

    $stmt = db_query(
        "UPDATE feb SET etape_actuelle=?, signatures=?::jsonb, historique=?::jsonb WHERE id=? AND etape_actuelle=?",
        [$next, $sigJson, $histJson, $feb_id, $cur]
    );
    if ($stmt->rowCount() === 0) return false;
    audit_log($uid, 'UPDATE', 'achats', $feb_id, "Étape « $etape_label » signée — passage à « {$wf[$next]['label']} »");
    ach_notifier_etape($wf[$next], $n1_user_id, "FEB {$feb['numero']} en attente de votre visa — étape : {$wf[$next]['label']}.", $feb_id);
    return true;
}

function ach_notifier_demandeur_feb(int $feb_id, string $message): void {
    $demandeur_id = db_fetch_value("SELECT demandeur_id FROM feb WHERE id=?", [$feb_id]);
    if (!$demandeur_id) return;
    db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','FEB',?,?)",
        [(int)$demandeur_id, $message, '/pages/achats/mes_feb.php']);
}

// ── Reprise d'une FEB rejetée par son acheteur — le rejet n'est pas une
//    mort : retour en prise_en_charge, signatures effacées, circuit à
//    recalculer (nouvel appel à ach_lancer_validation quand l'acheteur est prêt).
function ach_reprendre_feb_rejetee(int $feb_id, array $user): bool {
    $uid = (int)$user['id'];
    $stmt = db_query(
        "UPDATE feb SET statut='prise_en_charge', etape_actuelle=-1, signatures='[]'::jsonb,
                workflow_snapshot=NULL, etape_rejet=NULL, motif_rejet=NULL
          WHERE id=? AND acheteur_id=? AND statut='rejetee'",
        [$feb_id, $uid]
    );
    if ($stmt->rowCount() === 0) return false;
    audit_log($uid, 'UPDATE', 'achats', $feb_id, 'Reprise de la FEB rejetée — circuit à relancer');
    return true;
}

// ── Réouverture administrative d'une FEB en cours de validation (Bloc 5,
//    RG-12) : les offres et montants sont verrouillés dès en_validation par
//    le contrôle d'accès déjà en place sur pages/achats/feb_traitement.php
//    (acheteur_id + statut='prise_en_charge') — rien à ajouter là. Cette
//    fonction est l'unique porte de sortie, réservée à un administrateur,
//    tracée explicitement : elle annule les signatures et remet la FEB à
//    disposition de son acheteur, qui corrige puis relance
//    ach_lancer_validation() — c'est ce second appel qui recalcule le
//    palier et repart à l'étape 0.
function ach_admin_reouvrir_validation(int $feb_id, array $user): bool {
    $uid = (int)$user['id'];
    $stmt = db_query(
        "UPDATE feb SET statut='prise_en_charge', etape_actuelle=-1, signatures='[]'::jsonb,
                workflow_snapshot=NULL, etape_rejet=NULL, motif_rejet=NULL, date_lancement_validation=NULL
          WHERE id=? AND statut='en_validation'",
        [$feb_id]
    );
    if ($stmt->rowCount() === 0) return false;
    audit_log($uid, 'UPDATE', 'achats', $feb_id,
        "Réouverture administrative : signatures annulées, circuit à relancer depuis l'étape 0 (RG-12)");
    return true;
}

// ── FEB que CET utilisateur peut viser — même principe que di_a_valider().
function ach_a_viser(array $user): array {
    $uid   = (int)$user['id'];
    $roles = di_user_roles($uid);
    $has_dept_role = (bool)db_fetch_value(
        "SELECT COUNT(*) FROM user_departements ud JOIN di_roles dr ON dr.departement_id = ud.departement_id WHERE ud.user_id=?",
        [$uid]
    );
    // Un supérieur hiérarchique n'a pas nécessairement de rôle de circuit ni
    // de rôle lié à un département : le responsable des Opérations est un
    // simple superviseur_operation. Sans cette troisième porte, il serait
    // écarté avant même que di_can_validate() soit consulté, et ne verrait
    // jamais la FEB qu'il doit endosser.
    $est_n1 = (bool)db_fetch_value(
        "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND is_n1=1", [$uid]
    );
    if (!$roles && !$has_dept_role && !$est_n1) return [];

    $febs = db_fetch_all(
        "SELECT * FROM feb WHERE statut='en_validation' AND demandeur_id <> ? ORDER BY date_lancement_validation ASC",
        [$uid]
    );
    $out = [];
    foreach ($febs as $f) {
        $f = ach_feb_decode($f);
        $n1 = $f['n1_user_id'] ? (int)$f['n1_user_id'] : null;
        if (di_can_validate($roles, $uid, $f['workflow_snapshot'], (int)$f['etape_actuelle'], (int)$f['demandeur_id'], $n1)) {
            $out[] = $f;
        }
    }
    return $out;
}

// ── Décode les colonnes JSON d'une proposition d'affectation — même
//    forme que ach_feb_decode(), table distincte.
function ach_affectation_decode(array $row): array {
    foreach (['workflow_snapshot', 'signatures', 'historique'] as $k) {
        $row[$k] = json_decode($row[$k] ?? 'null', true) ?? [];
    }
    return $row;
}

// ── Étape 4 — Propose l'affectation d'un exemplaire en attente à un site
//    (et, facultativement, à un utilisateur nommé). Circuit résolu sur les
//    trois paliers existants (achat_paliers), comme au lancement d'une FEB
//    (ach_lancer_validation()) — décision retenue plutôt qu'une seconde
//    grille de délégation : ils sont déjà éprouvés et couvrent nativement
//    l'immobilisation à plusieurs millions. Montant de référence :
//    equipements.prix_achat de CET exemplaire, jamais le montant de la FEB
//    entière (plusieurs exemplaires d'une même ligne DAI peuvent suivre des
//    circuits différents si leur valorisation diffère).
function ach_proposer_affectation(int $equipement_id, ?int $site_id, ?int $utilisateur_id, array $user): array {
    $uid = (int)$user['id'];

    $equipement = db_fetch_one("SELECT * FROM equipements WHERE id=?", [$equipement_id]);
    if (!$equipement) throw new AchValidationException('Exemplaire introuvable.');
    if ($equipement['statut_stock'] !== 'en_attente_affectation') {
        throw new AchValidationException("Cet exemplaire n'est pas (ou plus) en attente d'affectation.");
    }

    // Destination : un site précis, ou — l'exemplaire porte déjà un
    // département (hérité de sa FEB d'origine) — le département seul,
    // sans site fixe (décision du 2026-08-24, choix proposé dans la
    // modale). Un site n'est donc obligatoire que s'il n'y a aucun
    // département de repli.
    if (!$site_id && !$equipement['departement_id']) {
        throw new AchValidationException('Le site de destination est obligatoire pour un exemplaire sans département.');
    }

    // L'utilisateur nommé doit appartenir à la destination choisie : au
    // site s'il y en a un, sinon au département de l'exemplaire — la
    // modale (pages/achats/equipements_attente.php) filtre déjà la liste
    // en conséquence, revérifié ici pour ne pas dépendre de lui.
    if ($utilisateur_id) {
        if ($site_id) {
            $appartient = (bool) db_fetch_value("SELECT COUNT(*) FROM users WHERE id=? AND site_id=?", [$utilisateur_id, $site_id]);
            if (!$appartient) {
                throw new AchValidationException("L'utilisateur choisi n'appartient pas au site de destination.");
            }
        } elseif ($equipement['departement_id']) {
            $appartient = (bool) db_fetch_value(
                "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=?",
                [$utilisateur_id, $equipement['departement_id']]
            );
            if (!$appartient) {
                throw new AchValidationException("L'utilisateur choisi n'appartient pas au département de cet exemplaire.");
            }
        }
    }

    $montant = (int) round((float)$equipement['prix_achat']);
    $palier  = ach_palier_pour_montant($montant);
    if (!$palier) {
        throw new AchValidationException("Aucun palier ne couvre le montant de $montant XOF — vérifiez la grille des paliers de validation.");
    }
    if (!$palier['signataires']) {
        throw new AchValidationException("Le palier « {$palier['libelle']} » n'a aucun signataire configuré.");
    }
    $labels   = array_column(db_fetch_all("SELECT code, label FROM di_roles"), 'label', 'code');
    $workflow = array_map(fn($code) => ['role' => $code, 'label' => $labels[$code] ?? $code], $palier['signataires']);

    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    $historique = [['action' => 'proposition', 'par' => $uid, 'nom' => $nom,
        'commentaire' => "Palier « {$palier['libelle']} » — $montant XOF", 'date' => $now]];

    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        // Conditionnel sur le statut actuel : sans lui, deux propositions
        // lancées en parallèle sur le même exemplaire produiraient deux
        // circuits concurrents — même défaut, même remède que le double
        // envoi d'offre (cf. feb_traitement.php).
        $stmt = db_query(
            "UPDATE equipements SET statut_stock='affectation_en_cours' WHERE id=? AND statut_stock='en_attente_affectation'",
            [$equipement_id]
        );
        if ($stmt->rowCount() === 0) {
            if ($transaction_locale) db_rollback();
            throw new AchValidationException("Cet exemplaire vient d'être proposé par quelqu'un d'autre — rechargez l'écran.");
        }
        db_query(
            "INSERT INTO equipement_affectations
             (equipement_id, site_id, utilisateur_id, statut, workflow_snapshot, historique, etape_actuelle, proposee_par, proposee_le)
             VALUES (?,?,?, 'en_validation', ?, ?, 0, ?, ?)",
            [$equipement_id, $site_id, $utilisateur_id ?: null,
             json_encode($workflow, JSON_UNESCAPED_UNICODE), json_encode($historique, JSON_UNESCAPED_UNICODE), $uid, $now]
        );
        $affectation_id = (int) db_last_id('equipement_affectations_id_seq');
        audit_log($uid, 'CREATE', 'equipements', $equipement_id,
            "Affectation proposée — palier « {$palier['libelle']} », $montant XOF");
        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    ach_notifier_role($workflow[0]['role'], "Affectation d'équipement en attente de votre visa — étape : {$workflow[0]['label']}.");
    return ['id' => $affectation_id, 'palier' => $palier['libelle'], 'etapes' => count($workflow)];
}

// ── Étape 4 — Vise (ou rejette) l'étape courante d'une proposition
//    d'affectation. Même structure que ach_viser() pour une FEB, sans
//    l'étape n1 (une affectation n'a pas de supérieur hiérarchique dans
//    son circuit — seulement les signataires du palier).
function ach_viser_affectation(int $affectation_id, array $user, bool $accepte, string $commentaire = ''): bool {
    $uid = (int)$user['id'];
    if (!$accepte && trim($commentaire) === '') {
        throw new AchValidationException('Le motif de rejet est obligatoire.');
    }

    $row = db_fetch_one("SELECT * FROM equipement_affectations WHERE id=?", [$affectation_id]);
    if (!$row) throw new AchValidationException('Proposition introuvable.');
    if ($row['statut'] !== 'en_validation') throw new AchValidationException("Cette proposition n'est plus en cours de validation.");
    $affectation = ach_affectation_decode($row);
    $cur = (int)$affectation['etape_actuelle'];
    $wf  = $affectation['workflow_snapshot'];

    $roles = di_user_roles($uid);
    if (!di_can_validate($roles, $uid, $wf, $cur, (int)$affectation['proposee_par'], null)) {
        throw new AchValidationException('Vous ne pouvez pas viser cette étape.');
    }

    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    $etape_label = $wf[$cur]['label'] ?? $wf[$cur]['role'];

    $signatures   = $affectation['signatures'];
    $signatures[] = ['etape' => $cur, 'etape_label' => $etape_label, 'user_id' => $uid, 'nom' => $nom,
        'action' => $accepte ? 'approuve' : 'rejete', 'commentaire' => $commentaire, 'date' => $now];
    $historique   = $affectation['historique'];
    $historique[] = ['action' => $accepte ? 'valide' : 'rejete', 'etape' => $etape_label, 'par' => $uid,
        'nom' => $nom, 'commentaire' => $commentaire, 'date' => $now];
    $sigJson  = json_encode($signatures, JSON_UNESCAPED_UNICODE);
    $histJson = json_encode($historique, JSON_UNESCAPED_UNICODE);

    $equipement = db_fetch_one("SELECT * FROM equipements WHERE id=?", [$affectation['equipement_id']]);

    if (!$accepte) {
        $stmt = db_query(
            "UPDATE equipement_affectations SET statut='rejetee', etape_rejet=?, motif_rejet=?, signatures=?::jsonb, historique=?::jsonb
              WHERE id=? AND etape_actuelle=?",
            [$cur, $commentaire, $sigJson, $histJson, $affectation_id, $cur]
        );
        if ($stmt->rowCount() === 0) return false;
        // Retour dans la file : une proposition rejetée n'immobilise pas
        // l'exemplaire, il redevient proposable — éventuellement vers une
        // autre destination.
        db_query("UPDATE equipements SET statut_stock='en_attente_affectation' WHERE id=?", [$affectation['equipement_id']]);
        audit_log($uid, 'UPDATE', 'equipements', $affectation['equipement_id'], "Affectation rejetée à l'étape « $etape_label » : $commentaire");
        if ($affectation['proposee_par']) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Affectation rejetée',?,?)",
                [(int)$affectation['proposee_par'],
                 "L'affectation de « " . ($equipement['numero_serie_interne'] ?? '') . " » a été rejetée à l'étape « $etape_label » : $commentaire",
                 '/pages/achats/equipements_attente.php']);
        }
        return true;
    }

    $next = di_next_step($wf, $cur);
    if ($next === null) {
        // Validée ne veut pas dire arrivée : l'exemplaire est expédié vers
        // sa destination (site_id/utilisateur_id posés dès maintenant, la
        // décision est prise), mais reste 'en_transit' tant que le N+1 du
        // département destinataire n'a pas confirmé la réception physique
        // (ach_confirmer_reception_equipement()) — décision du 2026-08-23,
        // même principe que le circuit magasin -> département des
        // consommables. date_mise_en_service n'est donc posée qu'à cette
        // confirmation, pas ici.
        $stmt = db_query(
            "UPDATE equipement_affectations SET statut='validee', valide_le=?, signatures=?::jsonb, historique=?::jsonb
              WHERE id=? AND etape_actuelle=?",
            [$now, $sigJson, $histJson, $affectation_id, $cur]
        );
        if ($stmt->rowCount() === 0) return false;
        db_query(
            "UPDATE equipements SET site_id=?, utilisateur_id=?, statut_stock='en_transit' WHERE id=?",
            [$affectation['site_id'], $affectation['utilisateur_id'], $affectation['equipement_id']]
        );
        audit_log($uid, 'UPDATE', 'equipements', $affectation['equipement_id'], "Affectation validée — étape finale « $etape_label » — expédié, en attente de confirmation de réception");
        if ($affectation['proposee_par']) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Affectation validée',?,?)",
                [(int)$affectation['proposee_par'],
                 "L'affectation de « " . ($equipement['numero_serie_interne'] ?? '') . " » est validée et expédiée — en attente de confirmation du département.",
                 '/pages/equipements.php']);
        }
        if ($equipement['departement_id']) {
            foreach (db_fetch_all(
                "SELECT user_id FROM user_departements WHERE departement_id=? AND is_n1=1", [(int)$equipement['departement_id']]
            ) as $n1) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Équipement à réceptionner',?,?)",
                    [(int)$n1['user_id'],
                     "« " . ($equipement['numero_serie_interne'] ?? '') . " » expédié vers votre département — à confirmer.",
                     '/pages/achats/equipements_attente.php']);
            }
        }
        return true;
    }

    $stmt = db_query(
        "UPDATE equipement_affectations SET etape_actuelle=?, signatures=?::jsonb, historique=?::jsonb
          WHERE id=? AND etape_actuelle=?",
        [$next, $sigJson, $histJson, $affectation_id, $cur]
    );
    if ($stmt->rowCount() === 0) return false;
    audit_log($uid, 'UPDATE', 'equipements', $affectation['equipement_id'], "Affectation — étape « $etape_label » visée, passage à « {$wf[$next]['label']} »");
    ach_notifier_role($wf[$next]['role'], "Affectation d'équipement en attente de votre visa — étape : {$wf[$next]['label']}.");
    return true;
}

// ── Confirmation de réception physique par le N+1 du département — dernier
//    maillon du circuit magasin -> département côté équipements, symétrique
//    de ach_receptionner_departement() côté consommables. Sans elle,
//    l'exemplaire reste 'en_transit' indéfiniment : ni disponible pour une
//    nouvelle affectation (il en a déjà une), ni compté comme réellement en
//    service (date_mise_en_service).
function ach_confirmer_reception_equipement(int $equipement_id, array $user, ?string $bon_livraison = null): bool {
    $uid = (int)$user['id'];
    $equipement = db_fetch_one("SELECT * FROM equipements WHERE id=?", [$equipement_id]);
    if (!$equipement) throw new AchValidationException('Équipement introuvable.');
    if ($equipement['statut_stock'] !== 'en_transit') {
        throw new AchValidationException("Cet exemplaire n'est pas en attente de confirmation de réception.");
    }
    if (!$equipement['departement_id']) {
        throw new AchValidationException('Cet exemplaire ne porte aucun département.');
    }

    $est_n1 = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=? AND is_n1=1",
        [$uid, (int)$equipement['departement_id']]
    );
    $is_admin = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
    if (!$est_n1 && !$is_admin) {
        throw new AchValidationException("Seul le supérieur hiérarchique du département peut confirmer cette réception.");
    }

    $statut_final = $equipement['utilisateur_id'] ? 'affecte' : 'en_stock';
    $stmt = db_query(
        "UPDATE equipements SET statut_stock=?, date_mise_en_service=COALESCE(date_mise_en_service, ?), bon_livraison=?
          WHERE id=? AND statut_stock='en_transit'",
        [$statut_final, date('Y-m-d'), $bon_livraison, $equipement_id]
    );
    if ($stmt->rowCount() === 0) return false;
    audit_log($uid, 'UPDATE', 'equipements', $equipement_id, 'Réception confirmée par le supérieur hiérarchique du département');
    return true;
}

// ── Équipements 'en_transit' que le N+1 connecté peut confirmer — même
//    triple porte que les autres files N+1 (rôle admin, ou is_n1 sur le
//    département de l'exemplaire).
function ach_equipements_en_transit(array $user): array {
    $uid = (int)$user['id'];
    $is_admin = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
    if ($is_admin) {
        return db_fetch_all("SELECT * FROM equipements WHERE statut_stock='en_transit' ORDER BY id DESC");
    }
    return db_fetch_all(
        "SELECT e.* FROM equipements e
         JOIN user_departements ud ON ud.departement_id = e.departement_id AND ud.user_id=? AND ud.is_n1=1
         WHERE e.statut_stock='en_transit' ORDER BY e.id DESC",
        [$uid]
    );
}

// ── Étape 4 — Propositions d'affectation en attente du visa de
//    l'utilisateur courant. Même filtre à trois portes que ach_a_viser()
//    (rôle de circuit, rôle lié à un département, ou N+1).
function ach_a_viser_affectations(array $user): array {
    $uid   = (int)$user['id'];
    $roles = di_user_roles($uid);
    $has_dept_role = (bool)db_fetch_value(
        "SELECT COUNT(*) FROM user_departements ud JOIN di_roles dr ON dr.departement_id = ud.departement_id WHERE ud.user_id=?",
        [$uid]
    );
    $est_n1 = (bool)db_fetch_value("SELECT COUNT(*) FROM user_departements WHERE user_id=? AND is_n1=1", [$uid]);
    if (!$roles && !$has_dept_role && !$est_n1) return [];

    $rows = db_fetch_all(
        "SELECT ea.*, e.numero_serie_interne, e.prix_achat, n.libelle AS nomenclature_libelle,
                s.nom AS site_nom, CONCAT(u2.prenom,' ',u2.nom) AS utilisateur_nom
         FROM equipement_affectations ea
         JOIN equipements e ON e.id = ea.equipement_id
         LEFT JOIN nomenclatures n ON n.id = e.nomenclature_id
         LEFT JOIN sites s         ON s.id = ea.site_id
         LEFT JOIN users u2        ON u2.id = ea.utilisateur_id
         WHERE ea.statut='en_validation' AND ea.proposee_par <> ?
         ORDER BY ea.proposee_le ASC",
        [$uid]
    );
    $out = [];
    foreach ($rows as $r) {
        $a = ach_affectation_decode($r);
        if (di_can_validate($roles, $uid, $a['workflow_snapshot'], (int)$a['etape_actuelle'], (int)$a['proposee_par'], null)) {
            $out[] = $a;
        }
    }
    return $out;
}

// ── Éclatement en lignes de suivi Sage, à la confirmation d'une FEB
//    (Bloc 1, J6). Appelée par ach_viser() dans la même transaction que le
//    passage à confirmee — jamais en dehors.
//    Une ligne feb_suivi par ligne arbitrée 'achat' : les lignes servies sur
//    stock sont closes depuis J3 (ach_basculer_vers_commande), elles
//    n'entrent jamais dans le suivi Sage.
function ach_eclater_suivi(int $feb_id): void {
    $feb = db_fetch_one("SELECT numero, site_id, acheteur_id, date_confirmation FROM feb WHERE id=?", [$feb_id]);
    if (!$feb) return;

    // date_livraison_prevue n'est jamais laissée vide : estimée au délai
    // standard (paramètre, jamais codé en dur) faute de date réelle
    // communiquée par le fournisseur — remplaçable au moment du BC (Bloc 2).
    $delai = (int) ach_param('delai_livraison_standard_jours', 15);
    $date_confirmation = $feb['date_confirmation'] ?: date('Y-m-d H:i:s');
    $date_prevue = (new DateTime($date_confirmation))->modify("+$delai days")->format('Y-m-d');

    $lignes = db_fetch_all("SELECT id, quantite FROM feb_lignes WHERE feb_id=? AND arbitrage='achat'", [$feb_id]);
    foreach ($lignes as $l) {
        db_query(
            "INSERT INTO feb_suivi (feb_id, feb_ligne_id, quantite_commandee, statut, site_id, date_livraison_prevue)
             VALUES (?,?,?,'en_attente',?,?)",
            [$feb_id, $l['id'], $l['quantite'], $feb['site_id'], $date_prevue]
        );
    }

    if ($feb['acheteur_id'] && $lignes) {
        $n = count($lignes);
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','FEB en suivi',?,?)",
            [(int)$feb['acheteur_id'], "FEB {$feb['numero']} confirmée — $n ligne(s) passée(s) en suivi Sage.", '/pages/achats/suivi_achats.php']
        );
    }
}

// ── Statut d'une ligne de suivi — jamais stocké, toujours recalculé à la
//    lecture. Version définitive (J8, Bloc 4) — la version J7 était
//    provisoire, comme noté dans son propre commentaire ; celle-ci
//    remplace intégralement l'ancienne logique livree/receptionnee
//    maintenant que la réception (ach_receptionner, ach_cloturer_reliquat)
//    alimente réellement quantite_recue et cloture_reliquat.
//    $ligne : une ligne feb_suivi (tableau associatif brut).
//    Ordre volontaire : les deux états terminaux (receptionnee, y compris
//    un reliquat clôturé de force) sont tranchés en premier, puis
//    en_attente, puis en_retard AVANT livree — un reliquat partiellement
//    reçu mais dont le délai est dépassé doit remonter en_retard, pas
//    rester affiché livree comme si tout allait bien.
function ach_statut_suivi_calcule(array $ligne): string {
    $commandee = (int)($ligne['quantite_commandee'] ?? 0);
    $recue     = (int)($ligne['quantite_recue'] ?? 0);

    if (!empty($ligne['cloture_reliquat'])) return 'receptionnee';
    if ($commandee > 0 && $recue >= $commandee) return 'receptionnee';
    if (empty($ligne['numero_da'])) return 'en_attente';

    if (!empty($ligne['date_livraison_prevue'])) {
        $seuil  = (int) ach_param('seuil_retard_jours', 5);
        $limite = (new DateTime($ligne['date_livraison_prevue']))->modify("+$seuil days");
        if (new DateTime() > $limite) return 'en_retard';
    }

    if ($recue > 0) return 'livree';
    return 'commande';
}

// ── Saisie du N° DA (Bloc 2). date_da horodatée à la première saisie
//    seulement — une correction ultérieure par un administrateur change le
//    numéro sans réécrire la date d'origine. Toute saisie ou modification
//    est tracée à l'audit.
function ach_saisir_da(int $suivi_id, string $numero_da, array $user, ?string $date_da = null): void {
    $uid       = (int)$user['id'];
    $numero_da = trim($numero_da);
    if ($numero_da === '') throw new AchValidationException('Le numéro de DA est obligatoire.');

    // La DA existe souvent avant sa saisie ici (papier, Sage) — sans date
    // réglable, date_da valait toujours « aujourd'hui », faussant tout calcul
    // du délai DA → BC pour une saisie faite après coup. CURRENT_DATE reste
    // le défaut si le champ est laissé vide.
    if ($date_da !== null) {
        $date_da = trim($date_da);
        $d = DateTime::createFromFormat('Y-m-d', $date_da);
        if (!$date_da || !$d || $d->format('Y-m-d') !== $date_da) {
            throw new AchValidationException('Date de DA invalide.');
        }
        if ($date_da > date('Y-m-d')) {
            throw new AchValidationException('La date de DA ne peut pas être dans le futur.');
        }
    }

    $ligne = db_fetch_one("SELECT * FROM feb_suivi WHERE id=?", [$suivi_id]);
    if (!$ligne) throw new AchValidationException('Ligne de suivi introuvable.');

    $premiere_saisie = empty($ligne['numero_da']);
    $is_admin = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
    if (!$premiere_saisie && !$is_admin) {
        throw new AchValidationException('Le N° DA est déjà saisi — seul un administrateur peut le modifier.');
    }

    if ($premiere_saisie) {
        db_query("UPDATE feb_suivi SET numero_da=?, date_da=? WHERE id=?", [$numero_da, $date_da ?: date('Y-m-d'), $suivi_id]);
    } elseif ($date_da) {
        db_query("UPDATE feb_suivi SET numero_da=?, date_da=? WHERE id=?", [$numero_da, $date_da, $suivi_id]);
    } else {
        db_query("UPDATE feb_suivi SET numero_da=? WHERE id=?", [$numero_da, $suivi_id]);
    }
    audit_log($uid, 'UPDATE', 'achats_suivi', $suivi_id, $premiere_saisie
        ? "Saisie N° DA : $numero_da"
        : "Modification N° DA (administrateur) : {$ligne['numero_da']} → $numero_da");
}

// ── Saisie du N° BC (Bloc 2) — refuse tant qu'aucun N° DA n'existe (règle
//    d'ordre confirmée en Bloc 0). $date_livraison_prevue : remplace
//    l'estimation à J+délai standard par la date réelle communiquée par le
//    fournisseur, si l'acheteur la renseigne (confirmé en recette).
function ach_saisir_bc(int $suivi_id, string $numero_bc, array $user, ?string $date_livraison_prevue = null): void {
    $uid       = (int)$user['id'];
    $numero_bc = trim($numero_bc);
    if ($numero_bc === '') throw new AchValidationException('Le numéro de BC est obligatoire.');

    $ligne = db_fetch_one("SELECT * FROM feb_suivi WHERE id=?", [$suivi_id]);
    if (!$ligne) throw new AchValidationException('Ligne de suivi introuvable.');
    if (empty($ligne['numero_da'])) {
        throw new AchValidationException('Le N° DA doit être saisi avant le N° BC.');
    }

    if ($date_livraison_prevue) {
        db_query("UPDATE feb_suivi SET numero_bc=?, date_bc=CURRENT_DATE, date_livraison_prevue=? WHERE id=?",
            [$numero_bc, $date_livraison_prevue, $suivi_id]);
    } else {
        db_query("UPDATE feb_suivi SET numero_bc=?, date_bc=CURRENT_DATE WHERE id=?", [$numero_bc, $suivi_id]);
    }
    audit_log($uid, 'UPDATE', 'achats_suivi', $suivi_id, "Saisie N° BC : $numero_bc"
        . ($date_livraison_prevue ? " — date de livraison prévue ajustée au $date_livraison_prevue" : ''));
}

// ── Saisie par lot (Bloc 2) : une même référence pour toutes les lignes
//    d'un lot. Ne touche que les lignes du lot sans référence — une ligne
//    déjà pourvue n'est jamais écrasée (filtrée avant même d'être tentée,
//    donc jamais bloquée par le verrou administrateur de ach_saisir_da() sur
//    ce chemin). Retourne le nombre de lignes effectivement mises à jour.
function ach_saisir_da_lot(int $feb_id, string $lot, string $numero_da, array $user, ?string $date_da = null): int {
    $lignes = db_fetch_all(
        "SELECT fs.id FROM feb_suivi fs JOIN feb_lignes fl ON fl.id = fs.feb_ligne_id
          WHERE fs.feb_id=? AND fl.lot=? AND (fs.numero_da IS NULL OR fs.numero_da = '')",
        [$feb_id, $lot]
    );
    foreach ($lignes as $l) ach_saisir_da((int)$l['id'], $numero_da, $user, $date_da);
    return count($lignes);
}
function ach_saisir_bc_lot(int $feb_id, string $lot, string $numero_bc, array $user, ?string $date_livraison_prevue = null): int {
    $lignes = db_fetch_all(
        "SELECT fs.id FROM feb_suivi fs JOIN feb_lignes fl ON fl.id = fs.feb_ligne_id
          WHERE fs.feb_id=? AND fl.lot=? AND (fs.numero_bc IS NULL OR fs.numero_bc = '')
            AND fs.numero_da IS NOT NULL AND fs.numero_da <> ''",
        [$feb_id, $lot]
    );
    foreach ($lignes as $l) ach_saisir_bc((int)$l['id'], $numero_bc, $user, $date_livraison_prevue);
    return count($lignes);
}

// ── Réception d'une ligne de suivi (J8, Bloc 2) — une transaction. Une
//    ligne feb_receptions par acte, jamais un écrasement : l'historique de
//    plusieurs livraisons partielles doit rester lisible.
function ach_receptionner(int $suivi_id, int $quantite, string $date, ?string $bl, string $observation, array $user): array {
    $uid = (int)$user['id'];
    if ($quantite <= 0) throw new AchValidationException('La quantité reçue doit être strictement positive.');

    $ligne = db_fetch_one(
        "SELECT fs.*, fl.article_id, fl.designation, fl.type_achat, fl.nomenclature_id,
                fl.quantite AS ligne_quantite, fl.montant_ttc AS ligne_montant_ttc,
                f.numero AS feb_numero, f.acheteur_id, f.demandeur_id, f.departement_id
         FROM feb_suivi fs
         JOIN feb_lignes fl ON fl.id = fs.feb_ligne_id
         JOIN feb f         ON f.id  = fs.feb_id
         WHERE fs.id = ?",
        [$suivi_id]
    );
    if (!$ligne) throw new AchValidationException('Ligne de suivi introuvable.');
    if (empty($ligne['numero_bc'])) throw new AchValidationException("Cette ligne n'a pas de N° BC — elle n'est pas réceptionnable.");
    if (!empty($ligne['cloture_reliquat'])) throw new AchValidationException('Ce reliquat est clôturé — aucune réception supplémentaire possible.');

    $commandee = (int)$ligne['quantite_commandee'];
    $recue     = (int)$ligne['quantite_recue'];
    $reste     = $commandee - $recue;
    // Message chiffré (point 8) : pas une saisie corrigée en silence.
    if ($quantite > $reste) {
        throw new AchValidationException("Quantité reçue supérieure au reste à recevoir (reste $reste, saisi $quantite).");
    }

    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        $cumul = $recue + $quantite;
        $ecart = $commandee - $cumul;  // positif = reliquat ouvert, zéro = ligne soldée

        db_query(
            "INSERT INTO feb_receptions (feb_suivi_id, quantite_recue, date_reception, bon_livraison, ecart, observation, recu_par)
             VALUES (?,?,?,?,?,?,?)",
            [$suivi_id, $quantite, $date, $bl, max(0, $ecart), $observation ?: null, $uid]
        );

        // date_livraison_reelle posée une seule fois, quand le cumul atteint
        // la quantité commandée — pas à la première réception partielle.
        if ($ecart <= 0) {
            db_query(
                "UPDATE feb_suivi SET quantite_recue=?, date_livraison_reelle = COALESCE(date_livraison_reelle, ?) WHERE id=?",
                [$cumul, $date, $suivi_id]
            );
        } else {
            db_query("UPDATE feb_suivi SET quantite_recue=? WHERE id=?", [$cumul, $suivi_id]);
        }

        // Bloc 0 : la réception alimente le stock global ET le stock du site
        // de la FEB (le magasin) — pas encore le stock du département.
        // Décision du 2026-08-23 : le crédit département n'a plus lieu ici,
        // mais à la confirmation de réception du département lui-même
        // (ach_receptionner_departement()), après une étape d'expédition
        // explicite (ach_expedier_departement()) — sans ça, le stock était
        // compté « au département » alors qu'il vient tout juste d'arriver
        // au magasin, avant même d'en être physiquement sorti. Rien à faire
        // pour une ligne en saisie libre (article_id NULL) : pas d'article à
        // créditer.
        if ($ligne['article_id']) {
            db_query("UPDATE articles SET stock_global = stock_global + ? WHERE id=?", [$quantite, $ligne['article_id']]);
            db_query(
                "INSERT INTO stock_site (article_id, site_id, quantite) VALUES (?,?,?)
                 ON CONFLICT (article_id, site_id) DO UPDATE SET quantite = stock_site.quantite + ?",
                [$ligne['article_id'], $ligne['site_id'], $quantite, $quantite]
            );
        }

        // Étape 3b : une ligne DAI rattachée à une nomenclature (au plus
        // tôt à cette réception, cf. ach_lier_nomenclature_ligne()) produit
        // un exemplaire equipements PAR UNITÉ reçue, jamais un par ligne —
        // une quantité de 5 donne 5 fiches affectables séparément
        // (Étape 4). Sans rattachement, la ligne reste réceptionnable
        // normalement : c'est le choix retenu, pas un blocage silencieux.
        if ($ligne['type_achat'] === 'DAI' && $ligne['nomenclature_id']) {
            $ligne_quantite = (int)$ligne['ligne_quantite'];
            // Valorisé au montant TOTAL de la ligne divisé par sa quantité
            // D'ORIGINE (fl.quantite) — jamais par la quantité de cette
            // réception si elle n'est que partielle.
            $prix_unitaire = $ligne_quantite > 0 ? (int) round(((int)$ligne['ligne_montant_ttc']) / $ligne_quantite) : 0;
            $nomenclature  = db_fetch_one("SELECT categorie, duree_amortissement_mois FROM nomenclatures WHERE id=?", [(int)$ligne['nomenclature_id']]);
            $chrono = (int) db_fetch_value("SELECT COALESCE(MAX(numero_chrono),0) FROM equipements");
            for ($i = 1; $i <= $quantite; $i++) {
                $chrono++;
                // Numéro de série interne provisoire, unique et traçable
                // jusqu'à la ligne d'origine — à corriger par le magasin une
                // fois l'exemplaire physiquement étiqueté (pages/equipements.php).
                $nsi = "DAI-{$ligne['feb_ligne_id']}-" . ($recue + $i);
                db_query(
                    "INSERT INTO equipements
                     (numero_serie_interne, numero_chrono, nomenclature_id, categorie, departement_id, feb_ligne_id,
                      etat, statut_stock, date_acquisition, prix_achat, duree_amortissement_mois, actif, created_by)
                     VALUES (?,?,?,?,?,?, 'neuf','en_attente_affectation', ?,?,?, 1, ?)",
                    [$nsi, $chrono, (int)$ligne['nomenclature_id'], $nomenclature['categorie'] ?? 'informatique',
                     $ligne['departement_id'] ?: null, (int)$ligne['feb_ligne_id'], $date, $prix_unitaire,
                     $nomenclature['duree_amortissement_mois'] ?? null, $uid]
                );
            }
            audit_log($uid, 'CREATE', 'equipements', (int)$ligne['feb_ligne_id'],
                "$quantite exemplaire(s) créé(s) en attente d'affectation — « {$ligne['designation']} » (FEB {$ligne['feb_numero']})");
        }

        audit_log($uid, 'CREATE', 'achats_suivi', $suivi_id,
            "Réception de $quantite sur « {$ligne['designation']} » (FEB {$ligne['feb_numero']}) — cumul $cumul, écart " . max(0, $ecart));

        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    if ($ligne['acheteur_id']) {
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Réception',?,?)",
            [(int)$ligne['acheteur_id'],
             "Réception de $quantite sur « {$ligne['designation']} » (FEB {$ligne['feb_numero']}) — cumul $cumul, reste " . max(0, $ecart) . '.',
             '/pages/achats/suivi_achats.php']
        );
    }
    // Pas de notification « besoin couvert » ici : le solde côté magasin ne
    // dit pas encore que le département a la marchandise en main — cf.
    // ach_receptionner_departement(), qui porte cette notification depuis
    // le 2026-08-23.

    return ['cumul' => $cumul, 'ecart' => max(0, $ecart), 'solde' => $ecart <= 0];
}

// ── Expédition du magasin vers le département (étape 2/3) — débite le
//    stock du site où la réception magasin a été créditée (jamais plus que
//    ce qui a été reçu au magasin et pas déjà expédié), ne crédite rien :
//    le département n'a pas encore confirmé. Même profil que la réception
//    magasin (achats_suivi.can_create) — décision du 2026-08-23.
function ach_expedier_departement(int $suivi_id, int $quantite, string $date, string $observation, array $user): array {
    $uid = (int)$user['id'];
    if ($quantite <= 0) throw new AchValidationException('La quantité expédiée doit être strictement positive.');

    $ligne = db_fetch_one(
        "SELECT fs.*, fl.article_id, fl.designation, fl.type_achat, f.numero AS feb_numero
         FROM feb_suivi fs
         JOIN feb_lignes fl ON fl.id = fs.feb_ligne_id
         JOIN feb f         ON f.id  = fs.feb_id
         WHERE fs.id = ?",
        [$suivi_id]
    );
    if (!$ligne) throw new AchValidationException('Ligne de suivi introuvable.');
    // DAI exclu : une immobilisation suit son propre circuit (file d'attente
    // équipements -> proposition d'affectation -> validation -> confirmation),
    // pas celui-ci. En dehors de ce cas, une ligne sans article_id (saisie
    // libre — ex. « Demande d'Achat Fournitures » qui ne correspond à aucun
    // article du référentiel) reste expédiable : elle n'a simplement aucun
    // stock à créditer/débiter, cf. plus bas.
    if ($ligne['type_achat'] === 'DAI') {
        throw new AchValidationException("Ligne DAI — le transfert vers le département se fait par la file d'attente équipements, pas ici.");
    }

    $recue     = (int)$ligne['quantite_recue'];
    $expediee  = (int)$ligne['quantite_expediee'];
    $reste     = $recue - $expediee;
    if ($quantite > $reste) {
        throw new AchValidationException("Quantité expédiée supérieure au reste disponible au magasin (reste $reste, saisi $quantite).");
    }

    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        db_query(
            "INSERT INTO feb_expeditions (feb_suivi_id, quantite, date_expedition, observation, expedie_par)
             VALUES (?,?,?,?,?)",
            [$suivi_id, $quantite, $date, $observation ?: null, $uid]
        );
        db_query("UPDATE feb_suivi SET quantite_expediee = quantite_expediee + ? WHERE id=?", [$quantite, $suivi_id]);
        if ($ligne['article_id']) {
            db_query(
                "UPDATE stock_site SET quantite = quantite - ? WHERE article_id=? AND site_id=?",
                [$quantite, $ligne['article_id'], $ligne['site_id']]
            );
        }
        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    audit_log($uid, 'UPDATE', 'achats_suivi', $suivi_id, "Expédition vers le département de $quantite sur « {$ligne['designation']} » (FEB {$ligne['feb_numero']})");

    if ($ligne['departement_id']) {
        foreach (db_fetch_all(
            "SELECT user_id FROM user_departements WHERE departement_id=? AND is_n1=1", [(int)$ligne['departement_id']]
        ) as $n1) {
            db_query(
                "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Expédition en cours',?,?)",
                [(int)$n1['user_id'], "$quantite unité(s) de « {$ligne['designation']} » (FEB {$ligne['feb_numero']}) expédiée(s) vers votre département — à réceptionner.", '/pages/achats/receptions.php']
            );
        }
    }

    return ['cumul_expediee' => (int)$ligne['quantite_expediee'] + $quantite];
}

// ── Réception par le département (étape 3/3) — seul geste qui crédite enfin
//    stock_departement. Réservée au N+1 du département de la FEB (même
//    porte que la réception magasin lui accorde déjà, cf.
//    ach_est_n1_quelque_part()) — revérifiée ici, pas seulement à l'écran.
function ach_receptionner_departement(int $suivi_id, int $quantite, string $date, string $observation, array $user, ?string $bon_transfert = null): array {
    $uid = (int)$user['id'];
    if ($quantite <= 0) throw new AchValidationException('La quantité reçue doit être strictement positive.');

    $ligne = db_fetch_one(
        "SELECT fs.*, fl.article_id, fl.designation, fl.type_achat, f.numero AS feb_numero, f.demandeur_id, f.departement_id
         FROM feb_suivi fs
         JOIN feb_lignes fl ON fl.id = fs.feb_ligne_id
         JOIN feb f         ON f.id  = fs.feb_id
         WHERE fs.id = ?",
        [$suivi_id]
    );
    if (!$ligne) throw new AchValidationException('Ligne de suivi introuvable.');
    // DAI exclu, même raison que ach_expedier_departement() — sans article_id
    // (saisie libre), la ligne reste réceptionnable, elle n'a simplement
    // aucun stock département à créditer, cf. plus bas.
    if ($ligne['type_achat'] === 'DAI') {
        throw new AchValidationException("Ligne DAI — la confirmation de réception se fait par la file d'attente équipements, pas ici.");
    }
    if (!$ligne['departement_id']) throw new AchValidationException('Cette ligne ne porte aucun département.');

    $est_n1 = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=? AND is_n1=1",
        [$uid, (int)$ligne['departement_id']]
    );
    $is_admin = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
    if (!$est_n1 && !$is_admin) {
        throw new AchValidationException("Seul le supérieur hiérarchique du département peut confirmer cette réception.");
    }

    $expediee = (int)$ligne['quantite_expediee'];
    $recue_dept = (int)$ligne['quantite_receptionnee_departement'];
    $reste = $expediee - $recue_dept;
    if ($quantite > $reste) {
        throw new AchValidationException("Quantité supérieure à ce qui a été expédié et pas encore confirmé (reste $reste, saisi $quantite).");
    }

    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        db_query(
            "INSERT INTO feb_receptions_departement (feb_suivi_id, quantite, date_reception, observation, recu_par, bon_transfert)
             VALUES (?,?,?,?,?,?)",
            [$suivi_id, $quantite, $date, $observation ?: null, $uid, $bon_transfert]
        );
        db_query("UPDATE feb_suivi SET quantite_receptionnee_departement = quantite_receptionnee_departement + ? WHERE id=?", [$quantite, $suivi_id]);
        if ($ligne['article_id']) {
            ach_crediter_stock_departement((int)$ligne['article_id'], (int)$ligne['departement_id'], $quantite);
        }
        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    $cumul = $recue_dept + $quantite;
    audit_log($uid, 'UPDATE', 'achats_suivi', $suivi_id, "Réception département de $quantite sur « {$ligne['designation']} » (FEB {$ligne['feb_numero']}) — cumul $cumul");

    if ($cumul >= $expediee && $ligne['demandeur_id']) {
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Besoin couvert',?,?)",
            [(int)$ligne['demandeur_id'],
             "Votre besoin « {$ligne['designation']} » (FEB {$ligne['feb_numero']}) est arrivé dans votre département.",
             '/pages/achats/mes_feb.php']
        );
    }

    return ['cumul' => $cumul, 'reste' => max(0, $expediee - $cumul)];
}

// ── Clôture explicite d'un reliquat (J8, Bloc 3) — pour les cas où le solde
//    n'arrivera jamais (rupture fournisseur, commande partiellement
//    annulée). Réservée à l'acheteur qui détient la FEB. Enregistrée comme
//    un acte de réception à quantité nulle (même table, même historique),
//    avec le motif dans feb_receptions.motif_ecart — jamais confondu avec
//    observation, réservée aux remarques de livraison normales.
function ach_cloturer_reliquat(int $suivi_id, string $motif, array $user): void {
    $uid   = (int)$user['id'];
    $motif = trim($motif);
    if ($motif === '') throw new AchValidationException('Le motif de clôture est obligatoire.');

    $ligne = db_fetch_one(
        "SELECT fs.*, fl.designation, f.numero AS feb_numero, f.acheteur_id
         FROM feb_suivi fs
         JOIN feb_lignes fl ON fl.id = fs.feb_ligne_id
         JOIN feb f         ON f.id  = fs.feb_id
         WHERE fs.id = ?",
        [$suivi_id]
    );
    if (!$ligne) throw new AchValidationException('Ligne de suivi introuvable.');
    if ((int)$ligne['acheteur_id'] !== $uid) throw new AchValidationException("Seul l'acheteur de cette FEB peut clôturer ce reliquat.");
    if (!empty($ligne['cloture_reliquat'])) throw new AchValidationException('Ce reliquat est déjà clôturé.');

    $ecart = (int)$ligne['quantite_commandee'] - (int)$ligne['quantite_recue'];
    if ($ecart <= 0) throw new AchValidationException('Cette ligne est déjà entièrement reçue — rien à clôturer.');

    $today = date('Y-m-d');
    $pdo = get_db();
    $transaction_locale = !$pdo->inTransaction();
    if ($transaction_locale) db_begin();
    try {
        db_query(
            "INSERT INTO feb_receptions (feb_suivi_id, quantite_recue, date_reception, ecart, motif_ecart, recu_par)
             VALUES (?,0,?,?,?,?)",
            [$suivi_id, $today, $ecart, $motif, $uid]
        );
        // Écart figé : quantite_recue n'est jamais forcée à quantite_commandee
        // — la fiche doit rester lisible comme "clôturée avec écart", pas
        // confondue avec une ligne totalement soldée.
        db_query(
            "UPDATE feb_suivi SET cloture_reliquat = 1, date_livraison_reelle = COALESCE(date_livraison_reelle, ?) WHERE id = ?",
            [$today, $suivi_id]
        );
        audit_log($uid, 'UPDATE', 'achats_suivi', $suivi_id,
            "Clôture du reliquat « {$ligne['designation']} » (FEB {$ligne['feb_numero']}) — écart figé à $ecart : $motif");
        if ($transaction_locale) db_commit();
    } catch (Exception $e) {
        if ($transaction_locale) db_rollback();
        throw $e;
    }

    if ($ligne['acheteur_id']) {
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,'info','Reliquat clôturé',?,?)",
            [(int)$ligne['acheteur_id'],
             "Reliquat clôturé sur « {$ligne['designation']} » (FEB {$ligne['feb_numero']}) — écart figé à $ecart : $motif",
             '/pages/achats/suivi_achats.php']
        );
    }
}

// ── J9 — Tableaux de bord : périmètre département ────────────
//    NULL = vue globale (Achats, direction, finance — RAF/DAF/PDG voient
//    déjà tout le circuit de validation, même logique ici) ; tableau =
//    restriction aux départements de l'utilisateur (user_departements,
//    même table que le N+1/di_roles) ; [] = aucun département couvert,
//    périmètre vide plutôt qu'une vue globale par défaut dangereuse.
function ach_perimetre_departements(array $user): ?array {
    $role = $user['role_slug'] ?? '';
    // Vocation globale par nature : direction, Achats, PDG, RAF, DAF.
    // RAF/DAF couvrent tout le circuit financier sans exception — même s'ils
    // sont par ailleurs rattachés à un département dans Admin → Départements
    // (rattachement qui reste utilisé ailleurs, ex. circuits de demandes
    // internes) : ce rattachement ne doit jamais réduire leur périmètre
    // budgétaire. Règle changée le 2026-08-17 sur demande explicite —
    // l'ancien comportement (scope si rattaché) était délibéré mais a créé
    // une restriction non voulue en pratique dès qu'un RAF/DAF avait un
    // département assigné pour une autre raison.
    if (in_array($role, ['admin', 'superadmin', 'superviseur_achat', 'directeur_general', 'lecteur', 'raf', 'daf'], true)) {
        return null;
    }
    return array_map('intval', array_column(
        db_fetch_all("SELECT DISTINCT departement_id FROM user_departements WHERE user_id=?", [(int)$user['id']]),
        'departement_id'
    ));
}

// Fragment de clause + paramètres pour restreindre une requête sur feb à un
// périmètre de départements. $depts === null : aucune restriction.
function ach_clause_departement(?array $depts, string $alias = 'f'): array {
    if ($depts === null) return ['', []];
    if (!$depts) return [" AND 1=0", []];
    $place = implode(',', array_fill(0, count($depts), '?'));
    return [" AND $alias.departement_id IN ($place)", $depts];
}

// Moyenne ET médiane (jours) — un délai extrême ne doit pas masquer les
// autres (Bloc 3, point 14).
function ach_delais_jours(string $sql, array $params): array {
    $valeurs = array_map('floatval', array_column(db_fetch_all($sql, $params), 'd'));
    if (!$valeurs) return ['moyenne' => null, 'mediane' => null, 'n' => 0];
    sort($valeurs);
    $n = count($valeurs);
    $mid = intdiv($n, 2);
    $mediane = $n % 2 === 0 ? ($valeurs[$mid - 1] + $valeurs[$mid]) / 2 : $valeurs[$mid];
    return ['moyenne' => round(array_sum($valeurs) / $n, 1), 'mediane' => round($mediane, 1), 'n' => $n];
}

// ── KPI du dashboard opérationnel (Bloc 3) ────────────────────
function ach_dashboard_kpis(array $user, ?array $depts, string $du, string $au): array {
    [$clause, $pd] = ach_clause_departement($depts, 'f');

    // À traiter aujourd'hui : file d'attente non prise en charge (J3) +
    // visas en attente de CET utilisateur (J5) — un seul chiffre agrégé.
    $a_viser   = count(ach_a_viser($user));
    $non_prise = (int) db_fetch_value("SELECT COUNT(*) FROM feb f WHERE f.statut='soumise' AND f.acheteur_id IS NULL$clause", $pd);

    // Taux de service sur stock — la mesure directe que J3 a rendue possible.
    $arb = db_fetch_one(
        "SELECT COUNT(*) FILTER (WHERE fl.arbitrage='stock') AS nb_stock, COUNT(*) AS nb_total
         FROM feb_lignes fl JOIN feb f ON f.id=fl.feb_id
         WHERE f.date_soumission BETWEEN ? AND ?$clause",
        array_merge([$du, $au], $pd)
    );
    $taux_service_stock = $arb && (int)$arb['nb_total'] > 0 ? round(100 * (int)$arb['nb_stock'] / (int)$arb['nb_total'], 1) : null;

    $delai_prise_charge = ach_delais_jours(
        "SELECT EXTRACT(EPOCH FROM (date_prise_charge - date_soumission))/86400 AS d
         FROM feb f WHERE date_prise_charge IS NOT NULL AND date_soumission BETWEEN ? AND ?$clause",
        array_merge([$du, $au], $pd)
    );
    $delai_validation = ach_delais_jours(
        "SELECT EXTRACT(EPOCH FROM (date_confirmation - date_lancement_validation))/86400 AS d
         FROM feb f WHERE date_confirmation IS NOT NULL AND date_lancement_validation IS NOT NULL AND date_soumission BETWEEN ? AND ?$clause",
        array_merge([$du, $au], $pd)
    );
    $delai_livraison = ach_delais_jours(
        "SELECT EXTRACT(EPOCH FROM (fs.date_livraison_reelle - f.date_confirmation))/86400 AS d
         FROM feb_suivi fs JOIN feb f ON f.id=fs.feb_id
         WHERE fs.date_livraison_reelle IS NOT NULL AND f.date_confirmation IS NOT NULL AND f.date_soumission BETWEEN ? AND ?$clause",
        array_merge([$du, $au], $pd)
    );
    // date_da/date_bc sont des DATE, pas des TIMESTAMP comme les trois delais
    // ci-dessus : leur soustraction donne déjà un entier (nombre de jours),
    // pas un intervalle — EXTRACT(EPOCH FROM ...) y échoue (42883).
    $delai_da_bc = ach_delais_jours(
        "SELECT (fs.date_bc - fs.date_da) AS d
         FROM feb_suivi fs JOIN feb f ON f.id=fs.feb_id
         WHERE fs.date_bc IS NOT NULL AND fs.date_da IS NOT NULL AND f.date_soumission BETWEEN ? AND ?$clause",
        array_merge([$du, $au], $pd)
    );

    // Taux de livraison à temps et complète — formulation retenue à la
    // place du sigle OTIF (point 15) : receptionnee, sans clôture de
    // reliquat, et livrée au plus tard à la date prévue.
    $liv = db_fetch_one(
        "SELECT COUNT(*) FILTER (WHERE fs.date_livraison_reelle <= fs.date_livraison_prevue) AS nb_temps, COUNT(*) AS nb_total
         FROM feb_suivi fs JOIN feb f ON f.id=fs.feb_id
         WHERE fs.quantite_recue >= fs.quantite_commandee AND fs.cloture_reliquat=0
           AND fs.date_livraison_reelle IS NOT NULL AND f.date_soumission BETWEEN ? AND ?$clause",
        array_merge([$du, $au], $pd)
    );
    $taux_livraison_temps = $liv && (int)$liv['nb_total'] > 0 ? round(100 * (int)$liv['nb_temps'] / (int)$liv['nb_total'], 1) : null;

    return [
        'a_traiter' => $a_viser + $non_prise, 'a_viser' => $a_viser, 'non_prise' => $non_prise,
        'taux_service_stock' => $taux_service_stock,
        'delai_prise_charge' => $delai_prise_charge,
        'delai_validation'   => $delai_validation,
        'delai_livraison'    => $delai_livraison,
        'delai_da_bc'        => $delai_da_bc,
        'taux_livraison_temps' => $taux_livraison_temps,
    ];
}

// ── Répartition des dépenses (Bloc 4, point 16-17) — barres, jamais un
//    camembert. $axe : 'famille' | 'departement' | 'fournisseur'.
//    Zéro-safe par construction : une requête sans ligne renvoie un
//    tableau vide, jamais une division par zéro (aucune division ici).
function ach_repartition_depenses(string $axe, ?array $depts, string $du, string $au): array {
    [$clause, $pd] = ach_clause_departement($depts, 'f');
    $params = array_merge([$du, $au], $pd);

    if ($axe === 'famille') {
        $sql = "SELECT COALESCE(fa.libelle,'—') AS label, SUM(fl.montant_ttc) AS total
                FROM feb_lignes fl JOIN feb f ON f.id=fl.feb_id
                LEFT JOIN familles_achat fa ON fa.id=fl.famille_id
                WHERE fl.arbitrage='achat' AND f.date_soumission BETWEEN ? AND ?$clause
                GROUP BY fa.libelle ORDER BY total DESC";
    } elseif ($axe === 'departement') {
        $sql = "SELECT COALESCE(d.label,'—') AS label, SUM(fl.montant_ttc) AS total
                FROM feb_lignes fl JOIN feb f ON f.id=fl.feb_id
                LEFT JOIN departements d ON d.id=f.departement_id
                WHERE fl.arbitrage='achat' AND f.date_soumission BETWEEN ? AND ?$clause
                GROUP BY d.label ORDER BY total DESC";
    } else {
        $sql = "SELECT fo.raison_sociale AS label, SUM(fl.montant_ttc) AS total
                FROM feb_lignes fl JOIN feb f ON f.id=fl.feb_id
                JOIN fournisseurs fo ON fo.id=fl.fournisseur_id
                WHERE fl.arbitrage='achat' AND f.date_soumission BETWEEN ? AND ?$clause
                GROUP BY fo.raison_sociale ORDER BY total DESC LIMIT 10";
    }
    $rows = db_fetch_all($sql, $params);
    foreach ($rows as &$r) { $r['total'] = (int)$r['total']; }
    unset($r);
    return $rows;
}

// ── Alertes cliquables (Bloc 4, point 18-19) — chacune retourne la liste
//    des FEB concernées, jamais un chiffre isolé.
function ach_alertes_retard_validation(?array $depts): array {
    [$clause, $pd] = ach_clause_departement($depts, 'f');
    $seuil = (int) ach_param('seuil_retard_validation_jours', 5);
    return db_fetch_all(
        "SELECT f.id, f.numero, f.objet, f.montant_total, f.date_lancement_validation,
                CONCAT(u.prenom,' ',u.nom) AS demandeur_nom, d.label AS departement_label,
                DATE_PART('day', NOW() - f.date_lancement_validation) AS jours_ecoules
         FROM feb f
         LEFT JOIN users u ON u.id=f.demandeur_id
         LEFT JOIN departements d ON d.id=f.departement_id
         WHERE f.statut='en_validation' AND f.date_lancement_validation < NOW() - (?::text || ' days')::interval$clause
         ORDER BY f.date_lancement_validation ASC",
        array_merge([$seuil], $pd)
    );
}

function ach_alertes_suivi_retard(?array $depts): array {
    [$clause, $pd] = ach_clause_departement($depts, 'f');
    $rows = db_fetch_all(
        "SELECT fs.*, f.numero, f.objet, f.montant_total, CONCAT(u.prenom,' ',u.nom) AS demandeur_nom,
                d.label AS departement_label, fl.designation
         FROM feb_suivi fs
         JOIN feb f         ON f.id  = fs.feb_id
         JOIN feb_lignes fl ON fl.id = fs.feb_ligne_id
         LEFT JOIN users u        ON u.id = f.demandeur_id
         LEFT JOIN departements d ON d.id = f.departement_id
         WHERE 1=1$clause",
        $pd
    );
    return array_values(array_filter($rows, fn($r) => ach_statut_suivi_calcule($r) === 'en_retard'));
}

function ach_alertes_depassement_budget(?array $depts, string $du, string $au): array {
    [$clause, $pd] = ach_clause_departement($depts, 'f');
    return db_fetch_all(
        "SELECT a.id AS audit_id, a.description, a.created_at, f.id AS feb_id, f.numero, f.objet, f.montant_total,
                CONCAT(u.prenom,' ',u.nom) AS demandeur_nom, d.label AS departement_label
         FROM audit_log a
         JOIN feb f ON f.id = a.entite_id AND a.module = 'achats'
         LEFT JOIN users u        ON u.id = f.demandeur_id
         LEFT JOIN departements d ON d.id = f.departement_id
         WHERE a.description ILIKE '%dépassement de%' AND a.created_at BETWEEN ? AND ?$clause
         ORDER BY a.created_at DESC",
        array_merge([$du . ' 00:00:00', $au . ' 23:59:59'], $pd)
    );
}

// ── Score fournisseur (Bloc 6) — deux critères mesurables aujourd'hui,
//    jamais agrégés en une note unique tant que leur pondération n'est pas
//    arbitrée (point 23). Le troisième critère annoncé à l'origine (le
//    service) reste hors V1 : sa donnée n'existe pas encore.
function ach_score_fournisseur(int $fournisseur_id): array {
    // Prix : écart moyen (%) à la moyenne des offres du même lot. Un lot
    // regroupe déjà les offres d'un même article/famille (J4) — c'est donc
    // la base de comparaison naturelle, pas une moyenne toutes familles
    // confondues qui n'aurait pas de sens.
    $offres = db_fetch_all("SELECT montant_ttc, lot, feb_id FROM feb_offres WHERE fournisseur_id=?", [$fournisseur_id]);
    $ecarts = [];
    foreach ($offres as $o) {
        $moyenne_lot = (float) db_fetch_value("SELECT AVG(montant_ttc) FROM feb_offres WHERE feb_id=? AND lot=?", [$o['feb_id'], $o['lot']]);
        if ($moyenne_lot > 0) {
            $ecarts[] = ((float)$o['montant_ttc'] - $moyenne_lot) / $moyenne_lot * 100;
        }
    }
    $score_prix = $ecarts ? round(array_sum($ecarts) / count($ecarts), 1) : null;

    // Délai : part des offres retenues de ce fournisseur dont la livraison
    // réelle a respecté le délai annoncé (delai_annonce jours après le BC).
    $retenues = db_fetch_all(
        "SELECT o.delai_annonce, fs.date_bc, fs.date_livraison_reelle
         FROM feb_offres o
         JOIN feb_lignes fl ON fl.feb_id = o.feb_id AND fl.lot = o.lot AND fl.fournisseur_id = o.fournisseur_id
         JOIN feb_suivi fs  ON fs.feb_ligne_id = fl.id
         WHERE o.fournisseur_id=? AND o.retenue=1 AND o.delai_annonce IS NOT NULL
           AND fs.date_bc IS NOT NULL AND fs.date_livraison_reelle IS NOT NULL",
        [$fournisseur_id]
    );
    $respectes = 0;
    foreach ($retenues as $r) {
        $limite = (new DateTime($r['date_bc']))->modify("+{$r['delai_annonce']} days");
        if (new DateTime($r['date_livraison_reelle']) <= $limite) $respectes++;
    }
    $total = count($retenues);
    $score_delai = $total > 0 ? round(100 * $respectes / $total, 1) : null;

    return ['score_prix' => $score_prix, 'nb_offres' => count($ecarts), 'score_delai' => $score_delai, 'nb_livraisons' => $total];
}

// ── Budget consommé par département, toutes familles confondues
//    (Bloc 5, dashboard direction) — enveloppe totale vs engagé+réservé.
function ach_budget_departements(?array $depts, int $exercice): array {
    [$clause, $pd] = ach_clause_departement($depts, 'd');
    $enveloppes = db_fetch_all(
        "SELECT d.id, d.label, COALESCE(SUM(lb.enveloppe),0) AS enveloppe,
                COALESCE(bv.statut, 'brouillon') AS statut
         FROM departements d
         LEFT JOIN lignes_budgetaires lb  ON lb.departement_id=d.id AND lb.exercice=? AND lb.actif=1
         LEFT JOIN budget_validations bv ON bv.departement_id=d.id AND bv.exercice=?
         WHERE d.actif=1$clause
         GROUP BY d.id, d.label, bv.statut ORDER BY d.label",
        array_merge([$exercice, $exercice], $pd)
    );
    $engages = array_column(
        db_fetch_all(
            "SELECT f.departement_id, SUM(fl.montant_ttc) AS engage
             FROM feb_lignes fl JOIN feb f ON f.id=fl.feb_id
             WHERE fl.arbitrage='achat' AND f.statut IN ('confirmee','en_validation') AND f.exercice=?
             GROUP BY f.departement_id",
            [$exercice]
        ),
        'engage', 'departement_id'
    );
    foreach ($enveloppes as &$e) {
        $engage = (int)($engages[$e['id']] ?? 0);
        $e['enveloppe'] = (int)$e['enveloppe'];
        $e['engage'] = $engage;
        $e['taux'] = $e['enveloppe'] > 0 ? round(100 * $engage / $e['enveloppe'], 1) : null;
    }
    unset($e);
    return $enveloppes;
}

// ── Barres horizontales, une seule teinte (les catégories sont déjà lues
//    sur l'étiquette de chaque barre — une couleur par barre n'encoderait
//    rien de plus) : dépenses par famille/département/fournisseur (Bloc 4).
//    Zéro-safe : une liste vide affiche un état vide, jamais une division
//    par zéro ni un graphique qui ressemble à une erreur.
function ach_html_barres(array $rows, string $message_vide = 'Aucune donnée pour cette période.'): string {
    if (!$rows) {
        return '<div class="ach-empty">' . h($message_vide) . '</div>';
    }
    $max = max(array_column($rows, 'total'));
    if ($max <= 0) {
        return '<div class="ach-empty">' . h($message_vide) . '</div>';
    }
    $html = '<div class="ach-barres">';
    foreach ($rows as $r) {
        $pct = round(100 * $r['total'] / $max, 1);
        $html .= '<div class="ach-barre-row">'
            . '<div class="ach-barre-label">' . h($r['label']) . '</div>'
            . '<div class="ach-barre-track"><div class="ach-barre-fill" style="width:' . $pct . '%"></div></div>'
            . '<div class="ach-barre-val">' . number_format((float)$r['total'], 0, ',', ' ') . ' XOF</div>'
            . '</div>';
    }
    return $html . '</div>';
}
