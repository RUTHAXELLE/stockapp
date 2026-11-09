<?php
// ============================================================
//  includes/demandes.php — Moteur "Demandes internes"
//  Porté depuis emu-demandes (Node) : workflow data-driven,
//  circuits en base (di_types/di_etapes), JSON géré en PHP.
//  SQL volontairement standard => compatible MySQL 8 et PostgreSQL.
// ============================================================

require_once __DIR__ . '/db.php';

// ── Statuts
const DI_STATUTS = ['brouillon','en_attente','en_cours','approuve','approuve_traitement','rejete','a_revoir'];

// ── Libellé + couleurs d'un statut  => [label, couleur_texte, couleur_fond]
function di_statut_label(string $s): array {
    return [
        'brouillon'           => ['Brouillon','#7f8c8d','#f0f4f8'],
        'en_attente'          => ['En attente','#e67e22','#fef9e7'],
        'en_cours'            => ['En cours','#1B75BC','#eaf3fb'],
        'approuve'            => ['Approuvée','#27ae60','#eafaf1'],
        // Orange comme « En attente » : le traitement IT reste à faire, la demande n'est pas close.
        'approuve_traitement' => ['En attente de traitement IT','#e67e22','#fef9e7'],
        // Statut d'affichage seulement (cf. di_statut_effectif) : le traitement
        // est fait, la demande est close.
        'traite'              => ['Traitée','#16a085','#e8f8f5'],
        'rejete'              => ['Rejetée','#e74c3c','#fdf0ef'],
        'a_revoir'            => ['À revoir','#8e44ad','#f5eefa'],
    ][$s] ?? [$s,'#7f8c8d','#f0f4f8'];
}
/**
 * Statut à afficher pour une demande.
 *
 * Le traitement IT ne change pas le statut en base — il pose traite_it=1 sur
 * un statut qui reste 'approuve_traitement'. Sans cette distinction, une
 * demande déjà traitée continuerait d'afficher « En attente de traitement IT ».
 */
function di_statut_effectif(array $demande): string {
    if (($demande['statut'] ?? '') === 'approuve_traitement' && !empty($demande['traite_it'])) {
        return 'traite';
    }
    return $demande['statut'] ?? '';
}

function di_badge(string $s): string {
    [$lbl,$c,$bg] = di_statut_label($s);
    return '<span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:10px;color:'.$c.';background:'.$bg.'">'.h($lbl).'</span>';
}

// ── Charge le circuit (étapes ordonnées) d'un type de demande
function di_workflow(string $typeCode): array {
    $rows = db_fetch_all(
        "SELECT e.role_code, e.label, e.ordre
         FROM di_etapes e JOIN di_types t ON t.id = e.type_id
         WHERE t.code = ? ORDER BY e.ordre ASC",
        [$typeCode]
    );
    return array_map(fn($r) => ['role' => $r['role_code'], 'label' => $r['label']], $rows);
}

// ── Type de demande par code
function di_type(string $typeCode): ?array {
    return db_fetch_one("SELECT * FROM di_types WHERE code = ?", [$typeCode]);
}

// ── Plateformes NSIIV actives (pour les champs de type 'plateformes')
function di_plateformes(): array {
    return db_fetch_all("SELECT code, label FROM di_plateformes WHERE actif = 1 ORDER BY ordre ASC");
}
function di_types_actifs(): array {
    return db_fetch_all("SELECT * FROM di_types WHERE actif = 1 ORDER BY ordre ASC");
}

