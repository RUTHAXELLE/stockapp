#!/usr/bin/env bash
# ============================================================
#  tools/verifier_vps.sh — contrôle complet avant mise en production
#
#  À lancer avant chaque push sur vps-mysql (GitHub le relance ensuite à
#  chaque push, cf. .github/workflows/verif-vps.yml) :
#
#      bash tools/verifier_vps.sh
#
#  Utilise la pile Docker du projet (docker-compose.yml) et deux bases
#  jetables, supprimées à la fin — la base locale « stockapp » n'est pas
#  touchée :
#    · verif_vide : installée exactement comme en production (base vide),
#      avec le compte limité de l'application ;
#    · verif_demo : même installation avec le jeu de démonstration, sur
#      laquelle tools/verifier.php contrôle le code (syntaxe PHP, restes
#      PostgreSQL, INSERT contre le schéma, ouverture de chaque écran).
#
#  Le code contrôlé est celui du dossier, modifications non commitées
#  comprises. Sortie 0 si tout passe.
# ============================================================
set -euo pipefail
export MSYS_NO_PATHCONV=1   # Git Bash (Windows) : ne pas réécrire /app/… en chemin Windows
cd "$(dirname "$0")/.."

DC=(docker compose)
racine() { "${DC[@]}" exec -T db mysql -uroot -proot -e "$1" 2>&1 | grep -v "Using a password" || true; }
nettoyer() { racine "DROP DATABASE IF EXISTS verif_vide; DROP DATABASE IF EXISTS verif_demo;" >/dev/null; }

echo "==> Base MySQL"
"${DC[@]}" up -d --wait db >/dev/null
trap nettoyer EXIT
nettoyer
racine "CREATE DATABASE verif_vide CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE DATABASE verif_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        GRANT ALL PRIVILEGES ON verif_vide.* TO 'stockapp'@'%';
        GRANT ALL PRIVILEGES ON verif_demo.* TO 'stockapp'@'%';"

ECHEC=0
installer() {   # $1 = base, $2 = option de l'installateur
    "${DC[@]}" run --rm --no-deps -T -e DB_NAME="$1" --entrypoint bash schema \
        /app/sql/installer_schema_mysql.sh ${2:-} 2>&1 | grep -E "==>|ok     |ÉCHEC|ERREUR|ERROR"
    return "${PIPESTATUS[0]}"
}

echo "==> Installation comme en production (verif_vide)"
installer verif_vide || { echo "  ✗ l'installation de production échoue"; ECHEC=1; }

echo "==> Installation avec données de démonstration (verif_demo)"
if installer verif_demo --avec-demo; then
    echo "==> Contrôles du code"
    "${DC[@]}" build -q app
    "${DC[@]}" run --rm --no-deps -T -e DB_NAME=verif_demo app php tools/verifier.php || ECHEC=1
else
    echo "  ✗ l'installation avec démonstration échoue : contrôles du code impossibles"; ECHEC=1
fi

echo
if [ "$ECHEC" = 0 ]; then echo "✅ Vérification réussie — prêt à pousser."; else echo "❌ Vérification en échec — corriger avant de pousser."; fi
exit "$ECHEC"
