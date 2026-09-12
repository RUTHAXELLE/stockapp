<?php
// ============================================================
//  includes/point_emuci_corrections.php — Circuit demande/réponse pour
//  la correction du déclaratif coordinateur sur Point EMUCI.
//
//  Un seul aller-retour, pas de boucle comme corrections_bobines : le GP
//  propose une valeur + motif, le coordinateur accepte telle quelle ou
//  conteste avec sa propre valeur + commentaire, et si contesté, le GP
//  tranche en dernier ressort (valide la contre-proposition ou impose
//  sa valeur d'origine). Le résultat s'applique toujours via les mêmes
//  colonnes correction_gp/motif_correction_gp/corrected_by_gp déjà
//  utilisées par pages/point_emuci.php.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

// ── Demande active (non close) pour un point journalier donné
function pec_active(int $pj_id): ?array {
    return db_fetch_one(
        "SELECT * FROM corrections_point_emuci WHERE pj_id=? AND statut IN ('en_attente','conteste') ORDER BY created_at DESC LIMIT 1",
        [$pj_id]
    );
}

function pec_notifier_coords(int $site_id, string $titre, string $message): void {
    $coords = db_fetch_all(
        "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1",
        [$site_id]
    );
    foreach ($coords as $c) {
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
            [$c['id'], 'info', $titre, $message, '/pages/operations/point_journalier.php']
        );
    }
}

// ── GP propose une correction
function pec_demander(array $pj, array $user, int $total_propose, string $motif): int {
    if (trim($motif) === '') throw new Exception('Le motif est obligatoire.');
    if (pec_active((int)$pj['id'])) {
        throw new Exception("Une demande de correction est déjà en cours pour ce point.");
    }
    db_query(
        "INSERT INTO corrections_point_emuci (pj_id,site_id,date_point,total_declare,total_propose,motif_gp,gp_id)
         VALUES (?,?,?,?,?,?,?)",
        [$pj['id'], $pj['site_id'], $pj['date_point'], (int)$pj['total_plaques'], $total_propose, $motif, $user['id']]
    );
    $id = (int)db_last_id();

    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    pec_notifier_coords(
        (int)$pj['site_id'],
        '🔁 Correction Point EMUCI demandée',
        "$nom propose de corriger votre déclaratif du " . fmt_date($pj['date_point']) .
        " : $total_propose plaques au lieu de {$pj['total_plaques']}. Motif : $motif"
    );
    return $id;
}

// ── Coordinateur accepte la valeur proposée par le GP
function pec_accepter(array $corr, array $user): void {
    if ($corr['statut'] !== 'en_attente') throw new Exception("Cette demande n'est plus en attente.");
    $final = (int)$corr['total_propose'];
    db_query(
        "UPDATE corrections_point_emuci SET statut='accepte', total_final=?, traite_par=?, traite_at=NOW() WHERE id=?",
        [$final, $user['id'], $corr['id']]
    );
    db_query(
        "UPDATE op_points_journaliers SET correction_gp=?, motif_correction_gp=?, corrected_by_gp=?, corrected_at=NOW() WHERE id=?",
        [$final, $corr['motif_gp'], $corr['gp_id'], $corr['pj_id']]
    );
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    db_query(
        "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
        [$corr['gp_id'], 'info', '✅ Correction acceptée',
         "$nom a accepté votre correction du " . fmt_date($corr['date_point']) . " ($final plaques).",
         '/pages/point_emuci.php']
    );
}

// ── Coordinateur conteste avec sa propre valeur
function pec_contester(array $corr, array $user, int $total_propose_coord, string $reponse): void {
    if ($corr['statut'] !== 'en_attente') throw new Exception("Cette demande n'est plus en attente.");
    if (trim($reponse) === '') throw new Exception('Un commentaire est obligatoire pour contester.');
    db_query(
        "UPDATE corrections_point_emuci SET statut='conteste', total_propose_coord=?, reponse_coord=? WHERE id=?",
        [$total_propose_coord, $reponse, $corr['id']]
    );
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    db_query(
        "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
        [$corr['gp_id'], 'info', '⚠️ Correction contestée',
         "$nom conteste votre proposition du " . fmt_date($corr['date_point']) .
         " et propose $total_propose_coord plaques. Motif : $reponse",
         '/pages/point_emuci.php']
    );
}

// ── GP tranche une contestation : valide la contre-proposition du coordinateur
function pec_valider_contestation(array $corr, array $user): void {
    if ($corr['statut'] !== 'conteste') throw new Exception("Cette demande n'est pas contestée.");
    $final = (int)$corr['total_propose_coord'];
    $motif = "Contre-proposition coordinateur validée : {$corr['reponse_coord']}";
    db_query(
        "UPDATE corrections_point_emuci SET statut='valide', total_final=?, traite_par=?, traite_at=NOW() WHERE id=?",
        [$final, $user['id'], $corr['id']]
    );
    db_query(
        "UPDATE op_points_journaliers SET correction_gp=?, motif_correction_gp=?, corrected_by_gp=?, corrected_at=NOW() WHERE id=?",
        [$final, $motif, $user['id'], $corr['pj_id']]
    );
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    pec_notifier_coords(
        (int)$corr['site_id'],
        '✅ Contestation validée',
        "$nom a validé votre contre-proposition du " . fmt_date($corr['date_point']) . " ($final plaques)."
    );
}

// ── GP tranche une contestation : refuse et impose sa valeur d'origine
function pec_refuser_contestation(array $corr, array $user): void {
    if ($corr['statut'] !== 'conteste') throw new Exception("Cette demande n'est pas contestée.");
    $final = (int)$corr['total_propose'];
    $motif = "{$corr['motif_gp']} (contestation du coordinateur refusée)";
    db_query(
        "UPDATE corrections_point_emuci SET statut='refuse', total_final=?, traite_par=?, traite_at=NOW() WHERE id=?",
        [$final, $user['id'], $corr['id']]
    );
    db_query(
        "UPDATE op_points_journaliers SET correction_gp=?, motif_correction_gp=?, corrected_by_gp=?, corrected_at=NOW() WHERE id=?",
        [$final, $motif, $user['id'], $corr['pj_id']]
    );
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    pec_notifier_coords(
        (int)$corr['site_id'],
        '❌ Contestation refusée',
        "$nom a refusé votre contre-proposition du " . fmt_date($corr['date_point']) . " — valeur maintenue à $final plaques."
    );
}
