-- ============================================================
--  Plaques réellement posées sur les duplicatas VP et camions
--
--  Un duplicata (sites de Re-Immatriculation) ne remplace pas toujours
--  les deux plaques d'un VP ou d'un camion : une seule peut avoir été
--  désactivée. Le coordinateur saisit donc, pour ces deux types, le
--  nombre de plaques posées (entre 1 et 2 par véhicule) au lieu du
--  forfait de 2 ; total_plaques_duplicata en découle et la comparaison
--  OptoPlate compare aussi les plaques (pages/operations/point_journalier.php,
--  includes/optoplate_comparaison.php). Semi-remorques et motos : 1 plaque,
--  inchangé. Nullable : un point antérieur garde le forfait de 2.
--
--  Idempotente : ADD COLUMN IF NOT EXISTS.
-- ============================================================
ALTER TABLE op_points_journaliers ADD COLUMN IF NOT EXISTS plaques_vp_duplicata integer DEFAULT NULL;
ALTER TABLE op_points_journaliers ADD COLUMN IF NOT EXISTS plaques_camion_duplicata integer DEFAULT NULL;