// ── Rôles de validation d'un utilisateur — DÉRIVÉS de son rôle ERP.
//    Source de vérité unique : Administration → Permissions (le rôle ERP = la fonction de
//    validation). Plus de matrice « Rôles valideurs » séparée. Le N+1 n'est pas ici : il est
//    résolu par le N+1 du département du demandeur (user_departements), cf. di_can_validate().
function di_user_roles(int $userId): array {
    // Rôle ERP (slug) → codes d'étape de circuit que ce rôle peut viser.
    static $map = [
        'raf'               => ['raf'],
        'daf'               => ['daf'],
        // Le rôle ERP 'gestionnaire' a été supprimé (lot 3). Le code d'étape
        // « gestionnaire » (Visa Administration) existe toujours dans les
        // circuits : il est porté par les affectations explicites de
        // di_user_roles et par le département lié dans di_roles.
        'support_it'        => ['it'],
        'superviseur_it'    => ['it'],
        'maintenance_info'  => ['it'],
        'directeur_general' => ['dg'],
        'lecteur'           => ['dg'],  // PDG — rôle ERP "lecteur" = visa Direction Générale
        // Administrateurs : visent toutes les étapes (jamais leur propre demande, cf. di_can_validate)
        'superadmin'        => ['n1', 'raf', 'daf', 'dg', 'it', 'gestionnaire'],
        'admin'             => ['n1', 'raf', 'daf', 'dg', 'it', 'gestionnaire'],
    ];
    $slug  = db_fetch_value("SELECT r.slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?", [$userId]);
    $roles = ($slug && isset($map[$slug])) ? $map[$slug] : [];

    // Filet de sécurité : anciens droits attribués manuellement (table di_user_roles, écran retiré)
    // restent honorés le temps de la transition. Peut être purgé pour un basculement 100 % rôles ERP.
    $legacy = array_column(db_fetch_all("SELECT role_code FROM di_user_roles WHERE user_id = ?", [$userId]), 'role_code');

    return array_values(array_unique(array_merge($roles, $legacy)));
}

// ── Demandes que CET utilisateur peut viser (étape courante = un de ses rôles).
//    Enrichit chaque demande de _etape_label et _demandeur.
function di_a_valider(array $user, ?string $from = null, ?string $to = null): array {
    $roles    = di_user_roles((int)$user['id']);
    // N+1 département
    $is_n1_dept = (bool)db_fetch_value(
        "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND is_n1=1",
        [(int)$user['id']]
    );
    // Membre d'un département lié à un rôle di (ex : Administration → gestionnaire)
    $has_dept_role = (bool)db_fetch_value(
        "SELECT COUNT(*) FROM user_departements ud
         JOIN di_roles dr ON dr.departement_id = ud.departement_id
         WHERE ud.user_id=?",
        [(int)$user['id']]
    );
    if (!$roles && !$is_n1_dept && !$has_dept_role) return [];

    $dateWhere = ''; $dateParams = [];
    if ($from) { $dateWhere .= " AND submitted_at >= ?"; $dateParams[] = $from . ' 00:00:00'; }
    if ($to)   { $dateWhere .= " AND submitted_at <= ?"; $dateParams[] = $to   . ' 23:59:59'; }

    $pending = db_fetch_all(
        "SELECT id FROM di_demandes WHERE statut IN ('en_attente','en_cours') AND demandeur_id <> ? $dateWhere ORDER BY created_at ASC",
        array_merge([(int)$user['id']], $dateParams)
    );
    $out = [];
    foreach ($pending as $p) {
        $d  = di_get((int)$p['id']);
        $wf = di_workflow_of($d);
        $n1Id = isset($d['n1_user_id']) && $d['n1_user_id'] ? (int)$d['n1_user_id'] : null;
        if (di_can_validate($roles, (int)$user['id'], $wf, (int)$d['etape_actuelle'], (int)$d['demandeur_id'], $n1Id)) {
            $d['_etape_label'] = $wf[(int)$d['etape_actuelle']]['label'] ?? '';
            $d['_demandeur']   = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$d['demandeur_id']]);
            $out[] = $d;
        }
    }
    return $out;
}

// ── L'utilisateur peut-il effectuer le traitement IT post-approbation (rôle 'it' ou membre
//    d'un département lié à ce rôle) ?
function di_user_can_traiter_it(int $userId, array $roles): bool {
    if (in_array('it', $roles, true)) return true;
    $dept_id = db_fetch_value("SELECT departement_id FROM di_roles WHERE code='it'");
    if ($dept_id) {
        return (bool)db_fetch_value(
            "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=?",
            [$userId, (int)$dept_id]
        );
    }
    return false;
}

