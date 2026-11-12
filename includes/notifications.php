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
    $magasin   = users_fonction(FONCTION_MAGASIN);
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

// ============================================================
//  Envoi de mails (SMTP, PHPMailer)
//
//  Configuration par variables d'environnement :
//    MAIL_ENABLED  1 pour activer les envois (sinon rien ne part)
//    MAIL_HOST     serveur SMTP            MAIL_PORT   587 (défaut)
//    MAIL_SECURE   tls (STARTTLS, défaut) | ssl (SMTPS, port 465) | none
//    MAIL_USER / MAIL_PASS  identifiants (vides si le serveur n'en demande pas)
//    MAIL_FROM     adresse expéditrice     MAIL_FROM_NAME  nom affiché
//
//  Les notifications sont doublées d'un mail par file d'attente :
//  notifications.mail_statut vaut 'attente' à la création (valeur par défaut
//  de la colonne, INSERT directs compris) et cron/envoyer_mails.php, lancé
//  chaque minute par cron, les envoie. Un mail ne part donc jamais pour une
//  action annulée (rollback), et une panne SMTP ne ralentit pas les pages.
// ============================================================

/** Les envois de mail sont-ils activés et configurés ? */
function mail_actif(): bool {
    return in_array(strtolower((string)env('MAIL_ENABLED', '')), ['1', 'true', 'oui', 'yes', 'on'], true)
        && (string)env('MAIL_HOST', '') !== '';
}

/** Dernier message d'erreur de mailer_send() ('' si le dernier envoi a réussi). */
function mailer_derniere_erreur(?string $set = null): string {
    static $err = '';
    if ($set !== null) $err = $set;
    return $err;
}

function mailer_send($to, string $subject, string $body, ?string $texte = null): bool {
    mailer_derniere_erreur('');
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) { mailer_derniere_erreur('PHPMailer non installé'); error_log('Mailer: PHPMailer non installé'); return false; }
    require_once $autoload;
    if ((string)env('MAIL_HOST', '') === '') { mailer_derniere_erreur('MAIL_HOST non renseigné'); return false; }
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host    = (string)env('MAIL_HOST');
        $mail->Port    = (int)env('MAIL_PORT', '587');
        $mail->Timeout = 15;
        $secure = strtolower((string)env('MAIL_SECURE', 'tls'));
        if ($secure === 'ssl')     $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        elseif ($secure === 'tls') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        else { $mail->SMTPSecure = ''; $mail->SMTPAutoTLS = false; }
        if ((string)env('MAIL_USER', '') !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = (string)env('MAIL_USER');
            $mail->Password = (string)env('MAIL_PASS', '');
        }
        $mail->setFrom((string)env('MAIL_FROM', 'noreply@emuci.ci'), (string)env('MAIL_FROM_NAME', APP_NAME));
        foreach ((array)$to as $addr) $mail->addAddress($addr);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $texte ?? trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $body)), ENT_QUOTES, 'UTF-8'));
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        mailer_derniere_erreur($e->getMessage());
        error_log('Mailer: ' . $e->getMessage());
        return false;
    }
}

const MAIL_NOTIF_TENTATIVES = 3;    // abandon après 3 échecs d'envoi
const MAIL_NOTIF_AGE_MAX_H  = 48;   // une notification plus ancienne n'est plus envoyée par mail

/** Corps HTML d'un mail de notification : titre, message, bouton vers la page. */
function mail_notification_html(string $prenom, string $titre, string $message, string $lien): string {
    $h   = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $url = $lien !== '' ? rtrim((string)APP_URL, '/') . '/' . ltrim($lien, '/') : '';
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;color:#1e293b">'
         . '<p>Bonjour' . ($prenom !== '' ? ' ' . $h($prenom) : '') . ',</p>'
         . '<p style="font-size:16px;font-weight:bold;color:#06033A;margin:16px 0 8px">' . $h($titre) . '</p>'
         . '<p style="margin:0 0 18px;line-height:1.5">' . nl2br($h($message)) . '</p>'
         . ($url !== '' ? '<p><a href="' . $h($url) . '" style="background:#1B75BC;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Ouvrir dans ' . $h(APP_NAME) . '</a></p>' : '')
         . '<p style="color:#94a3b8;font-size:12px;margin-top:24px">Notification automatique de ' . $h(APP_NAME) . '. Ne répondez pas à ce message.</p>'
         . '</div>';
}

