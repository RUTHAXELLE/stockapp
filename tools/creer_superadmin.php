<?php
// ============================================================
//  tools/creer_superadmin.php — Premier compte super-administrateur
//
//  Après sql/installer_schema_mysql.sh, la base ne contient aucun
//  utilisateur : ce script crée le compte qui ouvrira l'application.
//  Le mot de passe est saisi au clavier, masqué, et haché comme dans
//  l'application (bcrypt, coût 12) — il n'apparaît ni à l'écran, ni dans
//  l'historique du shell, ni dans la liste des processus.
//
//  Usage (depuis la racine de l'application, .env renseigné) :
//      sudo -u www-data php tools/creer_superadmin.php
//  Refuse de s'exécuter s'il existe déjà un super-administrateur actif :
//  les comptes suivants se créent dans Administration → Utilisateurs.
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('CLI_MODE', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

function demander(string $libelle): string {
    echo $libelle;
    return trim((string)fgets(STDIN));
}

function demander_masque(string $libelle): string {
    echo $libelle;
    $terminal = stream_isatty(STDIN);
    if ($terminal) shell_exec('stty -echo');
    $valeur = rtrim((string)fgets(STDIN), "\r\n");
    if ($terminal) { shell_exec('stty echo'); echo "\n"; }
    return $valeur;
}

$role_id = db_fetch_value("SELECT id FROM roles WHERE slug = 'superadmin'");
if (!$role_id) {
    fwrite(STDERR, "ERREUR : rôle « superadmin » introuvable — le schéma n'est pas installé.\n");
    exit(1);
}
$existant = db_fetch_value(
    "SELECT COUNT(*) FROM users WHERE role_id = ? AND actif = 1", [$role_id]
);
if ($existant > 0) {
    fwrite(STDERR, "ERREUR : un super-administrateur actif existe déjà. Créez les autres comptes dans Administration → Utilisateurs.\n");
    exit(1);
}

echo "Création du compte super-administrateur — base " . DB_NAME . "\n";

$email = strtolower(demander('Email : '));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ERREUR : email invalide.\n");
    exit(1);
}
if (db_fetch_value("SELECT COUNT(*) FROM users WHERE email = ?", [$email]) > 0) {
    fwrite(STDERR, "ERREUR : cet email est déjà utilisé.\n");
    exit(1);
}
$prenom = format_nom_prenom(demander('Prénom : '));
$nom    = format_nom_prenom(demander('Nom : '));
if ($prenom === '' || $nom === '') {
    fwrite(STDERR, "ERREUR : nom et prénom obligatoires (lettres uniquement).\n");
    exit(1);
}

$mdp = demander_masque('Mot de passe (min 8 caractères, une majuscule, un chiffre, un caractère spécial) : ');
if (!is_valid_password($mdp)) {
    fwrite(STDERR, "ERREUR : mot de passe trop faible.\n");
    exit(1);
}
if (demander_masque('Confirmer le mot de passe : ') !== $mdp) {
    fwrite(STDERR, "ERREUR : les deux saisies diffèrent.\n");
    exit(1);
}

db_query(
    "INSERT INTO users (nom, prenom, email, password_hash, role_id, actif, must_change_password)
     VALUES (?, ?, ?, ?, ?, 1, 0)",
    [$nom, $prenom, $email, password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 12]), (int)$role_id]
);
$id = (int)db_last_id();
audit_log($id, 'CREATE', 'users', $id, "Création du premier super-administrateur ($email) en ligne de commande");

echo "Compte créé : $prenom $nom <$email> (id $id). Connectez-vous sur " . rtrim(APP_URL, '/') . "/login.php\n";