// ── Demandes approuvées en attente de traitement IT (statut approuve_traitement, non traitées)
function di_a_traiter(array $user, ?string $from = null, ?string $to = null): array {
    $roles = di_user_roles((int)$user['id']);
    if (!di_user_can_traiter_it((int)$user['id'], $roles)) return [];

    $dateWhere = ''; $dateParams = [];
    if ($from) { $dateWhere .= " AND submitted_at >= ?"; $dateParams[] = $from . ' 00:00:00'; }
    if ($to)   { $dateWhere .= " AND submitted_at <= ?"; $dateParams[] = $to   . ' 23:59:59'; }

    $rows = db_fetch_all(
        "SELECT id FROM di_demandes WHERE statut='approuve_traitement' AND traite_it=0 $dateWhere ORDER BY updated_at ASC",
        $dateParams
    );
    $out = [];
    foreach ($rows as $r) {
        $d = di_get((int)$r['id']);
        if (!$d) continue;
        $d['_demandeur'] = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$d['demandeur_id']]);
        $out[] = $d;
    }
    return $out;
}

// ── Marquer le traitement IT d'une demande approuvée comme effectué.
//    Le n° de ticket GLPI est saisi ici : c'est le seul moment où l'IT sait
//    quel ticket a porté le traitement, et c'est ce numéro que le demandeur
//    doit retrouver dans son retour.
function di_traiter_it(array $demande, array $user, string $commentaire = '', string $ticketGlpi = ''): void {
    $roles = di_user_roles((int)$user['id']);
    if (!di_user_can_traiter_it((int)$user['id'], $roles)) {
        throw new Exception("Vous ne pouvez pas traiter cette demande.");
    }
    if ($demande['statut'] !== 'approuve_traitement') {
        throw new Exception("Cette demande n'est pas en attente de traitement IT.");
    }
    if (!empty($demande['traite_it'])) {
        throw new Exception("Cette demande a déjà été traitée.");
    }
    $ticketGlpi = trim($ticketGlpi);
    if ($ticketGlpi === '') {
        throw new Exception("Le numéro du ticket GLPI est obligatoire pour clôturer le traitement.");
    }
    if (mb_strlen($ticketGlpi) > 50) {
        throw new Exception("Numéro de ticket trop long (50 caractères maximum).");
    }

    $type = di_type($demande['type_code']);
    $now  = date('Y-m-d H:i:s');
    $nom  = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));

    $historique = $demande['historique'];
    $historique[] = ['action' => 'traite_it', 'par' => $user['id'], 'nom' => $nom,
        'commentaire' => $commentaire, 'ticket_glpi' => $ticketGlpi, 'date' => $now];

    db_query(
        "UPDATE di_demandes SET traite_it=1, traite_par=?, traite_date=?, ticket_glpi=?, historique=?, updated_at=? WHERE id=?",
        [$user['id'], $now, $ticketGlpi, json_encode($historique, JSON_UNESCAPED_UNICODE), $now, $demande['id']]
    );

    di_notify((int)$demande['demandeur_id'],
        "Le traitement IT de votre demande « {$type['label']} » est terminé — ticket GLPI n° $ticketGlpi.",
        (int)$demande['id']);
}

// ── Historique des demandes traitées par le service IT (file partagée, pas propre à un utilisateur)
function di_it_traitees(?string $from = null, ?string $to = null, int $limit = 100): array {
    $dateWhere = ''; $dateParams = [];
    if ($from) { $dateWhere .= " AND submitted_at >= ?"; $dateParams[] = $from . ' 00:00:00'; }
    if ($to)   { $dateWhere .= " AND submitted_at <= ?"; $dateParams[] = $to   . ' 23:59:59'; }
    $limit = max(1, min($limit, 200));
    $rows = db_fetch_all(
        "SELECT id FROM di_demandes WHERE traite_it=1 $dateWhere ORDER BY traite_date DESC LIMIT $limit",
        $dateParams
    );
    $out = [];
    foreach ($rows as $r) {
        $d = di_get((int)$r['id']);
        if (!$d) continue;
        $d['_demandeur']  = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$d['demandeur_id']]);
        $d['_traite_par'] = $d['traite_par'] ? db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$d['traite_par']]) : '';
        $out[] = $d;
    }
    return $out;
}

