<?php
// ============================================================
//  includes/upload.php  —  Gestion uploads BL et fiches signées
// ============================================================

define('UPLOAD_DIR',       __DIR__ . '/../uploads/');
define('UPLOAD_BL_DIR',    UPLOAD_DIR . 'bl/');
define('UPLOAD_FICHE_DIR', UPLOAD_DIR . 'fiches/');
define('UPLOAD_FEB_DIR',   UPLOAD_DIR . 'feb/');
define('UPLOAD_VALIDATION_DIR', UPLOAD_DIR . 'validation/');
define('UPLOAD_FOURNISSEUR_DIR', UPLOAD_DIR . 'fournisseurs/');
define('UPLOAD_MAX_SIZE',  10 * 1024 * 1024);  // 10 Mo
define('UPLOAD_URL',       APP_URL . '/uploads/');

/**
 * Types MIME autorisés pour les BL / fiches signées
 */
const UPLOAD_ALLOWED_TYPES = [
    'application/pdf'  => 'pdf',
    'image/jpeg'       => 'jpg',
    'image/jpg'        => 'jpg',
    'image/png'        => 'png',
    'image/webp'       => 'webp',
];

/**
 * S'assure que les dossiers d'upload existent.
 */
function upload_ensure_dirs(): void {
    foreach ([UPLOAD_BL_DIR, UPLOAD_FICHE_DIR, UPLOAD_FEB_DIR, UPLOAD_VALIDATION_DIR, UPLOAD_FOURNISSEUR_DIR] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}

/**
 * ── Archivage des fichiers déposés dans la base (table fichiers_archives) ──
 *
 * uploads/ n'est pas un stockage durable : Render l'efface à chaque
 * déploiement, deploy.sh (VPS) ne le sauvegarde pas, et un fichier s'y ouvre
 * sans connexion par son adresse. Chaque fichier déposé est donc copié dans
 * la base, servi par pages/fichier.php (connexion exigée), et la copie du
 * disque est supprimée en fin de requête. Sans la migration
 * (fichiers_archives absente), tout reste sur le disque comme avant.
 */
const UPLOAD_SOUS_DOSSIERS = ['bl' => 'bl', 'fiche' => 'fiches', 'feb' => 'feb',
                              'validation' => 'validation', 'fournisseur' => 'fournisseurs'];

function upload_dossier(string $type): string {
    return UPLOAD_DIR . (UPLOAD_SOUS_DOSSIERS[$type] ?? 'bl') . '/';
}

function upload_archive_dispo(): bool {
    static $ok = null;
    if ($ok === null) {
        try { $ok = function_exists('db_colonne_existe') && db_colonne_existe('fichiers_archives', 'contenu'); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Copie en base le fichier présent sur le disque. Faux si l'archivage est indisponible ou échoue. */
function upload_archiver(string $type, string $filename): bool {
    if (!upload_archive_dispo()) return false;
    $filename = basename($filename);
    $contenu  = @file_get_contents(upload_dossier($type) . $filename);
    if ($contenu === false || $contenu === '') return false;
    try {
        db_query("DELETE FROM fichiers_archives WHERE type=? AND nom=?", [$type, $filename]);
        // Binaire : PDO::PARAM_LOB (bytea / LONGBLOB) — db_query() lie tout en texte.
        $st = get_db()->prepare("INSERT INTO fichiers_archives (type, nom, taille, contenu) VALUES (?, ?, ?, ?)");
        $st->bindValue(1, $type);
        $st->bindValue(2, $filename);
        $st->bindValue(3, strlen($contenu), PDO::PARAM_INT);
        $st->bindValue(4, $contenu, PDO::PARAM_LOB);
        $st->execute();
        return true;
    } catch (Throwable $e) {
        error_log("Archivage du fichier $type/$filename impossible : " . $e->getMessage());
        return false;
    }
}

/** Supprime la copie du disque en fin de requête (l'appelant peut encore la lire d'ici là). */
function upload_purger_disque(string $type, string $filename): void {
    $chemin = upload_dossier($type) . basename($filename);
    register_shutdown_function(static function () use ($chemin) { if (is_file($chemin)) @unlink($chemin); });
}

/** Où se trouve le fichier : 'base', 'disque' ou null (perdu). */
function upload_etat(string $type, ?string $filename): ?string {
    if (!$filename) return null;
    $filename = basename($filename);
    if (upload_archive_dispo() && db_fetch_value("SELECT 1 FROM fichiers_archives WHERE type=? AND nom=?", [$type, $filename])) return 'base';
    return is_file(upload_dossier($type) . $filename) ? 'disque' : null;
}

/** Contenu du fichier, lu en base, sinon sur le disque (il est alors archivé au passage). Null s'il est perdu. */
function upload_contenu(string $type, ?string $filename): ?string {
    if (!$filename) return null;
    $filename = basename($filename);
    if (upload_archive_dispo()) {
        $c = db_fetch_value("SELECT contenu FROM fichiers_archives WHERE type=? AND nom=?", [$type, $filename]);
        if (is_resource($c)) $c = stream_get_contents($c);   // bytea (PostgreSQL)
        if (is_string($c) && $c !== '') return $c;
    }
    $chemin = upload_dossier($type) . $filename;
    if (!is_file($chemin)) return null;
    $c = file_get_contents($chemin);
    if ($c === false) return null;
    if (upload_archiver($type, $filename)) upload_purger_disque($type, $filename);
    return $c;
}

/** Archive en base tous les fichiers encore sur le disque, puis les supprime. → nombre de fichiers archivés. */
function upload_importer_disque(): int {
    if (!upload_archive_dispo()) return 0;
    $n = 0;
    foreach (array_keys(UPLOAD_SOUS_DOSSIERS) as $type) {
        foreach (glob(upload_dossier($type) . '*') ?: [] as $chemin) {
            if (!is_file($chemin) || str_starts_with(basename($chemin), '.')) continue;
            if (upload_archiver($type, basename($chemin))) { @unlink($chemin); $n++; }
        }
    }
    return $n;
}

/**
 * Traite l'upload d'un fichier BL ou fiche signée.
 *
 * @param string $field_name   Nom du champ input file ($_FILES key)
 * @param string $type         'bl', 'fiche' ou 'feb'
 * @param string $prefix       Préfixe du nom de fichier (ex: 'livraison_42')
 * @param bool   $required     Si true, retourne une erreur si aucun fichier
 * @return array ['success'=>bool, 'filename'=>string|null, 'message'=>string]
 */
function upload_document(string $field_name, string $type, string $prefix, bool $required = true): array {
    upload_ensure_dirs();

    $dir = match ($type) {
        'fiche'       => UPLOAD_FICHE_DIR,
        'feb'         => UPLOAD_FEB_DIR,
        'fournisseur' => UPLOAD_FOURNISSEUR_DIR,
        default       => UPLOAD_BL_DIR,
    };

    // Vérifier si un fichier est fourni
    if (empty($_FILES[$field_name]) || $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            // 'fournisseur' : message nu, sans "Le document X" — l'appelant
            // (param_fournisseurs.php) préfixe déjà avec le libellé précis de
            // la pièce (RCCM, DFE...), un second "document" ferait doublon.
            if ($type === 'fournisseur') {
                return ['success' => false, 'filename' => null, 'message' => 'ce document est obligatoire.'];
            }
            $label = match ($type) {
                'fiche' => 'fiche signée',
                'feb'   => 'pièce jointe',
                default => 'bon de livraison',
            };
            return ['success' => false, 'filename' => null,
                    'message' => "Le document $label est obligatoire."];
        }
        return ['success' => true, 'filename' => null, 'message' => ''];
    }

    $file  = $_FILES[$field_name];
    $error = $file['error'];

    if ($error !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'Fichier trop volumineux (limite serveur).',
            UPLOAD_ERR_FORM_SIZE  => 'Fichier trop volumineux.',
            UPLOAD_ERR_PARTIAL    => 'Upload incomplet. Réessayez.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant.',
            UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire sur le disque.',
        ];
        return ['success' => false, 'filename' => null,
                'message' => $msgs[$error] ?? 'Erreur upload inconnue.'];
    }

    // Taille
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        return ['success' => false, 'filename' => null,
                'message' => 'Fichier trop volumineux (max 10 Mo).'];
    }

    // Type MIME réel (via finfo)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);
    if (!array_key_exists($mime, UPLOAD_ALLOWED_TYPES)) {
        return ['success' => false, 'filename' => null,
                'message' => 'Format non autorisé. Utilisez PDF, JPG, PNG ou WEBP.'];
    }

    $ext      = UPLOAD_ALLOWED_TYPES[$mime];
    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest     = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'filename' => null,
                'message' => 'Impossible de déplacer le fichier. Vérifiez les permissions du dossier uploads/.'];
    }

    // Copie durable en base ; la copie du disque part en fin de requête.
    if (upload_archiver($type, $filename)) upload_purger_disque($type, $filename);

    return ['success' => true, 'filename' => $filename, 'message' => 'Fichier uploadé avec succès.'];
}

