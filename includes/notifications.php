<?php
// ============================================================
//  includes/notifications.php — Fonctions notifications in-app
// ============================================================
require_once __DIR__ . '/db.php';

function notif_create(string $type, string $titre, string $message, ?int $user_id = null, string $lien = ''): void {
    db_query(
        "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, ?, ?, ?, ?)",
        [$user_id, $type, $titre, $message, $lien]
    );
}
function notif_get_unread(int $user_id): array {
    return db_fetch_all(
        "SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND lu = 0 ORDER BY created_at DESC LIMIT 20",
        [$user_id]
    );
}
function notif_mark_read(int $notif_id, int $user_id): void {
    db_query("UPDATE notifications SET lu = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)", [$notif_id, $user_id]);
}
function notif_mark_all_read(int $user_id): void {
    db_query("UPDATE notifications SET lu = 1 WHERE user_id = ? OR user_id IS NULL", [$user_id]);
}
/**
 * Notifie (type 'info') tous les utilisateurs actifs d'un rôle, plus les
 * Support IT dont le sous-rôle actif correspond (un Support IT
 * « gestionnaire_bobines » fait le travail d'un Gestionnaire Stock
 * Bobines). Appelée par pages/operations/bobines.php (bobine perdue) sans
 * avoir jamais été définie : la déclaration de perte était enregistrée,
 * mais l'utilisateur recevait une erreur et personne n'était prévenu.
 */
function notify_role(string $role_slug, string $titre, string $message, string $lien = ''): void {
    $sous_role = [
        'gestionnaire_stock_bobines' => 'gestionnaire_bobines',
        'controleur_production'      => 'controleur_production',
        'maintenance_info'           => 'maintenance',
    ][$role_slug] ?? null;
    $ids = db_fetch_all(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.actif = 1 AND r.slug = ?
         UNION
         SELECT sr.user_id FROM support_it_roles sr JOIN users u ON u.id = sr.user_id
          WHERE sr.actif = 1 AND u.actif = 1 AND sr.sous_role = ?",
        [$role_slug, $sous_role ?? '']
    );
    foreach ($ids as $u) {
        notif_create('info', $titre, $message, (int)$u['id'], $lien);
    }
}

// ============================================================
//  Alertes quotidiennes (cron/check_alerts.php, 08:00)
//
//  Anti-doublon : une alerte n'est pas renvoyée à un utilisateur qui a
//  déjà reçu le même titre et ne l'a pas encore lu, ou qui l'a reçu il y
//  a moins de NOTIF_RAPPEL_JOURS jours. Tant que la situation dure, il
//  reçoit donc un rappel par semaine, jamais un par jour.
//
//  Au-delà de NOTIF_DETAIL_MAX nouvelles alertes d'un même genre pour un
//  utilisateur, une seule notification groupée remplace le détail (sinon
//  la première exécution en production enverrait une notification par
//  équipement déjà hors cycle).
//
//  Les dates sont calculées en PHP et passées en paramètres : le SQL reste
//  identique sous PostgreSQL (main) et MySQL (vps-mysql).
// ============================================================
const NOTIF_RAPPEL_JOURS = 7;
const NOTIF_DETAIL_MAX   = 5;
const NOTIF_FIN_CYCLE_JOURS = 30;   // même horizon que le KPI « Fin cycle <30j »

/**
 * Utilisateurs actifs ayant l'un des rôles donnés. $site_id restreint aux
 * utilisateurs rattachés à ce site (coordinateurs). $sous_role_it ajoute
 * les support_it portant ce sous-rôle actif.
 */
function _notif_users(array $slugs, ?int $site_id = null, ?string $sous_role_it = null): array {
    static $cache = [];
    $key = implode(',', $slugs) . "|$site_id|$sous_role_it";
    if (isset($cache[$key])) return $cache[$key];

    $params = $slugs;
    $cond   = 'r.slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')';
    if ($sous_role_it !== null) {
        $cond .= " OR (r.slug='support_it' AND EXISTS (
                     SELECT 1 FROM support_it_roles sir
                     WHERE sir.user_id=u.id AND sir.sous_role=? AND sir.actif=1))";
        $params[] = $sous_role_it;
    }
    $sql = "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.actif=1 AND ($cond)";
    if ($site_id !== null) { $sql .= ' AND u.site_id=?'; $params[] = $site_id; }

    return $cache[$key] = array_map('intval', array_column(db_fetch_all($sql, $params), 'id'));
}