// ── Demandes déjà traitées par cet utilisateur (a signé au moins une étape)
// MySQL : les signatures sont du JSON stocké en TEXT (comme sur PostgreSQL,
// cf. l'en-tête du fichier) mais l'appartenance "un des visas porte mon
// user_id" se vérifie côté PHP plutôt que via un opérateur de containment
// SQL — jsonb @> n'a pas d'équivalent MySQL au même comportement pour un
// tableau d'objets, et JSON_CONTAINS exigerait une correspondance exacte de
// l'objet entier, pas juste du champ user_id.
function di_deja_traite(array $user, ?string $from = null, ?string $to = null): array {
    $uid   = (int)$user['id'];
    $dateWhere = ''; $dateParams = [];
    if ($from) { $dateWhere .= " AND submitted_at >= ?"; $dateParams[] = $from . ' 00:00:00'; }
    if ($to)   { $dateWhere .= " AND submitted_at <= ?"; $dateParams[] = $to   . ' 23:59:59'; }
    $rows  = db_fetch_all(
        "SELECT id, signatures FROM di_demandes
         WHERE demandeur_id <> ?
           AND signatures != '[]'
           $dateWhere
         ORDER BY updated_at DESC LIMIT 500",
        array_merge([$uid], $dateParams)
    );
    $out = [];
    foreach ($rows as $r) {
        $sigs = json_decode($r['signatures'] ?? '[]', true) ?: [];
        $signe = false;
        foreach ($sigs as $s) { if ((int)($s['user_id'] ?? 0) === $uid) { $signe = true; break; } }
        if (!$signe) continue;

        $d = di_get((int)$r['id']);
        if (!$d) continue;
        $wf = di_workflow_of($d);
        $d['_etape_label'] = $wf[(int)$d['etape_actuelle']]['label'] ?? '';
        $d['_demandeur']   = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$d['demandeur_id']]);
        $out[] = $d;
        if (count($out) >= 100) break;
    }
    return $out;
}

// ── Étape suivante (ou null si terminé) — logique identique à l'app source
function di_next_step(array $workflow, int $currentStep): ?int {
    if ($currentStep === -1) return 0;
    if ($currentStep + 1 >= count($workflow)) return null;
    return $currentStep + 1;
}

// ── Le validateur peut-il agir sur l'étape courante ?
// $n1UserId : ID du N+1 résolu au moment de la soumission (null si aucun département défini)
function di_can_validate(array $userRoles, int $userId, array $workflow, int $currentStep, int $demandeurId, ?int $n1UserId = null): bool {
    if ($currentStep < 0 || $currentStep >= count($workflow)) return false;
    if ($userId === $demandeurId) return false;
    $role = $workflow[$currentStep]['role'];
    if ($role === 'n1') {
        return $n1UserId !== null ? $userId === $n1UserId : in_array('n1', $userRoles, true);
    }
    // Si ce rôle est lié à un département, tout membre du département peut valider
    $dept_id = db_fetch_value("SELECT departement_id FROM di_roles WHERE code=?", [$role]);
    if ($dept_id) {
        return (bool)db_fetch_value(
            "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=?",
            [$userId, (int)$dept_id]
        );
    }
    return in_array($role, $userRoles, true);
}

// ── Numéro lisible : DEM-YYYYMMDD-NNN
function di_generate_numero(): string {
    $prefix = 'DEM-' . date('Ymd') . '-';
    $n = (int) db_fetch_value(
        "SELECT COUNT(*) FROM di_demandes WHERE numero LIKE ?",
        [$prefix . '%']
    );
    return $prefix . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
}

