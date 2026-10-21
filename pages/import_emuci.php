<?php
// ============================================================
//  pages/import_emuci.php — Import EMUCI (OptoPlate + OptoTrace)
//  Page avec 2 onglets — Admin/Superviseur/Contrôleur Production
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/referentiels.php';
require_once __DIR__ . '/../includes/validation_stock.php';
require_once __DIR__ . '/../includes/historique_optoplate.php';

require_auth();

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$page_title  = 'Import EMUCI';
$active_page = 'import_emuci';

require_permission('import_emuci', 'can_read');

$sites_list = db_fetch_all("SELECT id, nom, nom_emuci FROM sites WHERE actif=1 ORDER BY nom");

// ============================================================
// FONCTIONS HELPERS
// ============================================================
function trouver_site_id(string $nom_emuci, array $sites): ?int {
    if (!$nom_emuci) return null;
    $nom = trim(strtolower($nom_emuci));

    // 1. Correspondance exacte sur nom_emuci (mapping défini par l'admin)
    foreach ($sites as $s) {
        if (!empty($s['nom_emuci']) && strtolower(trim($s['nom_emuci'])) === $nom)
            return (int)$s['id'];
    }
    // 2. Correspondance exacte sur nom ERP EMUCI
    foreach ($sites as $s) {
        if (strtolower(trim($s['nom'])) === $nom) return (int)$s['id'];
    }
    // 3. Correspondance partielle sur nom ERP EMUCI (fallback)
    foreach ($sites as $s) {
        if (str_contains(strtolower($s['nom']), $nom) || str_contains($nom, strtolower($s['nom'])))
            return (int)$s['id'];
    }
    return null;
}

// Convertit une date au format européen (JJ/MM/AAAA, heure optionnelle) — celui des
// exports OptoPlate/OptoTrace — vers le format ISO attendu par PostgreSQL. Sans cette
// conversion, PostgreSQL interprète le séparateur "/" selon son propre DateStyle
// (souvent MDY) : "13/08/2026" y devient un 13e mois invalide (erreur "field overflow"),
// et une date comme "05/08/2026" y serait silencieusement lue comme le 8 mai au lieu
// du 5 août — jour et mois inversés sans la moindre erreur pour tout jour ≤ 12.
function emuci_parse_date_fr(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $v);
        if ($dt !== false) return $dt->format('Y-m-d H:i:s');
    }
    // Filet de secours pour un format déjà non ambigu (ISO...)
    $ts = strtotime($v);
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
}

// Texte d'un fichier CSV → UTF-8. L'export OptoPlate brut est en UTF-8, mais un fichier
// ouvert puis réenregistré avec Excel (« CSV (séparateur : point-virgule) ») sort en
// Windows-1252, et « Texte Unicode » en UTF-16. Lu tel quel, « N°Bobine » devenait
// « N\xB0Bobine » (octet invalide) : la colonne était déclarée manquante — et, si elle
// avait été trouvée, les noms de sites accentués auraient été enregistrés illisibles.
function emuci_csv_vers_utf8(string $s): string {
    if (str_starts_with($s, "\xFF\xFE")) return mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16LE');
    if (str_starts_with($s, "\xFE\xFF")) return mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
    if (str_starts_with($s, "\xEF\xBB\xBF")) $s = substr($s, 3);
    return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

// Clé de comparaison d'un nom de colonne : insensible à la casse, aux accents, aux espaces
// (dont l'espace insécable) et aux variantes de « ° » et d'apostrophe — « N° Bobine »,
// « N°Bobine » et « Nº bobine » désignent la même colonne.
function emuci_cle_entete(string $h): string {
    static $memo = [];
    if (isset($memo[$h])) return $memo[$h];
    $k = mb_strtolower(trim(str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], ['', ' '], $h), " \t\r\n\"\0"), 'UTF-8');
    $k = strtr($k, [
        '’' => "'", '‘' => "'", '`' => "'", '´' => "'",
        'º' => '°', '˚' => '°', 'ᵒ' => '°',
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    return $memo[$h] = preg_replace('/\s+/u', '', $k);
}

// Lit un CSV (UTF-8 ou non, délimiteur « ; », « , » ou tabulation) :
// retourne [entêtes, lignes].
function emuci_lire_csv(string $chemin): array {
    $texte = emuci_csv_vers_utf8((string)file_get_contents($chemin));
    $premiere = strstr($texte, "\n", true);
    $premiere = $premiere === false ? $texte : $premiere;
    $delim = ';';
    $max = substr_count($premiere, ';');
    foreach ([',', "\t"] as $d) {
        if (substr_count($premiere, $d) > $max) { $delim = $d; $max = substr_count($premiere, $d); }
    }
    $flux = fopen('php://temp', 'r+');
    fwrite($flux, $texte);
    rewind($flux);
    $headers = fgetcsv($flux, 0, $delim);
    $rows = [];
    if ($headers) {
        while (($row = fgetcsv($flux, 0, $delim)) !== false) $rows[] = $row;
    }
    fclose($flux);
    return [$headers ? array_map(fn($h) => trim((string)$h), $headers) : [], $rows];
}

// Enregistre un site EMUCI inconnu pour traitement ultérieur par l'admin
function logger_site_inconnu(string $nom_emuci, string $type_import): void {
    if (!$nom_emuci) return;
    db_query(
        "INSERT INTO emuci_sites_inconnus (nom_emuci, type_import, nb_occurrences, derniere_apparition)
         VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE
           nb_occurrences = nb_occurrences + 1,
           derniere_apparition = NOW()",
        [$nom_emuci, $type_import]
    );
}

// Vérifie la cohérence entre les plaques OptoPlate (in_use) et le PJ coordinateur.
// Une plaque posée = statut 'in_use' uniquement. 'reserved' = en attente, ne compte pas.
// Comparaison au niveau site : COUNT(in_use) EMUCI vs total_plaques déclarées dans le PJ.
// Ne crée aucun enregistrement dans validations_stock_matin — notifie uniquement le coordinateur.
function _verifier_coherence_optoplate(string $date_import, int $user_id): array {
    $resultats = [];
    $sites_avec_data = db_fetch_all(
        "SELECT DISTINCT site_id FROM import_optoplate WHERE date_import=? AND site_id IS NOT NULL",
        [$date_import]
    );
    $sites_auto = historique_auto_sites();
    foreach ($sites_avec_data as $s) {
        $site_id = (int)$s['site_id'];
        if (isset($sites_auto[$site_id])) continue;   // point généré depuis OptoPlate : rien à comparer

        // EMUCI : total plaques posées sur ce site = COUNT(in_use), compté sur la
        // vraie date d'installation de chaque plaque (pas la date saisie à l'import,
        // qui peut couvrir un historique de plusieurs jours voire complet).
        $nb_inuse_emuci = (int)db_fetch_value(
            "SELECT COUNT(*) FROM import_optoplate WHERE site_id=? AND statut_plaque='in_use' AND DATE(date_installation)=?",
            [$site_id, $date_import]
        );

        // PJ coordinateur : total_plaques = (nb_vp*2)+(nb_camion*2)+(nb_semi*1)+(nb_moto*1)
        $total_plaques_pj = (int)db_fetch_value(
            "SELECT COALESCE(SUM(total_plaques),0)
             FROM op_points_journaliers
             WHERE site_id=? AND date_point=? AND statut='valide'",
            [$site_id, $date_import]
        );

        if ($nb_inuse_emuci === 0 && $total_plaques_pj === 0) continue;

        $site_nom = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]);
        $ecart    = $nb_inuse_emuci - $total_plaques_pj;

        if ($ecart !== 0) {
            $coords = db_fetch_all(
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1",
                [$site_id]
            );
            foreach ($coords as $c) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$c['id'],'info','⚠️ Incohérence plaques détectée',
                     "Import OptoPlate du $date_import — Site $site_nom : EMUCI enregistre $nb_inuse_emuci plaque(s) posée(s), votre PJ déclare $total_plaques_pj. Vérifiez votre point journalier.",
                     '/pages/operations/point_journalier.php']);
            }
            $resultats[] = ['site_id'=>$site_id,'statut'=>'incoherent',
                            'nb_inuse'=>$nb_inuse_emuci,'total_plaques_pj'=>$total_plaques_pj,'ecart'=>$ecart];
        } else {
            $resultats[] = ['site_id'=>$site_id,'statut'=>'coherent','nb_ecarts'=>0];
        }
    }
    return $resultats;
}

// Déclencheur « import » de la validation stock jour (includes/
// validation_stock.php) : chaque site dont le point 18h de la date est
// validé par le superviseur est comparé à CET import. L'ancienne version
// comparait toutes les bobines actives du site au dernier import, sans
// exiger un point 18h, et renvoyait l'alerte GSB à chaque réimport.
function _auto_valider_stock(string $date_import, int $user_id): array {
    return vsm_evaluer_import($date_import, $user_id);
}

