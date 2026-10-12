# Développement local — branche `vps-mysql`

Cette branche est celle du serveur de production (MySQL 8). On y travaille
directement, dans son propre dossier (`Prod\stockapp-vps`), sans passer par
`main` : les erreurs d'octobre 2026 venaient presque toutes de requêtes
écrites pour PostgreSQL puis traduites après coup, sans être exécutées
contre un vrai MySQL.

## Le circuit

1. Modifier le code dans ce dossier.
2. Tester sur <http://localhost:8081>.
3. Lancer le contrôle complet : `bash tools/verifier_vps.sh` — il doit finir
   par « ✅ Vérification réussie ».
4. `git commit` puis `git push` (ce dossier ne pousse que vers
   `RUTHAXELLE/stockapp`, branche `vps-mysql`).
5. Sur GitHub, onglet **Actions** : le même contrôle se relance. Rouge = ne
   pas déployer.
6. Préparer la fiche de l'administrateur de base de données, qui déploie :

   ```bash
   bash tools/fiche_mise_a_jour.sh <version_en_production>
   ```

   `<version_en_production>` est la « version » de la dernière ligne de
   trace qu'il a renvoyée (`sudo tail -1 /var/log/stockapp/deploiements.txt`
   sur le serveur). La fiche — écrite dans `fiches_mise_a_jour/`, non
   versionné — donne la version cible et son contrôle GitHub, l'aperçu,
   `sudo ./deploy.sh`, les migrations à jouer dans l'ordre avec leur
   commande, les vérifications et le retour arrière. Le script refuse
   (code 2) tant que le contrôle GitHub n'est pas au vert.
7. L'administrateur déploie, puis renvoie la nouvelle ligne de trace : elle
   sert de point de départ à la fiche suivante.

## Démarrer

```bash
docker compose up -d --build
```

Première fois : environ 2 minutes. Le service `schema` installe la base avec
`sql/installer_schema_mysql.sh` — le même script qu'en production — puis
s'arrête ; `docker compose logs schema` affiche son bilan. Ensuite
<http://localhost:8081>.

Le code est monté depuis le disque : un fichier enregistré est pris en compte
au rechargement de la page. Les erreurs PHP : `docker compose logs -f app`.

Repartir d'une base neuve : `docker compose down -v && docker compose up -d`.

## Ce qui est identique à la production

| | Production | Local |
|---|---|---|
| MySQL | 8.0, mode strict | 8.0 (image `mysql:8.0`), mode strict |
| Schéma | `sql/installer_schema_mysql.sh` | le même script |
| Compte MySQL de l'application | droits sur sa seule base | idem (`stockapp`, jamais root) |
| PHP | 8.3 | 8.3 |
| Données | base vide au départ | **jeu de démonstration** (`--avec-demo`) |
| Serveur web | Nginx + PHP-FPM | serveur intégré de PHP |

La différence de serveur web ne joue que sur le blocage des fichiers
sensibles, contrôlé par la configuration Nginx du manuel de déploiement.

## Compte local

`admin@stockapp.local` / `Admin@Local1` (super-administrateur du jeu de
démonstration). Ce mot de passe est posé par `docker/schema.sh` dans la base
locale uniquement : une installation de production (`deploy.sh`, sans
`--avec-demo`) vide la table des utilisateurs.

Pour d'autres rôles, créer des comptes dans Administration → Utilisateurs.

## Modifier le schéma

Toute évolution de structure passe par une migration :

1. créer `sql/migration_<sujet>_mysql.sql`, **rejouable** (MySQL 8 n'a pas
   `ADD COLUMN IF NOT EXISTS` : passer par `information_schema` et une
   requête préparée, cf. `sql/migration_rivets_seuil_alerte_mysql.sql`) ;
2. l'ajouter à la fin de la liste `FICHIERS` de
   `sql/installer_schema_mysql.sh` — le contrôle échoue sinon ;
3. `docker compose down -v && docker compose up -d` pour l'essayer sur une
   base neuve, puis `bash tools/verifier_vps.sh`.

En production, `deploy.sh` liste les nouvelles migrations après le
`git pull` ; l'administrateur les joue (manuel, § 12).

## Ce que vérifie `tools/verifier_vps.sh`

Sur deux bases jetables (la base locale `stockapp` n'est pas touchée) :

- installation comme en production (base vide) ;
- installation avec le jeu de démonstration, puis `tools/verifier.php` :
  1. syntaxe PHP de chaque fichier, et appels à des fonctions qui
     n'existent nulle part (`notify_role()`…) — quelques défauts connus,
     listés dans `tools/verifier.php` avec leur raison, sont affichés sans
     bloquer ;
  2. restes de syntaxe PostgreSQL dans les requêtes (`::date`, `ILIKE`,
     `FILTER (WHERE …)`, `ON CONFLICT`, `||`, `~`, `CAST(… AS INTEGER)`,
     `CURRENT_DATE - 30`…) ;
  3. chaque `INSERT` confronté au schéma : table et colonnes existantes,
     colonnes obligatoires fournies ; valeurs écrites en dur comparées aux
     listes fermées (ENUM) ; chaque migration MySQL jouée par
     l'installateur ;
  4. chaque écran de `pages/` ouvert en super-administrateur, sans erreur
     fatale ni erreur SQL.

Ce contrôle ne remplace pas un essai à l'écran : il ne voit ni les
formulaires envoyés (POST), ni les écrans des autres rôles.

## Rendre une page en ligne de commande

```bash
docker compose exec -T app php tools/rendre_page.php pages/kpi_dashboard.php 1 > sortie.html
```

(le 2e argument est l'id de l'utilisateur connecté).
