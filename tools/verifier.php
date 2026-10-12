<?php
// ============================================================
//  tools/verifier.php — contrôles du code contre la base MySQL
//
//  Lancé par tools/verifier_vps.sh (en local comme sur GitHub), dans le
//  conteneur de l'application, connecté à une base installée par
//  sql/installer_schema_mysql.sh --avec-demo. Quatre contrôles, nés des
//  erreurs trouvées en production en octobre 2026 :
//
//   1. syntaxe PHP de chaque fichier ;
//   2. restes de syntaxe PostgreSQL dans les requêtes (::date, ILIKE,
//      FILTER (WHERE…), ON CONFLICT…) — héritage des reports depuis main ;
//   3. chaque INSERT confronté au schéma : table et colonnes existantes,
//      colonnes obligatoires sans défaut toutes fournies (MySQL 8 strict
//      refuse sinon — cf. import_optotrace.plate_number) ;
//   4. chaque écran de pages/ ouvert en super-administrateur : aucune
//      erreur fatale ni erreur SQL.
//
//  Sortie 0 si tout passe, 1 sinon. Les avertissements PHP sont listés
//  sans bloquer.
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__);
chdir($racine);
require "$racine/includes/db.php";

$bloquants = 0;
function titre(string $t): void { echo "\n== $t\n"; }
function signaler(string $m): void { global $bloquants; $bloquants++; echo "  ✗ $m\n"; }

// Code de l'application (tools/ et vendor/ exclus : outils de poste et
// bibliothèques tierces).
function fichiers_php(string $racine): array {
    $liste = [];
    foreach (['', 'api', 'cron', 'includes', 'pages', 'templates'] as $d) {
        $dir = $d === '' ? $racine : "$racine/$d";
        if (!is_dir($dir)) continue;
        $it = $d === ''
            ? new ArrayIterator(glob("$racine/*.php"))
            : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $f = (string)$f;
            if (str_ends_with($f, '.php')) $liste[] = substr($f, strlen($racine) + 1);
        }
    }
    sort($liste);
    return array_values(array_unique($liste));
}
$fichiers = fichiers_php($racine);

// ── 1. Syntaxe PHP ──────────────────────────────────────────
titre('1/4 Syntaxe PHP');
$n_err = 0;
foreach ($fichiers as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    if ($code !== 0) { signaler("$f : " . trim(implode(' ', array_slice($out, 0, 2)))); $n_err++; }
    $out = [];
}
echo "  " . count($fichiers) . " fichiers, $n_err en erreur\n";

