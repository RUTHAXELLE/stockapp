<?php
// ============================================================
//  tools/inventaire_migrations.php
//  Inventaire des 77 migrations SQL contre une base réelle.
//
//  Livrable du jalon J1 du plan de mise en service (5 octobre 2026) :
//  « pour chacune des 77 migrations, on sait dire appliquée ou non ».
//  Aucune table ne suit ce qui a été joué sur Neon ; les migrations sont
//  passées à la main au fil des livraisons. Le 8 septembre, l'une d'elles
//  s'est révélée jamais exécutée en production (commande_compteurs
//  absente) — découverte par hasard, pas par vérification.
//
//  ── Ce que fait ce script ──
//  Il ne rejoue AUCUNE migration. Il lit chaque fichier sql/migration_*.sql,
//  en extrait les objets structurels qu'il déclare créer (tables, colonnes,
//  index, contraintes), puis vérifie leur présence réelle dans
//  information_schema / pg_indexes de la base ciblée. Une migration dont
//  tous les objets existent est réputée appliquée ; une dont aucun
//  n'existe, non appliquée ; une dont une partie seulement existe,
//  PARTIELLE — c'est le cas le plus dangereux, celui d'une migration
//  interrompue en cours de route.
//
//  Trois limites assumées, plutôt que masquées par un résultat qui
//  semblerait complet :
//   1. Les migrations purement DML (INSERT/UPDATE — la plupart des
//      migrations de permissions) n'ont pas d'objet structurel à
//      vérifier. Le script les liste à part, sous « non vérifiable
//      structurellement » : un humain doit les contrôler autrement
//      (ex. relire la matrice Admin > Permissions).
//   2. Trois fichiers sont de la syntaxe MySQL d'avant la bascule
//      PostgreSQL du 21/07/2026 (marqueurs ENGINE=/AUTO_INCREMENT/
//      USE `db`) : ils sont couverts par sql/stockapp_pg.sql, qui fait
//      autorité depuis (cf. CLAUDE.md). Listés à part, jamais vérifiés
//      contre Postgres.
//   3. Une table peut exister sans que TOUTES ses colonnes de la
//      migration soient identiques (type, défaut) : ce script vérifie
//      la PRÉSENCE des objets, pas leur définition exacte.
//
//  ── Usage ──
//    php tools/inventaire_migrations.php                  rapport lisible
//    php tools/inventaire_migrations.php --json            rapport en JSON
//    php tools/inventaire_migrations.php --creer-suivi     + table de suivi
//
//  La connexion suit exactement includes/db.php : DATABASE_URL ou
//  DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS/DB_SSLMODE en variables
//  d'environnement. Pour cibler Neon, positionner ces variables avant
//  d'appeler le script — ce script ne demande ni ne stocke aucun secret.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script est un outil en ligne de commande, pas une page web.\n");
}

require_once __DIR__ . '/../includes/db.php';

$args         = array_slice($argv, 1);
$mode_json    = in_array('--json', $args, true);
$creer_suivi  = in_array('--creer-suivi', $args, true);
$dossier_sql  = realpath(__DIR__ . '/../sql');

// ============================================================
//  1. LECTURE DES FICHIERS
// ============================================================
//  sql/ contient 77 fichiers .sql, pas tous des migrations à auditer :
//   - stockapp.sql / stockapp_pg.sql       : dumps de base, le socle sur
//                                             lequel les migrations
//                                             s'appliquent, pas une
//                                             migration elles-mêmes.
//   - recette_*.sql                        : schémas destinés à une base
//                                             de recette Achats séparée
//                                             (« à coller dans l'éditeur
//                                             SQL de Neon, sur une base
//                                             VIDE » — en-tête du
//                                             fichier), pas à la
//                                             production.
//   - le reste                             : les migrations réelles. La
//                                             plupart nommées
//                                             migration_*.sql, mais pas
//                                             toutes — add_bus_post_platform.sql,
//                                             agents_pg.sql et
//                                             demandes_internes_pg.sql
//                                             modifient le schéma sans
//                                             porter ce préfixe. Les
//                                             exclure serait précisément
//                                             le genre de trou que cet
//                                             inventaire existe pour
//                                             repérer.
$exclus = ['stockapp.sql', 'stockapp_pg.sql'];
$fichiers = array_values(array_filter(
    glob($dossier_sql . '/*.sql'),
    fn($p) => !in_array(basename($p), $exclus, true) && !str_starts_with(basename($p), 'recette_')
));
sort($fichiers, SORT_STRING);
if (!$fichiers) {
    fwrite(STDERR, "Aucun fichier de migration trouvé dans sql/.\n");
    exit(2);
}