// ============================================================
// POST — IMPORT OPTOPLATE (CSV)
// ============================================================
$msg_optoplate = null;
$result_optoplate = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_optoplate') {
    require_permission('import_emuci', 'can_create');
    $date_import = trim($_POST['date_import'] ?? date('Y-m-d'));

    if (empty($_FILES['fichier_optoplate']) || $_FILES['fichier_optoplate']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['fichier_optoplate']['error'] ?? null;
        $detail = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur ('.ini_get('upload_max_filesize').').',
            UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été que partiellement envoyé — réessayez.',
            UPLOAD_ERR_NO_FILE, null => 'Aucun fichier sélectionné.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Erreur serveur lors de la réception du fichier (code '.$err.').',
            default => 'Erreur inconnue (code '.$err.').',
        };
        $msg_optoplate = ['type'=>'danger','text'=>"Veuillez sélectionner un fichier CSV ou XLSX valide. — $detail"];
    } else {
        $tmp = $_FILES['fichier_optoplate']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['fichier_optoplate']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            $msg_optoplate = ['type'=>'danger','text'=>'Format invalide. Utilisez un fichier .csv ou .xlsx (exporté depuis OptoPlate).'];
        } else {
            $session_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));

            // Lire les lignes selon le format
            $all_rows = [];
            if ($ext === 'csv') {
                [$headers, $all_rows] = emuci_lire_csv($tmp);
            } else {
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (!file_exists($autoload)) {
                    $msg_optoplate = ['type'=>'danger','text'=>'PhpSpreadsheet non installé. Exécutez : composer require phpoffice/phpspreadsheet'];
                    $headers = [];
                } else {
                    require_once $autoload;
                    $tmp_dest = sys_get_temp_dir().'/optoplate_'.uniqid().'.'.$ext;
                    move_uploaded_file($tmp, $tmp_dest);
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_dest);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows_raw = $sheet->toArray(null, true, true, false);
                    @unlink($tmp_dest);
                    $headers_raw = $rows_raw[0] ?? [];
                    $headers = array_map(fn($h) => trim((string)$h), $headers_raw);
                    $all_rows = array_slice($rows_raw, 1);
                }
            }

            if (empty($headers)) {
                $msg_optoplate = ['type'=>'danger','text'=>'Fichier vide ou invalide.'];
            } else {
                // Index des colonnes par nom normalisé (cf. emuci_cle_entete)
                $col = [];
                foreach ($headers as $i => $h) {
                    $cle = emuci_cle_entete($h);
                    if ($cle !== '' && !isset($col[$cle])) $col[$cle] = $i;
                }

                // Colonnes requises
                $required = ["Date d'installation","Numéro d'immatriculation","Statut plaque","N°Bobine","Nom du site d'installation"];
                $missing = array_filter($required, fn($r) => !isset($col[emuci_cle_entete($r)]));
                if ($missing) {
                    $msg_optoplate = ['type'=>'danger','text'=>'Colonnes manquantes : '.implode(', ', $missing).
                        '. Colonnes détectées dans le fichier : '.implode(', ', array_filter($headers, fn($h) => $h !== '')).
                        '. Vérifiez que c\'est bien l\'export OptoPlate « Plaques posées » et que sa première ligne est la ligne d\'en-têtes.'];
                } else {
                    set_time_limit(0);
                    $nb_ok = 0; $nb_err = 0; $sites_inconnus = [];
                    $jours_pose = [];   // jour d'installation => nb de plaques posées dans le fichier

                    // Un seul fichier OptoPlate porte à la fois les poses et les
                    // désactivations (une ligne par plaque, mise à jour dans le temps) —
                    // dès que la migration désactivations est jouée, on upsert par
                    // N°Consommable au lieu de purger/réinsérer le jour (sinon une
                    // plaque posée hier puis désactivée aujourd'hui perdrait sa pose).
                    $peut_upsert = db_colonne_existe('import_optoplate', 'type_desactivation');
                    // Jour d'impression du film (migration_optoplate_date_impression) : une plaque
                    // « reserved » est imprimée mais pas posée — son film compte le jour de
                    // l'impression, l'engin le jour où elle passe in_use.
                    $avec_impression = $peut_upsert && db_colonne_existe('import_optoplate', 'date_impression');

                    // Créer session
                    db_query("INSERT INTO import_sessions_emuci (id,date_import,type_import,statut,importe_par) VALUES (?,?,'optoplate','en_cours',?)",
                        [$session_id,$date_import,$user['id']]);

                    if (!$peut_upsert) {
                        // Ancien comportement (avant migration) : purge du jour.
                        db_query("DELETE FROM import_optoplate WHERE date_import=?", [$date_import]);
                    }

                    try {
                    db_begin();
                    foreach ($all_rows as $row) {
                        if (count($row) < 5) continue;
                        $get = fn($k) => trim((string)($row[$col[emuci_cle_entete($k)] ?? -1] ?? ''));

                        $date_install = emuci_parse_date_fr($get("Date d'installation"));
                        $immat        = $get("Numéro d'immatriculation");
                        $vin          = $get('Vin');
                        $num_dossier  = $get('Numéro de dossier');
                        $type_plaque  = $get('Type de plaque');
                        $statut       = $get('Statut plaque');
                        $position     = $get('Position');
                        $num_conso    = $get('N°Consommable') ?: null;
                        $num_bobine   = $get('N°Bobine');
                        $site_id_emu  = $get('Id du site');
                        $site_nom_emu = $get("Nom du site d'installation");
                        if (!$immat || !$statut) { $nb_err++; continue; }

                        $site_id_ds = trouver_site_id($site_nom_emu, $sites_list);
                        if (!$site_id_ds && $site_nom_emu) {
                            $sites_inconnus[$site_nom_emu] = true;
                            logger_site_inconnu($site_nom_emu, 'optoplate');
                        }

                        $cols_opto = ['import_session_id','date_import','date_installation','numero_dossier','immatriculation','vin','type_plaque','statut_plaque','position','num_consommable','num_bobine','site_id_emuci','site_nom_emuci','site_id','importe_par'];
                        $vals_opto = [$session_id,$date_import,$date_install,$num_dossier,$immat,$vin,$type_plaque,$statut,$position,$num_conso,$num_bobine,$site_id_emu,$site_nom_emu,$site_id_ds,$user['id']];
                        // Colonnes désactivation (plan comparaison OptoPlate, 15/10) — même
                        // fichier/ligne que la pose ; présentes seulement si la plaque a
                        // été désactivée depuis (Duplicata / Modification POST / Incident).
                        if ($peut_upsert) {
                            $cols_opto = array_merge($cols_opto, ['categorie','ancienne_plaque','type_desactivation','motif_desactivation','date_desactivation']);
                            $vals_opto = array_merge($vals_opto, [
                                $get('Catégorie') ?: null,
                                $get('Ancien Plate') ?: null,
                                $get('Type de désactivation') ?: null,
                                $get('Motif de désactivation') ?: null,
                                // colonne DATE : la partie heure tronquée par MySQL
                                // ferait un avertissement (refusé en mode strict)
                                ($dd = emuci_parse_date_fr($get('Date de désactivation'))) ? substr($dd, 0, 10) : null,
                            ]);
                        }
                        if ($avec_impression) {
                            $derniere_action = emuci_parse_date_fr($get('Date dernière action'));
                            $jour_impr = ($statut === 'reserved' || !$date_install)
                                ? substr((string)($derniere_action ?: $date_import), 0, 10)
                                : substr($date_install, 0, 10);
                            $cols_opto = array_merge($cols_opto, ['date_derniere_action','date_impression']);
                            $vals_opto = array_merge($vals_opto, [$derniere_action, $jour_impr]);
                        }

                        if ($peut_upsert) {
                            // MySQL : ON DUPLICATE KEY sur l'index unique de
                            // num_consommable (migration_optoplate_desactivations_mysql.sql) —
                            // les NULL ne s'y heurtent jamais, comme l'index partiel de main.
                            // date_impression : figée au premier import qui voit la plaque.
                            $set_cols = array_diff($cols_opto, ['import_session_id','num_consommable']);
                            if ($avec_impression) {
                                // Un export plus ancien (historique réimporté après les imports
                                // quotidiens) ne remet pas une plaque dans un état dépassé — une
                                // plaque posée ne redevient pas « reserved ». MySQL applique les
                                // affectations dans l'ordre : date_derniere_action en dernier.
                                $plus_recent = "(date_derniere_action IS NULL OR VALUES(date_derniere_action) IS NULL
                                                 OR VALUES(date_derniere_action) >= date_derniere_action)";
                                $set_cols = array_merge(array_diff($set_cols, ['date_derniere_action']), ['date_derniere_action']);
                            }
                            $set_sql = implode(',', array_map(
                                fn($c) => $c === 'date_impression'
                                    ? "date_impression=COALESCE(date_impression, VALUES(date_impression))"
                                    : ($avec_impression ? "$c=IF($plus_recent, VALUES($c), $c)" : "$c=VALUES($c)"),
                                $set_cols
                            ));
                            db_query(
                                "INSERT INTO import_optoplate (" . implode(',', $cols_opto) . ")
                                 VALUES (" . implode(',', array_fill(0, count($cols_opto), '?')) . ")
                                 ON DUPLICATE KEY UPDATE $set_sql",
                                $vals_opto
                            );
                        } else {
                            db_query(
                                "INSERT INTO import_optoplate (" . implode(',', $cols_opto) . ")
                                 VALUES (" . implode(',', array_fill(0, count($cols_opto), '?')) . ")",
                                $vals_opto
                            );
                        }
                        $nb_ok++;
                        if ($statut === 'in_use' && $date_install) {
                            $j = substr($date_install, 0, 10);
                            $jours_pose[$j] = ($jours_pose[$j] ?? 0) + 1;
                        }
                    }

                    db_commit();
                    ksort($jours_pose);

                    db_query("UPDATE import_sessions_emuci SET statut='termine',nb_lignes_optoplate=?,nb_erreurs=? WHERE id=?",
                        [$nb_ok,$nb_err,$session_id]);
                    audit_log($user['id'],'CREATE','import_emuci',0,"Import OptoPlate $date_import — $nb_ok lignes");

                    // Sites dont le point est généré depuis OptoPlate : mis à jour avant le contrôle
                    // de cohérence (qui compare les plaques d'OptoPlate au point du jour).
                    $point_auto = $jours_pose
                        ? historique_auto_import(array_key_first($jours_pose), array_key_last($jours_pose))
                        : ['sites' => [], 'points' => 0, 'arretes' => [], 'erreurs' => []];
                    if ($point_auto['points'] > 0) {
                        audit_log($user['id'], 'CREATE', 'operations', 0,
                            "Points générés depuis OptoPlate : {$point_auto['points']} point(s), " . count($point_auto['sites']) . " site(s)");
                    }

                    // Vérifier cohérence plaques EMUCI vs PJ coordinateur
                    $auto_resultats  = _verifier_coherence_optoplate($date_import, $user['id']);
                    $nb_valide_auto  = 0;
                    $nb_incoherent   = count(array_filter($auto_resultats, fn($r)=>$r['statut']==='incoherent'));

                    $result_optoplate = [
                        'nb_ok' => $nb_ok, 'nb_err' => $nb_err,
                        'sites_inconnus' => array_keys($sites_inconnus),
                        'session_id' => $session_id, 'date' => $date_import,
                        'nb_valide_auto'  => $nb_valide_auto,
                        'nb_incoherent'   => $nb_incoherent,
                        'jours_pose'      => $jours_pose,
                        'point_auto'      => $point_auto,
                    ];
                    $incoh_msg = $nb_incoherent>0?" · ⚠️ $nb_incoherent site(s) avec incohérence plaques — coordinateur(s) notifié(s)":'';
                    $msg_optoplate = ['type'=>'success','text'=>"Import terminé : $nb_ok plaques importées.".($nb_err?" ($nb_err ignorées)":'').$incoh_msg];
                    } catch (Exception $e) {
                        // PostgreSQL : indispensable de rollback, sinon la connexion
                        // reste en transaction annulée (25P02) pour toute la page.
                        db_rollback();
                        $msg_optoplate = ['type'=>'danger','text'=>'Erreur import : '.$e->getMessage()];
                    }
                }
            }
        }
    }
}