// Appels à des fonctions qui n'existent nulle part : php -l ne les voit
// pas, l'erreur n'arrive qu'à l'exécution (notify_role(), fonctions
// d'alerte de cron/check_alerts.php…). Analyse par les jetons PHP : le
// JavaScript et le HTML des vues (T_INLINE_HTML) ne sont pas lus.
$definies = [];
foreach ($fichiers as $f) {
    if (preg_match_all('/\bfunction\s+&?\s*(\w+)\s*\(/i', file_get_contents($f), $m)) {
        foreach ($m[1] as $nom) $definies[strtolower($nom)] = true;
    }
}
// Défauts connus, affichés sans bloquer. Chaque entrée dit pourquoi elle
// est tolérée ; la retirer dès que le défaut est corrigé.
$connus = [
    'cron/check_alerts.php:notif_check_fin_cycles' => 'alertes quotidiennes, désactivées en production (docs/deploiement/cron-stockapp) — fonctions à écrire',
    'cron/check_alerts.php:notif_check_stock_bas'  => 'alertes quotidiennes, désactivées en production (docs/deploiement/cron-stockapp) — fonctions à écrire',
];
$n_fct = 0;
$ignorer = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
foreach ($fichiers as $f) {
    $jetons = token_get_all(file_get_contents($f));
    $vus = [];
    foreach ($jetons as $i => $j) {
        if (!is_array($j) || $j[0] !== T_STRING) continue;
        $k = $i + 1; while (isset($jetons[$k]) && is_array($jetons[$k]) && in_array($jetons[$k][0], $ignorer, true)) $k++;
        if (($jetons[$k] ?? null) !== '(') continue;
        $p = $i - 1; while ($p >= 0 && is_array($jetons[$p]) && in_array($jetons[$p][0], $ignorer, true)) $p--;
        $prec = $jetons[$p] ?? null;
        if (is_array($prec) && in_array($prec[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                                                      T_FUNCTION, T_NEW, T_CONST, T_USE, T_INSTANCEOF], true)) continue;
        if ($prec === '&' && is_array($jetons[$p - 2] ?? null) && ($jetons[$p - 2][0] ?? null) === T_FUNCTION) continue;
        $nom = $j[1];
        if (isset($definies[strtolower($nom)]) || function_exists($nom) || isset($vus[$nom])) continue;
        $vus[$nom] = true;
        if (isset($connus["$f:$nom"])) { echo "  · connu, non bloquant : $f:{$j[2]} — $nom() — {$connus["$f:$nom"]}\n"; continue; }
        signaler("$f:{$j[2]} — appel à la fonction inexistante $nom()");
        $n_fct++;
    }
}
echo "  $n_fct appel(s) à une fonction inexistante\n";

// ── 2. Syntaxe PostgreSQL ───────────────────────────────────
titre('2/4 Syntaxe PostgreSQL dans les requêtes');
$motifs = [
    '/::(date|int|integer|bigint|numeric|text|varchar|timestamp|timestamptz|boolean|bool|float|real|jsonb?|interval)\b/i'
        => 'conversion ::type (MySQL : CAST(… AS …) ou DATE(…))',
    '/\bILIKE\b/i'                                   => 'ILIKE (MySQL : LIKE, insensible à la casse en utf8mb4_unicode_ci)',
    '/FILTER\s*\(\s*WHERE/i'                         => 'FILTER (WHERE …) (MySQL : SUM(CASE WHEN … THEN 1 ELSE 0 END))',
    '/ON\s+CONFLICT/i'                               => 'ON CONFLICT (MySQL : ON DUPLICATE KEY UPDATE)',
    '/\bEXCLUDED\./i'                                => 'EXCLUDED. (MySQL : VALUES(col))',
    '/\bRETURNING\b/i'                               => 'RETURNING (MySQL : db_last_id())',
    '/EXTRACT\s*\(\s*EPOCH/i'                        => 'EXTRACT(EPOCH …) (MySQL : UNIX_TIMESTAMP / TIMESTAMPDIFF)',
    '/\bregexp_(match|matches|split_to_array)\s*\(/i' => 'regexp_match (MySQL : REGEXP_SUBSTR / REGEXP)',
    '/\bstring_agg\s*\(/i'                           => 'string_agg (MySQL : GROUP_CONCAT)',
    '/\bdate_trunc\s*\(/i'                           => 'date_trunc (MySQL : DATE_FORMAT / DATE)',
    '/\bgenerate_series\s*\(/i'                      => 'generate_series (pas d\'équivalent direct en MySQL)',
    '/\bTO_CHAR\s*\(/i'                              => 'TO_CHAR (MySQL : DATE_FORMAT)',
    "/INTERVAL\s+'/i"                                => "INTERVAL '…' (MySQL : INTERVAL n DAY)",
    '/\bNULLS\s+(FIRST|LAST)\b/i'                    => 'NULLS FIRST/LAST (absent de MySQL)',
    '/\b(CURRENT_DATE|NOW\(\))\s*[-+]\s*\d/i'        => 'date ± nombre (MySQL : ± INTERVAL n DAY)',
    "/\w\s*\|\|\s*'[^']*'\s*\|\|/"                   => '|| de concaténation (MySQL : CONCAT, || y vaut OR)',
    "/\|\|\s*'[^']*'\s*\|\||'\s*\|\|\s*(\?|COALESCE|LOWER|UPPER|TRIM|CAST|[a-z_]+\.[a-z_]+)/i"
        => '|| de concaténation (MySQL : CONCAT, || y vaut OR)',
    "/\s~\*?\s*[('\"]/"                              => 'opérateur ~ (MySQL : REGEXP)',
    '/CAST\s*\([^()]*\bAS\s+(INTEGER|INT|TEXT|VARCHAR|BIGINT|BOOLEAN|BOOL|NUMERIC|TIMESTAMP)\b/i'
        => 'CAST(… AS type PostgreSQL) (MySQL : SIGNED, UNSIGNED, CHAR, DECIMAL, DATETIME)',
];
$n_pg = 0;
foreach ($fichiers as $f) {
    foreach (file($f) as $i => $ligne) {
        $t = ltrim($ligne);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '#') || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
        // JavaScript dans les vues : || y est un « ou » légitime.
        $js = (bool)preg_match('/===|!==|=>|\$\{|document\.|\b(const|let|var)\s/', $ligne);
        foreach ($motifs as $rx => $libelle) {
            if ($js && str_contains($libelle, '||')) continue;
            if (preg_match($rx, $ligne)) { signaler("$f:" . ($i + 1) . " — $libelle"); $n_pg++; break; }
        }
    }
}
echo "  $n_pg occurrence(s)\n";