/** Titres de ce type encore non lus ou envoyés récemment : [user_id => [titre => true]], 0 = diffusion. */
function _notif_deja_envoyees(string $type): array {
    $depuis = date('Y-m-d', strtotime('-' . (NOTIF_RAPPEL_JOURS - 1) . ' days'));
    $deja = [];
    foreach (db_fetch_all(
        "SELECT user_id, titre FROM notifications WHERE type=? AND (lu=0 OR created_at >= ?)",
        [$type, $depuis]
    ) as $r) {
        $deja[(int)$r['user_id']][$r['titre']] = true;
    }
    return $deja;
}

/**
 * Envoie un genre d'alertes en respectant l'anti-doublon.
 *  $alertes : [['titre','message','lien','libelle','users' => [ids]], ...]
 *  $groupe  : ['titre' => sprintf avec %d, 'message' => sprintf avec %d puis %s, 'lien']
 * Retourne le nombre de notifications créées.
 */
function _notif_diffuser(string $type, array $alertes, array $groupe, array &$deja): int {
    $par_user = [];
    foreach ($alertes as $a) {
        $a['titre'] = mb_substr($a['titre'], 0, 200);
        foreach (array_unique($a['users']) as $uid) {
            if (isset($deja[$uid][$a['titre']]) || isset($deja[0][$a['titre']])) continue;
            $par_user[$uid][] = $a;
        }
    }

    $motif_groupe = '/^' . str_replace('%d', '\d+', preg_quote($groupe['titre'], '/')) . '$/u';
    $nb = 0;
    foreach ($par_user as $uid => $liste) {
        if (count($liste) <= NOTIF_DETAIL_MAX) {
            foreach ($liste as $a) {
                notif_create($type, $a['titre'], $a['message'], $uid, $a['lien']);
                $deja[$uid][$a['titre']] = true;
                $nb++;
            }
            continue;
        }
        foreach (array_keys($deja[$uid] ?? []) as $t) {
            if (preg_match($motif_groupe, $t)) continue 2;
        }
        $noms = array_column(array_slice($liste, 0, NOTIF_DETAIL_MAX), 'libelle');
        $reste = count($liste) - count($noms);
        $titre = sprintf($groupe['titre'], count($liste));
        notif_create($type, $titre,
            sprintf($groupe['message'], count($liste), implode(', ', $noms) . ($reste ? " et $reste autre(s)" : '')),
            $uid, $groupe['lien']);
        $deja[$uid][$titre] = true;
        $nb++;
    }
    return $nb;
}