// ============================================================
// POST — ALIMENTER LE DASHBOARD AVEC LA PRODUCTION D'AVANT L'ERP
//        (simulation, puis application sur confirmation)
// ============================================================
$rapport_historique = null;
$msg_historique = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'historique_optoplate') {
    require_permission('import_emuci', 'can_create');
    $h_du      = trim($_POST['hist_du'] ?? '');
    $h_au      = trim($_POST['hist_au'] ?? '');
    $h_site    = (int)($_POST['hist_site'] ?? 0);
    $h_refaire = !empty($_POST['hist_refaire']);
    $h_appliquer = ($_POST['mode'] ?? '') === 'appliquer';
    $date_ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

    if (!$date_ok($h_du) || !$date_ok($h_au)) {
        $msg_historique = ['type'=>'danger','text'=>'Dates invalides : indiquez le début et la fin de la période.'];
    } elseif ($h_au > date('Y-m-d', strtotime('-1 day'))) {
        $msg_historique = ['type'=>'danger','text'=>"La période doit se terminer au plus tard hier : la journée en cours n'est pas terminée."];
    } elseif ($h_du > $h_au) {
        $msg_historique = ['type'=>'danger','text'=>"Période vide : le début est après la fin."];
    } else {
        try {
            set_time_limit(0);
            ini_set('memory_limit', '1G');
            $rapport_historique = historique_optoplate($h_du, $h_au, $h_appliquer, $h_site, $h_refaire);
            if ($h_appliquer && $rapport_historique['stats']['points'] > 0) {
                audit_log($user['id'], 'CREATE', 'operations', 0,
                    "Reconstitution historique OptoPlate $h_du au $h_au : {$rapport_historique['stats']['points']} point(s), {$rapport_historique['stats']['engins']} engins");
            }
        } catch (Throwable $e) {
            $msg_historique = ['type'=>'danger','text'=>$e->getMessage()];
        }
    }
}

// ============================================================
// POST — IMPORT OPTOTRACE (XLSX)
// ============================================================
$msg_optotrace = null;
$result_optotrace = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_optotrace') {
    require_permission('import_emuci', 'can_create');
    $date_import = trim($_POST['date_import_trace'] ?? date('Y-m-d'));

    if (empty($_FILES['fichier_optotrace']) || $_FILES['fichier_optotrace']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['fichier_optotrace']['error'] ?? null;
        $detail = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur ('.ini_get('upload_max_filesize').').',
            UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été que partiellement envoyé — réessayez.',
            UPLOAD_ERR_NO_FILE, null => 'Aucun fichier sélectionné.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Erreur serveur lors de la réception du fichier (code '.$err.').',
            default => 'Erreur inconnue (code '.$err.').',
        };
        $msg_optotrace = ['type'=>'danger','text'=>"Veuillez sélectionner un fichier XLSX valide. — $detail"];
    } else {
        $tmp = $_FILES['fichier_optotrace']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['fichier_optotrace']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx','xls'])) {
            $msg_optotrace = ['type'=>'danger','text'=>'Format invalide. Utilisez un fichier .xlsx (exporté depuis OptoTrace).'];
        } else {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!file_exists($autoload)) {
                $msg_optotrace = ['type'=>'danger','text'=>'PhpSpreadsheet non installé. Exécutez : composer require phpoffice/phpspreadsheet'];
            } else {
                require_once $autoload;

                $session_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
                    mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
                    mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));

                try {
                    $tmp_dest = sys_get_temp_dir().'/optotrace_'.uniqid().'.'.$ext;
                    move_uploaded_file($tmp, $tmp_dest);
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_dest);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows  = $sheet->toArray(null, true, true, false);
                    @unlink($tmp_dest);

                    if (empty($rows)) throw new Exception('Fichier vide.');

                    // ── Normaliser les headers (trim + lowercase pour la détection)
                    $headers_raw = $rows[0];
                    $headers = array_map('trim', $headers_raw);
                    $col = array_flip($headers);

                    // Colonnes obligatoires réelles du fichier OptoTrace
                    $required = ['keyname', 'quantity', 'site'];
                    $missing  = array_filter($required, fn($r) => !isset($col[$r]));
                    if ($missing) throw new Exception('Colonnes manquantes : '.implode(', ', $missing).
                        '. Colonnes détectées : '.implode(', ', array_filter($headers)));

                    set_time_limit(0);

                    // Créer session
                    db_query("INSERT INTO import_sessions_emuci (id,date_import,type_import,statut,importe_par) VALUES (?,?,'optotrace','en_cours',?)",
                        [$session_id, $date_import, $user['id']]);

                    // Supprimer import du même jour
                    db_query("DELETE FROM import_optotrace WHERE date_import=?", [$date_import]);

                    $nb_ok = 0; $nb_err = 0; $nb_stock_maj = 0; $sites_inconnus = [];

                    db_begin();
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        // Helper : lire une colonne par nom, retourner '' si absente
                        $get = fn(string $k): string => isset($col[$k])
                            ? trim((string)($row[$col[$k]] ?? ''))
                            : '';

                        $keyname    = $get('keyname');    // N° bobine
                        $batch      = $get('batch');
                        $project    = $get('project');
                        $article    = $get('article');
                        $format     = $get('format');
                        $box        = $get('box');
                        $quantity   = (int)$get('quantity');  // films restants
                        $type_trace = $get('type');
                        $state      = (int)$get('state');
                        $parse_dt   = fn($v) => emuci_parse_date_fr($v);
                        $first_use  = $parse_dt($get('first'));
                        $last_use   = $parse_dt($get('last'));
                        $site_nom   = $get('site');
                        $sended_on  = $parse_dt($get('sendedOn'));
                        $received_on = $parse_dt($get('receivedOn'));
                        $canceled_on = $parse_dt($get('canceledOn'));

                        if (!$keyname) { $nb_err++; continue; }

                        $site_id_ds = trouver_site_id($site_nom, $sites_list);
                        if (!$site_id_ds && $site_nom) {
                            $sites_inconnus[$site_nom] = true;
                            logger_site_inconnu($site_nom, 'optotrace');
                        }

                        db_query(
                            "INSERT INTO import_optotrace
                             (import_session_id, date_import, keyname, batch, project, article, format, box,
                              quantity, type_trace, state, first_use, last_use,
                              site_nom_emuci, site_id, sended_on, received_on, canceled_on, importe_par)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                            [$session_id, $date_import, $keyname, $batch, $project, $article, $format, $box,
                             $quantity, $type_trace, $state, $first_use, $last_use,
                             $site_nom, $site_id_ds, $sended_on, $received_on, $canceled_on, $user['id']]
                        );

                        // ── Synchronisation bobine depuis OptoTrace (source de vérité)
                        $bobine = db_fetch_one(
                            "SELECT id, films_restants, stock_systeme, site_id, qte_initiale FROM op_bobines WHERE numero=?",
                            [$keyname]
                        );

                        if (!$bobine) {
                            // ── Bobine inconnue → CRÉATION automatique
                            $serie_bobine = strtoupper(substr($format ?: '', 0, 1));
                            if (str_starts_with(strtoupper($format), 'WSL')) $serie_bobine = 'W';
                            elseif (str_starts_with(strtoupper($format), 'TL')) $serie_bobine = 'T';

                            // Capacité lue au catalogue ; l'ancienne règle
                            // « WSL/TL = 2000, sinon 500 » reste le repli des
                            // codes qui n'y figurent pas.
                            $qte_init = capacite_bobine(
                                $format,
                                (str_starts_with(strtoupper($format),'WSL') || str_starts_with(strtoupper($format),'TL'))
                                    ? 2000 : 500);

                            $tv = db_fetch_one("SELECT id FROM op_types_vehicule WHERE serie_bobine=? LIMIT 1",
                                [$serie_bobine]);

                            // Nouvelle bobine : en_cours si déjà consommée, sinon en_stock
                            $new_statut = match(true) {
                                $state == 7    => 'retiree',
                                $quantity == 0 => 'epuisee',
                                $quantity < $qte_init => 'en_cours',
                                default        => 'en_stock',
                            };

                            db_query(
                                "INSERT INTO op_bobines
                                 (numero,type_code,serie,type_vehicule_id,site_id,format,qte_initiale,
                                  stock_systeme,films_restants,statut)
                                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                                [$keyname, $format, $serie_bobine, $tv['id']??null,
                                 $site_id_ds, $format, $qte_init, $quantity, $quantity, $new_statut]
                            );
                            $new_id = (int)db_last_id();
                            if ($quantity > 0) {
                                db_query(
                                    "INSERT INTO mouvements_bobines
                                     (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                                     VALUES (?,?,?,?,?,?,?)",
                                    [$new_id,'entree',$quantity,0,$quantity,
                                     "Création auto via OptoTrace $date_import",$user['id']]
                                );
                            }
                            $nb_stock_maj++;

                        } else {
                            // ── Bobine existante → MISE À JOUR
                            // films_restants = quantité PHYSIQUE (gérée par PJ) — ne pas écraser
                            // stock_systeme  = quantité EMUCI (comparaison uniquement)
                            $old_systeme = (int)$bobine['stock_systeme'];
                            $old_phys    = (int)$bobine['films_restants'];
                            $qte_init    = (int)$bobine['qte_initiale'] ?: 500;
                            $site_upd    = $site_id_ds ?? $bobine['site_id'];
                            $diff_sys    = $quantity - $old_systeme;

                            // Statut basé sur la quantité PHYSIQUE (pas EMUCI)
                            $new_statut = match(true) {
                                $state == 7        => 'retiree',
                                $old_phys == 0     => 'epuisee',
                                $old_phys < $qte_init => 'en_cours',
                                default            => 'en_stock',
                            };

                            db_query(
                                "UPDATE op_bobines
                                 SET stock_systeme=?, statut=?,
                                     site_id=?, format=CASE WHEN ?<>'' THEN ? ELSE format END
                                 WHERE id=?",
                                [$quantity, $new_statut,
                                 $site_upd, $format, $format, $bobine['id']]
                            );

                            if ($diff_sys !== 0) {
                                db_query(
                                    "INSERT INTO mouvements_bobines
                                     (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                                     VALUES (?,?,?,?,?,?,?)",
                                    [$bobine['id'],'sync_emuci',$diff_sys,$old_systeme,$quantity,
                                     "Sync EMUCI $date_import (quantité système)",$user['id']]
                                );
                                $nb_stock_maj++;
                            }
                        }

                        $nb_ok++;
                    }

                    db_commit();

                    db_query("UPDATE import_sessions_emuci SET statut='termine',nb_lignes_optotrace=?,nb_erreurs=? WHERE id=?",
                        [$nb_ok, $nb_err, $session_id]);
                    $nb_crees = (int)db_fetch_value(
                        "SELECT COUNT(*) FROM op_bobines
                         WHERE created_at >= (NOW() - INTERVAL 5 MINUTE)"
                    );
                    audit_log($user['id'], 'CREATE', 'import_emuci', 0,
                        "Import OptoTrace $date_import — $nb_ok lignes, $nb_stock_maj bobines MAJ/créées");

                    // Déclencher validation automatique
                    $auto_resultats = _auto_valider_stock($date_import, $user['id']);
                    $nb_valide_auto  = count(array_filter($auto_resultats, fn($r) => $r['statut'] === 'valide_auto'));
                    // Sites en écart : le GSB est alerté (l'ancien compteur visait un statut
                    // « bloque » que la fonction ne renvoyait jamais — toujours 0).
                    $nb_bloque       = count(array_filter($auto_resultats, fn($r) => $r['statut'] === 'ecart'));

                    $result_optotrace = [
                        'nb_ok'          => $nb_ok,
                        'nb_err'         => $nb_err,
                        'nb_stock_maj'   => $nb_stock_maj,
                        'sites_inconnus' => array_keys($sites_inconnus),
                        'session_id'     => $session_id,
                        'date'           => $date_import,
                        'nb_valide_auto' => $nb_valide_auto,
                        'nb_bloque'      => $nb_bloque,
                    ];
                    $auto_msg = $nb_valide_auto > 0 ? " · ✅ $nb_valide_auto site(s) validé(s) auto" : '';
                    $bloc_msg = $nb_bloque      > 0 ? " · ⚠️ $nb_bloque site(s) en écart, GSB alerté" : '';
                    $stock_msg = $nb_stock_maj  > 0 ? " · 🔄 $nb_stock_maj bobine(s) mises à jour" : '';
                    $msg_optotrace = ['type'=>'success',
                        'text'=>"Import terminé : $nb_ok lignes importées.".($nb_err?" ($nb_err ignorées)":'').$stock_msg.$auto_msg.$bloc_msg];

                } catch (Exception $e) {
                    // PostgreSQL : rollback obligatoire pour sortir de l'état 25P02
                    db_rollback();
                    $msg_optotrace = ['type'=>'danger','text'=>'Erreur : '.$e->getMessage()];
                }
            }
        }
    }
}

