-- ============================================================
--  Migration MySQL : cause des endommagements en saisie libre
-- ============================================================
--  Traduction de sql/migration_endommagements_cause_libre.sql (main, 13/10/2026).
--
--  La cause d'un endommagement n'est plus choisie dans une liste : texte
--  libre (avec suggestions) jusqu'à 255 caractères. MODIFY rejouable tel quel.
--
--  Rejouable sans effet : MySQL 8.0 n'a ni ADD COLUMN IF NOT EXISTS ni
--  CREATE INDEX IF NOT EXISTS ; chaque ajout est conditionné par
--  information_schema et passe par une requête préparée.
-- ============================================================
ALTER TABLE `op_endommagements`
  MODIFY `cause` varchar(255) NOT NULL COMMENT 'Cause en saisie libre (anciens codes : manipulation | defaut_materiel | incident_externe | autre)';