// ── 3. INSERT contre le schéma ──────────────────────────────
titre('3/4 INSERT confrontés au schéma ' . DB_NAME);
// Le schéma contrôlé est celui que pose l'installateur : une migration
// MySQL qu'il ne joue pas échapperait à tous les contrôles ci-dessous, et
// manquerait à toute nouvelle installation.
$installateur = file_get_contents("$racine/sql/installer_schema_mysql.sh");
foreach (array_unique(array_merge(glob("$racine/sql/*_mysql.sql"), glob("$racine/sql/migration_vps_mysql_*.sql"))) as $m) {
    if (!str_contains($installateur, basename($m))) {
        signaler('sql/' . basename($m) . " n'est pas jouée par sql/installer_schema_mysql.sh (liste FICHIERS)");
    }
}
$colonnes = []; $requises = [];
foreach (db_fetch_all(
    "SELECT table_name AS t, column_name AS c,
            (is_nullable = 'NO' AND column_default IS NULL
             AND extra NOT LIKE '%auto_increment%' AND extra NOT LIKE '%GENERATED%') AS requise
     FROM information_schema.columns WHERE table_schema = DATABASE()") as $r) {
    $colonnes[$r['t']][$r['c']] = true;
    if ($r['requise']) $requises[$r['t']][] = $r['c'];
}
$rx = '/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^()]*(?:\([^()]*\)[^()]*)*)\)/is';
$n_ins = 0; $n_pb = 0;
foreach ($fichiers as $f) {
    $src = file_get_contents($f);
    if (!preg_match_all($rx, $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
    foreach ($m as $x) {
        $table = $x[1][0];
        $ligne = substr_count($src, "\n", 0, $x[0][1]) + 1;
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) continue;   // table dynamique ($var)
        $n_ins++;
        if (!isset($colonnes[$table])) { signaler("$f:$ligne — table inexistante : $table"); $n_pb++; continue; }
        $fournies = array_filter(array_map(fn($c) => trim($c, " \t\r\n`\""), explode(',', $x[2][0])),
                                 fn($c) => preg_match('/^\w+$/', $c));
        if ($inconnues = array_diff($fournies, array_keys($colonnes[$table]))) {
            signaler("$f:$ligne — $table : colonne(s) inexistante(s) " . implode(', ', $inconnues)); $n_pb++;
        }
        if ($manquantes = array_diff($requises[$table] ?? [], $fournies)) {
            signaler("$f:$ligne — $table : colonne(s) obligatoire(s) sans défaut non fournie(s) " . implode(', ', $manquantes)); $n_pb++;
        }
    }
}
echo "  $n_ins INSERT vérifiés, $n_pb problème(s)\n";

// ── 3 bis. Valeurs écrites en dur contre les listes fermées (ENUM) ──
// PostgreSQL stockait ces colonnes en texte libre : un nouveau type de
// mouvement y passait sans rien changer au schéma. En MySQL strict, une
// valeur hors liste est refusée (« Data truncated for column »). Contrôle
// limité aux valeurs littérales : 'x' dans VALUES, dans le tableau de
// paramètres en face d'un ?, ou dans SET col = 'x'.
$enums = [];
foreach (db_fetch_all(
    "SELECT table_name AS t, column_name AS c, column_type AS ty FROM information_schema.columns
     WHERE table_schema = DATABASE() AND data_type = 'enum'") as $r) {
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $r['ty'], $v);
    $enums[$r['t']][$r['c']] = $v[1];
}
function decouper_args(string $s): array {      // découpe au niveau 0 sur les virgules
    $out = []; $cur = ''; $prof = 0; $q = null;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $ch = $s[$i];
        if ($q) { $cur .= $ch; if ($ch === '\\') { $cur .= $s[++$i] ?? ''; } elseif ($ch === $q) $q = null; continue; }
        if ($ch === "'" || $ch === '"') { $q = $ch; $cur .= $ch; continue; }
        if (strpbrk($ch, '([{')) $prof++;
        if (strpbrk($ch, ')]}')) $prof--;
        if ($ch === ',' && $prof === 0) { $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}
function litteral(?string $x): ?string {
    return ($x !== null && preg_match("/^'([^'\\\\]*)'$/", trim($x), $m)) ? $m[1] : null;
}
$n_enum = 0; $n_enum_pb = 0;
$rx_ins = '/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^()]*)\)\s*VALUES\s*\(([^()]*(?:\([^()]*\)[^()]*)*)\)/is';
foreach ($fichiers as $f) {
    $src = file_get_contents($f);
    if (preg_match_all($rx_ins, $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $x) {
            $table = $x[1][0];
            if (empty($enums[$table])) continue;
            $ligne = substr_count($src, "\n", 0, $x[0][1]) + 1;
            $cols  = array_map(fn($c) => trim($c, " \t\r\n`\""), explode(',', $x[2][0]));
            $vals  = decouper_args($x[3][0]);
            // Tableau de paramètres : premier [...] après la requête.
            $params = [];
            $fin = $x[0][1] + strlen($x[0][0]);
            if (preg_match('/^[^\[;]*\[/s', substr($src, $fin, 400), $p)) {
                $debut = $fin + strlen($p[0]); $prof = 1; $j = $debut; $q = null;
                for (; $j < strlen($src) && $prof > 0; $j++) {
                    $ch = $src[$j];
                    if ($q) { if ($ch === '\\') $j++; elseif ($ch === $q) $q = null; continue; }
                    if ($ch === "'" || $ch === '"') $q = $ch;
                    elseif ($ch === '[') $prof++;
                    elseif ($ch === ']') $prof--;
                }
                $params = decouper_args(substr($src, $debut, $j - $debut - 1));
            }
            $k = 0;
            foreach ($cols as $i => $col) {
                $v = $vals[$i] ?? null;
                $lit = litteral($v);
                if ($v !== null && trim($v) === '?') { $lit = litteral($params[$k] ?? null); $k++; }
                elseif ($v !== null) { $k += substr_count($v, '?'); }
                if ($lit === null || !isset($enums[$table][$col])) continue;
                $n_enum++;
                if (!in_array($lit, $enums[$table][$col], true)) {
                    signaler("$f:$ligne — $table.$col = '$lit' hors de la liste autorisée (" . implode(', ', $enums[$table][$col]) . ')');
                    $n_enum_pb++;
                }
            }
        }
    }
    if (preg_match_all('/UPDATE\s+`?(\w+)`?\s+SET\s+(.{1,600}?)\s+WHERE/is', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $x) {
            $table = $x[1][0];
            if (empty($enums[$table])) continue;
            $ligne = substr_count($src, "\n", 0, $x[0][1]) + 1;
            preg_match_all("/`?(\w+)`?\s*=\s*'([^'\\\\]*)'/", $x[2][0], $aff, PREG_SET_ORDER);
            foreach ($aff as [, $col, $lit]) {
                if (!isset($enums[$table][$col])) continue;
                $n_enum++;
                if (!in_array($lit, $enums[$table][$col], true)) {
                    signaler("$f:$ligne — $table.$col = '$lit' hors de la liste autorisée (" . implode(', ', $enums[$table][$col]) . ')');
                    $n_enum_pb++;
                }
            }
        }
    }
}
echo "  $n_enum valeur(s) en dur contrôlée(s) contre les listes fermées, $n_enum_pb problème(s)\n";

// ── 4. Écrans ───────────────────────────────────────────────
titre('4/4 Écrans ouverts en super-administrateur');
if (in_array('--sans-ecrans', $argv, true)) { echo "  ignoré (--sans-ecrans)\n"; goto fin; }
$uid = (int)db_fetch_value(
    "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
     WHERE r.slug = 'superadmin' AND u.actif = 1 ORDER BY u.id LIMIT 1");
if (!$uid) {
    signaler('aucun super-administrateur actif dans ' . DB_NAME . ' (base installée sans --avec-demo ?)');
} else {
    $pages = array_values(array_filter($fichiers, fn($f) => str_starts_with($f, 'pages/')));
    $n_ok = 0; $avert = []; $vides = [];
    foreach ($pages as $p) {
        $sortie = [];
        exec('timeout 120 php tools/rendre_page.php ' . escapeshellarg($p) . " $uid 2>&1", $sortie, $code);
        $texte = implode("\n", $sortie);
        if (preg_match('/(PHP )?Fatal error:[^\n]{0,200}|Uncaught [^\n]{0,200}|SQLSTATE\[[^\n]{0,200}|Erreur DB[^\n]{0,120}/', $texte, $e)) {
            signaler("$p — " . trim(strip_tags($e[0])));
        } elseif ($code === 124) {
            signaler("$p — plus de 120 s sans réponse");
        } else {
            $n_ok++;
            // Écran quasi vide : redirection (paramètre manquant, droits…)
            // ou connexion simulée inopérante.
            if (strlen($texte) < 1000) $vides[] = $p;
        }
        if (preg_match_all('/(Warning|Deprecated|Notice): ([^\n]{0,160})/', $texte, $w)) {
            $avert[$p] = trim(strip_tags($w[0][0])) . (count($w[0]) > 1 ? ' (+' . (count($w[0]) - 1) . ')' : '');
        }
    }
    echo "  " . count($pages) . " écrans, $n_ok sans erreur\n";
    if (count($vides) > count($pages) / 2) {
        signaler(count($vides) . ' écrans sur ' . count($pages) . " s'ouvrent vides : la connexion simulée ne fonctionne plus (tools/rendre_page.php)");
    } elseif ($vides) {
        echo '  Écrans vides, sans doute une redirection (non bloquant) : ' . implode(', ', $vides) . "\n";
    }
    if ($avert) {
        echo "  Avertissements PHP (non bloquants) :\n";
        foreach ($avert as $p => $w) echo "    · $p — $w\n";
    }
}

fin:
echo "\n" . ($bloquants ? "ÉCHEC : $bloquants problème(s) bloquant(s)." : "OK : aucun problème bloquant.") . "\n";
exit($bloquants ? 1 : 0);