// ============================================================
// DONNÉES — COMPARAISON & HISTORIQUE
// ============================================================
$f_date = trim($_GET['date'] ?? date('Y-m-d'));
// Juste après un import, l'écran se place sur la dernière journée de pose contenue dans le
// fichier : les plaques « posées » sont comptées sur leur date d'installation, donc sur
// « aujourd'hui » un export du 14/10 donnait « Aucune donnée » alors qu'il était bien importé.
if (!isset($_GET['date']) && !empty($result_optoplate)) {
    $f_date = $result_optoplate['jours_pose'] ? array_key_last($result_optoplate['jours_pose']) : $result_optoplate['date'];
}
$f_site = (int)($_GET['site'] ?? 0);
$onglet = $_GET['tab'] ?? 'optoplate';

// Encadré « Alimenter le dashboard » : période proposée (de la première plaque importée à la
// veille du premier vrai point de l'ERP).
$hist_prereq  = historique_optoplate_prerequis();
$hist_periode = $hist_prereq ? null : historique_optoplate_periode();

// Stats OptoPlate du jour — "in_use" (plaque réellement posée) compté sur sa
// vraie date d'installation (date_installation), pas la date saisie à l'import
// (date_import), qui peut couvrir un historique de plusieurs jours voire
// complet. Les autres statuts (reserved, declared_broken...) n'ont pas de date
// d'installation puisque la plaque n'est pas posée : ils restent rattachés à
// la date de l'import qui les a rapportés.
$stats_optoplate = db_fetch_all(
    "SELECT
        statut_plaque,
        site_nom_emuci,
        site_id,
        COUNT(*) AS nb_plaques,
        COUNT(DISTINCT num_bobine) AS nb_bobines,
        COUNT(DISTINCT immatriculation) AS nb_vehicules
     FROM import_optoplate
     WHERE ((statut_plaque='in_use' AND DATE(date_installation)=?)
            OR (statut_plaque!='in_use' AND date_import=?))
     " . ($f_site ? "AND site_id=$f_site" : "") . "
     GROUP BY statut_plaque, site_nom_emuci, site_id
     ORDER BY site_nom_emuci, statut_plaque",
    [$f_date, $f_date]
);

// Stats OptoTrace du jour (colonnes nouvelles : keyname, quantity, state, site_nom_emuci)
$stats_optotrace = db_fetch_all(
    "SELECT
        site_nom_emuci,
        site_id,
        COUNT(*)                                          AS nb_lignes,
        COUNT(DISTINCT keyname)                           AS nb_bobines,
        SUM(CASE WHEN state = 7 THEN 1 ELSE 0 END)       AS nb_retirees,
        SUM(CASE WHEN quantity = 0 AND state != 7 THEN 1 ELSE 0 END) AS nb_epuisees,
        SUM(quantity)                                     AS total_films_restants
     FROM import_optotrace
     WHERE date_import=?
     " . ($f_site ? "AND site_id=$f_site" : "") . "
     GROUP BY site_nom_emuci, site_id
     ORDER BY site_nom_emuci",
    [$f_date]
);

// Détail bobines OptoTrace du jour pour comparaison stock
$detail_bobines_optotrace = db_fetch_all(
    "SELECT ot.keyname, ot.format, ot.quantity AS qty_optotrace, ot.state,
            ot.site_nom_emuci, ot.site_id,
            b.id AS bobine_id, b.films_restants AS qty_digistock,
            (ot.quantity - COALESCE(b.films_restants, 0)) AS ecart
     FROM import_optotrace ot
     LEFT JOIN op_bobines b ON b.numero = ot.keyname
     WHERE ot.date_import=?
     " . ($f_site ? "AND ot.site_id=$f_site" : "") . "
     ORDER BY ot.site_nom_emuci, ot.keyname",
    [$f_date]
);

// Comparaison OptoPlate vs ERP EMUCI PJ par site — "in_use" (plaque réellement
// posée) compté sur sa vraie date d'installation (date_installation), pas la
// date saisie à l'import (date_import), qui peut couvrir un historique de
// plusieurs jours voire complet : compter par date_import ferait remonter
// tout le fichier sous une seule journée. reserved/declared_broken n'ont pas
// de date d'installation (plaque pas posée) : ils restent rattachés à la date
// de l'import.
$comparaison = db_fetch_all(
    "SELECT
        s.nom AS site_nom,
        s.id AS site_id,
        COALESCE(op.nb_in_use,0) AS plaques_in_use_emuci,
        COALESCE(op.nb_reserved,0) AS plaques_reserved_emuci,
        COALESCE(op.nb_broken,0) AS plaques_broken_emuci,
        COALESCE(pj.total_plaques,0) AS plaques_digi,
        (COALESCE(op.nb_in_use,0) - COALESCE(pj.total_plaques,0)) AS ecart
     FROM sites s
     LEFT JOIN (
         SELECT site_id,
                SUM(CASE WHEN statut_plaque='in_use' THEN nb_plaques ELSE 0 END) AS nb_in_use,
                SUM(CASE WHEN statut_plaque='reserved' THEN nb_plaques ELSE 0 END) AS nb_reserved,
                SUM(CASE WHEN statut_plaque='declared_broken' THEN nb_plaques ELSE 0 END) AS nb_broken
         FROM (
             SELECT site_id, statut_plaque, COUNT(*) AS nb_plaques
             FROM import_optoplate
             WHERE ((statut_plaque='in_use' AND DATE(date_installation)=?)
                    OR (statut_plaque!='in_use' AND date_import=?))
             GROUP BY site_id, statut_plaque
         ) sub GROUP BY site_id
     ) op ON op.site_id=s.id
     LEFT JOIN (
         SELECT site_id, SUM(total_plaques) AS total_plaques
         FROM op_points_journaliers WHERE date_point=?
         GROUP BY site_id
     ) pj ON pj.site_id=s.id
     WHERE s.actif=1
     " . ($f_site ? "AND s.id=$f_site" : "") . "
     ORDER BY s.nom",
    [$f_date, $f_date, $f_date]
);

// Historique sessions
$historique = db_fetch_all(
    "SELECT ise.*, CONCAT(u.prenom,' ',u.nom) AS importeur
     FROM import_sessions_emuci ise
     LEFT JOIN users u ON u.id=ise.importe_par
     ORDER BY ise.created_at DESC LIMIT 20"
);

include __DIR__ . '/../templates/header.php';
?>
<style>
.tab-nav{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:24px}
.tab-btn{padding:12px 28px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:600;
  border:none;background:none;cursor:pointer;color:var(--muted);border-bottom:3px solid transparent;
  margin-bottom:-2px;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;}
.tab-btn.active{color:var(--primary-d);border-bottom-color:var(--primary)}
.tab-btn:hover{color:var(--navy)}
.tab-pane{display:none}.tab-pane.active{display:block}

.upload-zone{border:2px dashed var(--border);border-radius:16px;padding:40px;text-align:center;
  background:var(--tertiary);transition: background-color .2s, border-color .2s, color .2s, box-shadow .2s, transform .2s, opacity .2s;cursor:pointer}
.upload-zone:hover,.upload-zone.drag{border-color:var(--primary);background:var(--primary-l)}
.upload-zone input[type=file]{display:none}
.upload-icon{font-size:48px;margin-bottom:12px}
.upload-label{font-size:15px;font-weight:600;color:var(--navy);margin-bottom:4px}
.upload-sub{font-size:12px;color:var(--muted)}
.upload-selected{margin-top:12px;font-size:13px;font-weight:600;color:var(--primary-d)}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:20px}
.stat-card{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 18px;border-left:4px solid var(--primary)}
.stat-card.green{border-left-color:var(--success)}
.stat-card.orange{border-left-color:#f39c12}
.stat-card.red{border-left-color:var(--danger)}
.stat-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:26px;font-weight:900;color:var(--navy);line-height:1}
.stat-lbl{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}