/**
 * Un fichier est pré-PostgreSQL s'il porte un marqueur MySQL sans
 * équivoque. Le simple backtick ne suffit pas : plusieurs migrations
 * l'utilisent en prose dans un commentaire (« le catalogue devient la
 * référence, et son `code` reprend... »), pas comme guillemet
 * d'identifiant SQL.
 */
function est_pre_postgres(string $sql): bool {
    return (bool) preg_match('/\bENGINE\s*=|\bAUTO_INCREMENT\b|^\s*USE\s+`/im', $sql);
}

/**
 * Objets structurels qu'une migration déclare créer, extraits par
 * expression régulière sur le SQL débarrassé de ses commentaires
 * « -- » (sans quoi un commentaire citant un nom de colonne entre
 * backticks se ferait passer pour une déclaration).
 */
function extraire_objets(string $sql): array {
    $sans_commentaires = preg_replace('/--[^\n]*/', '', $sql);
    $objets = [];

    // Le nom peut être qualifié par son schéma (« public.op_endommagements » :
    // trois fichiers l'écrivent ainsi). Sans le groupe optionnel ci-dessous,
    // la capture s'arrête à « public » — le nom du schéma, pas celui de la
    // table — et fait passer une table réellement créée pour absente.
    if (preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[a-zA-Z_][a-zA-Z0-9_]*`?\.)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
        $sans_commentaires, $m)) {
        foreach ($m[1] as $t) $objets[] = ['type' => 'table', 'nom' => strtolower($t)];
    }

    if (preg_match_all(
        '/ALTER\s+TABLE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+ADD\s+COLUMN\s+'
        . '(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
        $sans_commentaires, $m)) {
        foreach ($m[1] as $i => $t)
            $objets[] = ['type' => 'colonne', 'table' => strtolower($t), 'nom' => strtolower($m[2][$i])];
    }

    if (preg_match_all(
        '/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
        $sans_commentaires, $m)) {
        foreach ($m[1] as $t) $objets[] = ['type' => 'index', 'nom' => strtolower($t)];
    }

    if (preg_match_all(
        '/ADD\s+CONSTRAINT\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
        $sans_commentaires, $m)) {
        foreach ($m[1] as $t) $objets[] = ['type' => 'contrainte', 'nom' => strtolower($t)];
    }

    return $objets;
}

$fiches = [];
foreach ($fichiers as $chemin) {
    $nom = basename($chemin);
    $sql = file_get_contents($chemin);
    $fiches[$nom] = [
        'pre_postgres' => est_pre_postgres($sql),
        'objets'       => extraire_objets($sql),
    ];
}

// ============================================================
//  2. ÉTAT RÉEL DE LA BASE — quelques requêtes, pas une par objet
// ============================================================
$db = get_db();

$tables_en_base = array_flip(array_map('strtolower', array_column(
    db_fetch_all("SELECT table_name FROM information_schema.tables WHERE table_schema='public'"),
    'table_name')));

$colonnes_en_base = [];
foreach (db_fetch_all(
    "SELECT table_name, column_name FROM information_schema.columns WHERE table_schema='public'") as $r) {
    $colonnes_en_base[strtolower($r['table_name'])][strtolower($r['column_name'])] = true;
}

$index_en_base = array_flip(array_map('strtolower', array_column(
    db_fetch_all("SELECT indexname FROM pg_indexes WHERE schemaname='public'"),
    'indexname')));

$contraintes_en_base = array_flip(array_map('strtolower', array_column(
    db_fetch_all("SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema='public'"),
    'constraint_name')));

/** Un objet existe-t-il réellement dans la base ciblée ? */
function objet_present(array $o): bool {
    global $tables_en_base, $colonnes_en_base, $index_en_base, $contraintes_en_base;
    switch ($o['type']) {
        case 'table':      return isset($tables_en_base[$o['nom']]);
        case 'colonne':    return isset($colonnes_en_base[$o['table']][$o['nom']]);
        case 'index':      return isset($index_en_base[$o['nom']]);
        case 'contrainte': return isset($contraintes_en_base[$o['nom']]);
    }
    return false;
}

// ============================================================
//  3. CLASSEMENT
// ============================================================
$resultat = [];
foreach ($fiches as $nom => $f) {
    if ($f['pre_postgres']) {
        $resultat[$nom] = ['statut' => 'pre_postgres', 'presents' => 0, 'total' => 0, 'manquants' => []];
        continue;
    }
    if (!$f['objets']) {
        $resultat[$nom] = ['statut' => 'non_verifiable', 'presents' => 0, 'total' => 0, 'manquants' => []];
        continue;
    }
    $total = count($f['objets']);
    $manquants = [];
    $presents = 0;
    foreach ($f['objets'] as $o) {
        if (objet_present($o)) {
            $presents++;
        } else {
            $manquants[] = $o['type'] === 'colonne'
                ? "{$o['table']}.{$o['nom']}"
                : "{$o['type']} {$o['nom']}";
        }
    }
    $statut = $presents === $total ? 'appliquee' : ($presents === 0 ? 'non_appliquee' : 'partielle');
    $resultat[$nom] = compact('statut', 'presents', 'total', 'manquants');
}

// ============================================================
//  4. RAPPORT
// ============================================================
$comptes = array_count_values(array_column($resultat, 'statut'));
foreach (['appliquee', 'partielle', 'non_appliquee', 'non_verifiable', 'pre_postgres'] as $k)
    $comptes[$k] = $comptes[$k] ?? 0;

if ($mode_json) {
    echo json_encode([
        'genere_le' => date('c'),
        'total_fichiers' => count($resultat),
        'comptes' => $comptes,
        'detail' => $resultat,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    $LIBELLES = [
        'appliquee'      => 'appliquée',
        'partielle'      => 'PARTIELLE — à examiner en priorité',
        'non_appliquee'  => 'NON appliquée',
        'non_verifiable' => 'non vérifiable structurellement (DML seul)',
        'pre_postgres'   => 'pré-PostgreSQL — couverte par stockapp_pg.sql',
    ];

    echo "============================================================\n";
    echo " Inventaire des migrations — " . count($resultat) . " fichiers, "
       . date('Y-m-d H:i') . "\n";
    echo "============================================================\n\n";

    foreach (['partielle', 'non_appliquee'] as $prioritaire) {
        $lignes = array_filter($resultat, fn($r) => $r['statut'] === $prioritaire);
        if (!$lignes) continue;
        echo "── " . strtoupper($LIBELLES[$prioritaire]) . " (" . count($lignes) . ") ──\n";
        foreach ($lignes as $nom => $r) {
            printf("  %-62s %d/%d objet(s) présent(s)\n", $nom, $r['presents'], $r['total']);
            if ($r['manquants']) printf("      manque : %s\n", implode(', ', $r['manquants']));
        }
        echo "\n";
    }

    echo "── DÉTAIL COMPLET ──\n";
    foreach ($resultat as $nom => $r) {
        printf("  %-62s %s\n", $nom, $LIBELLES[$r['statut']]);
    }

    echo "\n── RÉSUMÉ ──\n";
    printf("  Appliquées                : %d\n", $comptes['appliquee']);
    printf("  Partielles (à examiner)   : %d\n", $comptes['partielle']);
    printf("  Non appliquées            : %d\n", $comptes['non_appliquee']);
    printf("  Non vérifiables (DML)     : %d — à contrôler manuellement\n", $comptes['non_verifiable']);
    printf("  Pré-PostgreSQL (ignorées) : %d\n", $comptes['pre_postgres']);
    printf("  Total                      : %d\n", count($resultat));
}

// ============================================================
//  5. TABLE DE SUIVI — sur demande explicite seulement
// ============================================================
//  Ne fait qu'enregistrer un constat, jamais une action : seules les
//  migrations classées « appliquée » sans ambiguïté sont inscrites, avec
//  le statut 'backfill_estime' pour dire clairement que la date réelle
//  d'application est inconnue — contrairement à une ligne 'confirme'
//  qu'une future exécution normale du script d'application pourra poser.
if ($creer_suivi) {
    db_query("CREATE TABLE IF NOT EXISTS schema_migrations (
        fichier      varchar(200) PRIMARY KEY,
        applique_le  timestamp,
        statut       varchar(20) NOT NULL DEFAULT 'confirme',
        note         text
    )");
    $inscrites = 0;
    foreach ($resultat as $nom => $r) {
        if ($r['statut'] !== 'appliquee') continue;
        db_query(
            "INSERT INTO schema_migrations (fichier, statut, note)
             VALUES (?, 'backfill_estime', 'Inscrite rétroactivement par inventaire_migrations.php — date réelle inconnue.')
             ON CONFLICT (fichier) DO NOTHING",
            [$nom]
        );
        $inscrites++;
    }
    fwrite(STDERR, "\nTable schema_migrations prête. $inscrites migration(s) confirmée(s) inscrite(s) en backfill.\n");
    fwrite(STDERR, "Les statuts 'partielle', 'non_appliquee' et 'non_verifiable' ne sont PAS inscrits : "
                  . "à traiter et enregistrer à la main après vérification.\n");
}

$sortie_non_ok = $comptes['partielle'] + $comptes['non_appliquee'];
exit($sortie_non_ok > 0 ? 1 : 0);
