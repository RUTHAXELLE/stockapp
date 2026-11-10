-- ============================================================
--  Archivage en base des fichiers déposés — MySQL (ligne VPS)
-- ============================================================
--
--  Pièces jointes des FEB, bons de livraison et de transfert des
--  réceptions, fiches signées, fiches de validation et documents
--  fournisseurs étaient rangés dans uploads/ : absent des sauvegardes
--  (deploy.sh ne sauvegarde que la base) et lisible sans connexion par son
--  adresse. Ils sont désormais copiés ici (includes/upload.php) et servis
--  par pages/fichier.php. Les fichiers encore sur le disque s'importent
--  depuis Stock → Archives des BL → « Importer en base » (administrateur).
--
--  Un fichier fait au plus 10 Mo (contrôle de l'envoi) : sous la limite
--  max_allowed_packet par défaut de MySQL 8 (64 Mo).
--
--  Rejouable sans effet de bord.

CREATE TABLE IF NOT EXISTS fichiers_archives (
    type      VARCHAR(20)  NOT NULL,
    nom       VARCHAR(255) NOT NULL,
    taille    INT          NOT NULL,
    contenu   LONGBLOB     NOT NULL,
    depose_le DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (type, nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
