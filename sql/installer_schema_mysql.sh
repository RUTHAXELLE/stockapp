#!/usr/bin/env bash
# ============================================================
#  sql/installer_schema_mysql.sh — Schéma complet sur une base MySQL VIDE
#
#  Joue, dans l'ordre où ils ont été écrits, sql/stockapp.sql puis toutes
#  les migrations *_mysql.sql de la branche vps-mysql, et vérifie le
#  résultat. Pensé pour la première installation de la production, avant
#  la copie des données de la recette (tools/migrate_pg_to_mysql.py).
#
#  Pourquoi un script plutôt qu'une liste de commandes : neuf anciennes
#  migrations (migration_vps_mysql_*) ont été reportées depuis dans
#  sql/stockapp.sql. Sur une base neuve, leur première instruction échoue
#  (« Duplicate column »…) et `mysql < fichier` s'arrête là, sans jouer la
#  suite — dont deux colonnes des demandes internes (di_demandes.site_id,
#  ticket_glpi) et la levée d'une unicité qui interdirait le second type
#  de rivets par site. Ces neuf fichiers sont donc joués avec --force, et
#  seules les erreurs « déjà présent » y sont tolérées. Tout autre fichier
#  s'arrête à la première erreur.
#
#  Base livrée VIDE : sql/stockapp.sql embarque le jeu de démonstration
#  de la recette (comptes de test, bobines, imports, audit…). Une fois le
#  schéma vérifié, toutes les tables sont vidées sauf les référentiels
#  listés dans GARDER (rôles, permissions, types, sites, nomenclatures,
#  paramétrage des demandes internes et des achats). Le premier compte
#  se crée ensuite avec tools/creer_superadmin.php.
#
#  Usage (depuis la racine de l'application, .env renseigné) :
#      ./sql/installer_schema_mysql.sh               # production : base vide
#      ./sql/installer_schema_mysql.sh --avec-demo   # essais : garde la démo
#  Refuse de s'exécuter sur une base qui contient déjà des utilisateurs.
# ============================================================
set -uo pipefail

AVEC_DEMO=0
[ "${1:-}" = "--avec-demo" ] && AVEC_DEMO=1

cd "$(dirname "$0")/.." || exit 1
# Une variable d'environnement DB_* l'emporte sur .env : c'est ce qui
# permet à tools/verifier_vps.sh et à docker/schema.sh d'installer des
# bases jetables. Sur le serveur, aucune n'est définie : seul .env compte.
[ -f .env ] || [ -n "${DB_NAME:-}" ] || { echo "ERREUR : .env manquant à la racine de l'application" >&2; exit 1; }

