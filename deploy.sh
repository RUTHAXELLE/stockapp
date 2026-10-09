#!/usr/bin/env bash
# ============================================================
#  deploy.sh — Déploiement / mise à jour de l'ERP EMUCI sur le VPS
#  Branche vps-mysql (Nginx ou Apache + PHP >= 8.2 + MySQL 8). Idempotent.
#
#  Étapes : git pull  ->  sauvegarde DB  ->  (1re install : schéma complet
#           en base vide, sql/installer_schema_mysql.sh)  ->  schéma Demandes
#           internes (idempotent)  ->  permissions + rechargement du serveur
#           web  ->  (1re install : compte super-administrateur).
#
#  Usage :   sudo ./deploy.sh
#  Options (variables d'environnement) :
#     APP_DIR=/chemin        (défaut /var/www/stockapp)
#     APP_USER=www-data      propriétaire des fichiers
#     BRANCH=vps-mysql       branche git à déployer
#     BACKUP_DIR=/chemin     (défaut /var/backups/stockapp) — HORS du dossier
#                            publié : une sauvegarde rangée dans APP_DIR
#                            serait téléchargeable depuis Internet
#     SKIP_BACKUP=1          ne pas sauvegarder la base avant
#     SKIP_PULL=1            ne pas faire de git pull
#     RELOAD_WEB=0           ne pas recharger le serveur web
#                            (RELOAD_APACHE=0, ancien nom, reste accepté)
#
#  Les nouvelles migrations *_mysql.sql d'une mise à jour ne sont PAS jouées
#  automatiquement sur une base existante : voir docs/MANUEL_DEPLOIEMENT_VPS.md.
# ============================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/stockapp}"
APP_USER="${APP_USER:-www-data}"
BRANCH="${BRANCH:-vps-mysql}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/stockapp}"
SKIP_BACKUP="${SKIP_BACKUP:-0}"
SKIP_PULL="${SKIP_PULL:-0}"
RELOAD_WEB="${RELOAD_WEB:-${RELOAD_APACHE:-1}}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m/!\\\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mERREUR:\033[0m %s\n' "$*" >&2; exit 1; }

cd "$APP_DIR" 2>/dev/null || die "Répertoire introuvable : $APP_DIR"
log "ERP EMUCI — déploiement ($APP_DIR, branche $BRANCH)"

command -v git   >/dev/null || die "git introuvable"
command -v mysql >/dev/null || die "client mysql introuvable"
[ -f .env ] || die "Fichier .env manquant ($APP_DIR/.env)"

