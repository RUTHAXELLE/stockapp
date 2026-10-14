<?php
// ============================================================
//  cron/check_alerts.php
//  Quotidien (ex: 0 8 * * *) : alertes fin de cycle + stocks bas
//  Toutes les 5 min (ex: */5 * * * *) : auto-termination bobines
//
//  Usage:
//    php cron/check_alerts.php          → toutes les vérifications
//    php cron/check_alerts.php bobines  → uniquement auto-termination
// ============================================================
define('CLI_MODE', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit.php';

$mode = $argv[1] ?? 'all';

echo "[" . date('Y-m-d H:i:s') . "] Vérification des alertes (mode: $mode)...\n";

// ── AUTO-TERMINATION DES BOBINES ÉPUISÉES ──────────────────────────────────
// Porte la logique équivalente au setInterval Node.js de server.js :
// toute bobine dont films_restants=0 ET stock_systeme=0 passe en statut 'epuisee'.
if ($mode === 'all' || $mode === 'bobines') {
    $bobines_epuisees = db_fetch_all(
        "SELECT b.id, b.numero, b.site_id
         FROM op_bobines b
         WHERE b.films_restants = 0
           AND b.stock_systeme  = 0
           AND b.statut NOT IN ('epuisee', 'retiree', 'perdue')"
    );

    $nb_terminees = 0;
    foreach ($bobines_epuisees as $b) {
        db_query(
            // op_bobines n'a pas de colonne updated_at (ni en MySQL ni sur main) :
            // la tâche plantait dès la première bobine épuisée (13/10/2026).
            "UPDATE op_bobines SET statut='epuisee' WHERE id=?",
            [$b['id']]
        );
        db_query(
            "INSERT INTO mouvements_bobines
             (bobine_id, type, quantite, stock_avant, stock_apres, motif, created_by)
             VALUES (?, 'epuisement_auto', 0, 0, 0, 'Bobine épuisée automatiquement (stock système et films restants = 0)', NULL)",
            [$b['id']]
        );
        audit_log(null, 'UPDATE', 'operations', $b['id'],
            "Auto-termination bobine {$b['numero']} (films_restants=0, stock_systeme=0)"
        );

        // Notifier les GSB (support_it avec sous-rôle gestionnaire_bobines)
        $gsb_users = db_fetch_all(
            "SELECT u.id FROM users u
             JOIN roles r ON r.id=u.role_id
             WHERE r.slug IN ('gestionnaire_stock_bobines','admin','superadmin')
               AND u.actif=1"
        );
        foreach ($gsb_users as $gsb) {
            db_query(
                "INSERT INTO notifications (user_id, type, titre, message)
                 VALUES (?, 'info', '🎞️ Bobine épuisée', ?)",
                [
                    $gsb['id'],
                    "La bobine {$b['numero']} est épuisée (stock = 0) et a été clôturée automatiquement.",
                ]
            );
        }
        $nb_terminees++;
    }

    if ($nb_terminees > 0) {
        echo "[OK] $nb_terminees bobine(s) épuisée(s) clôturée(s) automatiquement.\n";
    } else {
        echo "[OK] Aucune bobine épuisée à clôturer.\n";
    }
}

// ── ALERTES QUOTIDIENNES ───────────────────────────────────────────────────
if ($mode === 'all') {
    // Alertes fin de cycle équipements
    $nb = notif_check_fin_cycles();
    echo "[OK] Fin de cycles vérifiés ($nb notification(s)).\n";

    // Alertes stock bas (articles, rivets, PMMA)
    $nb = notif_check_stock_bas();
    echo "[OK] Stocks bas vérifiés ($nb notification(s)).\n";

    audit_log(null, 'CREATE', 'cron', null, 'Exécution des alertes automatiques quotidiennes');
}

echo "[DONE]\n";
