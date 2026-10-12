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