# ── Identifiants DB depuis .env ─────────────────────────────
getenv() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^["'\'']//' -e 's/["'\'']$//'; }
DB_HOST="$(getenv DB_HOST)"; DB_HOST="${DB_HOST:-localhost}"
DB_PORT="$(getenv DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(getenv DB_NAME)"
DB_USER="$(getenv DB_USER)"
DB_PASS="$(getenv DB_PASS)"
[ -n "$DB_NAME" ] && [ -n "$DB_USER" ] || die "DB_NAME / DB_USER absents du .env"
export MYSQL_PWD="$DB_PASS"                       # évite le mot de passe dans la liste des process
MYSQL=(mysql --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME")
"${MYSQL[@]}" -e "SELECT 1" >/dev/null 2>&1 || die "Connexion MySQL impossible (vérifier .env)"

# ── 1. Récupérer le code ────────────────────────────────────
if [ "$SKIP_PULL" != "1" ]; then
  # Les fichiers appartiennent à www-data (chown plus bas) : sans cette
  # exception, Git >= 2.35 refuse d'y travailler en root (« dubious
  # ownership ») et toute mise à jour après la première échoue.
  git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$APP_DIR" \
    || git config --global --add safe.directory "$APP_DIR"
  if ! git diff --quiet || ! git diff --cached --quiet; then
    warn "Modifications locales non commitées — git pull peut échouer."
  fi
  AVANT="$(git rev-parse HEAD)"
  log "git pull --ff-only origin $BRANCH"
  git pull --ff-only origin "$BRANCH"
  # Les migrations d'une mise à jour ne sont pas jouées automatiquement
  # (cf. en-tête) : on les nomme pour que l'administrateur ne les oublie pas.
  NOUVELLES="$(git diff --name-only --diff-filter=A "$AVANT" HEAD -- 'sql/*_mysql.sql' | grep -v 'demandes_internes_mysql.sql' || true)"
  if [ -n "$NOUVELLES" ]; then
    warn "Nouvelles migrations SQL à jouer APRÈS lecture (voir docs/MANUEL_DEPLOIEMENT_VPS.md) :"
    printf '      %s\n' $NOUVELLES
  fi
else
  log "git pull ignoré (SKIP_PULL=1)"
fi

# ── 2. Sauvegarde de la base (avant tout changement) ────────
if [ "$SKIP_BACKUP" != "1" ]; then
  mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR" 2>/dev/null || true
  BK="$BACKUP_DIR/${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql.gz"
  log "Sauvegarde DB -> $BK"
  if command -v gzip >/dev/null; then
    mysqldump --no-tablespaces --single-transaction --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" | gzip > "$BK" \
      || die "Sauvegarde échouée (relancer avec SKIP_BACKUP=1 pour forcer)"
  else
    mysqldump --no-tablespaces --single-transaction --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" > "${BK%.gz}" \
      || die "Sauvegarde échouée (relancer avec SKIP_BACKUP=1 pour forcer)"
  fi
else
  warn "Sauvegarde DB ignorée (SKIP_BACKUP=1)"
fi

# ── 3. Schémas SQL ──────────────────────────────────────────
# Le schéma de base n'est chargé QUE si la base est vide (1re installation) :
# le recharger sur une base existante écraserait les données.
if "${MYSQL[@]}" -N -e "SELECT 1 FROM users LIMIT 1" >/dev/null 2>&1; then
  log "Base déjà initialisée — schéma de base non rechargé (protège les données)"
else
  # Le schéma complet ne tient pas dans sql/stockapp.sql seul : 25 migrations
  # _mysql.sql le complètent, dont 9 déjà reportées dans le dump (cf. script).
  log "Première installation — sql/installer_schema_mysql.sh (base vide)"
  bash sql/installer_schema_mysql.sh || die "Installation du schéma incomplète"
  PREMIERE_INSTALL=1
fi

log "Module Demandes internes — sql/demandes_internes_mysql.sql (idempotent)"
"${MYSQL[@]}" < sql/demandes_internes_mysql.sql

# ── 4. Permissions + reload (si root) ───────────────────────
if [ "$(id -u)" = "0" ]; then
  log "chown -R $APP_USER:$APP_USER $APP_DIR"
  chown -R "$APP_USER":"$APP_USER" "$APP_DIR"
  chmod 640 "$APP_DIR/.env"
  if [ "$RELOAD_WEB" = "1" ] && command -v systemctl >/dev/null; then
    # Recharge ce qui tourne réellement : Nginx + PHP-FPM, ou Apache.
    recharge=0
    for svc in nginx apache2 $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
      if systemctl is-active --quiet "$svc"; then
        log "reload $svc"; systemctl reload "$svc" || warn "reload $svc échoué (à faire manuellement)"; recharge=1
      fi
    done
    [ "$recharge" = "1" ] || warn "Aucun serveur web actif détecté (nginx, apache2, php-fpm) — rien rechargé."
  fi
else
  warn "Non-root : chown / rechargement du serveur web ignorés (relancer avec sudo si besoin)."
fi

# ── 5. Premier compte (1re installation) ────────────────────
if [ "${PREMIERE_INSTALL:-0}" = "1" ]; then
  if [ -t 0 ]; then
    log "Création du compte super-administrateur"
    php tools/creer_superadmin.php || warn "Compte non créé — relancer : sudo -u $APP_USER php tools/creer_superadmin.php"
  else
    warn "Aucun compte : lancer  sudo -u $APP_USER php tools/creer_superadmin.php"
  fi
fi

log "✅ Déploiement terminé."
