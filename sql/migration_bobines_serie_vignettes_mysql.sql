-- ============================================================
--  migration_bobines_serie_vignettes_mysql.sql  (rejouable)
--  Les imports (import_bobines.php, import_emuci.php) créaient les
--  vignettes avec op_bobines.serie = 'T' ou 'W' (premier caractère du
--  format), alors que la page Vignettes et les commandes filtrent sur
--  'TL' / 'WSL' : ces rouleaux n'apparaissaient nulle part.
--  On réaligne la série sur le catalogue op_types_bobines.
-- ============================================================
UPDATE op_bobines b
  JOIN op_types_bobines tb ON tb.code = b.type_code
   SET b.serie = TRIM(tb.serie)
 WHERE b.serie <> TRIM(tb.serie);

-- Repli pour un type absent du catalogue.
UPDATE op_bobines SET serie = 'WSL' WHERE serie = 'W' AND UPPER(type_code) LIKE 'WSL%';
UPDATE op_bobines SET serie = 'TL'  WHERE serie = 'T' AND UPPER(type_code) LIKE 'TL%';
