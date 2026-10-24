-- ============================================================
--  Bobines commandées depuis les Commandes générales (ligne « bobine »)
--  Fusion de « Commande bobines » dans « Commandes » (option B).
--  Idempotente. 2026-10-24
-- ============================================================
--  Une ligne bobine (commande_lignes.type_article = 'bobine') commande N
--  bobines d'un type. Après validation du superviseur, le GSB choisit les
--  bobines précises : chacune est enregistrée ici, 'assigne' (réservée, encore
--  à l'entrepôt), puis 'recu' quand le site réceptionne (elle passe alors au
--  site, en stock).
--  Tant que cette migration n'est pas passée, une ligne bobine garde l'ancien
--  fonctionnement (aucune affectation).
-- ============================================================
CREATE TABLE IF NOT EXISTS commande_ligne_bobines (
    id                serial PRIMARY KEY,
    commande_id       integer     NOT NULL,
    commande_ligne_id integer     NOT NULL,
    bobine_id         integer     NOT NULL,
    numero_bobine     varchar(50) NOT NULL,
    statut            varchar(20) NOT NULL DEFAULT 'assigne',   -- assigne | recu
    assigne_par       integer     DEFAULT NULL,
    assigne_at        timestamp   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_clb_commande ON commande_ligne_bobines (commande_id);
CREATE INDEX IF NOT EXISTS idx_clb_ligne    ON commande_ligne_bobines (commande_ligne_id);
CREATE INDEX IF NOT EXISTS idx_clb_bobine   ON commande_ligne_bobines (bobine_id, statut);