/** Équipements dont la fin de cycle est dépassée ou tombe dans les 30 jours. */
function notif_check_fin_cycles(): int {
    $auj    = date('Y-m-d');
    $limite = date('Y-m-d', strtotime('+' . NOTIF_FIN_CYCLE_JOURS . ' days'));
    $equips = db_fetch_all(
        "SELECT e.id, e.numero_serie_interne, e.categorie, e.marque, e.modele, e.date_fin_cycle,
                n.libelle AS type_equip, s.nom AS site_nom
         FROM equipements e
         LEFT JOIN nomenclatures n ON n.id = e.nomenclature_id
         LEFT JOIN sites s ON s.id = e.site_id
         WHERE e.actif = 1 AND e.date_fin_cycle IS NOT NULL AND e.date_fin_cycle <= ?
           AND e.etat <> 'hs' AND e.statut_stock <> 'hs'
         ORDER BY e.date_fin_cycle, e.numero_serie_interne",
        [$limite]
    );

    // Parc informatique : maintenance IT (comme les demandes d'intervention).
    // Parc opérationnel : supervision des opérations.
    $dest = [
        'informatique' => _notif_users(['admin','superadmin','superviseur_it','maintenance_info'], null, 'maintenance'),
        'operationnel' => _notif_users(['admin','superadmin','superviseur_operation']),
    ];

    $alertes = [];
    foreach ($equips as $e) {
        $cat    = $e['categorie'] === 'operationnel' ? 'operationnel' : 'informatique';
        $depasse = $e['date_fin_cycle'] < $auj;
        $jours  = (int)(new DateTime($auj))->diff(new DateTime($e['date_fin_cycle']))->days;
        $quoi   = trim(($e['type_equip'] ?? 'Équipement') . ' ' . trim(($e['marque'] ?? '') . ' ' . ($e['modele'] ?? '')));
        $ou     = $e['site_nom'] ? " ({$e['site_nom']})" : '';
        $le     = date('d/m/Y', strtotime($e['date_fin_cycle']));
        $alertes[$cat][$depasse ? 'depasse' : 'proche'][] = [
            'titre'   => ($depasse ? '⛔ Fin de cycle dépassée — ' : '⏳ Fin de cycle proche — ') . $e['numero_serie_interne'],
            'message' => $depasse
                ? "$quoi$ou : fin de cycle dépassée depuis le $le ($jours jour(s))."
                : "$quoi$ou : fin de cycle le $le" . ($jours ? " (dans $jours jour(s))." : " (aujourd'hui)."),
            'lien'    => '/pages/equipements.php?categorie=' . $cat . '&q=' . rawurlencode($e['numero_serie_interne']),
            'libelle' => $e['numero_serie_interne'],
            'users'   => $dest[$cat],
        ];
    }

    $deja = _notif_deja_envoyees('fin_cycle');
    $nb = 0;
    foreach ($alertes as $cat => $genres) {
        $lien = "/pages/equipements.php?categorie=$cat&fin_cycle=1";
        if (!empty($genres['depasse'])) {
            $nb += _notif_diffuser('fin_cycle', $genres['depasse'], [
                'titre'   => '⛔ %d équipements en fin de cycle dépassée',
                'message' => '%d équipement(s) ont dépassé leur fin de cycle : %s.',
                'lien'    => $lien,
            ], $deja);
        }
        if (!empty($genres['proche'])) {
            $nb += _notif_diffuser('fin_cycle', $genres['proche'], [
                'titre'   => '⏳ %d équipements en fin de cycle sous ' . NOTIF_FIN_CYCLE_JOURS . ' jours',
                'message' => '%d équipement(s) atteignent leur fin de cycle dans les ' . NOTIF_FIN_CYCLE_JOURS . ' jours : %s.',
                'lien'    => $lien,
            ], $deja);
        }
    }
    return $nb;
}

/**
 * Stocks sous le seuil d'alerte :
 *  - articles (stock magasin global, même règle que le bloc tableau de bord : stock <= seuil)
 *  - articles par site (stock_site, comparé au seuil de l'article, comme le tableau de bord coordinateur)
 *  - rivets et PMMA par site (stock < seuil, comme les pages Rivets et PMMA)
 * consommables / stock_consommables_site ne sont pas surveillés : tables
 * héritées de la scission consommables → articles, plus alimentées par les écrans.
 */