// ── Notifications (réutilise la table ERP `notifications`)
function di_notify(int $userId, string $message, ?int $demandeId = null): void {
    $lien = $demandeId ? '/pages/demandes.php?id=' . $demandeId : '/pages/demandes.php';
    db_query(
        "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?, 'info', 'Demande interne', ?, ?)",
        [$userId, $message, $lien]
    );
}
// Notifier tous les porteurs d'un rôle de validation (di_user_roles + membres du département lié)
function di_notify_role(string $roleCode, string $message, ?int $demandeId = null): void {
    $notified = [];
    foreach (db_fetch_all("SELECT user_id FROM di_user_roles WHERE role_code = ?", [$roleCode]) as $u) {
        $uid = (int)$u['user_id'];
        if (!in_array($uid, $notified, true)) { di_notify($uid, $message, $demandeId); $notified[] = $uid; }
    }
    $dept_id = db_fetch_value("SELECT departement_id FROM di_roles WHERE code=?", [$roleCode]);
    if ($dept_id) {
        foreach (db_fetch_all("SELECT user_id FROM user_departements WHERE departement_id=?", [(int)$dept_id]) as $u) {
            $uid = (int)$u['user_id'];
            if (!in_array($uid, $notified, true)) { di_notify($uid, $message, $demandeId); $notified[] = $uid; }
        }
    }
}

// ── Créer / soumettre une demande. Retourne l'id créé.
function di_creer(array $user, string $typeCode, array $champs, bool $soumettre, string $priorite = 'normal'): int {
    $type = di_type($typeCode);
    if (!$type) throw new Exception('Type de demande invalide.');
    // Le champ « Motif » du formulaire exceptionnel est devenu « Description /
    // Justification » (clé description) en juillet 2026 : le contrôle cherchait
    // encore « motif » et refusait toute demande exceptionnelle, brouillon
    // compris. Exigé à la soumission seulement ; « motif » reste lu pour les
    // brouillons d'avant le changement.
    if ($typeCode === 'exceptionnel' && $soumettre
        && trim((string)($champs['description'] ?? $champs['motif'] ?? '')) === '') {
        throw new Exception('La description / justification est obligatoire pour une demande exceptionnelle.');
    }
    $workflow = di_workflow($typeCode);
    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));

    $statut  = $soumettre ? 'en_attente' : 'brouillon';
    $etape   = $soumettre ? 0 : -1;
    $hist    = [['action' => $soumettre ? 'soumis' : 'brouillon', 'par' => $user['id'], 'nom' => $nom, 'date' => $now]];

    // Résoudre le N+1 du département du demandeur au moment de la soumission
    $n1UserId = null;
    if ($soumettre) {
        $n1Row = db_fetch_one(
            "SELECT user_id FROM user_departements
             WHERE departement_id = (SELECT departement_id FROM user_departements WHERE user_id = ? LIMIT 1)
               AND is_n1 = 1 AND user_id != ?
             LIMIT 1",
            [$user['id'], $user['id']]
        );
        $n1UserId = $n1Row ? (int)$n1Row['user_id'] : null;
    }

    $numero       = di_generate_numero();
    $demandeur_site_id = $user['site_id'] ? (int)$user['site_id'] : null;
    db_query(
        "INSERT INTO di_demandes
         (numero, type_code, statut, etape_actuelle, demandeur_id, n1_user_id, site_id, champs, historique, signatures,
          workflow_snapshot, priorite, submitted_at, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$numero, $typeCode, $statut, $etape, $user['id'], $n1UserId, $demandeur_site_id,
         json_encode($champs, JSON_UNESCAPED_UNICODE),
         json_encode($hist, JSON_UNESCAPED_UNICODE),
         '[]',
         json_encode($workflow, JSON_UNESCAPED_UNICODE),
         $priorite, $soumettre ? $now : null, $now, $now]
    );
    $id = (int) db_last_id();

    if ($soumettre && $workflow) {
        // Si le demandeur est lui-même N+1 de son département et que l'étape 0 est 'n1',
        // l'étape est validée d'office : il n'a pas besoin de sa propre approbation.
        if ($workflow[0]['role'] === 'n1') {
            $submitter_is_n1 = (bool)db_fetch_value(
                "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND is_n1=1",
                [(int)$user['id']]
            );
            if ($submitter_is_n1) {
                $autoSig  = [['etape' => 0, 'etape_label' => $workflow[0]['label'],
                    'user_id' => $user['id'], 'nom' => $nom,
                    'action' => 'approuve', 'commentaire' => "Validé d'office — demandeur est N+1", 'date' => $now]];
                $autoHist = $hist;
                $autoHist[] = ['action' => 'valide', 'etape' => $workflow[0]['label'],
                    'par' => $user['id'], 'nom' => $nom,
                    'commentaire' => "Validé d'office — demandeur est N+1", 'date' => $now];
                $next = di_next_step($workflow, 0);
                if ($next === null) {
                    $newStatut = !empty($type['traitement_it']) ? 'approuve_traitement' : 'approuve';
                    $newEtape  = 0;
                } else {
                    $newStatut = 'en_cours';
                    $newEtape  = $next;
                }
                db_query(
                    "UPDATE di_demandes SET statut=?, etape_actuelle=?, signatures=?, historique=? WHERE id=?",
                    [$newStatut, $newEtape, json_encode($autoSig, JSON_UNESCAPED_UNICODE),
                     json_encode($autoHist, JSON_UNESCAPED_UNICODE), $id]
                );
                if ($next === null) {
                    if (!empty($type['traitement_it'])) {
                        di_notify((int)$user['id'], "Votre demande « {$type['label']} » est approuvée et en cours de traitement IT.", $id);
                        di_notify_role('it', "Demande « {$type['label']} » approuvée — à traiter ($numero)", $id);
                    } else {
                        di_notify((int)$user['id'], "Votre demande « {$type['label']} » a été approuvée.", $id);
                    }
                } else {
                    di_notify_role($workflow[$next]['role'],
                        "Demande « {$type['label']} » en attente — étape : {$workflow[$next]['label']} ($numero)", $id);
                }
                return $id;
            }
        }
        // Notification normale pour la première étape
        if ($workflow[0]['role'] === 'n1' && $n1UserId) {
            di_notify($n1UserId, "Nouvelle demande « {$type['label']} » de $nom ($numero) à valider", $id);
        } else {
            di_notify_role($workflow[0]['role'],
                "Nouvelle demande « {$type['label']} » de $nom ($numero)", $id);
        }
    }
    return $id;
}

