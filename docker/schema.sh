#!/usr/bin/env bash
# ============================================================
#  docker/schema.sh — prépare la base locale comme en production
#
#  Lancé par le service « schema » de docker-compose.yml, dans l'image
#  mysql:8.0 (vrai client MySQL), une seule fois : si la base contient
#  déjà des utilisateurs, il ne fait rien.
#
#  Même chemin que le serveur : sql/installer_schema_mysql.sh, avec le
#  compte limité de l'application (pas root) et le mode strict par défaut
#  de MySQL 8. Une erreur qui arriverait en production arrive donc ici
#  d'abord. Différence voulue : le jeu de démonstration est conservé
#  (--avec-demo), pour avoir des données à l'écran.
#
#  Compte local : admin@stockapp.local, mot de passe dans DEV_LOCAL.md.
#  Il n'existe que dans cette base locale.
# ============================================================
set -euo pipefail

export MYSQL_PWD="$DB_PASS"
MYSQL=(mysql --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME")

for _ in $(seq 1 60); do "${MYSQL[@]}" -e "SELECT 1" >/dev/null 2>&1 && break; sleep 2; done
"${MYSQL[@]}" -e "SELECT 1" >/dev/null || { echo "ERREUR : MySQL injoignable ($DB_USER@$DB_HOST:$DB_PORT/$DB_NAME)" >&2; exit 1; }

if "${MYSQL[@]}" -N -e "SELECT 1 FROM users LIMIT 1" >/dev/null 2>&1; then
    echo "Base locale déjà installée — rien à faire (docker compose down -v pour repartir de zéro)."
    exit 0
fi

bash /app/sql/installer_schema_mysql.sh --avec-demo

# Mot de passe connu pour le compte de démonstration admin@stockapp.local
# (empreinte bcrypt de la valeur indiquée dans DEV_LOCAL.md).
"${MYSQL[@]}" -e "UPDATE users SET password_hash = '\$2y\$12\$QdpCYEoLd/XS0ISFk13kkOexrKcOXdGnLKNqb4oNxMUZHqpfBMyB6', actif = 1, must_change_password = 0, failed_login_attempts = 0, locked_until = NULL WHERE email = 'admin@stockapp.local'"
echo "==> Base locale prête : http://localhost:8081 (admin@stockapp.local)"