function notif_check_stock_bas(): int {
    $magasin   = _notif_users(['admin','superadmin','gestionnaire_stock','superviseur_achat']);
    $operation = _notif_users(['admin','superadmin','superviseur_operation']);
    $coord     = fn($site_id) => _notif_users(['coordinateur_site'], (int)$site_id);
    $fmt       = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ' '), '0'), ',');

    $genres = [];

    // Articles — stock magasin global. Même titre que l'alerte émise à la
    // distribution (pages/articles.php) : une alerte déjà diffusée n'est pas répétée.
    foreach (db_fetch_all(
        "SELECT id, libelle, unite, stock_global, seuil_alerte FROM articles
         WHERE actif = 1 AND seuil_alerte > 0 AND stock_global <= seuil_alerte
         ORDER BY libelle"
    ) as $a) {
        $genres['articles'][] = [
            'titre'   => "⚠️ Stock bas — {$a['libelle']}",
            'message' => "Stock magasin : {$fmt($a['stock_global'])} {$a['unite']}(s) (seuil : {$fmt($a['seuil_alerte'])}).",
            'lien'    => '/pages/articles.php',
            'libelle' => $a['libelle'],
            'users'   => $magasin,
        ];
    }

    // Articles — stock de chaque site
    foreach (db_fetch_all(
        "SELECT a.libelle, a.unite, a.seuil_alerte, ss.quantite, ss.site_id, s.nom AS site_nom
         FROM stock_site ss
         JOIN articles a ON a.id = ss.article_id
         JOIN sites s ON s.id = ss.site_id
         WHERE a.actif = 1 AND a.seuil_alerte > 0 AND ss.quantite <= a.seuil_alerte
         ORDER BY s.nom, a.libelle"
    ) as $a) {
        $genres['articles_site'][] = [
            'titre'   => "⚠️ Stock bas {$a['site_nom']} — {$a['libelle']}",
            'message' => "Stock du site {$a['site_nom']} : {$fmt($a['quantite'])} {$a['unite']}(s) (seuil : {$fmt($a['seuil_alerte'])}).",
            'lien'    => '/pages/articles.php',
            'libelle' => "{$a['libelle']} ({$a['site_nom']})",
            'users'   => $coord($a['site_id']),
        ];
    }

    // Rivets
    $types_rivets = ['gonflable' => 'Gonflables', 'eclate' => 'Éclatés'];
    foreach (db_fetch_all(
        "SELECT sr.site_id, sr.type_rivet, sr.quantite, COALESCE(sr.seuil_alerte, 200) AS seuil, s.nom AS site_nom
         FROM op_stock_rivets sr
         JOIN sites s ON s.id = sr.site_id
         WHERE sr.quantite < COALESCE(sr.seuil_alerte, 200)
         ORDER BY s.nom, sr.type_rivet"
    ) as $r) {
        $lbl = 'Rivets ' . mb_strtolower($types_rivets[$r['type_rivet']] ?? $r['type_rivet']);
        $genres['rivets'][] = [
            'titre'   => "⚠️ Stock bas {$r['site_nom']} — $lbl",
            'message' => "$lbl sur {$r['site_nom']} : {$r['quantite']} en stock (seuil : {$r['seuil']}).",
            'lien'    => '/pages/operations/rivets.php',
            'libelle' => "$lbl ({$r['site_nom']})",
            'users'   => array_merge($operation, $coord($r['site_id'])),
        ];
    }

    // PMMA
    foreach (db_fetch_all(
        "SELECT sp.site_id, sp.type_pmma, sp.quantite, COALESCE(sp.seuil_alerte, 10) AS seuil, s.nom AS site_nom
         FROM stock_pmma_site sp
         JOIN sites s ON s.id = sp.site_id
         WHERE sp.type_pmma IS NOT NULL AND sp.type_pmma <> ''
           AND sp.quantite < COALESCE(sp.seuil_alerte, 10)
         ORDER BY s.nom, sp.type_pmma"
    ) as $p) {
        $genres['pmma'][] = [
            'titre'   => "⚠️ Stock bas {$p['site_nom']} — PMMA {$p['type_pmma']}",
            'message' => "PMMA {$p['type_pmma']} sur {$p['site_nom']} : {$p['quantite']} en stock (seuil : {$p['seuil']}).",
            'lien'    => '/pages/pmma.php',
            'libelle' => "PMMA {$p['type_pmma']} ({$p['site_nom']})",
            'users'   => array_merge($operation, $coord($p['site_id'])),
        ];
    }

    $groupes = [
        'articles'      => ['⚠️ %d articles sous le seuil d\'alerte',            '/pages/articles.php'],
        'articles_site' => ['⚠️ %d articles sous le seuil d\'alerte sur les sites', '/pages/articles.php'],
        'rivets'        => ['⚠️ %d stocks de rivets sous le seuil d\'alerte',     '/pages/operations/rivets.php'],
        'pmma'          => ['⚠️ %d stocks PMMA sous le seuil d\'alerte',          '/pages/pmma.php'],
    ];
    $deja = _notif_deja_envoyees('stock_bas');
    $nb = 0;
    foreach ($genres as $genre => $alertes) {
        $nb += _notif_diffuser('stock_bas', $alertes, [
            'titre'   => $groupes[$genre][0],
            'message' => '%d stock(s) sous le seuil d\'alerte : %s.',
            'lien'    => $groupes[$genre][1],
        ], $deja);
    }
    return $nb;
}

function mailer_send($to, string $subject, string $body): bool {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) { error_log('PHPMailer non installé'); return false; }
    require_once $autoload;
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->Host = defined('MAIL_HOST') ? MAIL_HOST : 'localhost';
        $mail->SMTPAuth = false; $mail->Port = 587;
        $mail->setFrom(defined('MAIL_FROM') ? MAIL_FROM : 'noreply@emuci.ci', APP_NAME);
        foreach ((array)$to as $addr) $mail->addAddress($addr);
        $mail->isHTML(true); $mail->Subject = $subject; $mail->Body = $body;
        $mail->send(); return true;
    } catch (Exception $e) { error_log('Mailer: '.$e->getMessage()); return false; }
}