// ── Charge une demande (décode les colonnes JSON)
function di_get(int $id): ?array {
    $d = db_fetch_one("SELECT * FROM di_demandes WHERE id = ?", [$id]);
    if (!$d) return null;
    foreach (['champs','historique','signatures','workflow_snapshot'] as $k) {
        $d[$k] = json_decode($d[$k] ?? 'null', true) ?: ($k === 'champs' ? [] : []);
    }
    return $d;
}

// ── Circuit effectif d'une demande (snapshot figé, sinon circuit courant du type)
function di_workflow_of(array $demande): array {
    return !empty($demande['workflow_snapshot']) ? $demande['workflow_snapshot'] : di_workflow($demande['type_code']);
}

// ── Valider l'étape courante
function di_valider(array $demande, array $user, string $commentaire = ''): void {
    $wf = di_workflow_of($demande);
    $cur = (int)$demande['etape_actuelle'];
    $n1Id = isset($demande['n1_user_id']) && $demande['n1_user_id'] ? (int)$demande['n1_user_id'] : null;
    if (!di_can_validate(di_user_roles((int)$user['id']), (int)$user['id'], $wf, $cur, (int)$demande['demandeur_id'], $n1Id)) {
        throw new Exception("Vous ne pouvez pas valider cette étape.");
    }
    if (!in_array($demande['statut'], ['en_attente','en_cours'], true)) {
        throw new Exception("Cette demande ne peut plus être validée.");
    }
    $type = di_type($demande['type_code']);
    $stepLabel = $wf[$cur]['label'];
    $next = di_next_step($wf, $cur);
    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));

    $signatures = $demande['signatures'];
    $signatures[] = ['etape' => $cur, 'etape_label' => $stepLabel, 'user_id' => $user['id'],
        'nom' => $nom, 'action' => 'approuve', 'commentaire' => $commentaire, 'date' => $now];
    $historique = $demande['historique'];
    $historique[] = ['action' => 'valide', 'etape' => $stepLabel, 'par' => $user['id'],
        'nom' => $nom, 'commentaire' => $commentaire, 'date' => $now];

    if ($next === null) {
        $statut = !empty($type['traitement_it']) ? 'approuve_traitement' : 'approuve';
        $etape  = $cur;
    } else {
        $statut = 'en_cours';
        $etape  = $next;
    }

    db_query(
        "UPDATE di_demandes SET statut=?, etape_actuelle=?, signatures=?, historique=?, updated_at=? WHERE id=?",
        [$statut, $etape, json_encode($signatures, JSON_UNESCAPED_UNICODE),
         json_encode($historique, JSON_UNESCAPED_UNICODE), $now, $demande['id']]
    );

    if ($next === null) {
        if (!empty($type['traitement_it'])) {
            di_notify((int)$demande['demandeur_id'], "Votre demande « {$type['label']} » est approuvée et en cours de traitement IT.", (int)$demande['id']);
            di_notify_role('it', "Demande « {$type['label']} » approuvée — à traiter ({$demande['numero']})", (int)$demande['id']);
        } else {
            di_notify((int)$demande['demandeur_id'], "Votre demande « {$type['label']} » a été approuvée.", (int)$demande['id']);
        }
    } else {
        di_notify((int)$demande['demandeur_id'], "Étape « $stepLabel » validée pour votre demande « {$type['label']} ».", (int)$demande['id']);
        di_notify_role($wf[$next]['role'], "Demande « {$type['label']} » en attente — étape : {$wf[$next]['label']} ({$demande['numero']})", (int)$demande['id']);
    }
}