/**
 * Supprime un fichier uploadé (bl ou fiche).
 */
function upload_delete(string $filename, string $type): void {
    $dir = match ($type) {
        'fiche'       => UPLOAD_FICHE_DIR,
        'feb'         => UPLOAD_FEB_DIR,
        'validation'  => UPLOAD_VALIDATION_DIR,
        'fournisseur' => UPLOAD_FOURNISSEUR_DIR,
        default       => UPLOAD_BL_DIR,
    };
    $path = $dir . basename($filename);
    if (file_exists($path)) {
        unlink($path);
    }
    if (upload_archive_dispo()) {
        try { db_query("DELETE FROM fichiers_archives WHERE type=? AND nom=?", [$type, basename($filename)]); }
        catch (Throwable $e) { error_log('Suppression archive : ' . $e->getMessage()); }
    }
}

/**
 * Retourne l'URL d'un fichier uploadé : pages/fichier.php le lit en base (ou
 * sur le disque) après contrôle de la connexion — plus l'adresse directe dans
 * uploads/, lisible sans connexion et vide après un déploiement.
 */
function upload_url(string $filename, string $type): string {
    return upload_url_base($type) . rawurlencode($filename);
}

/** Préfixe d'URL d'un type de fichier : le nom du fichier (encodé) s'y ajoute. */
function upload_url_base(string $type): string {
    return APP_URL . '/pages/fichier.php?d=' . (UPLOAD_SOUS_DOSSIERS[$type] ?? 'bl') . '&f=';
}

