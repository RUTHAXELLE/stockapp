-- ============================================================
--  Archivage des bons de livraison signés dans la base — MySQL (ligne VPS)
-- ============================================================
--
--  Le BL signé joint par le site à la réception d'une commande était rangé
--  dans uploads/bl/ : absent des sauvegardes (deploy.sh ne sauvegarde que
--  la base) et lisible sans connexion par son adresse. Il est désormais
--  rangé ici (cf. includes/commande_bl.php). Les BL encore sur le disque
--  s'importent depuis Stock → Archives des BL (administrateur).
--
--  Un BL fait au plus 10 Mo (contrôle de l'envoi) : sous la limite
--  max_allowed_packet par défaut de MySQL 8 (64 Mo).
--
--  Rejouable sans effet de bord.

CREATE TABLE IF NOT EXISTS commande_bl (
    commande_id INT UNSIGNED NOT NULL PRIMARY KEY,
    nom         VARCHAR(255) NULL,
    taille      INT          NOT NULL,
    sha256      CHAR(64)     NOT NULL,
    contenu     LONGBLOB     NOT NULL,
    depose_par  INT UNSIGNED NULL,
    depose_le   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_commande_bl_commande FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