// ── Rejeter l'étape courante
function di_rejeter(array $demande, array $user, string $motif): void {
    $wf = di_workflow_of($demande);
    $cur = (int)$demande['etape_actuelle'];
    $n1Id = isset($demande['n1_user_id']) && $demande['n1_user_id'] ? (int)$demande['n1_user_id'] : null;
    if (!di_can_validate(di_user_roles((int)$user['id']), (int)$user['id'], $wf, $cur, (int)$demande['demandeur_id'], $n1Id)) {
        throw new Exception("Vous ne pouvez pas rejeter cette étape.");
    }
    if (trim($motif) === '') throw new Exception('Le motif de rejet est obligatoire.');
    $type = di_type($demande['type_code']);
    $stepLabel = $wf[$cur]['label'];
    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));

    $signatures = $demande['signatures'];
    $signatures[] = ['etape' => $cur, 'etape_label' => $stepLabel, 'user_id' => $user['id'],
        'nom' => $nom, 'action' => 'rejete', 'motif' => $motif, 'date' => $now];
    $historique = $demande['historique'];
    $historique[] = ['action' => 'rejete', 'etape' => $stepLabel, 'par' => $user['id'],
        'nom' => $nom, 'motif' => $motif, 'date' => $now];

    db_query(
        "UPDATE di_demandes SET statut='rejete', etape_rejet=?, signatures=?, historique=?, updated_at=? WHERE id=?",
        [$cur, json_encode($signatures, JSON_UNESCAPED_UNICODE),
         json_encode($historique, JSON_UNESCAPED_UNICODE), $now, $demande['id']]
    );
    di_notify((int)$demande['demandeur_id'],
        "Votre demande « {$type['label']} » a été rejetée à l'étape « $stepLabel » : $motif", (int)$demande['id']);
}

// ── Qui peut déposer une demande
// Le lecteur consulte et valide, mais ne dépose pas : son profil est un
// profil de consultation. La règle vit ici pour que le menu, la page de
// création et les boutons d'appel s'appuient tous sur la même.
function di_peut_creer(?array $user = null): bool {
    $user = $user ?? current_user();
    return ($user['role_slug'] ?? '') !== 'lecteur';
}