.ecart-pos{color:#e74c3c;font-weight:700}
.ecart-neg{color:#f39c12;font-weight:700}
.ecart-zero{color:var(--success-d);font-weight:700}

.statut-badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:12px;font-weight:700}
.s-in_use{background:#d1fae5;color:#065f46}
.s-reserved{background:#dbeafe;color:#1d4ed8}
.s-declared_broken{background:#fee2e2;color:#991b1b}
.s-ad-print{background:#fef3c7;color:#92400e}
.s-in_use_need_reprint,.s-inused_need_reprint{background:#f3e8ff;color:#6b21a8}
.s-lost{background:#f1f5f9;color:#475569}

.fsel{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none;cursor:pointer}

/* ── Loading overlay ── */
#loadingOverlay{
  display:none;position:fixed;inset:0;z-index:9999;
  background:rgba(6,3,58,.65);backdrop-filter:blur(4px);
  align-items:center;justify-content:center;flex-direction:column
}
.ld-box{
  background:white;border-radius:20px;padding:40px 56px;
  text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);
  min-width:340px
}
.ld-spinner{
  width:52px;height:52px;border-radius:50%;
  border:5px solid #E8ECFF;border-top-color:#1B75BC;
  animation:spin .8s linear infinite;margin:0 auto 20px
}
@keyframes spin{to{transform:rotate(360deg)}}
.ld-title{font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:800;color:#06033A;margin-bottom:6px}
.ld-sub{font-size:13px;color:#64748B}
.ld-file{font-size:11.5px;color:#1B75BC;font-weight:600;margin-top:10px;
  max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ld-warn{font-size:11.5px;color:#92400E;background:#FEF3C7;
  padding:6px 12px;border-radius:8px;margin-top:14px}

/* ── Résultat import ── */
.import-result-banner{
  border-radius:16px;padding:20px 24px;margin-bottom:24px;
  border-left:5px solid;animation:slideIn .3s ease
}
.import-result-banner.success{background:#D1FAE5;border-color:#34D399}
.import-result-banner.danger {background:#FEE2E2;border-color:#F87171}
.irb-title{font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;margin-bottom:10px}
.irb-stats{display:flex;flex-wrap:wrap;gap:16px;margin-top:8px}
.irb-stat{display:flex;flex-direction:column;align-items:center;
  background:rgba(255,255,255,.6);border-radius:10px;padding:8px 16px;min-width:90px}
.irb-stat-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:900;color:#06033A;line-height:1}
.irb-stat-lbl{font-size:10.5px;color:#64748B;font-weight:600;text-transform:uppercase;margin-top:3px}
@keyframes slideIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
</style>

<!-- ── LOADING OVERLAY ── -->
<div id="loadingOverlay">
  <div class="ld-box">
    <div class="ld-spinner"></div>
    <div class="ld-title" id="ldTitle">Import en cours…</div>
    <div class="ld-sub" id="ldSub">Lecture et traitement du fichier</div>
    <div class="ld-file" id="ldFile"></div>
    <div class="ld-warn">⏳ Ne fermez pas cette page</div>
  </div>
</div>

<!-- ── RÉSULTAT IMPORT (bannière proéminente) ── -->
<?php if($msg_optoplate || $msg_optotrace): ?>
<div id="import-result">
<?php if($msg_optoplate): ?>
<div class="import-result-banner <?= $msg_optoplate['type'] ?>">
  <div class="irb-title">
    <?= $msg_optoplate['type']==='success' ? '<i class="ph ph-check-circle" aria-hidden="true"></i> Import OptoPlate terminé' : '<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur import OptoPlate' ?>
  </div>
  <div style="font-size:13.5px"><?= h($msg_optoplate['text']) ?></div>
  <?php if(!empty($result_optoplate)): ?>
  <div class="irb-stats">
    <div class="irb-stat"><div class="irb-stat-val"><?= $result_optoplate['nb_ok'] ?></div><div class="irb-stat-lbl">Plaques importées</div></div>
    <?php if($result_optoplate['nb_err']>0): ?>
    <div class="irb-stat"><div class="irb-stat-val" style="color:#F87171"><?= $result_optoplate['nb_err'] ?></div><div class="irb-stat-lbl">Ignorées</div></div>
    <?php endif; ?>
    <?php if($result_optoplate['nb_valide_auto']>0): ?>
    <div class="irb-stat"><div class="irb-stat-val" style="color:#059669"><?= $result_optoplate['nb_valide_auto'] ?></div><div class="irb-stat-lbl">Sites validés auto</div></div>
    <?php endif; ?>
    <?php if(($result_optoplate['nb_incoherent']??0)>0): ?>
    <div class="irb-stat"><div class="irb-stat-val" style="color:#D97706"><?= $result_optoplate['nb_incoherent'] ?></div><div class="irb-stat-lbl">Sites incohérents</div></div>
    <?php endif; ?>
    <div class="irb-stat"><div class="irb-stat-val" style="font-size:15px"><?= fmt_date($result_optoplate['date'],'d/m/Y') ?></div><div class="irb-stat-lbl">Date import</div></div>
  </div>
  <?php if(!empty($result_optoplate['jours_pose'])): $jp = $result_optoplate['jours_pose']; ?>
  <div style="margin-top:12px;font-size:13px">
    <strong>Plaques posées du <?= fmt_date(array_key_first($jp),'d/m/Y') ?> au <?= fmt_date(array_key_last($jp),'d/m/Y') ?></strong>
    <?= count($jp) > 1 ? '(' . count($jp) . ' jours)' : '' ?> — afficher le jour :
    <?php foreach(array_slice(array_reverse($jp, true), 0, 10, true) as $j => $n): ?>
    <a href="?tab=optoplate&date=<?= h($j) ?>" style="display:inline-block;margin:3px 4px 0 0;padding:3px 10px;border-radius:999px;background:#fff;border:1px solid #34D399;color:#065F46;font-weight:600;text-decoration:none"><?= fmt_date($j,'d/m') ?> · <?= (int)$n ?></a>
    <?php endforeach; ?>
    <?php if(count($jp) > 10): ?><span style="color:#64748B">… autres jours : choisissez la date en haut de page.</span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php $pa = $result_optoplate['point_auto'] ?? null; if ($pa && ($pa['points'] || $pa['arretes'] || $pa['erreurs'])): ?>
  <div style="margin-top:12px;font-size:13px">
    <?php if ($pa['points']): ?><div><i class="ph ph-magic-wand" aria-hidden="true"></i> <strong>Points générés depuis OptoPlate :</strong> <?= (int)$pa['points'] ?> jour(s) mis à jour pour <?= h(implode(', ', $pa['sites'])) ?>.</div><?php endif; ?>
    <?php foreach ($pa['arretes'] as $x): ?><div style="color:#065F46"><i class="ph ph-check-circle" aria-hidden="true"></i> Génération automatique arrêtée : <?= h($x) ?>.</div><?php endforeach; ?>
    <?php foreach ($pa['erreurs'] as $x): ?><div style="color:#991B1B"><i class="ph ph-warning" aria-hidden="true"></i> <?= h($x) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if(!empty($result_optoplate['sites_inconnus'])): ?>
  <div style="margin-top:10px;font-size:12.5px;color:#92400E"><i class="ph ph-warning" aria-hidden="true"></i> Sites non reconnus : <strong><?= implode(', ', array_map('h',$result_optoplate['sites_inconnus'])) ?></strong></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if($msg_optotrace): ?>
<div class="import-result-banner <?= $msg_optotrace['type'] ?>">
  <div class="irb-title">
    <?= $msg_optotrace['type']==='success' ? '<i class="ph ph-check-circle" aria-hidden="true"></i> Import OptoTrace terminé' : '<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur import OptoTrace' ?>
  </div>
  <div style="font-size:13.5px"><?= h($msg_optotrace['text']) ?></div>
  <?php if(!empty($result_optotrace)): ?>
  <div class="irb-stats">
    <div class="irb-stat"><div class="irb-stat-val"><?= $result_optotrace['nb_ok'] ?></div><div class="irb-stat-lbl">Lignes importées</div></div>
    <?php if(!empty($result_optotrace['nb_stock_maj'])): ?>
    <div class="irb-stat"><div class="irb-stat-val" style="color:#1B75BC"><?= $result_optotrace['nb_stock_maj'] ?></div><div class="irb-stat-lbl">Bobines MAJ</div></div>
    <?php endif; ?>
    <?php if($result_optotrace['nb_err']>0): ?>
    <div class="irb-stat"><div class="irb-stat-val" style="color:#F87171"><?= $result_optotrace['nb_err'] ?></div><div class="irb-stat-lbl">Ignorées</div></div>
    <?php endif; ?>
    <?php if($result_optotrace['nb_valide_auto']>0): ?>
    <div class="irb-stat"><div class="irb-stat-val" style="color:#059669"><?= $result_optotrace['nb_valide_auto'] ?></div><div class="irb-stat-lbl">Sites validés auto</div></div>
    <?php endif; ?>
    <?php if($result_optotrace['nb_bloque']>0): ?>
    <div class="irb-stat"><div class="irb-stat-val" style="color:#D97706"><?= $result_optotrace['nb_bloque'] ?></div><div class="irb-stat-lbl">Sites en écart (GSB alerté)</div></div>
    <?php endif; ?>
    <div class="irb-stat"><div class="irb-stat-val" style="font-size:15px"><?= fmt_date($result_optotrace['date'],'d/m/Y') ?></div><div class="irb-stat-lbl">Date import</div></div>
  </div>
  <?php if(!empty($result_optotrace['sites_inconnus'])): ?>
  <div style="margin-top:10px;font-size:12.5px;color:#92400E"><i class="ph ph-warning" aria-hidden="true"></i> Sites non reconnus : <strong><?= implode(', ', array_map('h',$result_optotrace['sites_inconnus'])) ?></strong></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
</div><!-- #import-result -->
<?php endif; ?>

<!-- TABS -->
<div class="tab-nav">
  <button class="tab-btn <?= $onglet==='optoplate'?'active':'' ?>" onclick="showTab('optoplate')">
    <i class="ph ph-clipboard-text" aria-hidden="true"></i> OptoPlate — Plaques posées
  </button>
  <button class="tab-btn <?= $onglet==='optotrace'?'active':'' ?>" onclick="showTab('optotrace')">
    <i class="ph ph-film-strip" aria-hidden="true"></i> BI-Stock bobine
  </button>
  <button class="tab-btn <?= $onglet==='comparaison'?'active':'' ?>" onclick="showTab('comparaison')">
    <i class="ph ph-scales" aria-hidden="true"></i> Comparaison EMUCI vs ERP EMUCI
  </button>
  <button class="tab-btn <?= $onglet==='historique'?'active':'' ?>" onclick="showTab('historique')">
    <i class="ph ph-calendar" aria-hidden="true"></i> Historique imports
  </button>
</div>

<!-- FILTRE DATE + SITE -->
<form method="GET" style="display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
  <input type="hidden" name="tab" id="tabInput" value="<?= h($onglet) ?>">
  <label for="inp-date-emuci" style="font-size:13px;font-weight:600;color:var(--navy)">Date :</label>
  <input type="date" id="inp-date-emuci" name="date" value="<?= h($f_date) ?>" onchange="this.form.submit()"
         style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none">
  <label style="font-size:13px;font-weight:600;color:var(--navy)">Site :</label>
  <select name="site" class="fsel" aria-label="Filtrer par site" onchange="this.form.submit()">
    <option value="0">Tous les sites</option>
    <?php foreach($sites_list as $s): ?>
    <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<!-- ═══ ONGLET OPTOPLATE ═══ -->
<div id="tab-optoplate" class="tab-pane <?= $onglet==='optoplate'?'active':'' ?>">

  <!-- Import -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3><i class="ph ph-upload-simple" aria-hidden="true"></i> Importer fichier OptoPlate (CSV)</h3>
      <span style="font-size:12px;color:var(--muted)">Format : export CSV/XLSX depuis OptoPlate · poses et désactivations dans le même fichier</span>
    </div>
    <div class="card-body">
      <?php if($msg_optoplate): ?>
      <div class="alert alert-<?= $msg_optoplate['type'] ?>" style="margin-bottom:16px"><?= h($msg_optoplate['text']) ?></div>
      <?php endif; ?>
      <?php if(!empty($result_optoplate['sites_inconnus'])): ?>
      <div class="alert alert-warning"><i class="ph ph-warning" aria-hidden="true"></i> Sites non reconnus dans ERP EMUCI : <strong><?= implode(', ', array_map('h',$result_optoplate['sites_inconnus'])) ?></strong></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import_optoplate">
        <div style="display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:start">
          <div>
            <label for="inp-date-fichier" style="font-size:13px;font-weight:600;color:var(--navy);display:block;margin-bottom:6px">Date du fichier</label>
            <input type="date" id="inp-date-fichier" name="date_import" value="<?= date('Y-m-d') ?>"
                   style="padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;outline:none">
          </div>
          <div>
            <label style="font-size:13px;font-weight:600;color:var(--navy);display:block;margin-bottom:6px">Fichier OptoPlate</label>
            <div class="upload-zone" onclick="document.getElementById('fileOpto').click()"
                 ondragover="this.classList.add('drag');event.preventDefault()"
                 ondragleave="this.classList.remove('drag')"
                 ondrop="this.classList.remove('drag');handleDrop(event,'fileOpto','selectedOpto')">
              <div class="upload-icon"><i class="ph ph-clipboard-text" aria-hidden="true"></i></div>
              <div class="upload-label">Glissez le fichier ici ou cliquez</div>
              <div class="upload-sub">export_plates_from_YYYY-MM-DD_until_YYYY-MM-DD.csv (ou .xlsx) — poses + désactivations</div>
              <div class="upload-selected" id="selectedOpto"></div>
              <input type="file" id="fileOpto" name="fichier_optoplate" accept=".csv,.xlsx,.xls"
                     onchange="document.getElementById('selectedOpto').textContent=this.files[0]?.name||''">
            </div>
          </div>
        </div>
        <div style="margin-top:16px;display:flex;justify-content:flex-end">
          <button type="submit" class="btn btn-primary"><i class="ph ph-upload-simple" aria-hidden="true"></i> Importer OptoPlate</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Alimenter le dashboard : production d'avant l'ERP -->
  <?php if(can('import_emuci', 'can_create')): ?>
  <div class="card" style="margin-bottom:20px" id="alimenter-dashboard">
    <div class="card-header">
      <h3><i class="ph ph-chart-line-up" aria-hidden="true"></i> Alimenter le dashboard avec la production d'avant l'ERP</h3>
    </div>
    <div class="card-body">
      <?php if($hist_prereq): ?>
      <div class="alert alert-warning"><?= h($hist_prereq) ?></div>
      <?php elseif(!$hist_periode['du']): ?>
      <p style="margin:0;color:var(--muted);font-size:13px">Aucune plaque OptoPlate importée : importez d'abord un export ci-dessus.</p>
      <?php else:
        $hv_du = $rapport_historique['du'] ?? ($_POST['hist_du'] ?? $hist_periode['du']);
        $hv_au = $rapport_historique['au'] ?? ($_POST['hist_au'] ?? $hist_periode['au']);
        $hv_site = (int)($_POST['hist_site'] ?? 0);
        $hv_refaire = !empty($_POST['hist_refaire']);
        $champ = 'padding:9px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;outline:none';
      ?>
      <p style="margin:0 0 10px;font-size:13px;color:var(--muted)">
        Le dashboard ne lit que les points journaliers de l'ERP. Cette étape crée un point « historique » par site et par jour
        à partir des plaques OptoPlate déjà importées. <strong>Aucun stock n'est modifié</strong>, et un jour déjà saisi dans l'ERP
        n'est jamais touché. Vous voyez d'abord une simulation : rien n'est écrit sans votre clic sur « Alimenter ».
      </p>
      <p style="margin:0 0 14px;font-size:12.5px;color:var(--muted)">
        Plaques importées depuis le <?= fmt_date($hist_periode['du'], 'd/m/Y') ?>.
        <?php if($hist_periode['coupe_a_la_mise_en_service']): ?>
        Premier point saisi dans l'ERP : <?= fmt_date($hist_periode['premier_reel'], 'd/m/Y') ?> (période proposée : jusqu'à la veille).
        <?php elseif($hist_periode['premier_reel']): ?>
        Le premier point saisi dans l'ERP (<?= fmt_date($hist_periode['premier_reel'], 'd/m/Y') ?>) précède les plaques importées : période proposée jusqu'à hier, les jours déjà saisis restent intacts.
        <?php else: ?>Aucun point saisi dans l'ERP pour le moment (période proposée : jusqu'à hier).<?php endif; ?>
      </p>

      <?php if($msg_historique): ?>
      <div class="alert alert-<?= $msg_historique['type'] ?>" style="margin-bottom:14px"><?= h($msg_historique['text']) ?></div>
      <?php endif; ?>

      <form method="POST" action="#alimenter-dashboard" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"
            onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='Calcul en cours…'">
        <input type="hidden" name="action" value="historique_optoplate">
        <input type="hidden" name="mode" value="simuler">
        <div>
          <label for="hist-du" style="font-size:12.5px;font-weight:600;color:var(--navy);display:block;margin-bottom:5px">Du</label>
          <input type="date" id="hist-du" name="hist_du" value="<?= h($hv_du) ?>" max="<?= date('Y-m-d', strtotime('-1 day')) ?>" style="<?= $champ ?>">
        </div>
        <div>
          <label for="hist-au" style="font-size:12.5px;font-weight:600;color:var(--navy);display:block;margin-bottom:5px">Au</label>
          <input type="date" id="hist-au" name="hist_au" value="<?= h($hv_au) ?>" max="<?= date('Y-m-d', strtotime('-1 day')) ?>" style="<?= $champ ?>">
        </div>
        <div>
          <label for="hist-site" style="font-size:12.5px;font-weight:600;color:var(--navy);display:block;margin-bottom:5px">Site</label>
          <select id="hist-site" name="hist_site" style="<?= $champ ?>">
            <option value="0">Tous les sites</option>
            <?php foreach($sites_list as $_s): ?>
            <option value="<?= (int)$_s['id'] ?>"<?= $hv_site === (int)$_s['id'] ? ' selected' : '' ?>><?= h($_s['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label style="font-size:12.5px;color:var(--navy);display:flex;align-items:center;gap:6px;padding-bottom:10px">
          <input type="checkbox" name="hist_refaire" value="1"<?= $hv_refaire ? ' checked' : '' ?>> Recalculer les jours déjà reconstitués
        </label>
        <button type="submit" class="btn btn-secondary"><i class="ph ph-magnifying-glass" aria-hidden="true"></i> Simuler</button>
      </form>

      <?php if($rapport_historique): $rh = $rapport_historique; $st = $rh['stats']; ?>
      <div style="margin-top:18px;border-top:1px solid var(--border);padding-top:16px">
        <?php if(!$st['points']): ?>
        <div class="alert alert-warning">
          Rien à <?= $rh['appliquer'] ? 'écrire' : 'reconstituer' ?> du <?= fmt_date($rh['du'], 'd/m/Y') ?> au <?= fmt_date($rh['au'], 'd/m/Y') ?> :
          <?= (int)$st['ignores_reels'] ?> jour(s) ont déjà un point saisi dans l'ERP, <?= (int)$st['ignores_hist'] ?> sont déjà reconstitué(s)
          <?= $st['ignores_hist'] ? '(cochez « Recalculer » pour les refaire)' : '' ?>.
          <?php if($rh['sans_site']): ?> <?= (int)$rh['sans_site'] ?> plaque(s) appartiennent à un site inconnu.<?php endif; ?>
        </div>
        <?php else: ?>
        <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:700;color:var(--navy);margin-bottom:10px">
          <?php if($rh['appliquer']): ?>
          <i class="ph ph-check-circle" aria-hidden="true" style="color:#059669"></i> Dashboard alimenté
          <?php else: ?>
          <i class="ph ph-eye" aria-hidden="true"></i> Simulation : rien n'est encore écrit
          <?php endif; ?>
          — du <?= fmt_date($rh['du'], 'd/m/Y') ?> au <?= fmt_date($rh['au'], 'd/m/Y') ?>
        </div>
        <div class="stat-grid" style="margin-bottom:14px">
          <div class="stat-card green"><div class="stat-val"><?= fmt_number($st['points']) ?></div><div class="stat-lbl">Points <?= $rh['appliquer'] ? 'créés' : 'à créer' ?> (site × jour)</div></div>
          <div class="stat-card"><div class="stat-val"><?= fmt_number($st['engins']) ?></div><div class="stat-lbl">Engins</div></div>
          <div class="stat-card"><div class="stat-val"><?= fmt_number($st['plaques']) ?></div><div class="stat-lbl">Plaques</div></div>
        </div>
        <div class="table-wrap" style="margin-bottom:12px">
          <table>
            <thead><tr><th>Mois</th><th style="text-align:center">Points</th><th style="text-align:center">Engins</th><th style="text-align:center">Plaques</th></tr></thead>
            <tbody>
            <?php foreach($rh['par_mois'] as $m => $v): ?>
            <tr>
              <td style="font-weight:600"><?= h(fmt_date($m . '-01', 'm/Y')) ?></td>
              <td style="text-align:center"><?= (int)$v['points'] ?></td>
              <td style="text-align:center;font-weight:700"><?= fmt_number($v['engins']) ?></td>
              <td style="text-align:center"><?= fmt_number($v['plaques']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <details style="margin-bottom:12px">
          <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--navy)">Détail par site (<?= count($rh['par_site']) ?>)</summary>
          <div class="table-wrap" style="margin-top:8px">
            <table>
              <thead><tr><th>Site</th><th style="text-align:center">Jours</th><th style="text-align:center">Engins</th><th style="text-align:center">dont duplicata / post-immat</th></tr></thead>
              <tbody>
              <?php foreach($rh['par_site'] as $v): ?>
              <tr>
                <td style="font-weight:600"><?= h($v['nom']) ?></td>
                <td style="text-align:center"><?= (int)$v['points'] ?></td>
                <td style="text-align:center"><?= fmt_number($v['engins']) ?></td>
                <td style="text-align:center"><?= (int)$v['dup_pi'] ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>

        <?php if($st['ignores_reels']): ?><p style="margin:4px 0;font-size:12.5px;color:var(--muted)"><i class="ph ph-info" aria-hidden="true"></i> <?= (int)$st['ignores_reels'] ?> jour(s) ignoré(s) : déjà saisis dans l'ERP.</p><?php endif; ?>
        <?php if($st['ignores_hist']): ?><p style="margin:4px 0;font-size:12.5px;color:var(--muted)"><i class="ph ph-info" aria-hidden="true"></i> <?= (int)$st['ignores_hist'] ?> jour(s) ignoré(s) : déjà reconstitués (cochez « Recalculer » pour les refaire).</p><?php endif; ?>
        <?php if($rh['appliquer'] && $st['refaits']): ?><p style="margin:4px 0;font-size:12.5px;color:var(--muted)"><i class="ph ph-info" aria-hidden="true"></i> <?= (int)$st['refaits'] ?> jour(s) reconstitué(s) puis refait(s).</p><?php endif; ?>
        <?php if($rh['sans_site']): ?><p style="margin:4px 0;font-size:12.5px;color:#92400E"><i class="ph ph-warning" aria-hidden="true"></i> <?= (int)$rh['sans_site'] ?> plaque(s) d'un site inconnu ne sont dans aucun point : créez le site dans l'écran Sites, puis réimportez le fichier.</p><?php endif; ?>
        <?php if($st['vehicules_autre']): ?><p style="margin:4px 0;font-size:12.5px;color:#92400E"><i class="ph ph-warning" aria-hidden="true"></i> <?= (int)$st['vehicules_autre'] ?> véhicule(s) de catégorie inconnue comptés en VP.</p><?php endif; ?>
        <?php if($st['plaques_sans_bobine']): ?>
        <p style="margin:4px 0;font-size:12.5px;color:#92400E"><i class="ph ph-warning" aria-hidden="true"></i>
          Films : <?= (int)$st['plaques_sans_bobine'] ?> plaque(s) sur <?= (int)$st['plaques_total'] ?> sont sur des bobines absentes de l'ERP ;
          les films par bobine du dashboard seront en dessous des engins.
          <?php if($rh['bobines_inconnues']): ?>Bobines les plus concernées : <?= h(implode(', ', array_map(fn($n, $v) => "$n ($v)", array_keys($rh['bobines_inconnues']), $rh['bobines_inconnues']))) ?>.<?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if(!$rh['appliquer']): ?>
        <form method="POST" action="#alimenter-dashboard" style="margin-top:14px"
              onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='Écriture en cours…'">
          <input type="hidden" name="action" value="historique_optoplate">
          <input type="hidden" name="mode" value="appliquer">
          <input type="hidden" name="hist_du" value="<?= h($rh['du']) ?>">
          <input type="hidden" name="hist_au" value="<?= h($rh['au']) ?>">
          <input type="hidden" name="hist_site" value="<?= (int)$rh['site_seul'] ?>">
          <?php if($rh['refaire']): ?><input type="hidden" name="hist_refaire" value="1"><?php endif; ?>
          <button type="submit" class="btn btn-primary"><i class="ph ph-chart-line-up" aria-hidden="true"></i> Alimenter le dashboard (<?= fmt_number($st['points']) ?> points)</button>
        </form>
        <?php else: $dernier_mois = array_key_last($rh['par_mois']); ?>
        <p style="margin:8px 0 0;font-size:13px">
          Aucun stock n'a été modifié.
          <a href="pdg_overview.php?periode=mensuel&amp;mois=<?= h($dernier_mois) ?>" style="color:var(--blue);font-weight:600">Voir la vue PDG de <?= h(fmt_date($dernier_mois . '-01', 'm/Y')) ?></a>
        </p>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Résultats du jour -->
  <?php if(!empty($stats_optoplate)): ?>
  <?php
  $totaux = ['in_use'=>0,'reserved'=>0,'declared_broken'=>0,'ad-print'=>0,'in_use_need_reprint'=>0,'lost'=>0];
  $total_vehicules = 0; $total_bobines = 0;
  foreach($stats_optoplate as $st) {
    $totaux[$st['statut_plaque']] = ($totaux[$st['statut_plaque']]??0) + $st['nb_plaques'];
    $total_vehicules += $st['nb_vehicules'];
    $total_bobines += $st['nb_bobines'];
  }
  ?>
  <div style="margin-bottom:16px">
    <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:700;color:var(--navy);margin-bottom:12px">
      <i class="ph ph-chart-bar" aria-hidden="true"></i> État des plaques au <?= fmt_date($f_date,'d/m/Y') ?>
    </div>
    <div class="stat-grid">
      <div class="stat-card green"><div class="stat-val"><?= fmt_number($totaux['in_use']??0) ?></div><div class="stat-lbl"><i class="ph ph-check-circle" aria-hidden="true"></i> In Use (posées)</div></div>
      <div class="stat-card"><div class="stat-val"><?= fmt_number($totaux['reserved']??0) ?></div><div class="stat-lbl"><i class="ph ph-push-pin" aria-hidden="true"></i> Reserved <span style="font-weight:400">· imprimées, en attente de pose</span></div></div>
      <div class="stat-card red"><div class="stat-val"><?= fmt_number($totaux['declared_broken']??0) ?></div><div class="stat-lbl"><i class="ph ph-x-circle" aria-hidden="true"></i> Declared Broken</div></div>
      <div class="stat-card orange"><div class="stat-val"><?= fmt_number($totaux['ad-print']??0) ?></div><div class="stat-lbl"><i class="ph ph-printer" aria-hidden="true"></i> Ad-Print</div></div>
      <div class="stat-card orange"><div class="stat-val"><?= fmt_number($totaux['in_use_need_reprint']??0) ?></div><div class="stat-lbl"><i class="ph ph-warning" aria-hidden="true"></i> Need Reprint</div></div>
      <div class="stat-card"><div class="stat-val"><?= fmt_number($totaux['lost']??0) ?></div><div class="stat-lbl"><i class="ph ph-magnifying-glass" aria-hidden="true"></i> Lost</div></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Détail par site et statut</h3>
      <a href="?tab=optoplate&date=<?= $f_date ?>&site=<?= $f_site ?>&export=optoplate"
         class="btn btn-secondary btn-sm"><i class="ph ph-download-simple" aria-hidden="true"></i> Export Excel</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Site</th>
          <th>Statut</th>
          <th style="text-align:center">Nb plaques</th>
          <th style="text-align:center">Véhicules</th>
          <th style="text-align:center">Bobines concernées</th>
        </tr></thead>
        <tbody>
        <?php foreach($stats_optoplate as $st): ?>
        <tr>
          <td style="font-weight:600"><?= h($st['site_nom_emuci']) ?></td>
          <td><span class="statut-badge s-<?= h($st['statut_plaque']) ?>"><?= h($st['statut_plaque']) ?></span></td>
          <td style="text-align:center;font-weight:700"><?= fmt_number($st['nb_plaques']) ?></td>
          <td style="text-align:center"><?= $st['nb_vehicules'] ?></td>
          <td style="text-align:center"><?= $st['nb_bobines'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="card"><div class="card-body" style="text-align:center;color:var(--muted);padding:40px">
    Aucune donnée OptoPlate pour le <?= fmt_date($f_date,'d/m/Y') ?>. Importez un fichier.
  </div></div>
  <?php endif; ?>
</div>

<!-- ═══ ONGLET OPTOTRACE ═══ -->
<div id="tab-optotrace" class="tab-pane <?= $onglet==='optotrace'?'active':'' ?>">

  <!-- Import -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3><i class="ph ph-upload-simple" aria-hidden="true"></i> Importer fichier OptoTrace (XLSX)</h3>
      <span style="font-size:12px;color:var(--muted)">Format : export Excel depuis OptoTrace</span>
    </div>
    <div class="card-body">
      <?php if($msg_optotrace): ?>
      <div class="alert alert-<?= $msg_optotrace['type'] ?>"><?= h($msg_optotrace['text']) ?></div>
      <?php endif; ?>
      <?php if(!empty($result_optotrace['sites_inconnus'])): ?>
      <div class="alert alert-warning"><i class="ph ph-warning" aria-hidden="true"></i> Sites non reconnus : <strong><?= implode(', ', array_map('h',$result_optotrace['sites_inconnus'])) ?></strong></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import_optotrace">
        <div style="display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:start">
          <div>
            <label style="font-size:13px;font-weight:600;color:var(--navy);display:block;margin-bottom:6px">Date du fichier</label>
            <input type="date" name="date_import_trace" value="<?= date('Y-m-d') ?>"
                   style="padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;outline:none">
          </div>
          <div>
            <label style="font-size:13px;font-weight:600;color:var(--navy);display:block;margin-bottom:6px">Fichier XLSX OptoTrace</label>
            <div class="upload-zone" onclick="document.getElementById('fileTrace').click()"
                 ondragover="this.classList.add('drag');event.preventDefault()"
                 ondragleave="this.classList.remove('drag')"
                 ondrop="this.classList.remove('drag');handleDrop(event,'fileTrace','selectedTrace')">
              <div class="upload-icon"><i class="ph ph-film-strip" aria-hidden="true"></i></div>
              <div class="upload-label">Glissez le fichier XLSX ici ou cliquez</div>
              <div class="upload-sub">production-plaques-YYYY-MM-DD.xlsx</div>
              <div class="upload-selected" id="selectedTrace"></div>
              <input type="file" id="fileTrace" name="fichier_optotrace" accept=".xlsx,.xls"
                     onchange="document.getElementById('selectedTrace').textContent=this.files[0]?.name||''">
            </div>
          </div>
        </div>
        <div style="margin-top:16px;display:flex;justify-content:flex-end">
          <button type="submit" class="btn btn-primary"><i class="ph ph-upload-simple" aria-hidden="true"></i> Importer OptoTrace</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Résultats OptoTrace -->
  <?php if(!empty($stats_optotrace)): ?>
  <?php
  $total_lignes     = array_sum(array_column($stats_optotrace, 'nb_lignes'));
  $total_bobines    = array_sum(array_column($stats_optotrace, 'nb_bobines'));
  $total_retirees   = array_sum(array_column($stats_optotrace, 'nb_retirees'));
  $total_epuisees   = array_sum(array_column($stats_optotrace, 'nb_epuisees'));
  $total_films      = array_sum(array_column($stats_optotrace, 'total_films_restants'));
  ?>
  <div class="stat-grid" style="margin-bottom:16px">
    <div class="stat-card green"><div class="stat-val"><?= fmt_number($total_lignes) ?></div><div class="stat-lbl">Lignes importées</div></div>
    <div class="stat-card"><div class="stat-val"><?= fmt_number($total_bobines) ?></div><div class="stat-lbl">Bobines uniques</div></div>
    <div class="stat-card green"><div class="stat-val"><?= fmt_number($total_films) ?></div><div class="stat-lbl">Films restants (total)</div></div>
    <div class="stat-card red"><div class="stat-val"><?= fmt_number($total_retirees + $total_epuisees) ?></div><div class="stat-lbl">Retirées / Épuisées</div></div>
  </div>

  <!-- Résumé par site -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Résumé par site</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Site EMUCI</th>
          <th style="text-align:center">Bobines</th>
          <th style="text-align:center">Films restants</th>
          <th style="text-align:center">Retirées</th>
          <th style="text-align:center">Épuisées</th>
        </tr></thead>
        <tbody>
        <?php foreach($stats_optotrace as $st): ?>
        <tr>
          <td style="font-weight:600"><?= h($st['site_nom_emuci'] ?: '— non mappé —') ?></td>
          <td style="text-align:center;font-weight:700"><?= $st['nb_bobines'] ?></td>
          <td style="text-align:center;color:var(--success-d);font-weight:700"><?= fmt_number($st['total_films_restants']) ?></td>
          <td style="text-align:center;color:var(--muted)"><?= $st['nb_retirees'] ?></td>
          <td style="text-align:center;color:var(--danger-d)"><?= $st['nb_epuisees'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Détail bobines avec comparaison ERP EMUCI -->
  <?php if(!empty($detail_bobines_optotrace)): ?>
  <div class="card">
    <div class="card-header">
      <h3><i class="ph ph-magnifying-glass" aria-hidden="true"></i> Détail bobines — OptoTrace vs ERP EMUCI</h3>
      <span style="font-size:12px;color:var(--muted)">keyname = N° bobine</span>
    </div>
    <div class="table-wrap">
      <table data-paginer="25">
        <thead><tr>
          <th>N° Bobine (keyname)</th>
          <th>Format</th>
          <th>Site</th>
          <th style="text-align:center">Films OptoTrace</th>
          <th style="text-align:center">Films ERP EMUCI</th>
          <th style="text-align:center">Écart</th>
          <th style="text-align:center">État</th>
        </tr></thead>
        <tbody>
        <?php foreach($detail_bobines_optotrace as $b):
          $ecart = (int)$b['ecart'];
          $ecart_cls = $ecart === 0 ? 'ecart-zero' : ($ecart > 0 ? 'ecart-pos' : 'ecart-neg');
        ?>
        <tr>
          <td style="font-family:monospace;font-weight:700;color:var(--navy)"><?= h($b['keyname']) ?></td>
          <td style="font-size:12px"><?= h($b['format']) ?></td>
          <td style="font-size:12px"><?= h($b['site_nom_emuci']) ?></td>
          <td style="text-align:center;font-weight:700;color:var(--success-d)"><?= fmt_number($b['qty_optotrace']) ?></td>
          <td style="text-align:center;font-weight:700"><?= $b['bobine_id'] ? fmt_number($b['qty_digistock']) : '<span style="color:var(--muted)">—</span>' ?></td>
          <td style="text-align:center" class="<?= $ecart_cls ?>">
            <?= $b['bobine_id'] ? ($ecart > 0 ? '+' : '') . $ecart : '<span style="color:var(--warning-d)">Inconnue</span>' ?>
          </td>
          <td style="text-align:center;font-size:12px">
            <?php
            if ($b['state'] == 7) echo '<span class="statut-badge" style="background:#f1f5f9;color:#475569">Retirée</span>';
            elseif ($b['qty_optotrace'] == 0) echo '<span class="statut-badge" style="background:#fee2e2;color:#991b1b">Épuisée</span>';
            else echo '<span class="statut-badge s-in_use">Active</span>';
            ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <div class="card"><div class="card-body" style="text-align:center;color:var(--muted);padding:40px">
    <i class="ph-duotone ph-film-strip" style="font-size:40px;color:var(--border)"></i>
    <p style="margin-top:12px">Aucune donnée OptoTrace pour le <?= fmt_date($f_date,'d/m/Y') ?>. Importez un fichier.</p>
  </div></div>
  <?php endif; ?>
</div>

<!-- ═══ ONGLET COMPARAISON ═══ -->
<div id="tab-comparaison" class="tab-pane <?= $onglet==='comparaison'?'active':'' ?>">
  <div class="card">
    <div class="card-header">
      <h3><i class="ph ph-scales" aria-hidden="true"></i> Comparaison EMUCI (OptoPlate) vs ERP EMUCI (PJ coordinateurs) — <?= fmt_date($f_date,'d/m/Y') ?></h3>
      <a href="?tab=comparaison&date=<?= $f_date ?>&site=<?= $f_site ?>&export=comparaison"
         class="btn btn-secondary btn-sm"><i class="ph ph-download-simple" aria-hidden="true"></i> Export Excel</a>
    </div>
    <div class="table-wrap">
      <table data-paginer="25">
        <thead><tr>
          <th>Site</th>
          <th style="text-align:center">In Use EMUCI</th>
          <th style="text-align:center">Reserved EMUCI</th>
          <th style="text-align:center">Broken EMUCI</th>
          <th style="text-align:center">PJ ERP EMUCI</th>
          <th style="text-align:center">Écart</th>
          <th style="text-align:center">Statut</th>
        </tr></thead>
        <tbody>
        <?php if(empty($comparaison)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">Aucune donnée pour cette date.</td></tr>
        <?php else: foreach($comparaison as $c):
          $ecart = (int)$c['ecart'];
          $ecart_cls = $ecart===0?'ecart-zero':($ecart>0?'ecart-pos':'ecart-neg');
          $statut_icon = $ecart===0?'<i class="ph ph-check-circle" aria-hidden="true"></i> OK':($ecart>0?'<i class="ph ph-warning" aria-hidden="true"></i> EMUCI > ERP EMUCI':'<i class="ph ph-warning" aria-hidden="true"></i> ERP EMUCI > EMUCI');
        ?>
        <tr>
          <td style="font-weight:600;color:var(--navy)"><?= h($c['site_nom']) ?></td>
          <td style="text-align:center;font-weight:700;color:var(--success-d)"><?= fmt_number($c['plaques_in_use_emuci']) ?></td>
          <td style="text-align:center;color:var(--blue)"><?= fmt_number($c['plaques_reserved_emuci']) ?></td>
          <td style="text-align:center;color:var(--danger-d)"><?= fmt_number($c['plaques_broken_emuci']) ?></td>
          <td style="text-align:center;font-weight:700"><?= fmt_number($c['plaques_digi']) ?></td>
          <td style="text-align:center" class="<?= $ecart_cls ?>"><?= $ecart>0?'+':'' ?><?= $ecart ?></td>
          <td style="text-align:center;font-size:12px;font-weight:600"><?= $statut_icon ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:14px 20px;background:var(--tertiary);font-size:12px;color:var(--muted)">
      <strong>Légende :</strong>
      Écart = (Plaques In Use EMUCI) − (Plaques PJ ERP EMUCI).
      Un écart positif signifie qu'EMUCI a plus de plaques posées que le déclaratif coordinateur.
    </div>
  </div>
</div>

<!-- ═══ ONGLET HISTORIQUE ═══ -->
<div id="tab-historique" class="tab-pane <?= $onglet==='historique'?'active':'' ?>">
  <div class="card">
    <div class="card-header"><h3><i class="ph ph-calendar" aria-hidden="true"></i> Historique des imports</h3></div>
    <div class="table-wrap">
      <table data-paginer="25">
        <thead><tr>
          <th>Date</th>
          <th>Type</th>
          <th style="text-align:center">Lignes OptoPlate</th>
          <th style="text-align:center">Lignes OptoTrace</th>
          <th style="text-align:center">Erreurs</th>
          <th>Statut</th>
          <th>Importé par</th>
          <th>Date import</th>
        </tr></thead>
        <tbody>
        <?php if(empty($historique)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Aucun import enregistré.</td></tr>
        <?php else: foreach($historique as $h): ?>
        <tr>
          <td style="font-weight:700"><?= fmt_date($h['date_import'],'d/m/Y') ?></td>
          <td><span style="padding:2px 9px;border-radius:12px;font-size:12px;font-weight:700;background:var(--primary-l);color:var(--primary-d)"><?= h($h['type_import']) ?></span></td>
          <td style="text-align:center"><?= $h['nb_lignes_optoplate']>0?fmt_number($h['nb_lignes_optoplate']):'—' ?></td>
          <td style="text-align:center"><?= $h['nb_lignes_optotrace']>0?fmt_number($h['nb_lignes_optotrace']):'—' ?></td>
          <td style="text-align:center;color:<?= $h['nb_erreurs']>0?'var(--danger-d)':'var(--muted)' ?>"><?= $h['nb_erreurs'] ?></td>
          <td><span style="padding:2px 9px;border-radius:12px;font-size:12px;font-weight:700;background:<?= $h['statut']==='termine'?'#d1fae5':'#fee2e2' ?>;color:<?= $h['statut']==='termine'?'#065f46':'#991b1b' ?>"><?= $h['statut']==='termine'?'<i class="ph ph-check-circle" aria-hidden="true"></i> Terminé':'<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur' ?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?= h($h['importeur']??'—') ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= fmt_datetime($h['created_at']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// ── Onglets
function showTab(name) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-'+name).classList.add('active');
  document.querySelectorAll('.tab-btn').forEach(b => {
    if(b.getAttribute('onclick').includes("'"+name+"'")) b.classList.add('active');
  });
  document.getElementById('tabInput').value = name;
}

// ── Drag & drop
function handleDrop(e, inputId, labelId) {
  e.preventDefault();
  const file = e.dataTransfer.files[0];
  if(!file) return;
  const input = document.getElementById(inputId);
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  document.getElementById(labelId).textContent = file.name;
}

// ── Loading overlay : affiché dès la soumission d'un formulaire d'import
document.querySelectorAll('form[method="POST"]').forEach(form => {
  form.addEventListener('submit', function(e) {
    // Vérifier qu'un fichier est sélectionné
    const fileInput = form.querySelector('input[type="file"]');
    if (fileInput && !fileInput.files.length) return; // laisser la validation HTML se déclencher

    const action  = form.querySelector('[name="action"]')?.value || '';
    const overlay = document.getElementById('loadingOverlay');
    const title   = document.getElementById('ldTitle');
    const sub     = document.getElementById('ldSub');
    const fileEl  = document.getElementById('ldFile');

    if (action === 'import_optoplate') {
      title.textContent = 'Import OptoPlate en cours…';
      sub.textContent   = 'Lecture CSV et insertion des plaques';
    } else if (action === 'import_optotrace') {
      title.textContent = 'Import OptoTrace en cours…';
      sub.textContent   = 'Lecture XLSX et mise à jour du stock bobines';
    }

    if (fileInput && fileInput.files.length) {
      const f = fileInput.files[0];
      const kb = (f.size / 1024).toFixed(0);
      fileEl.textContent = `📄 ${f.name}  (${kb} Ko)`;
    }

    overlay.style.display = 'flex';
  });
});

// ── Scroll automatique vers le résultat si présent
window.addEventListener('DOMContentLoaded', () => {
  const result = document.getElementById('import-result');
  if (result) {
    setTimeout(() => result.scrollIntoView({ behavior: 'smooth', block: 'start' }), 200);
  }
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
