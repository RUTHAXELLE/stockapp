-- ============================================================
--  Commandes inter-sites. Idempotente. 2026-11-22
-- ============================================================
--  Sortie diverse : sur une commande validée, le gestionnaire de stock fait
--  servir tout ou partie d'une ligne par un autre site de production (B).
--  Cela crée une commande liée (commande_origine_id, ligne_origine_id), que
--  B accepte (accepte_par, accepte_at) avant de préparer.
--  Un site (A) est ainsi servi par un autre site (B) au lieu d'un
--  dépôt. Même circuit qu'une commande : validation du superviseur, choix
--  des bobines (coordinateur de B ou GSB), enlèvement chez B par le chauffeur
--  du gestionnaire de stock (stock de B débité), réception par A.
--   - site_source_id   : site fournisseur B (NULL = commande classique) ;
--   - motif_inter_site : motif de la demande (rupture, urgence…) ;
--   - chauffeur        : chauffeur envoyé par le gestionnaire de stock.
--  Tant que cette migration n'est pas passée, l'option n'apparaît pas.
-- ============================================================
ALTER TABLE commandes ADD COLUMN IF NOT EXISTS site_source_id   integer      DEFAULT NULL;
ALTER TABLE commandes ADD COLUMN IF NOT EXISTS motif_inter_site text         DEFAULT NULL;
ALTER TABLE commandes ADD COLUMN IF NOT EXISTS chauffeur        varchar(150) DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_commandes_site_source ON commandes (site_source_id, statut);
-- Sortie diverse (2026-11-28) : la commande inter-site naît d'une ligne d'une
-- commande validée, décidée par le gestionnaire de stock, et le site
-- fournisseur l'accepte avant de préparer.
ALTER TABLE commandes      ADD COLUMN IF NOT EXISTS commande_origine_id integer   DEFAULT NULL;
ALTER TABLE commandes      ADD COLUMN IF NOT EXISTS accepte_par         integer   DEFAULT NULL;
ALTER TABLE commandes      ADD COLUMN IF NOT EXISTS accepte_at          timestamp DEFAULT NULL;
ALTER TABLE commande_lignes ADD COLUMN IF NOT EXISTS ligne_origine_id   integer   DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_commandes_origine ON commandes (commande_origine_id);
CREATE INDEX IF NOT EXISTS idx_commande_lignes_origine ON commande_lignes (ligne_origine_id);

COMMENT ON COLUMN commandes.site_source_id   IS 'Commande inter-site : site fournisseur (NULL = commande servie par un dépôt).';
COMMENT ON COLUMN commandes.motif_inter_site IS 'Commande inter-site : motif de la demande.';
COMMENT ON COLUMN commandes.chauffeur        IS 'Commande inter-site : chauffeur envoyé par le gestionnaire de stock pour l''enlèvement.';
