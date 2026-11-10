<?php
// ============================================================
//  includes/commande_bl.php — Archivage des bons de livraison signés
//
//  Le BL signé joint par le site à la réception d'une commande est rangé
//  dans la base (table commande_bl), et non plus dans uploads/bl/ :
//    · Render n'a pas de disque permanent — chaque déploiement effaçait
//      uploads/, donc les BL ;
//    · la base est déjà sauvegardée (Neon, deploy.sh sur le VPS), pas
//      le dossier uploads/ ;
//    · un fichier de uploads/ s'ouvrait sans connexion, par son adresse.
//  Les BL déposés avant cette version restent lus sur le disque tant qu'ils
//  n'ont pas été importés (blc_importer_disque()), puis le fichier est
//  supprimé.
//
//  Schéma : sql/migration_commande_bl_archive(.sql | _mysql.sql).
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/upload.php';

function blc_disponible(): bool {
    return db_colonne_existe('commande_bl', 'contenu');
}

/** Range un BL (fichier local) pour la commande, en remplaçant l'éventuel précédent. */
function blc_enregistrer(int $cmd_id, string $chemin, string $nom, ?int $uid): void {
    $contenu = file_get_contents($chemin);
    if ($contenu === false || $contenu === '') throw new RuntimeException('Bon de livraison illisible.');
    db_query("DELETE FROM commande_bl WHERE commande_id=?", [$cmd_id]);
    // Paramètre binaire : PDO::PARAM_LOB (bytea PostgreSQL, LONGBLOB MySQL) —
    // db_query() lie tout en texte, ce qui casse un PDF sous PostgreSQL.
    $st = get_db()->prepare("INSERT INTO commande_bl (commande_id, nom, taille, sha256, contenu, depose_par)
                             VALUES (?, ?, ?, ?, ?, ?)");
    $st->bindValue(1, $cmd_id, PDO::PARAM_INT);
    $st->bindValue(2, mb_substr($nom, 0, 255));
    $st->bindValue(3, strlen($contenu), PDO::PARAM_INT);
    $st->bindValue(4, hash('sha256', $contenu));
    $st->bindValue(5, $contenu, PDO::PARAM_LOB);
    $st->bindValue(6, $uid, $uid === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->execute();
}

/** Contenu du BL rangé en base : ['nom', 'taille', 'contenu'] ou null. */
function blc_lire(int $cmd_id): ?array {
    $r = db_fetch_one("SELECT nom, taille, contenu FROM commande_bl WHERE commande_id=?", [$cmd_id]);
    if (!$r) return null;
    if (is_resource($r['contenu'])) $r['contenu'] = stream_get_contents($r['contenu']);   // bytea (PostgreSQL)
    return $r;
}

/** Chemin du BL encore sur le disque (dépôt antérieur à l'archivage en base), ou null. */
function blc_fichier_disque(?string $filename): ?string {
    if (!$filename) return null;
    $p = UPLOAD_BL_DIR . basename($filename);
    return is_file($p) ? $p : null;
}

/** État du BL signé d'une commande : 'base', 'disque', 'perdu' (référencé, fichier disparu) ou null (aucun). */
function blc_etat(array $cmd, bool $en_base): ?string {
    if ($en_base) return 'base';
    if (empty($cmd['bl_reception'])) return null;
    // Disque, ou archive générique des fichiers (includes/upload.php).
    return upload_etat('bl', $cmd['bl_reception']) !== null ? 'disque' : 'perdu';
}

/**
 * Importe en base les BL encore sur le disque, puis supprime le fichier.
 * → ['importes' => n, 'perdus' => n].
 */
function blc_importer_disque(?int $uid): array {
    $n = ['importes' => 0, 'perdus' => 0];
    foreach (db_fetch_all(
        "SELECT c.id, c.bl_reception, c.recu_par FROM commandes c
          WHERE COALESCE(c.bl_reception, '') <> ''
            AND NOT EXISTS (SELECT 1 FROM commande_bl b WHERE b.commande_id = c.id)") as $c) {
        $p = blc_fichier_disque($c['bl_reception']);
        if (!$p) { $n['perdus']++; continue; }
        blc_enregistrer((int)$c['id'], $p, basename($c['bl_reception']), $c['recu_par'] !== null ? (int)$c['recu_par'] : $uid);
        @unlink($p);
        $n['importes']++;
    }
    return $n;
}

/**
 * Qui consulte les BL d'une commande : le coordinateur pour son site,
 * sinon la lecture des commandes ou l'option Magasin (cf. droit_fonction()).
 */
function blc_peut_voir(array $user, array $cmd): bool {
    if (($user['role_slug'] ?? '') === 'coordinateur_site')
        return $user['site_id'] && (int)$user['site_id'] === (int)$cmd['site_id'];
    return can('commandes', 'can_read') || droit_fonction(FONCTION_MAGASIN, $user);
}