lire() {
    if [ -n "${!1:-}" ]; then printf '%s' "${!1}"; return; fi
    [ -f .env ] && grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^["'\'']//' -e 's/["'\'']$//'
}
DB_HOST="$(lire DB_HOST)"; DB_HOST="${DB_HOST:-localhost}"
DB_PORT="$(lire DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(lire DB_NAME)"; DB_USER="$(lire DB_USER)"
export MYSQL_PWD="$(lire DB_PASS)"          # hors de la liste des processus
MYSQL=(mysql --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME")

"${MYSQL[@]}" -e "SELECT 1" >/dev/null 2>&1 || { echo "ERREUR : connexion MySQL impossible (vérifier .env)" >&2; exit 1; }
if "${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM users" 2>/dev/null | grep -qv '^0$'; then
    echo "ERREUR : la base contient déjà des utilisateurs — ce script ne s'utilise que sur une base vide." >&2
    exit 1
fi

# Migrations déjà reportées dans sql/stockapp.sql : jouées avec --force.
ANCIENNES=" migration_vps_mysql_di_roles_departement.sql migration_vps_mysql_stock_rivets_type.sql
 migration_vps_mysql_points_rivets_types.sql migration_vps_mysql_equipements_departement_statut.sql
 migration_vps_mysql_equipements_feb_ligne.sql migration_vps_mysql_inventaires_bobines_session.sql
 migration_vps_mysql_colonnes_manquantes_diverses.sql migration_vps_mysql_bobines_format_version_serie_tlwsl.sql
 migration_vps_mysql_inventaire_rivets_pmma_equipements.sql "
DEJA_PRESENT='Duplicate column|already exists|Duplicate key name|check that column/key exists|Duplicate foreign key'
est_ancienne() { local a; for a in $ANCIENNES; do [ "$a" = "$1" ] && return 0; done; return 1; }

# Ordre : celui de leur écriture (date d'ajout dans Git, figée ici pour ne
# pas dépendre de l'historique sur le serveur).
FICHIERS="stockapp.sql demandes_internes_mysql.sql agents_mysql.sql achats_mysql.sql
 migration_vps_mysql_sites_type_magasin.sql migration_vps_mysql_di_roles_departement.sql
 migration_vps_mysql_stock_rivets_type.sql migration_vps_mysql_points_rivets_types.sql
 migration_vps_mysql_equipements_departement_statut.sql migration_vps_mysql_equipements_feb_ligne.sql
 migration_vps_mysql_inventaires_bobines_session.sql migration_vps_mysql_colonnes_manquantes_diverses.sql
 migration_vps_mysql_achats_donnees_reference.sql migration_vps_mysql_permissions_matrice_complete.sql
 migration_vps_mysql_referentiels_organisation.sql migration_vps_mysql_bobines_format_version_serie_tlwsl.sql
 migration_vps_mysql_inventaire_rivets_pmma_equipements.sql
 migration_permissions_inventaire_ecarts_equip_pmma_rivets_mysql.sql migration_op_pmma_utilises_mysql.sql
 migration_preferences_affichage_mysql.sql migration_correction_point_emuci_mysql.sql
 migration_equipements_emplacement_mysql.sql migration_observations_suivi_mysql.sql
 migration_referentiels_capacites_mysql.sql migration_rivets_seuil_alerte_mysql.sql
 migration_tracabilite_endommagements_mysql.sql
 migration_import_optotrace_plate_number_mysql.sql
 migration_demandes_correction_saisie_structure_mysql.sql
 migration_commandes_depot_source_mysql.sql migration_enums_en_texte_mysql.sql
 migration_permissions_modules_scindes_mysql.sql
 migration_endommagements_cause_libre_mysql.sql migration_endommagements_film_no_mysql.sql
 migration_rivets_endommages_par_type_mysql.sql migration_pmma_non_conformes_mysql.sql
 migration_endommagements_rivets_pmma_mysql.sql migration_films_nc_numero_consommable_mysql.sql
 migration_operation_sites_duplicata_postimmat_mysql.sql"

echo "==> Installation du schéma dans $DB_NAME@$DB_HOST"
for f in $FICHIERS; do
    [ -f "sql/$f" ] || { echo "ERREUR : sql/$f introuvable" >&2; exit 1; }
    if est_ancienne "$f"; then
        sortie=$("${MYSQL[@]}" --force < "sql/$f" 2>&1)
        autres=$(echo "$sortie" | grep ERROR | grep -vE "$DEJA_PRESENT")
        if [ -n "$autres" ]; then echo "ERREUR inattendue dans $f :"; echo "$autres"; exit 1; fi
        printf '    %-70s ok (déjà dans stockapp.sql : %s instruction(s) ignorée(s))\n' "$f" "$(echo "$sortie" | grep -c ERROR)"
    else
        if ! sortie=$("${MYSQL[@]}" < "sql/$f" 2>&1); then echo "ERREUR dans $f :"; echo "$sortie" | grep ERROR; exit 1; fi
        printf '    %-70s ok\n' "$f"
    fi
done

echo "==> Vérifications"
verif() { local r; r=$("${MYSQL[@]}" -N -e "$2" 2>/dev/null); if [ "$r" = "1" ]; then echo "    ok     $1"; else echo "    ÉCHEC  $1"; ECHEC=1; fi; }
ECHEC=0
verif "au moins 116 tables" "SELECT COUNT(*) >= 116 FROM information_schema.tables WHERE table_schema = DATABASE()"
verif "di_demandes.ticket_glpi et site_id" "SELECT COUNT(*) = 2 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'di_demandes' AND column_name IN ('ticket_glpi','site_id')"
verif "rivets : un stock par site ET par type" "SELECT COUNT(*) = 0 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'op_stock_rivets' AND index_name = 'uq_site'"
verif "tables d'octobre 2026 (endommagements, observations, corrections EMUCI, PMMA)" "SELECT COUNT(*) = 5 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('op_endommagements','op_observations','op_observation_relances','corrections_point_emuci','op_types_pmma')"
verif "op_stock_rivets.seuil_alerte et equipements.emplacement" "SELECT COUNT(*) = 2 FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'op_stock_rivets' AND column_name = 'seuil_alerte') OR (table_name = 'equipements' AND column_name = 'emplacement'))"
verif "import OptoTrace et corrections GSB acceptés en mode strict" "SELECT (SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'import_optotrace' AND column_name = 'plate_number') = 'YES' AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'demandes_correction_saisie' AND column_name IN ('bobine_id','site_id','gsb_id','date_cible','films_pj','films_emuci','ecart','notes_gsb')) = 8"
verif "commandes.depot_source_id (dépôt source des livraisons)" "SELECT COUNT(*) = 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'commandes' AND column_name = 'depot_source_id'"
verif "plus aucune liste fermée (ENUM) hors colonnes calculées, comme sur main" "SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND data_type = 'enum' AND extra NOT LIKE '%GENERATED%'"
verif "droits des modules scindés (Nouvelle demande, À valider, Traitements IT, Vignette…)" "SELECT COUNT(DISTINCT module) = 5 FROM permissions WHERE module IN ('demandes_new','demandes_valider','demandes_it','equipements_operationnel','vignette')"
verif "report du 14/10/2026 : Re-Immatriculation, endommagements par unité, non conformes" "SELECT COUNT(*) = 9 FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'sites' AND column_name = 'operation') OR (table_name = 'op_points_journaliers' AND column_name IN ('total_plaques_duplicata','total_plaques_postimmat','rivets_gonflables_end','rivets_eclates_end')) OR (table_name = 'op_endommagements' AND column_name IN ('objet','numero_consommable')) OR (table_name IN ('op_pmma_utilises','op_films_utilises') AND column_name = 'non_conformes'))"
[ "$ECHEC" = "0" ] && echo "==> Schéma complet." || { echo "==> Schéma INCOMPLET : ne pas continuer."; exit 1; }

if [ "$AVEC_DEMO" = "1" ]; then
    echo "==> Données de démonstration conservées (--avec-demo) — NE PAS utiliser en production."
    exit 0
fi

# Référentiels conservés. Tout le reste — y compris une table ajoutée plus
# tard par une migration — est vidé : une table oubliée ici repart vide,
# jamais avec des données de recette.
GARDER=" roles permissions op_types_bobines op_types_vehicule op_types_pmma
 sites nomenclatures configurations_site defauts_affichage
 di_etapes di_plateformes di_roles di_types
 achat_paliers achat_parametres achat_types departements familles_achat lignes_budgetaires "
est_gardee() { local g; for g in $GARDER; do [ "$g" = "$1" ] && return 0; done; return 1; }

echo "==> Base vide : suppression des données de démonstration"
VIDER="SET FOREIGN_KEY_CHECKS=0;"
for t in $("${MYSQL[@]}" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"); do
    est_gardee "$t" && continue
    VIDER="$VIDER TRUNCATE TABLE \`$t\`;"
done
# Les référentiels gardés ne doivent plus désigner de comptes de test.
VIDER="$VIDER UPDATE sites SET responsable_id = NULL; UPDATE achat_parametres SET modifie_par = NULL; SET FOREIGN_KEY_CHECKS=1;"
if ! sortie=$("${MYSQL[@]}" -e "$VIDER" 2>&1); then echo "ERREUR pendant la suppression :"; echo "$sortie" | grep ERROR; exit 1; fi

echo "==> Vérifications de la base vide"
ECHEC=0
verif "aucun utilisateur, aucune bobine, journal d'audit vide" "SELECT (SELECT COUNT(*) FROM users) + (SELECT COUNT(*) FROM op_bobines) + (SELECT COUNT(*) FROM audit_log) + (SELECT COUNT(*) FROM import_optotrace) = 0"
verif "rôles et permissions présents" "SELECT (SELECT COUNT(*) FROM roles WHERE slug = 'superadmin') = 1 AND (SELECT COUNT(*) FROM permissions) > 0"
verif "sites et nomenclatures conservés, sans responsable" "SELECT (SELECT COUNT(*) FROM sites) > 0 AND (SELECT COUNT(*) FROM nomenclatures) > 0 AND (SELECT COUNT(*) FROM sites WHERE responsable_id IS NOT NULL) = 0"
[ "$ECHEC" = "0" ] || { echo "==> Base NON conforme : ne pas continuer."; exit 1; }
echo "==> Base vide prête. Créer le premier compte : sudo -u www-data php tools/creer_superadmin.php"
