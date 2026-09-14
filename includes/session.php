<?php
// ============================================================
//  includes/session.php — Authentification & Droits v3
//  Gère : profils normaux + Support IT sous-rôles + délégations
// ============================================================
// audit_log() pour tracer la déconnexion pour inactivité (voir require_auth()).
// audit.php ne déclare qu'une fonction et n'inclut rien : aucun cycle, et
// db_query() y est déjà disponible puisque db.php précède toujours ce fichier
// (SESSION_LIFETIME, utilisé juste en dessous, en vient).
require_once __DIR__ . '/audit.php';

// Render termine le TLS à la frontière et transmet en HTTP simple au
// conteneur : $_SERVER['HTTPS'] n'est donc jamais posé en production, il
// faut se fier à l'en-tête X-Forwarded-Proto posé par le proxy. Sans ce
// second test, le cookie de session ne recevait jamais l'attribut Secure
// en production malgré le HTTPS réel.
$_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => SESSION_LIFETIME,
        'cookie_httponly'  => true,
        'cookie_samesite'  => 'Lax',
        'cookie_secure'    => $_https,
    ]);
}

// En-têtes de sécurité — posés ici car includes/session.php est chargé par
// virtuellement toutes les pages, avant toute sortie.
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    // SAMEORIGIN, pas DENY : pages/achats/param_fournisseurs.php prévisualise
    // les pièces jointes fournisseur dans un <iframe> même origine.
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($_https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ── Retourner l'utilisateur connecté (cache par requête uniquement — pas de session cache pour éviter les données périmées)
function current_user(): ?array {
    static $user_cached = null;
    if ($user_cached !== null) return $user_cached;
    if (!isset($_SESSION['user_id'])) return null;

    $u = db_fetch_one(
        "SELECT u.*, r.slug AS role_slug, r.nom AS role_nom, s.nom AS site_nom
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         LEFT JOIN sites s ON s.id = u.site_id
         WHERE u.id = ? AND u.actif = 1",
        [$_SESSION['user_id']]
    );
    if (!$u) { session_destroy(); return null; }

    // Charger les sous-rôles Support IT
    if ($u['role_slug'] === 'support_it') {
        $sous_roles = db_fetch_all(
            "SELECT sous_role FROM support_it_roles WHERE user_id=? AND actif=1",
            [$u['id']]
        );
        $u['support_it_sous_roles'] = array_column($sous_roles, 'sous_role');
    } else {
        $u['support_it_sous_roles'] = [];
    }

    // Charger les délégations reçues (pour Gestionnaire Opération)
    if ($u['role_slug'] === 'gestionnaire_operation') {
        $deleg = db_fetch_all(
            "SELECT module FROM delegations WHERE gestionnaire_id=? AND actif=1",
            [$u['id']]
        );
        $u['delegations_recues'] = array_column($deleg, 'module');
    } else {
        $u['delegations_recues'] = [];
    }

    $user_cached = $u;
    return $u;
}

function is_logged_in(): bool { return isset($_SESSION['user_id']); }

function require_auth(): void {
    if (!is_logged_in()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    // Déconnexion pour inactivité (garde-fou serveur, cf. INACTIVITY_TIMEOUT
    // dans db.php) : les requêtes de fond (notifications, liveRefresh) ne
    // sont jamais envoyées tant que l'onglet est masqué, donc last_activity
    // ne progresse pas pendant ce temps — seul un vrai retour sur l'appli
    // (onglet redevenu visible, rechargement) peut la faire avancer.
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > INACTIVITY_TIMEOUT) {
        // Même trace que le chemin JS, qui passe par auth_logout(true) : sans
        // elle, le cas le plus fréquent — l'onglet laissé de côté — n'apparaît
        // nulle part, et l'audit ne montre que les déconnexions volontaires.
        // On la pose avant de vider $_SESSION, sinon l'utilisateur est perdu.
        audit_log($_SESSION['user_id'] ?? null, 'LOGOUT', 'auth',
            $_SESSION['user_id'] ?? null, 'Déconnexion pour inactivité');
        $_SESSION = [];
        session_destroy();
        header('Location: ' . APP_URL . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Mot de passe par défaut (création ou réinitialisation admin) : bloque
    // tout écran tant qu'il n'a pas été changé. Comparaison par suffixe,
    // comme la barrière recette juste en dessous — même raison (racine de
    // service potentiellement variable).
    // Désactivé en mode recette : les comptes de test y sont partagés entre
    // testeurs avec un mot de passe connu de tous — l'imposer casserait ce
    // fonctionnement, et la recette ne porte pas sur ce mécanisme.
    if (!empty($_SESSION['must_change_password'])
        && !(defined('RECETTE_ACHATS') && RECETTE_ACHATS)) {
        $script = '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if (substr($script, -strlen('/changer-mot-de-passe.php')) !== '/changer-mot-de-passe.php') {
            if (is_ajax()) json_response(false, 'Vous devez changer votre mot de passe avant de continuer.');
            header('Location: ' . APP_URL . '/changer-mot-de-passe.php');
            exit;
        }
    }

    // Recette Achats : masquer les menus ne suffit pas, une URL tapée à la
    // main ouvrirait le reste de l'application. La barrière porte sur le
    // script appelé, donc sur tous les points d'entrée, y compris les
    // requêtes AJAX des autres modules.
    if (defined('RECETTE_ACHATS') && RECETTE_ACHATS
        && !recette_achats_autorise($_SERVER['SCRIPT_NAME'] ?? '')) {
        http_response_code(403);
        include __DIR__ . '/../templates/403_recette.php';
        exit;
    }
}

// ── Écrans laissés ouverts en mode recette Achats.
//    Comparaison par suffixe : l'application peut être servie depuis une
//    racine quelconque, seul le chemin de fin est stable.
function recette_achats_autorise(string $script): bool {
    $script = '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $script), '/');

    // pages/commandes.php : la bascule d'une FEB vers une commande interne y
    // aboutit — l'exclure couperait le parcours en deux au milieu.
    // api/notifications.php : la cloche est présente sur tous les écrans.
    // changer-mot-de-passe.php : le blocage must_change_password (juste au-
    // dessus dans require_auth()) y redirige avant même d'atteindre cette
    // barrière — sans elle dans la liste, tout compte de recette fraîchement
    // créé se retrouve sur un 403 sans jamais pouvoir changer son mot de
    // passe.
    $ouverts = [
        '/index.php',
        '/pages/accueil.php',
        '/pages/commandes.php',
        '/pages/mon_profil.php',
        '/pages/profil.php',
        '/api/notifications.php',
        '/changer-mot-de-passe.php',
    ];
    foreach ($ouverts as $o) {
        if (substr($script, -strlen($o)) === $o) return true;
    }
    return strpos($script, '/pages/achats/') !== false;
}

// ── Vérifier si l'utilisateur a un droit sur un module
function can(string $module, string $droit = 'can_read'): bool {
    $user = current_user();
    if (!$user) return false;

    // Admin et superadmin ont tout
    if (in_array($user['role_slug'], ['admin', 'superadmin'])) return true;

    // Sessions d'inventaire (n° 19 réunion ERP) : réservé à l'admin/superadmin
    // ci-dessus, sauf délégation explicite à une personne précise — cf.
    // Administration → Délégations, réutilisée ici avec superviseur_id =
    // l'admin délégant et gestionnaire_id = n'importe quel utilisateur (pas
    // seulement Gestionnaire Opération, contrairement à l'usage historique
    // de cette table). N'existe jamais dans la table permissions : ne pas
    // retomber sur _check_permission_db en bas de fonction, qui renverrait
    // toujours faux et cacherait un mauvais diagnostic si le module y est
    // ajouté par erreur un jour.
    if ($module === 'inventaire_sessions') {
        return _a_delegation((int)$user['id'], 'inventaire_sessions');
    }

    // Le N+1 d'un gestionnaire de stock voit les commandes de son périmètre,
    // en lecture seule, même si son rôle ne porte pas le droit : il doit
    // pouvoir suivre les déclarations de commande de son équipe. La lecture
    // seule est volontaire — le visa reste au superviseur opération. Ciblé
    // sur la personne réellement N+1 (user_departements.is_n1), pas sur son
    // rôle : un octroi par rôle dans Admin → Permissions donnerait l'accès à
    // tout le monde ayant ce rôle, pas seulement au N+1 réel.
    // Placé avant les embranchements support_it / gestionnaire_operation ci-
    // dessous : ils renvoient leur propre résultat sans jamais retomber ici,
    // et le N+1 d'un gestionnaire de stock peut très bien être support_it.
    if ($module === 'commandes' && $droit === 'can_read'
        && _est_n1_de_gestionnaire_stock((int)$user['id'])) {
        return true;
    }

    // Support IT : vérifier les sous-rôles actifs
    if ($user['role_slug'] === 'support_it') {
        return _support_it_can($user, $module, $droit);
    }

    // Gestionnaire Opération : droits de base + délégations
    if ($user['role_slug'] === 'gestionnaire_operation') {
        $deleg = $user['delegations_recues'] ?? [];
        // Modules délégués = droits étendus
        if (in_array($module, $deleg)) {
            return _check_permission_db($user['role_id'], $module, $droit);
        }
        // Modules non délégués = lecture seule si déjà has_read
        if ($droit !== 'can_read') return false;
    }

    return _check_permission_db($user['role_id'], $module, $droit);
}

/**
 * L'utilisateur est-il N+1 d'un département auquel appartient au moins un
 * gestionnaire de stock ?
 */
function _est_n1_de_gestionnaire_stock(int $user_id): bool {
    static $cache = [];
    if (!array_key_exists($user_id, $cache)) {
        $cache[$user_id] = (bool)db_fetch_value(
            "SELECT COUNT(*)
               FROM user_departements n1
               JOIN user_departements membre ON membre.departement_id = n1.departement_id
                                            AND membre.user_id <> n1.user_id
               JOIN users u  ON u.id = membre.user_id AND u.actif = 1
               JOIN roles r  ON r.id = u.role_id
              WHERE n1.user_id = ? AND n1.is_n1 = 1
                AND r.slug IN ('gestionnaire_stock','gestionnaire_stock_bobines')",
            [$user_id]
        );
    }
    return $cache[$user_id];
}

/**
 * L'utilisateur a-t-il reçu une délégation active pour ce module ? Table
 * delegations réutilisée au-delà de son usage d'origine (superviseur →
 * gestionnaire opération) : ici superviseur_id est l'admin qui délègue,
 * gestionnaire_id la personne choisie, quel que soit son rôle.
 */
function _a_delegation(int $user_id, string $module): bool {
    static $cache = [];
    $key = "$user_id:$module";
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = (bool)db_fetch_value(
            "SELECT COUNT(*) FROM delegations WHERE gestionnaire_id=? AND module=? AND actif=1",
            [$user_id, $module]
        );
    }
    return $cache[$key];
}

function _check_permission_db(int $role_id, string $module, string $droit): bool {
    static $allowed = ['can_read','can_create','can_update','can_delete','can_export'];
    static $cache   = [];
    if (!in_array($droit, $allowed, true)) return false;
    $key = "$role_id:$module:$droit";
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = (bool)db_fetch_value(
            "SELECT $droit FROM permissions WHERE role_id=? AND module=?",
            [$role_id, $module]
        );
    }
    return $cache[$key];
}

