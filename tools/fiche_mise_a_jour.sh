#!/usr/bin/env bash
# ============================================================
#  tools/fiche_mise_a_jour.sh — fiche de mise à jour pour l'administrateur
#
#  Produit, depuis le poste du développeur, la fiche à transmettre à
#  l'administrateur qui déploie sur le serveur de production : version
#  cible, contrôle GitHub, changements, migrations à jouer dans l'ordre
#  avec leur commande, vérifications et retour arrière.
#
#      bash tools/fiche_mise_a_jour.sh <version_en_production> [<version_cible>]
#
#  <version_en_production> : la « version » de la dernière ligne de
#  /var/log/stockapp/deploiements.txt, que l'administrateur renvoie après
#  chaque déploiement (sudo tail -1 /var/log/stockapp/deploiements.txt).
#  <version_cible> : par défaut, la dernière version poussée sur
#  origin/vps-mysql.
#
#  La fiche est écrite dans fiches_mise_a_jour/ (non versionné) et
#  affichée à l'écran.
# ============================================================
set -euo pipefail
cd "$(dirname "$0")/.."

DEPOT_GITHUB="RUTHAXELLE/stockapp"
CLIENT_CNF="/etc/mysql/stockapp-client.cnf"

[ $# -ge 1 ] || { sed -n '10,16p' "$0" | sed 's/^#  \{0,1\}//'; exit 1; }

git fetch -q origin vps-mysql
ACTUELLE="$1"
CIBLE="${2:-origin/vps-mysql}"
git cat-file -e "$ACTUELLE^{commit}" 2>/dev/null || { echo "ERREUR : version en production inconnue : $ACTUELLE" >&2; exit 1; }
git cat-file -e "$CIBLE^{commit}" 2>/dev/null || { echo "ERREUR : version cible inconnue : $CIBLE" >&2; exit 1; }
A="$(git rev-parse --short "$ACTUELLE")"
C="$(git rev-parse --short "$CIBLE")"
C_LONG="$(git rev-parse "$CIBLE")"

[ "$A" != "$C" ] || { echo "La production est déjà en $C : rien à déployer."; exit 0; }
git merge-base --is-ancestor "$ACTUELLE" "$CIBLE" \
    || { echo "ERREUR : $C ne descend pas de $A (historique divergent) — vérifier les versions." >&2; exit 1; }
git merge-base --is-ancestor "$CIBLE" origin/vps-mysql \
    || { echo "ERREUR : $C n'est pas poussée sur origin/vps-mysql — le serveur ne pourra pas la récupérer." >&2; exit 1; }

# ── Contrôle GitHub de la version cible ─────────────────────
ETAT="inconnu (vérifier sur GitHub)"; BLOQUANT=0
if json="$(curl -fsS "https://api.github.com/repos/$DEPOT_GITHUB/actions/workflows/verif-vps.yml/runs?head_sha=$C_LONG&per_page=1" 2>/dev/null)"; then
    statut="$(printf '%s' "$json" | grep -m1 '"status":' | sed 's/.*: *"\([^"]*\)".*/\1/' || true)"
    conclusion="$(printf '%s' "$json" | grep -m1 '"conclusion":' | sed 's/.*: *"\{0,1\}\([^",]*\)"\{0,1\}.*/\1/' || true)"
    case "$statut/$conclusion" in
        completed/success) ETAT="✅ au vert" ;;
        completed/*)       ETAT="❌ en échec ($conclusion)"; BLOQUANT=1 ;;
        /)                 ETAT="aucun contrôle trouvé pour $C"; BLOQUANT=1 ;;
        *)                 ETAT="⏳ en cours ($statut) — attendre le vert avant d'envoyer la fiche"; BLOQUANT=1 ;;
    esac
fi

# ── Migrations à jouer, dans l'ordre de l'installateur ──────
ORDRE="$(sed -n '/^FICHIERS="/,/"$/p' sql/installer_schema_mysql.sh | tr -d '"' | sed 's/FICHIERS=//' | tr ' ' '\n' | grep -v '^$')"
mapfile -t AJOUTEES < <(git diff --no-renames --name-only --diff-filter=A "$A" "$C" -- 'sql/*_mysql.sql' \
                        | grep -v 'demandes_internes_mysql.sql' | xargs -r -n1 basename || true)
MIGRATIONS=()
while read -r f; do
    for x in "${AJOUTEES[@]:-}"; do [ "$x" = "$f" ] && MIGRATIONS+=("$f"); done
done <<< "$ORDRE"
for x in "${AJOUTEES[@]:-}"; do        # absentes de l'installateur : en dernier
    [ -n "$x" ] || continue
    printf '%s\n' "${MIGRATIONS[@]:-}" | grep -qxF "$x" || MIGRATIONS+=("$x")
done
# Le serveur exécute le deploy.sh de la version en production : la trace
# des déploiements n'existe que si cette version-là l'avait déjà.
TRACE=0
git show "$A:deploy.sh" 2>/dev/null | grep -q 'deploiements.txt' && TRACE=1
TRACE_CIBLE=0
git show "$C:deploy.sh" 2>/dev/null | grep -q 'deploiements.txt' && TRACE_CIBLE=1

resume_migration() {   # 1re ligne utile de l'en-tête du fichier, à la version cible
    local l
    l="$(git show "$C:sql/$1" | grep -m1 -E '^-- +Migration MySQL *:' | sed 's/^-- *Migration MySQL *: *//' || true)"
    [ -n "$l" ] || l="$(git show "$C:sql/$1" | grep -m1 -E '^-- +[[:alpha:]]' | sed 's/^-- *//' || true)"
    [ -n "$l" ] || l="voir l'en-tête du fichier"
    printf '%s' "$l"
}

# ── Fiche ───────────────────────────────────────────────────
mkdir -p fiches_mise_a_jour
FICHE="fiches_mise_a_jour/fiche_${C}_$(date +%Y%m%d).md"
{
echo "# Mise à jour ERP EMUCI — serveur de production"
echo
echo "- **Date de la fiche :** $(date '+%d/%m/%Y')"
echo "- **Demandeur :** $(git config fiche.demandeur || git config user.name || echo '?')"
echo "- **Version en production :** \`$A\` — $(git log -1 --format='%s' "$A" | cut -c1-90)"
echo "- **Version à déployer :** \`$C\` — $(git log -1 --format='%s (%cd)' --date=format:'%d/%m/%Y' "$C" | cut -c1-110)"
echo "- **Contrôle automatique GitHub :** $ETAT — https://github.com/$DEPOT_GITHUB/actions/workflows/verif-vps.yml"
if [ "$BLOQUANT" = 1 ]; then
    echo
    echo "> ⚠️ **Ne pas déployer** tant que le contrôle GitHub de \`$C\` n'est pas au vert."
fi
echo
echo "## 1. Aperçu (ne modifie rien)"
echo
echo '```bash'
echo "cd /var/www/stockapp && sudo git fetch -q origin vps-mysql && sudo git log --oneline HEAD..$C"
echo '```'
echo
echo "La première ligne affichée doit être \`$C\`. Si la commande n'affiche rien ou une autre version, ne pas continuer et prévenir le demandeur."
echo
echo "## 2. Déploiement"
echo
echo '```bash'
echo "cd /var/www/stockapp && sudo ./deploy.sh"
echo '```'
echo
if [ "$TRACE" = 1 ]; then
    echo "Le script sauvegarde la base, met à jour le code et recharge le serveur web. Il doit finir par « Version déployée : $C »."
else
    echo "Le script sauvegarde la base, met à jour le code et recharge le serveur web. Il doit finir par « ✅ Déploiement terminé »."
fi
echo
echo "## 3. Migrations à jouer (${#MIGRATIONS[@]})"
echo
if [ "${#MIGRATIONS[@]}" -eq 0 ]; then
    echo "Aucune."
else
    echo "Dans cet ordre, **après** le déploiement. Elles sont rejouables : déjà appliquée, une migration ne change rien (lignes « … déjà présente »)."
    i=0
    for m in "${MIGRATIONS[@]}"; do
        i=$((i + 1))
        echo
        echo "$i. **\`sql/$m\`** — $(resume_migration "$m")"
        echo
        echo '   ```bash'
        echo "   sudo mysql --defaults-file=$CLIENT_CNF stockapp < sql/$m"
        echo '   ```'
    done
fi
echo
echo "## 4. Vérifications"
echo
echo "- \`cd /var/www/stockapp && sudo git log --oneline -1\` doit afficher \`$C\`."
if [ "$TRACE" = 1 ]; then
    echo "- \`sudo tail -1 /var/log/stockapp/deploiements.txt\` : la ligne doit indiquer \`version $C\`. **Renvoyer cette ligne au demandeur.**"
else
    echo "- **Renvoyer au demandeur la ligne affichée par \`git log --oneline -1\`.**"
    [ "$TRACE_CIBLE" = 1 ] && echo "  (La trace \`/var/log/stockapp/deploiements.txt\` arrive avec cette mise à jour : elle sera remplie à partir du déploiement suivant.)"
fi
echo "- Se connecter sur https://erp.digimmat.ci et ouvrir l'accueil : pas de page d'erreur."
echo
echo "## 5. Retour arrière (en cas de problème)"
echo
echo '```bash'
echo "cd /var/www/stockapp && sudo git checkout $A"
echo '```'
echo
echo "Puis restaurer la sauvegarde indiquée dans la ligne de \`deploiements.txt\` (manuel de déploiement, § 13 et § 14), et prévenir le demandeur."
echo
echo "## Changements inclus ($(git rev-list --count "$A..$C") commit(s))"
echo
git log --reverse --format='- `%h` %s' "$A..$C"
echo
echo "Fichiers modifiés : $(git diff --no-renames --shortstat "$A" "$C" | sed 's/^ //')."
} > "$FICHE"

cat "$FICHE"
echo
echo "──────── Fiche enregistrée : $FICHE"
[ "$BLOQUANT" = 0 ] || { echo "⚠️  Contrôle GitHub pas au vert : ne pas envoyer la fiche pour l'instant."; exit 2; }