/**
 * Retourne le HTML d'un lien de téléchargement pour un fichier uploadé.
 */
function upload_link(string $filename, string $type, string $label = 'Voir le document'): string {
    if (!$filename) return '<span style="color:var(--muted);font-size:12px">—</span>';
    $url  = upload_url($filename, $type);
    $icon = str_ends_with(strtolower($filename), '.pdf') ? '<i class="ph ph-file-text" aria-hidden="true"></i>' : '<i class="ph ph-image" aria-hidden="true"></i>';
    return "<a href='$url' target='_blank' style='font-size:12.5px;color:var(--blue-mid,#1a56a0);text-decoration:none'>$icon $label</a>";
}

/**
 * Retourne true si le rôle courant est limité aux équipements informatiques.
 */
function is_maintenance_info(): bool {
    $user = current_user();
    return $user && $user['role_slug'] === 'maintenance_info';
}

/**
 * Retourne true si l'utilisateur est gestionnaire de stock.
 */
function is_gestionnaire_stock(): bool {
    $user = current_user();
    return $user && $user['role_slug'] === 'gestionnaire_stock';
}

/**
 * Retourne true si l'utilisateur est coordinateur de site.
 */
function is_coordinateur_site(): bool {
    $user = current_user();
    return $user && $user['role_slug'] === 'coordinateur_site';
}

/**
 * Retourne true si l'utilisateur est superviseur opération.
 */
function is_superviseur_operation(): bool {
    $user = current_user();
    return $user && $user['role_slug'] === 'superviseur_operation';
}

/**
 * Filtre SQL catégorie équipement pour maintenance_info.
 * Retourne la clause WHERE supplémentaire.
 */
function equip_categorie_filter(string $alias = 'e'): string {
    if (is_maintenance_info()) {
        return " AND {$alias}.categorie = 'informatique'";
    }
    return '';
}
