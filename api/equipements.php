<?php
// ============================================================
//  api/equipements.php  —  API CRUD équipements
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user   = current_user();

switch ($action) {

    // ── LISTE avec filtres + pagination
    case 'list':
        require_permission('equipements', 'can_read');
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 20;
        $where    = ['e.actif = 1'];
        $params   = [];

        if (!empty($_GET['search'])) {
            $where[]  = '(e.numero_serie_interne LIKE ? OR e.numero_serie_origine LIKE ? OR e.marque LIKE ? OR e.modele LIKE ?)';
            $s = '%' . $_GET['search'] . '%';
            array_push($params, $s, $s, $s, $s);
        }
        if (!empty($_GET['nomenclature_id'])) { $where[] = 'e.nomenclature_id = ?'; $params[] = (int)$_GET['nomenclature_id']; }
        if (!empty($_GET['site_id']))          { $where[] = 'e.site_id = ?';          $params[] = (int)$_GET['site_id']; }
        if (!empty($_GET['etat']))             { $where[] = 'e.etat = ?';             $params[] = $_GET['etat']; }
        if (!empty($_GET['fin_cycle']))        { $where[] = 'e.date_fin_cycle <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)'; }

        $ws    = implode(' AND ', $where);
        $total = (int)db_fetch_value("SELECT COUNT(*) FROM equipements e WHERE $ws", $params);
        $offset = ($page - 1) * $per_page;
        $p2    = array_merge($params, [$per_page, $offset]);

        $rows  = db_fetch_all(
            "SELECT e.*,
                    n.libelle AS type_equip, n.code AS type_code, n.duree_vie_mois,
                    s.nom AS site_nom,
                    CONCAT(u.prenom,' ',u.nom) AS utilisateur_nom,
                    DATEDIFF(e.date_fin_cycle, CURDATE()) AS jours_fin_cycle
             FROM equipements e
             JOIN nomenclatures n ON n.id = e.nomenclature_id
             LEFT JOIN sites s ON s.id = e.site_id
             LEFT JOIN users u ON u.id = e.utilisateur_id
             WHERE $ws
             ORDER BY e.numero_chrono DESC
             LIMIT ? OFFSET ?", $p2
        );
        json_response(true, '', ['data' => $rows, 'total' => $total, 'page' => $page, 'last_page' => (int)ceil($total / $per_page)]);
        break;

    // ── DÉTAIL
    case 'get':
        require_permission('equipements', 'can_read');
        $id  = (int)($_GET['id'] ?? 0);
        $row = db_fetch_one(
            "SELECT e.*, n.libelle AS type_equip, n.code AS type_code, n.duree_vie_mois,
                    s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS utilisateur_nom
             FROM equipements e
             JOIN nomenclatures n ON n.id = e.nomenclature_id
             LEFT JOIN sites s ON s.id = e.site_id
             LEFT JOIN users u ON u.id = e.utilisateur_id
             WHERE e.id = ? AND e.actif = 1", [$id]
        );
        if (!$row) json_response(false, 'Équipement introuvable.');
        $row['mouvements'] = db_fetch_all(
            "SELECT me.*, s1.nom AS src, s2.nom AS dest, CONCAT(u.prenom,' ',u.nom) AS agent
             FROM mouvements_equipements me
             LEFT JOIN sites s1 ON s1.id = me.site_source_id
             LEFT JOIN sites s2 ON s2.id = me.site_dest_id
             LEFT JOIN users u ON u.id = me.created_by
             WHERE me.equipement_id = ? ORDER BY me.created_at DESC", [$id]
        );
        json_response(true, '', ['data' => $row]);
        break;

    // ── CRÉER
    case 'create':
        // Désactivée (12/10/2026) : aucun écran ne l'appelle — la création
        // passe par pages/equipements.php, qui attribue le numéro interne
        // (NOMENCLATURE-EMUCI-0001) et le numéro chrono. Cette version-ci
        // ne les fournissait pas : MySQL 8 strict aurait refusé l'INSERT
        // (numero_serie_interne et numero_chrono obligatoires).
        json_response(false, "Création d'équipement : utiliser l'écran Équipements.");
        break;

    // ── MODIFIER
    case 'update':
        require_permission('equipements', 'can_update');
        $id = (int)($_POST['id'] ?? 0);
        $eq = db_fetch_one("SELECT * FROM equipements WHERE id=? AND actif=1", [$id]);
        if (!$eq) json_response(false, 'Équipement introuvable.');

        $v = validate_input([
            'etat'                  => 'string',
            'marque'                => 'string',
            'modele'                => 'string',
            'observations'          => 'string',
            'site_id'               => 'nullable_int',
            'utilisateur_id'        => 'nullable_int',
            'date_acquisition'      => 'nullable_date',
            'date_mise_en_service'  => 'nullable_date',
            'date_fin_cycle'        => 'nullable_date',
        ]);
        if (!$v['valid']) json_response(false, implode(' ', $v['errors']));
        $d = $v['data'];

        if ($d['date_mise_en_service'] && !$d['date_fin_cycle']) {
            $nom = db_fetch_one("SELECT duree_vie_mois FROM nomenclatures WHERE id=?", [$eq['nomenclature_id']]);
            $d['date_fin_cycle'] = calc_fin_cycle($d['date_mise_en_service'], $nom['duree_vie_mois']);
        }

        db_begin();
        try {
            $site_change = ((int)$d['site_id'] !== (int)$eq['site_id']);
            db_query(
                "UPDATE equipements SET etat=?, marque=?, modele=?, observations=?,
                  site_id=?, utilisateur_id=?, date_acquisition=?, date_mise_en_service=?, date_fin_cycle=?
                 WHERE id=?",
                [$d['etat'], $d['marque'], $d['modele'], $d['observations'],
                 $d['site_id'], $d['utilisateur_id'],
                 $d['date_acquisition'], $d['date_mise_en_service'], $d['date_fin_cycle'], $id]
            );
            if ($site_change) {
                db_query(
                    "INSERT INTO mouvements_equipements (equipement_id, type, site_source_id, site_dest_id, notes, created_by)
                     VALUES (?, 'transfert', ?, ?, 'Transfert via modification', ?)",
                    [$id, $eq['site_id'], $d['site_id'], $user['id']]
                );
            }
            audit_log($user['id'], 'UPDATE', 'equipements', $id,
                "Modification {$eq['numero_serie_interne']}", $eq, $d);
            db_commit();
            json_response(true, 'Équipement mis à jour.');
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
        break;

    // ── TRANSFERT
    case 'transfert':
        require_permission('equipements', 'can_update');
        $id           = (int)($_POST['id'] ?? 0);
        $site_dest_id = (int)($_POST['site_dest_id'] ?? 0);
        $user_dest_id = (int)($_POST['user_dest_id'] ?? 0) ?: null;
        $notes        = trim($_POST['notes'] ?? '');
        $eq = db_fetch_one("SELECT * FROM equipements WHERE id=? AND actif=1", [$id]);
        if (!$eq) json_response(false, 'Équipement introuvable.');
        db_begin();
        try {
            db_query("UPDATE equipements SET site_id=?, utilisateur_id=? WHERE id=?", [$site_dest_id, $user_dest_id, $id]);
            db_query(
                "INSERT INTO mouvements_equipements (equipement_id, type, site_source_id, site_dest_id, user_dest_id, notes, created_by)
                 VALUES (?, 'transfert', ?, ?, ?, ?, ?)",
                [$id, $eq['site_id'], $site_dest_id, $user_dest_id, $notes, $user['id']]
            );
            audit_log($user['id'], 'TRANSFER', 'equipements', $id, "Transfert {$eq['numero_serie_interne']}");
            db_commit();
            json_response(true, 'Transfert effectué.');
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
        break;

    // ── RÉFORMER
    case 'reforme':
        require_permission('equipements', 'can_update');
        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $eq    = db_fetch_one("SELECT * FROM equipements WHERE id=? AND actif=1", [$id]);
        if (!$eq) json_response(false, 'Équipement introuvable.');
        db_query("UPDATE equipements SET etat='reforme', site_id=NULL, utilisateur_id=NULL WHERE id=?", [$id]);
        db_query(
            "INSERT INTO mouvements_equipements (equipement_id, type, site_source_id, notes, created_by)
             VALUES (?, 'reforme', ?, ?, ?)",
            [$id, $eq['site_id'], $notes ?: 'Réforme équipement', $user['id']]
        );
        audit_log($user['id'], 'UPDATE', 'equipements', $id, "Réforme {$eq['numero_serie_interne']}");
        json_response(true, 'Équipement réformé.');
        break;

    // ── SUPPRIMER (soft delete)
    case 'delete':
        require_permission('equipements', 'can_delete');
        $id = (int)($_POST['id'] ?? 0);
        $eq = db_fetch_one("SELECT * FROM equipements WHERE id=? AND actif=1", [$id]);
        if (!$eq) json_response(false, 'Équipement introuvable.');
        db_query("UPDATE equipements SET actif=0 WHERE id=?", [$id]);
        audit_log($user['id'], 'DELETE', 'equipements', $id, "Suppression {$eq['numero_serie_interne']}", $eq);
        json_response(true, 'Équipement supprimé.');
        break;

    default:
        json_response(false, 'Action inconnue.');
}
