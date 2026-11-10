<?php
// ============================================================
//  includes/demandes.php — Moteur "Demandes internes"
//  Porté depuis emu-demandes (Node) : workflow data-driven,
//  circuits en base (di_types/di_etapes), JSON géré en PHP.
//  SQL volontairement standard => compatible MySQL 8 et PostgreSQL.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/demandes_champs.php';

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
function di_roles_erp_map(): array {
    // Rôle ERP (slug) → codes d'étape de circuit que ce rôle peut viser.
    return [
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
}
function di_user_roles(int $userId): array {
    $map   = di_roles_erp_map();
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

    // Sous condition : de deux clôtures simultanées, une seule passe (la
    // seconde écrasait le ticket et l'historique de la première).
    $maj = db_query(
        "UPDATE di_demandes SET traite_it=1, traite_par=?, traite_date=?, ticket_glpi=?, historique=?, updated_at=?
          WHERE id=? AND traite_it=0 AND statut='approuve_traitement'",
        [$user['id'], $now, $ticketGlpi, json_encode($historique, JSON_UNESCAPED_UNICODE), $now, $demande['id']]
    );
    if ($maj->rowCount() !== 1) throw new Exception("Cette demande vient d'être traitée par quelqu'un d'autre.");

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

// ── Numéro lisible : DEM-YYYYMMDD-NNN — dernier numéro du jour + 1 (le
//    comptage des lignes redonnait un numéro déjà pris après une suppression).
//    Une collision reste possible entre deux créations simultanées : di_creer()
//    réessaie alors avec le numéro suivant.
function di_generate_numero(): string {
    $prefix = 'DEM-' . date('Ymd') . '-';
    $dernier = db_fetch_value(
        "SELECT numero FROM di_demandes WHERE numero LIKE ? ORDER BY numero DESC LIMIT 1",
        [$prefix . '%']
    );
    $n = $dernier ? (int)substr((string)$dernier, strlen($prefix)) : 0;
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

// Administrateurs actifs (secours d'une étape sans porteur, cf. di_soumettre()).
function di_admins(): array {
    return array_map('intval', array_column(db_fetch_all(
        "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug IN ('admin','superadmin') AND u.actif=1"), 'id'));
}

// Notifier tous les porteurs d'un rôle de validation : rôle ERP qui le porte
// (cf. di_roles_erp_map() — c'est lui qui donne le droit de viser), anciens
// droits de la table di_user_roles, membres du département lié. Les
// administrateurs, qui visent tout, ne sont pas notifiés de chaque étape.
function di_notify_role(string $roleCode, string $message, ?int $demandeId = null): void {
    $ids = array_column(db_fetch_all("SELECT user_id FROM di_user_roles WHERE role_code = ?", [$roleCode]), 'user_id');
    $slugs = [];
    foreach (di_roles_erp_map() as $slug => $codes) {
        if (in_array($roleCode, $codes, true) && !in_array($slug, ['admin', 'superadmin'], true)) $slugs[] = $slug;
    }
    if ($slugs) {
        $ids = array_merge($ids, array_column(db_fetch_all(
            "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
              WHERE u.actif=1 AND r.slug IN (" . implode(',', array_fill(0, count($slugs), '?')) . ")", $slugs), 'id'));
    }
    $dept_id = db_fetch_value("SELECT departement_id FROM di_roles WHERE code=?", [$roleCode]);
    if ($dept_id) {
        $ids = array_merge($ids, array_column(db_fetch_all(
            "SELECT user_id FROM user_departements WHERE departement_id=?", [(int)$dept_id]), 'user_id'));
    }
    foreach (array_unique(array_map('intval', $ids)) as $uid) di_notify($uid, $message, $demandeId);
}

// ── Département du demandeur, pour résoudre son N+1 : celui où il n'est pas
//    lui-même N+1 d'abord (un agent membre d'OPÉRATION et N+1 d'ACHAT dépend
//    d'OPÉRATION), puis par id — l'ancien LIMIT 1 sans ORDER BY tirait au sort.
function di_departement_demandeur(int $userId): ?array {
    return db_fetch_one(
        "SELECT departement_id, is_n1 FROM user_departements WHERE user_id=? ORDER BY is_n1 ASC, departement_id ASC LIMIT 1",
        [$userId]
    );
}

// ── Département du BÉNÉFICIAIRE de la demande (l'agent saisi dans le
//    formulaire), dont le N+1 vise — règle retenue le 10/11/2026 : c'est le
//    responsable de l'agent concerné qui valide, pas celui du demandeur.
//    Cherché dans l'ordre : compte ERP de l'agent (même e-mail) → son
//    département ERP ; sinon département de l'annuaire des agents, sinon
//    champ « Département » du formulaire, rapprochés du code ou du libellé
//    d'un département ERP. Null si rien ne correspond (pas d'agent saisi,
//    ou département inconnu de l'ERP) : l'appelant revient au demandeur.
function di_norm_dept(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
}
function di_departement_beneficiaire(array $champs): ?array {
    $email = trim((string)($champs['agent_email'] ?? ''));
    $nom   = trim((string)($champs['agent_nom'] ?? ''));
    if ($email === '' && $nom === '') return null;

    if ($email !== '') {
        $uid = db_fetch_value("SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND actif=1 LIMIT 1", [$email]);
        if ($uid && ($d = di_departement_demandeur((int)$uid))) {
            return ['departement_id' => (int)$d['departement_id'], 'source' => 'compte ERP de l\'agent'];
        }
    }
    $texte = null;
    try {
        $agent = $email !== ''
            ? db_fetch_one("SELECT departement FROM agents WHERE LOWER(email)=LOWER(?) LIMIT 1", [$email])
            : null;
        if (!$agent && $nom !== '') {
            $agent = db_fetch_one("SELECT departement FROM agents WHERE UPPER(TRIM(CONCAT(nom,' ',prenom)))=UPPER(?) LIMIT 1", [$nom]);
        }
        $texte = $agent['departement'] ?? null;
    } catch (Throwable $e) { /* annuaire absent : on s'en passe */ }
    $source = 'annuaire des agents';
    if (trim((string)$texte) === '') { $texte = $champs['departement'] ?? null; $source = 'formulaire'; }
    $cible = di_norm_dept((string)$texte);
    if ($cible === '') return null;
    foreach (db_fetch_all("SELECT id, code, label FROM departements") as $d) {
        if ($cible === di_norm_dept((string)$d['code']) || $cible === di_norm_dept((string)$d['label'])) {
            return ['departement_id' => (int)$d['id'], 'source' => $source];
        }
    }
    return null;
}

// ── Champs obligatoires du type, contrôlés côté serveur à la soumission
//    (le navigateur seul ne suffit pas). Mêmes champs que l'attribut
//    required du formulaire : les plages de dates et les plateformes n'en
//    portent pas.
function di_verifier_champs(string $typeCode, array $champs): void {
    if ($typeCode === 'exceptionnel' && trim((string)($champs['description'] ?? $champs['motif'] ?? '')) === '') {
        throw new Exception('La description / justification est obligatoire pour une demande exceptionnelle.');
    }
    foreach (di_champs_of($typeCode) as $f) {
        if (empty($f['required']) || in_array($f['type'] ?? 'text', ['daterange', 'plateformes'], true)) continue;
        $v = $champs[$f['key']] ?? '';
        if (is_array($v) ? di_value_empty($v) : trim((string)$v) === '') {
            throw new Exception("Le champ « {$f['label']} » est obligatoire.");
        }
    }
}

// ── Créer une demande (brouillon), et la soumettre si demandé. Retourne l'id.
function di_creer(array $user, string $typeCode, array $champs, bool $soumettre, string $priorite = 'normal'): int {
    $type = di_type($typeCode);
    if (!$type || empty($type['actif'])) throw new Exception('Type de demande invalide ou désactivé.');
    if ($soumettre) di_verifier_champs($typeCode, $champs);
    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    // Soumise directement : l'historique commence à « soumis » (di_soumettre()).
    $hist = $soumettre ? [] : [['action' => 'brouillon', 'par' => $user['id'], 'nom' => $nom, 'date' => $now]];

    $id = 0;
    for ($essai = 0; $essai < 5 && !$id; $essai++) {
        try {
            db_query(
                "INSERT INTO di_demandes
                 (numero, type_code, statut, etape_actuelle, demandeur_id, n1_user_id, site_id, champs, historique, signatures,
                  workflow_snapshot, priorite, submitted_at, created_at, updated_at)
                 VALUES (?,?,'brouillon',-1,?,NULL,?,?,?,'[]',?,?,NULL,?,?)",
                [di_generate_numero(), $typeCode, $user['id'], $user['site_id'] ? (int)$user['site_id'] : null,
                 json_encode($champs, JSON_UNESCAPED_UNICODE), json_encode($hist, JSON_UNESCAPED_UNICODE),
                 json_encode(di_workflow($typeCode), JSON_UNESCAPED_UNICODE), $priorite, $now, $now]
            );
            $id = (int) db_last_id();
        } catch (PDOException $e) {
            // Numéro pris entre-temps (index unique) : on réessaie avec le suivant.
            if ($essai === 4 || !in_array((string)$e->getCode(), ['23505', '23000'], true)) throw $e;
        }
    }
    if ($soumettre) {
        // Soumission directe refusée : pas de brouillon fantôme laissé derrière.
        try { di_soumettre($id, $user); }
        catch (Exception $e) { db_query("DELETE FROM di_demandes WHERE id=? AND statut='brouillon'", [$id]); throw $e; }
    }
    return $id;
}

// ── Reprendre un brouillon : met à jour ses champs, et le soumet si demandé.
function di_maj_brouillon(int $id, array $user, array $champs, bool $soumettre): void {
    $d = di_get($id);
    if (!$d || (int)$d['demandeur_id'] !== (int)$user['id'] || $d['statut'] !== 'brouillon') {
        throw new Exception("Ce brouillon est introuvable ou n'est plus modifiable.");
    }
    if ($soumettre) di_verifier_champs($d['type_code'], $champs);
    $maj = db_query("UPDATE di_demandes SET champs=?, updated_at=? WHERE id=? AND statut='brouillon'",
        [json_encode($champs, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $id]);
    if ($maj->rowCount() !== 1) throw new Exception("Ce brouillon n'est plus modifiable.");
    if ($soumettre) di_soumettre($id, $user);
}

// ── Soumission d'un brouillon : fige le circuit et le N+1, ouvre l'étape 0.
function di_soumettre(int $id, array $user): void {
    $d = di_get($id);
    if (!$d || (int)$d['demandeur_id'] !== (int)$user['id'] || $d['statut'] !== 'brouillon') {
        throw new Exception("Cette demande n'est plus un brouillon.");
    }
    $type = di_type($d['type_code']);
    if (!$type || empty($type['actif'])) throw new Exception('Ce type de demande est désactivé.');
    $workflow = di_workflow($d['type_code']);
    $numero   = $d['numero'];
    $now = date('Y-m-d H:i:s');
    $nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    $uid = (int)$user['id'];

    // N+1 : celui du département du BÉNÉFICIAIRE (l'agent concerné) ; à
    // défaut — pas d'agent saisi, ou département introuvable dans l'ERP —
    // celui du demandeur. Jamais le demandeur lui-même.
    $benef = di_departement_beneficiaire($d['champs']);
    $dept_id = $benef['departement_id'] ?? null;
    $note_n1 = null;
    if ($dept_id === null) {
        $dd = di_departement_demandeur($uid);
        $dept_id = $dd ? (int)$dd['departement_id'] : null;
        if (trim((string)($d['champs']['agent_nom'] ?? '')) !== '') {
            $note_n1 = "Département du bénéficiaire introuvable dans l'ERP : N+1 du département du demandeur.";
        }
    }
    $n1UserId = null;
    if ($dept_id) {
        $n1 = db_fetch_value(
            "SELECT ud.user_id FROM user_departements ud JOIN users u ON u.id=ud.user_id AND u.actif=1
              WHERE ud.departement_id=? AND ud.is_n1=1 AND ud.user_id<>? ORDER BY ud.user_id LIMIT 1",
            [(int)$dept_id, $uid]);
        $n1UserId = $n1 ? (int)$n1 : null;
    }
    // Validé d'office seulement si le demandeur est lui-même N+1 du
    // département retenu, pas d'un autre.
    $auto_n1 = $workflow && $workflow[0]['role'] === 'n1' && $dept_id && (bool)db_fetch_value(
        "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=? AND is_n1=1", [$uid, (int)$dept_id]);

    $hist = $d['historique'];
    $dept_lbl = $dept_id ? db_fetch_value("SELECT label FROM departements WHERE id=?", [(int)$dept_id]) : null;
    $hist[] = ['action' => 'soumis', 'par' => $uid, 'nom' => $nom, 'date' => $now,
               'commentaire' => $note_n1 ?? ($dept_lbl ? "Visa N+1 : département $dept_lbl"
                   . ($benef ? " (bénéficiaire, " . $benef['source'] . ')' : ' (demandeur)') : null)];
    $sigs = [];
    $statut = 'en_attente'; $etape = $workflow ? 0 : -1;
    if (!$workflow) $statut = !empty($type['traitement_it']) ? 'approuve_traitement' : 'approuve';
    if ($auto_n1) {
        $sigs[] = ['etape' => 0, 'etape_label' => $workflow[0]['label'], 'user_id' => $uid, 'nom' => $nom,
                   'action' => 'approuve', 'commentaire' => "Validé d'office — demandeur est N+1", 'date' => $now];
        $hist[] = ['action' => 'valide', 'etape' => $workflow[0]['label'], 'par' => $uid, 'nom' => $nom,
                   'commentaire' => "Validé d'office — demandeur est N+1", 'date' => $now];
        $next = di_next_step($workflow, 0);
        if ($next === null) { $statut = !empty($type['traitement_it']) ? 'approuve_traitement' : 'approuve'; $etape = 0; }
        else                { $statut = 'en_cours'; $etape = $next; }
    }

    $maj = db_query(
        "UPDATE di_demandes SET statut=?, etape_actuelle=?, n1_user_id=?, workflow_snapshot=?, signatures=?, historique=?,
                submitted_at=?, updated_at=? WHERE id=? AND statut='brouillon'",
        [$statut, $etape, $n1UserId, json_encode($workflow, JSON_UNESCAPED_UNICODE), json_encode($sigs, JSON_UNESCAPED_UNICODE),
         json_encode($hist, JSON_UNESCAPED_UNICODE), $now, $now, $id]);
    if ($maj->rowCount() !== 1) throw new Exception("Cette demande vient d'être soumise.");

    // Notifications
    if (in_array($statut, ['approuve', 'approuve_traitement'], true)) {
        if ($statut === 'approuve_traitement') {
            di_notify($uid, "Votre demande « {$type['label']} » est approuvée et en cours de traitement IT.", $id);
            di_notify_role('it', "Demande « {$type['label']} » approuvée — à traiter ($numero)", $id);
        } else {
            di_notify($uid, "Votre demande « {$type['label']} » a été approuvée.", $id);
        }
        return;
    }
    $role = $workflow[$etape]['role'];
    if ($role === 'n1' && !$n1UserId) {
        // Aucun N+1 désigné pour le département : les administrateurs, qui
        // peuvent viser à sa place, sont prévenus, et le demandeur aussi.
        foreach (di_admins() as $a) {
            if ($a !== $uid) di_notify($a, "Demande « {$type['label']} » de $nom ($numero) : aucun N+1 désigné pour le département concerné — visa N+1 à donner par un administrateur.", $id);
        }
        di_notify($uid, "Aucun N+1 n'est désigné pour le département concerné : votre demande « {$type['label']} » ($numero) est transmise aux administrateurs pour le visa N+1.", $id);
    } elseif ($role === 'n1') {
        di_notify($n1UserId, "Nouvelle demande « {$type['label']} » de $nom ($numero) à valider", $id);
    } else {
        di_notify_role($role, $etape === 0
            ? "Nouvelle demande « {$type['label']} » de $nom ($numero)"
            : "Demande « {$type['label']} » en attente — étape : {$workflow[$etape]['label']} ($numero)", $id);
    }
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

// ── Verrouille la demande et la relit, dans la transaction de l'appelant :
//    une décision se prend sur l'état réel, pas sur une lecture d'avant.
function di_verrouiller(int $id): ?array {
    db_query("SELECT id FROM di_demandes WHERE id=? FOR UPDATE", [$id]);
    return di_get($id);
}

// ── Valider l'étape courante
function di_valider(array $demande, array $user, string $commentaire = ''): void {
    db_begin();
    try {
        // Relue sous verrou : deux visas simultanés s'écrasaient (signature
        // perdue, étape sautée par un double clic).
        $demande = di_verrouiller((int)$demande['id']);
        if (!$demande) throw new Exception('Demande introuvable.');
        if (!in_array($demande['statut'], ['en_attente','en_cours'], true)) {
            throw new Exception("Cette demande ne peut plus être validée.");
        }
        $wf = di_workflow_of($demande);
        $cur = (int)$demande['etape_actuelle'];
        $n1Id = isset($demande['n1_user_id']) && $demande['n1_user_id'] ? (int)$demande['n1_user_id'] : null;
        if (!di_can_validate(di_user_roles((int)$user['id']), (int)$user['id'], $wf, $cur, (int)$demande['demandeur_id'], $n1Id)) {
            throw new Exception("Vous ne pouvez pas valider cette étape.");
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
            if ($wf[$next]['role'] === 'n1' && empty($demande['n1_user_id'])) {
                foreach (di_admins() as $a) di_notify($a, "Demande « {$type['label']} » ({$demande['numero']}) : aucun N+1 désigné — visa N+1 à donner par un administrateur.", (int)$demande['id']);
            } else {
                di_notify_role($wf[$next]['role'], "Demande « {$type['label']} » en attente — étape : {$wf[$next]['label']} ({$demande['numero']})", (int)$demande['id']);
            }
        }
        db_commit();
    } catch (Exception $e) { db_rollback(); throw $e; }
}

// ── Rejeter l'étape courante
function di_rejeter(array $demande, array $user, string $motif): void {
    if (trim($motif) === '') throw new Exception('Le motif de rejet est obligatoire.');
    db_begin();
    try {
        $demande = di_verrouiller((int)$demande['id']);
        if (!$demande) throw new Exception('Demande introuvable.');
        // Une demande approuvée garde son étape finale comme étape courante :
        // sans ce contrôle, le valideur de cette étape pouvait encore la rejeter.
        if (!in_array($demande['statut'], ['en_attente','en_cours'], true)) {
            throw new Exception("Cette demande n'est plus en cours de validation : elle ne peut plus être rejetée.");
        }
        $wf = di_workflow_of($demande);
        $cur = (int)$demande['etape_actuelle'];
        $n1Id = isset($demande['n1_user_id']) && $demande['n1_user_id'] ? (int)$demande['n1_user_id'] : null;
        if (!di_can_validate(di_user_roles((int)$user['id']), (int)$user['id'], $wf, $cur, (int)$demande['demandeur_id'], $n1Id)) {
            throw new Exception("Vous ne pouvez pas rejeter cette étape.");
        }
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
        db_commit();
    } catch (Exception $e) { db_rollback(); throw $e; }
}

// ── Qui peut déposer une demande
// Le lecteur consulte et valide, mais ne dépose pas : son profil est un
// profil de consultation. La règle vit ici pour que le menu, la page de
// création et les boutons d'appel s'appuient tous sur la même.
function di_peut_creer(?array $user = null): bool {
    $user = $user ?? current_user();
    return ($user['role_slug'] ?? '') !== 'lecteur';
}