/** Version texte d'un mail de notification. */
function mail_notification_texte(string $titre, string $message, string $lien): string {
    $url = $lien !== '' ? rtrim((string)APP_URL, '/') . '/' . ltrim($lien, '/') : '';
    return $titre . "\n\n" . $message . ($url !== '' ? "\n\nOuvrir dans " . APP_NAME . " : " . $url : '')
         . "\n\n-- Notification automatique de " . APP_NAME . ". Ne répondez pas à ce message.";
}

/**
 * Notifications à envoyer par mail : [['id','to','prenom','sujet','html','texte'], ...].
 * Celles qui n'ont pas lieu d'être envoyées passent à 'ignore' : déjà lue,
 * sans destinataire, destinataire inactif ou sans adresse valide, plus ancienne
 * que MAIL_NOTIF_AGE_MAX_H. Les autres restent 'attente' jusqu'à
 * mail_enregistrer_resultat(). Renvoie ['mails' => [...], 'ignores' => n].
 */
function mail_notifications_a_envoyer(int $max = 100): array {
    $r = ['mails' => [], 'ignores' => 0];
    if (!db_colonne_existe('notifications', 'mail_statut')) return $r;
    $limite = date('Y-m-d H:i:s', time() - MAIL_NOTIF_AGE_MAX_H * 3600);
    $rows = db_fetch_all(
        "SELECT n.id, n.titre, n.message, n.lien, n.lu, n.created_at, u.email, u.prenom, u.actif
           FROM notifications n LEFT JOIN users u ON u.id = n.user_id
          WHERE n.mail_statut = 'attente' ORDER BY n.id LIMIT " . (int)$max);
    foreach ($rows as $n) {
        $email = trim((string)($n['email'] ?? ''));
        if ($email === '' || !(int)$n['actif'] || (int)$n['lu'] === 1 || $n['created_at'] < $limite
            || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            db_query("UPDATE notifications SET mail_statut='ignore' WHERE id=?", [$n['id']]);
            $r['ignores']++;
            continue;
        }
        $r['mails'][] = [
            'id'     => (int)$n['id'],
            'to'     => $email,
            'prenom' => (string)$n['prenom'],
            'sujet'  => $n['titre'],
            'html'   => mail_notification_html((string)$n['prenom'], $n['titre'], $n['message'], (string)$n['lien']),
            'texte'  => mail_notification_texte($n['titre'], $n['message'], (string)$n['lien']),
        ];
    }
    return $r;
}

/**
 * Enregistre le résultat d'envoi. $envoyes : ids partis ; $echecs : ids refusés
 * (nouvelle tentative au prochain passage, 'echec' après MAIL_NOTIF_TENTATIVES).
 * Seules les notifications encore 'attente' sont touchées.
 */
function mail_enregistrer_resultat(array $envoyes, array $echecs): array {
    $r = ['envoyes' => 0, 'echecs' => 0];
    foreach (array_unique(array_map('intval', $envoyes)) as $id) {
        $r['envoyes'] += db_query("UPDATE notifications SET mail_statut='envoye' WHERE id=? AND mail_statut='attente'", [$id])->rowCount();
    }
    foreach (array_unique(array_map('intval', $echecs)) as $id) {
        $r['echecs'] += db_query(
            // MySQL évalue les affectations de gauche à droite : le statut se décide
            // avant l'incrément du compteur.
            "UPDATE notifications SET
                    mail_statut = CASE WHEN mail_tentatives + 1 >= " . MAIL_NOTIF_TENTATIVES . " THEN 'echec' ELSE 'attente' END,
                    mail_tentatives = mail_tentatives + 1
              WHERE id=? AND mail_statut='attente'", [$id])->rowCount();
    }
    return $r;
}

/**
 * Envoie par SMTP les notifications en attente (cron/envoyer_mails.php).
 * Renvoie ['envoyes','ignores','echecs'].
 */
function mail_envoyer_notifications(int $max = 100): array {
    $a = mail_notifications_a_envoyer($max);
    $ok = []; $ko = []; $de_suite = 0;
    foreach ($a['mails'] as $m) {
        if (mailer_send($m['to'], $m['sujet'], $m['html'], $m['texte'])) { $ok[] = $m['id']; $de_suite = 0; }
        else {
            $ko[] = $m['id'];
            // Serveur SMTP injoignable : inutile d'insister pour tout le lot.
            if (++$de_suite >= 3) break;
        }
    }
    $r = mail_enregistrer_resultat($ok, $ko);
    return ['envoyes' => $r['envoyes'], 'ignores' => $a['ignores'], 'echecs' => $r['echecs']];
}