function _support_it_can(array $user, string $module, string $droit): bool {
    // Demandes internes est transversal ("tous les employés", cf.
    // groupes_config.php) : ne pas le filtrer par sous-rôle IT, sinon
    // aucun support_it ne peut jamais y accéder quel que soit son
    // sous-rôle actif, quoi que porte la table permissions. Trouvé en
    // testant l'activation du droit 'demandes' (2026-08-27).
    if ($module === 'demandes') {
        return _check_permission_db($user['role_id'], $module, $droit);
    }

    $sous_roles = $user['support_it_sous_roles'] ?? [];

    $acces = [
        'maintenance'            => ['interventions','equipements','sites'],
        'controleur_production'  => ['import_emuci','point_emuci','equipements','sites'],
        'gestionnaire_bobines'   => ['bobines','inventaire_bobines','equipements','sites'],
    ];

    $modules_autorises = [];
    foreach ($sous_roles as $sr) {
        $modules_autorises = array_merge($modules_autorises, $acces[$sr] ?? []);
    }
    $modules_autorises = array_unique($modules_autorises);

    if (!in_array($module, $modules_autorises)) return false;

    // Vérifier le droit spécifique en BDD
    return _check_permission_db($user['role_id'], $module, $droit);
}

function require_permission(string $module, string $droit = 'can_read'): void {
    if (!can($module, $droit)) {
        http_response_code(403);
        include __DIR__ . '/../templates/403.php';
        exit;
    }
}

function is_ajax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response(bool $success, string $message = '', $data = null): never {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// ── Helpers profil
function is_support_it_with(string $sous_role): bool {
    $u = current_user();
    if (!$u || $u['role_slug'] !== 'support_it') return false;
    return in_array($sous_role, $u['support_it_sous_roles'] ?? []);
}

function has_delegation(string $module): bool {
    $u = current_user();
    if (!$u || $u['role_slug'] !== 'gestionnaire_operation') return false;
    return in_array($module, $u['delegations_recues'] ?? []);
}

function invalidate_user_cache(): void {
    unset($_SESSION['user_cache']);
}
