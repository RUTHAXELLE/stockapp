<?php
// ============================================================
//  includes/db.php  —  Connexion PDO centralisée (MySQL)
// ============================================================
//
//  Variante VPS : MySQL 8 (PDO, utf8mb4). La configuration est lue
//  depuis les variables d'environnement — aucun secret en dur.
//  Sur le VPS, ces variables sont fournies par Apache (SetEnv dans le
//  VirtualHost) ou par un fichier .env chargé plus bas.
//
//  Variables attendues :
//    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
//    APP_URL       (URL publique de l'application)
//    APP_TIMEZONE  (par défaut Africa/Abidjan)
// ------------------------------------------------------------

/**
 * Récupère une variable d'environnement (getenv + $_ENV + $_SERVER),
 * avec une valeur par défaut si absente ou vide.
 */
function env(string $key, ?string $default = null): ?string {
    $val = getenv($key);
    if ($val === false || $val === '') {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }
    return ($val === null || $val === '') ? $default : $val;
}

// Chargement facultatif d'un fichier .env à la racine (KEY=VALUE par ligne).
// Pratique sur un VPS sans SetEnv Apache. Ne remplace pas une variable déjà définie.
(function () {
    $path = __DIR__ . '/../.env';
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if (getenv($k) === false && !isset($_ENV[$k])) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
})();

// --- Configuration base de données -------------------------------------
define('DB_HOST',    env('DB_HOST',    'localhost'));
define('DB_PORT',    env('DB_PORT',    '3306'));
define('DB_NAME',    env('DB_NAME',    'stockapp'));
define('DB_USER',    env('DB_USER',    'root'));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', 'utf8mb4');

// --- Configuration application -----------------------------------------
define('APP_NAME',    'ERP EMUCI');
define('APP_VERSION', '2.0.0');
define('APP_URL',     env('APP_URL', 'http://localhost:8080'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Africa/Abidjan'));

date_default_timezone_set(APP_TIMEZONE);
define('SESSION_LIFETIME', 28800);
// Filet de securite cote serveur pour la deconnexion pour inactivite : le
// minuteur JS (templates/footer.php) ne suffit pas seul, un onglet mis en
// arriere-plan peut etre decharge par le navigateur — ce garde-fou tranche
// sur la derniere requete recue, independamment du JS.
define('INACTIVITY_TIMEOUT', 900);   // 15 min — meme delai cote JS dans templates/footer.php

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('<div style="font-family:Arial;padding:40px;color:#e74c3c"><h2>Erreur DB</h2><p>' . htmlspecialchars($e->getMessage()) . '</p></div>');
        }
    }
    return $pdo;
}

function db_query(string $sql, array $params = []): PDOStatement {
    $stmt = get_db()->prepare($sql);
    // Toute exception est remontée. Les SQLSTATE 01xxx étaient ignorés
    // (juin 2026, MariaDB non strict, où la ligne était quand même écrite) :
    // en MySQL 8 strict, « Data truncated » (1265, SQLSTATE 01000) signifie
    // que la requête a ÉCHOUÉ et que rien n'est écrit. Ignorer l'exception
    // faisait croire à un succès — validation du stock du matin « réussie »
    // sans mouvement de stock, sans trace d'écart ni notification (constaté
    // le 13/10/2026). PDO ne lève jamais d'exception pour un simple
    // avertissement : une exception est toujours un échec.
    $stmt->execute($params);
    return $stmt;
}
function db_fetch_all(string $sql, array $params = []): array {
    return db_query($sql, $params)->fetchAll();
}
function db_fetch_one(string $sql, array $params = []): ?array {
    $row = db_query($sql, $params)->fetch();
    return $row ?: null;
}
function db_fetch_value(string $sql, array $params = []) {
    $row = db_query($sql, $params)->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : null;
}
// $seq accepté pour compatibilité avec la variante PostgreSQL ; ignoré en MySQL.
function db_last_id(?string $seq = null): string { return get_db()->lastInsertId(); }
function db_begin(): void    { get_db()->beginTransaction(); }
function db_commit(): void   { get_db()->commit(); }
function db_rollback(): void { if (get_db()->inTransaction()) get_db()->rollBack(); }
