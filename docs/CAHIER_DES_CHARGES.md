# Cahier des charges — ERP EMUCI

**Version du logiciel** : branche `main`, commit `437102d` (21 septembre 2026)
**Version du cahier des charges** : 1.0
**Objet** : établir ce que le système doit faire, pour qui, sous quelles
contraintes, et à quoi se mesure qu'il le fait.

> **Provenance.** Ce document est établi par lecture du code source, du
> schéma de base et de la configuration des menus. Il décrit le besoin **tel
> que le produit l'implémente aujourd'hui**, et signale explicitement les
> écarts entre l'intention exprimée dans le code et ce qui est réellement en
> place.
>
> Le document jumeau, `SPECIFICATION.md`, décrit *comment* le système est
> construit. Celui-ci décrit *ce qui est attendu de lui*. Lorsqu'une exigence
> n'est pas satisfaite en l'état, elle est marquée **⚠ écart**.

---

## Sommaire

1. [Contexte et enjeux](#1-contexte-et-enjeux)
2. [Objectifs](#2-objectifs)
3. [Périmètre fonctionnel](#3-périmètre-fonctionnel)
4. [Acteurs et habilitations](#4-acteurs-et-habilitations)
5. [Exigences fonctionnelles](#5-exigences-fonctionnelles)
6. [Exigences non fonctionnelles](#6-exigences-non-fonctionnelles)
7. [Contraintes d'architecture et d'exploitation](#7-contraintes-darchitecture-et-dexploitation)
8. [Données de référence](#8-données-de-référence)
9. [Règles de gestion transverses](#9-règles-de-gestion-transverses)
10. [Critères d'acceptation](#10-critères-dacceptation)
11. [Hors périmètre](#11-hors-périmètre)
12. [Écarts connus et points ouverts](#12-écarts-connus-et-points-ouverts)

---

## 1. Contexte et enjeux

EMU-CI intervient dans le projet **NSIIV** (Nouveau Système d'Immatriculation
des Véhicules). L'activité consiste à poser des plaques d'immatriculation sur
**21 sites** répartis sur le territoire, à partir de consommables — bobines de
film, rivets, PMMA — distribués depuis un stock central, et d'un parc
d'équipements (informatique et opérationnel) affecté aux sites.

Trois difficultés justifient l'outil :

**La donnée de production est produite sur le terrain et consommée au siège.**
Chaque site saisit son activité quotidienne (véhicules traités, plaques posées,
films et rivets consommés). Ces chiffres alimentent la facturation, le
réapprovisionnement et le pilotage. Sans outil, ils circulent par tableur et
par téléphone, sans traçabilité ni contrôle de cohérence.

**Le stock réel diverge du stock théorique.** Un film endommagé, une bobine
mal décomptée, un rivet perdu : l'écart se constate des semaines plus tard,
quand il est trop tard pour l'expliquer. Il faut un mécanisme d'inventaire
périodique qui fige le stock système, le confronte au stock physique, et
conserve l'écart comme objet de suivi.

**Les engagements de dépense ne sont pas tracés.** L'expression de besoin, la
validation hiérarchique, le contrôle budgétaire et la réception se font hors
système. Il faut un circuit d'achat où chaque étape laisse une trace datée et
nominative.

---

## 2. Objectifs

| N° | Objectif | Mesure |
|---|---|---|
| **O1** | Saisir la production quotidienne à la source, par les coordinateurs de site | Le point journalier du jour est saisi et validé pour chaque site actif |
| **O2** | Rendre le stock vérifiable | Un inventaire périodique est ouvert, saisi et clôturé, et chaque écart constaté porte un statut |
| **O3** | Tracer les demandes internes de bout en bout | Toute demande porte un circuit de validation résolu, daté et nominatif |
| **O4** | Encadrer l'engagement de dépense | Toute FEB suit un circuit de visas déterminé par son montant, avec contrôle budgétaire |
| **O5** | Donner à chaque métier une vue adaptée à son périmètre | Aucun rôle ne voit de données hors de son périmètre, aucun ne rencontre de 403 sur son parcours normal |
| **O6** | Conserver une trace opposable | Toute action modifiante est journalisée avec auteur, date et valeurs |

---

## 3. Périmètre fonctionnel

Le produit est organisé en **dix domaines**, qui correspondent aux dix groupes
de navigation (`includes/groupes_config.php`).

| Domaine | Objet | Écrans principaux |
|---|---|---|
| **Tableau de bord** | Synthèse adaptée au rôle, vue exécutive | `dashboard`, `pdg_overview` |
| **Stock** | Parc équipements (IT et opérationnel), articles, consommables, PMMA, bobines, vignettes, rivets, commandes | `equipements`, `articles`, `consommables`, `pmma`, `commandes`, `mouvements_equipements` |
| **Bobines** | Cycle de vie du film : commande, validation du stock du matin, rapports, vue par site | `commandes_bobines`, `validation_stock_matin`, `rapports_gsb`, `stock_bobines_vue` |
| **Inventaire** | Inventaire périodique et écarts, sur quatre natures de stock | `inventaire_sessions`, `inventaire_{bobines,rivets,pmma,equipements}`, `ecarts_*` |
| **Opérations** | Point journalier, demande d'intervention, données EMUCI | `operations/point_journalier`, `point_emuci`, `import_emuci` |
| **Informatique** | Interventions, rapport journalier IT, affectation des sous-rôles Support IT, transfert d'équipement | `interventions`, `rapport_journalier`, `affectations_it`, `affectations` |
| **Rapports** | Résumé superviseur, rapports généraux, exports | `resume_superviseur`, `rapports`, `export` |
| **Demandes internes** | Demandes transverses avec circuit de validation | `demandes`, `demandes_new`, `demandes_a_valider`, `demandes_it`, `agents` |
| **Achats** | Expression de besoin (FEB), visas, suivi DA/BC, réceptions, paramétrage | `achats/*` (20 écrans) |
| **Administration** | Utilisateurs, permissions, nomenclatures, audit, délégations, sites, départements | `admin/*` |

**Volumétrie de référence** : 76 écrans sous `pages/`, 6 points d'entrée à la
racine, 106 tables, 16 rôles, 21 sites actifs, et 42 identifiants de module
contrôlés dans le code — dont 37 administrables depuis l'interface, 4
modifiables seulement par migration, et 1 (`inventaire_sessions`) résolu par
délégation nominative sans passer par la table des permissions.

---

## 4. Acteurs et habilitations

### 4.1 Les seize rôles

| Rôle (slug) | Périmètre attendu |
|---|---|
| `superadmin` | Accès total, y compris lecture de tous les audits |
| `admin` | Gestion utilisateurs, équipements, sites, permissions |
| `lecteur` | Consultation. **Libellé affiché : « PDG »** — le slug n'a pas été renommé |
| `gestionnaire_stock` | Stock central : entrées, sorties, livraisons aux sites |
| `coordinateur_site` | Son site uniquement : point journalier, réception, réponse aux corrections |
| `maintenance_info` | Parc informatique. **Rôle conservé sans compte rattaché** pour la lisibilité de l'audit |
| `superviseur_operation` | Supervision des coordinateurs de site |
| `controleur_production` | Saisie quotidienne des plaques posées et réservées (données EMUCI) |
| `gestionnaire_stock_bobines` | Validation du stock du matin, demandes bobines, réajustements |
| `gestionnaire_operation` | Second du superviseur opération, destinataire des délégations |
| `superviseur_it` | Informatique complet, supervise les Support IT |
| `support_it` | Profil à sous-rôles (voir 4.2) |
| `superviseur_achat` | Consommables, équipements, consommation des sites, circuit achat |
| `raf` | Visa financier — responsable administratif et financier |
| `daf` | Visa financier — directeur administratif et financier |
| `directeur_general` | Visa de direction |

### 4.2 Sous-rôles Support IT

Le rôle `support_it` n'ouvre aucun accès par lui-même : les droits viennent
des **sous-rôles actifs** portés par la table `support_it_roles`.

| Sous-rôle | Modules ouverts |
|---|---|
| `maintenance` | interventions, équipements, sites |
| `controleur_production` | import EMUCI, point EMUCI, équipements, sites |
| `gestionnaire_bobines` | bobines, inventaire bobines, équipements, sites |

**EF-HAB-1** — Un compte `support_it` sans sous-rôle actif n'a accès à rien.
C'est le comportement voulu, mais il rend la bascule d'un compte vers ce
profil risquée : si le sous-rôle n'est pas posé dans le même mouvement, le
compte perd tout accès sans message explicite.

**EF-HAB-2** — Le module `demandes` échappe à ce filtrage : il est transverse
(« tous les employés ») et s'évalue directement sur la table `permissions`,
quel que soit le sous-rôle actif.

### 4.3 Règles d'habilitation

**EF-HAB-3** — Les droits sont stockés en base, par couple (rôle, module),
avec cinq actions : `can_read`, `can_create`, `can_update`, `can_delete`,
`can_export`.

**EF-HAB-4** — `admin` et `superadmin` court-circuitent la table : ils
obtiennent tout sans ligne de permission.

**EF-HAB-5** — Un module absent de la table vaut **refus** pour l'accès aux
pages. Conséquence directe : livrer un écran sans livrer sa ligne de
permission le rend invisible à tous sauf aux deux profils d'administration.
Ce cas s'est produit en septembre 2026 sur les six écrans Inventaire/Écarts,
corrigé depuis.

**EF-HAB-5 bis** — Une exception, et une seule : un rôle **sans aucune
permission déclarée** voit tous les blocs du tableau de bord. Ce repli ne
vaut **que** pour le tableau de bord — les pages restent refusées. Un rôle
non paramétré présente donc un accueil complet et n'accède à rien : c'est
l'accès à la page qui fait foi.

**EF-HAB-5 ter** — Trois accès échappent à la matrice par conception :

| Accès | Mécanisme |
|---|---|
| Sessions d'inventaire | Réservé à l'administration, sauf **délégation nominative** ; le module n'existe jamais dans la table des permissions |
| Lecture des commandes par le N+1 d'un gestionnaire de stock | Accordé à la **personne** identifiée comme N+1, pas à un rôle — sinon tous les porteurs du rôle l'obtiendraient |
| Gestionnaire opération hors modules délégués | **Lecture seule** : tout droit d'écriture est refusé sans consulter la table |

**EF-HAB-6** — Le contrôle doit être posé **à chaque action**, pas seulement à
l'ouverture de l'écran. Un contrôle limité à la page laisse passer les appels
directs sur les actions AJAX du même fichier.

**EF-HAB-7** — Les portées implicites (un coordinateur ne voit que son site)
sont appliquées par filtrage SQL, pas par la table de permissions.

**EF-HAB-8** — Une délégation nominative (table `delegations`) peut ouvrir un
module à un compte précis, sans modifier le rôle.

---

## 5. Exigences fonctionnelles

### 5.1 Opérations — le point journalier

**EF-OP-1** — Un coordinateur de site saisit, pour son site et pour une date,
un point journalier comprenant : véhicules par type (VP, camion, semi, moto),
plaques posées, films consommés par bobine, rivets utilisés/endommagés
(gonflables et éclatés distingués), PMMA utilisés, non-posés (concessionnaires
et usagers), heures de travail, et observations typées.

**EF-OP-2** — Les observations sont une **liste de lignes typées** (info,
alerte, relance, incident, urgence, autre), pas un champ libre. L'ancien format
texte reste lisible pour les points antérieurs.

**EF-OP-3** — Le point suit le cycle : `brouillon` → `en_attente_validation` →
`valide`, avec `rejete` (motif obligatoire) et `suivi` comme états
complémentaires.

**EF-OP-4** — Un point vide ne peut pas être soumis : la soumission vérifie
qu'au moins une ligne de film est saisie.

**EF-OP-5** — La reprise d'un brouillon doit restaurer **tous** les champs
saisis, y compris les lignes de bobines et de rivets.

**EF-OP-6** — La modification d'un point déjà enregistré ne doit pas déduire le
stock deux fois : le stock des films est restauré avant réécriture des lignes.

**EF-OP-7** — Un point validé ne se modifie pas directement. Le gestionnaire
stock bobines ouvre une **demande de correction** ; le coordinateur répond ; la
correction est tracée séparément (`correction_gp`, `motif_correction_gp`,
`corrected_by_gp`, `corrected_at`).

**EF-OP-8** — Le point journalier est exportable en PDF. **Un brouillon n'est
consultable que par son auteur.**

**EF-OP-9** — Les données EMUCI (plaques posées, réservées) sont importées par
fichier (OptoPlate en CSV, OptoTrace en XLSX) et rapprochées de la saisie
terrain ; les sites inconnus de l'import sont isolés pour rattachement manuel.

### 5.2 Stock bobines

**EF-BOB-1** — Une bobine est un objet individuel identifié par son numéro,
porteur d'un type, d'une série, d'un stock système et d'un stock temps réel.

**EF-BOB-2** — Le gestionnaire stock bobines valide chaque matin le stock
déclaré par site. Quatre issues : `Conforme`, `Avec écart`, `Réajusté`,
`Bloqué`.

**EF-BOB-3** — En cas d'écart, le gestionnaire ouvre une demande de correction
adressée au coordinateur, qui répond dans l'outil.

**EF-BOB-4** — Les rapports GSB produisent un export Excel à trois feuilles et
un export PDF.

**EF-BOB-5** — La vue « stock par site » est exportable en XLSX et en PPTX.

### 5.3 Inventaire et écarts

**EF-INV-1** — L'administration ouvre une **session d'inventaire** portant une
périodicité (`mensuel`, `trimestriel`, `semestriel`, `annuel`) et une date de
début. **La date de fin est déduite de la périodicité, jamais saisie
librement.** Le libellé est calculé si l'administrateur n'en fournit pas.

**EF-INV-2** — Une session porte une liste de sites (`inventaire_session_sites`)
et passe de `ouverte` à `cloturee`.

**EF-INV-3** — L'ouverture d'une session **provisionne automatiquement**
l'inventaire de chaque site rattaché, sur quatre natures de stock :

| Nature | Unité inventoriée | Source du stock système |
|---|---|---|
| Bobines | La bobine, objet individuel | `op_bobines.stock_systeme` |
| Rivets | Le type de rivet, quantité agrégée par site | `op_stock_rivets` |
| PMMA | Le type de PMMA, quantité agrégée par site | `stock_pmma_site` |
| Équipements | L'équipement, **sans quantité** — présence à cocher | `equipements` actifs du site |

**EF-INV-4** — Chaque ligne d'inventaire porte l'**écart déjà ouvert avant
l'inventaire** (`ecart_connu_avant`), afin de ne pas recompter deux fois un
écart déjà constaté et en cours de traitement.

**EF-INV-5** — Pour les bobines, l'inventaire calcule en plus la consommation
quotidienne moyenne sur 30 jours et l'écart entre la saisie terrain et les
données EMUCI du jour.

**EF-INV-6** — Un inventaire ne peut pas être créé deux fois pour le même site,
la même date et la même périodicité.

**EF-INV-7** — Un inventaire sans objet à compter (site sans bobine active,
sans stock de rivets, sans PMMA, sans équipement affecté) est refusé avec un
message explicite plutôt que créé vide.

**EF-INV-8** — Tout écart constaté devient un objet de suivi (`ecarts_bobines`,
`ecarts_rivets`, `ecarts_pmma`, `ecarts_equipements`) avec un statut `ouvert`
puis `resolu`.

**EF-INV-9** — Les corrections d'inventaire suivent un circuit d'autorisation :
`en_attente` → `autorise` ou `refuse` → `traite`.

**EF-INV-10** — La création d'un inventaire journalier par un coordinateur de
site n'est plus possible : elle dépend de la session ouverte par
l'administrateur.

### 5.4 Demandes internes

**EF-DEM-1** — Une demande porte un type, un demandeur, une plateforme, et un
circuit de validation.

**EF-DEM-2** — Le **circuit est figé sur la demande au moment du dépôt**
(`di_etapes`), et non lu dynamiquement : modifier un type de demande ne
change pas les demandes déjà en cours.

**EF-DEM-3** — Les quatre écrans du domaine (mes demandes, nouvelle demande, à
valider, traitements IT) sont protégés par le module `demandes` :
`can_read` pour la consultation, `can_create` pour le dépôt.

**EF-DEM-4** — Le filtrage fin par rôle et circuit (`di_user_roles`) reste
appliqué **en plus** du contrôle de module : `can_read` sur `demandes` est un
interrupteur général, pas un remplacement de la logique de circuit.

**EF-DEM-5** — Un annuaire d'agents alimente les demandes ; il accepte l'import
Excel et la saisie manuelle.

### 5.5 Achats — la FEB

**EF-ACH-1** — Une FEB (fiche d'expression de besoin) porte des lignes, des
pièces jointes, un montant, un code analytique et une urgence
(normale/urgente/critique). Le numéro est attribué automatiquement par
exercice.

**EF-ACH-2** — Le cycle comprend **huit statuts** :

| Statut | Signification |
|---|---|
| `brouillon` | En rédaction par le demandeur |
| `en_attente_n1` | Attend l'aval du supérieur hiérarchique |
| `soumise` | En file d'attente, aucun acheteur attribué |
| `prise_en_charge` | Un acheteur se l'est attribuée |
| `en_validation` | Dans le circuit de visas, montants verrouillés |
| `confirmee` | Validée, prête à commander |
| `cloturee` | Terminée |
| `rejetee` | Refusée à une étape, avec motif obligatoire |

**EF-ACH-3** — Le **circuit de visas est déterminé par le montant**, via des
paliers paramétrables (`achat_paliers`) portant la liste ordonnée des rôles
signataires.

**EF-ACH-4** — Le circuit et le palier sont **figés au lancement de la
validation** (`workflow_snapshot`) : une modification ultérieure du paramétrage
ne rejoue pas les visas déjà engagés.

**EF-ACH-5** — Les montants sont verrouillés à l'entrée en validation.

**EF-ACH-6** — Le contrôle budgétaire s'appuie sur des lignes budgétaires par
exercice, avec un comportement paramétrable en cas de dépassement :
`aucun`, `alerte`, `blocage`.

**EF-ACH-7** — Les offres fournisseurs sont comparables par lot ; la conformité
du fournisseur est vérifiée.

**EF-ACH-8** — La réception peut être partielle : les reliquats sont suivis, et
l'entrée en stock se fait par département.

**EF-ACH-9** — Les équipements achetés sont mis en attente d'affectation puis
rattachés à un site ou à un utilisateur.

**EF-ACH-10** — Trois retours en arrière sont prévus dans le circuit ; chacun
exige un motif.

**EF-ACH-11** — La fiche FEB et la fiche de validation sont exportables en PDF.

### 5.6 Informatique

**EF-IT-1** — Une intervention est préventive ou curative, portée par un
technicien, rattachée à un site et éventuellement à un équipement.

**EF-IT-2** — Un coordinateur peut signaler une panne (`demande_coordinateur`),
ce qui notifie les profils de maintenance. **Cette action exige le droit de
création sur `interventions`.**

**EF-IT-3** — Le transfert d'équipement entre sites et le retour en stock sont
tracés dans un historique des mouvements, séparé de l'écran d'affectation.

**EF-IT-4** — Les sous-rôles Support IT s'affectent depuis un écran dédié.
**L'affectation et la promotion exigent le droit de modification sur
`affectations_it`.**

### 5.7 Administration

**EF-ADM-1** — La matrice rôle × module est éditable depuis l'interface.

**EF-ADM-2** — La liste des modules envoyée au serveur lors de la sauvegarde
doit être **dérivée de la liste affichée**, et non recopiée à la main : une
liste recopiée se désynchronise et remet silencieusement à zéro les droits des
modules oubliés.

**EF-ADM-3** — Le journal d'audit conserve auteur, action, module, entité,
description, valeurs avant/après, adresse IP et horodatage.

**EF-ADM-4** — La création d'un utilisateur impose une validation stricte des
champs et un changement de mot de passe à la première connexion.

---

## 6. Exigences non fonctionnelles

### 6.1 Sécurité

**EN-SEC-1** — Les mots de passe sont hachés en bcrypt, coût 12. Longueur
minimale : 8 caractères.

**EN-SEC-2** — Après **cinq échecs consécutifs**, le compte est verrouillé
**quinze minutes**. Le compteur est remis à zéro à la connexion réussie et à
tout changement de mot de passe — sans quoi une réinitialisation par
l'administrateur laisserait le compte bloqué.

**EN-SEC-3** — La session expire après **quinze minutes d'inactivité**, contrôle
posé côté serveur et tracé dans l'audit.

**EN-SEC-4** — Le cookie de session est `HttpOnly`, `SameSite=Lax`, et `Secure`
dès que la requête est servie en HTTPS. **Derrière un proxy qui termine le TLS,
la détection doit s'appuyer sur `X-Forwarded-Proto`** : sans cela, l'attribut
`Secure` n'est jamais posé en production.

**EN-SEC-5** — Les en-têtes suivants sont posés sur toute réponse :
`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
`Referrer-Policy: strict-origin-when-cross-origin`, et
`Strict-Transport-Security` en HTTPS. L'en-tête `X-Powered-By` est retiré.

**EN-SEC-6** — Aucune superglobale n'est interpolée dans une requête SQL.

**EN-SEC-7** — Toute donnée rendue en HTML passe par l'échappement.

**EN-SEC-8** — Les téléversements sont limités à 10 Mo et restreints aux types
PDF, JPEG, PNG et WebP, **vérifiés sur le contenu** et non sur l'extension.

**EN-SEC-9** — Les dépendances sont auditées automatiquement à chaque envoi sur
la branche principale (analyse statique Semgrep + audit Composer).

**EN-SEC-10 ⚠ écart** — **Aucune protection CSRF applicative.** La protection
repose entièrement sur `SameSite=Lax`, donc sur le comportement du navigateur.
C'est la dette de sécurité la plus nette du produit.

**EN-SEC-11 ⚠ écart** — Le mot de passe administrateur par défaut et l'ancien
mot de passe de base exposé dans l'historique Git restent à traiter.

### 6.2 Exploitation

**EN-EXP-1** — Deux environnements distincts :

| Environnement | Pile | Rôle |
|---|---|---|
| **Recette** | Render + PostgreSQL (Neon), déploiement automatique depuis `main` | Pré-lancement, audit, répétition |
| **Production (cible)** | VPS dédié : Apache, PHP 8.4, MySQL 8, branche `vps-mysql` | Exploitation réelle |

**EN-EXP-2** — La base de production est **créée par le gestionnaire de base de
données**, distincte de la base de recette. Les données de recette y sont
migrées par un script dédié (PostgreSQL → MySQL).

**EN-EXP-3** — Une procédure de sauvegarde **éprouvée par une restauration
réelle** doit exister avant l'ouverture aux utilisateurs.

**EN-EXP-4** — Les évolutions de schéma sont des fichiers SQL joués
manuellement. Ils doivent être **idempotents** : rejouer un fichier ne change
rien de plus.

**EN-EXP-5 ⚠ écart** — Aucune table ne trace les migrations appliquées. L'état
de la base ne se déduit que par inspection de sa structure. Un outil
d'inventaire existe (`tools/inventaire_migrations.php`) ; la table de suivi
reste à créer.

**EN-EXP-6** — Les limites de téléversement de PHP doivent être relevées au
niveau du serveur (50 Mo) : les valeurs par défaut rejetaient silencieusement
les fichiers d'import EMUCI.

### 6.3 Ergonomie et accessibilité

**EN-ERG-1** — L'application est utilisée **sur téléphone par les coordinateurs
de site**, qui saisissent sur le terrain.

**EN-ERG-2 ⚠ écart** — Onze pages débordent horizontalement à 375 px de large,
et des champs en fenêtre modale n'ont pas de libellé accessible. Le parcours du
point journalier est prioritaire.

**EN-ERG-3** — Les filtres des listes longues fonctionnent sans rechargement de
page.

### 6.4 Traçabilité

**EN-TRA-1** — Toute action modifiante est journalisée.

**EN-TRA-2** — Les rôles supprimés ou fusionnés sont **conservés en base sans
compte rattaché** lorsque le journal d'audit y fait référence : les supprimer
rendrait l'historique illisible.

---

## 7. Contraintes d'architecture et d'exploitation

| Contrainte | Portée |
|---|---|
| **PHP 8.2, sans framework** | Pas de routeur, pas d'ORM, pas d'injection de dépendances. Chaque écran est un fichier autonome qui inclut ses dépendances. |
| **Deux moteurs de base** | PostgreSQL en recette, MySQL en production cible. Toute requête doit être écrite ou traduite pour les deux. |
| **Accès base centralisé** | Uniquement par les aides `db_*`, jamais PDO directement. |
| **Configuration par variables d'environnement** | Aucun secret dans le dépôt. |
| **Exports** | Excel via PhpSpreadsheet, PDF via Dompdf, PPTX via ZipArchive natif. |

---

## 8. Données de référence

**106 tables**, regroupées par domaine :

| Domaine | Tables principales |
|---|---|
| Comptes et droits | `users`, `roles`, `permissions`, `support_it_roles`, `delegations`, `sessions`, `audit_log` |
| Organisation | `sites`, `departements`, `user_departements`, `configurations_site`, `agents` |
| Opérations | `op_points_journaliers`, `op_films_utilises`, `op_pmma_utilises`, `op_types_vehicule`, `points_emuci`, `import_optoplate`, `import_optotrace`, `import_sessions_emuci`, `emuci_sites_inconnus` |
| Bobines | `op_bobines`, `op_types_bobines`, `mouvements_bobines`, `consommations_bobines`, `commandes_bobines`, `demandes_bobines`, `corrections_bobines`, `validations_stock_matin`, `comparaisons_stock` |
| Rivets et PMMA | `op_stock_rivets`, `stock_pmma`, `stock_pmma_site` |
| Inventaire | `inventaire_sessions`, `inventaire_session_sites`, `inventaires_{bobines,rivets,pmma,equipements}`, `inventaire_details_*`, `inventaire_corrections_*`, `ecarts_{bobines,rivets,pmma,equipements}` |
| Parc | `equipements`, `equipement_affectations`, `mouvements_equipements`, `affectations_equipements`, `nomenclatures`, `nomenclature_liens`, `interventions_maintenance` |
| Stock général | `articles`, `consommables`, `stock_site`, `stock_consommables_site`, `stock_fin_mois`, `mouvements_stock`, `commandes`, `commande_lignes`, `commande_compteurs`, `distributions_site`, `distribution_lignes`, `receptions_site`, `receptions_consommables`, `receptions_fournisseur`, `reception_lignes`, `livraisons_consommables`, `litige_messages` |
| Demandes internes | `di_demandes`, `di_etapes`, `di_types`, `di_roles`, `di_user_roles`, `di_plateformes` |
| Achats | `feb`, `feb_lignes`, `feb_offres`, `feb_pieces_jointes`, `feb_receptions`, `feb_receptions_departement`, `feb_expeditions`, `feb_suivi`, `feb_compteurs`, `fournisseurs`, `familles_achat`, `achat_types`, `achat_paliers`, `achat_parametres`, `lignes_budgetaires`, `budget_validations`, `stock_departement` |
| Transverse | `notifications`, `rapports_journaliers_info`, `points_journaliers_info`, `bilans_mensuels_bobines` |

---

## 9. Règles de gestion transverses

**RG-1 — Les compteurs sont atomiques.** Tout numéro de document (commande,
FEB) est attribué par incrément sous verrou de ligne, jamais par tirage
aléatoire : un tirage entre en collision avec l'index d'unicité et remonte une
erreur brute à l'utilisateur.

**RG-2 — Trois notions de stock coexistent** et ne doivent pas être
confondues : stock système (théorique, tenu par les mouvements), stock temps
réel (recalculé), stock physique (constaté à l'inventaire).

**RG-3 — Un écart connu ne se recompte pas.** Un écart déjà ouvert est reporté
sur la ligne d'inventaire suivante comme antériorité.

**RG-4 — Une valeur de référence figée sur un document ne se relit pas.**
Circuit de demande, palier de visa, montant en validation : ce qui est figé au
lancement reste figé.

**RG-5 — Les notifications utilisent un type contraint** parmi `fin_cycle`,
`stock_bas`, `alerte_conso`, `info`.

**RG-6 — Toute migration de permissions est additive par défaut.** Elle relève
un droit sans jamais en retirer, sauf intention explicite et documentée.

---

## 10. Critères d'acceptation

La mise en service est acceptée lorsque les conditions suivantes sont réunies.

| N° | Critère | Vérification |
|---|---|---|
| **CA-1** | Chaque rôle parcourt son périmètre sans 403 ni bloc vide | Un compte par rôle, parcours complet |
| **CA-2** | Le point journalier du jour est saisi par les sites et validé par le gestionnaire stock bobines | Donnée du jour entrée et ressortie validée |
| **CA-3** | Une session d'inventaire s'ouvre, se saisit et se clôture sur les quatre natures | Sur au moins un site pilote |
| **CA-4** | Une demande interne parcourt son circuit jusqu'à la clôture | Un circuit à plusieurs étapes |
| **CA-5** | Une FEB parcourt le circuit jusqu'à la réception, visas compris | Montant déclenchant au moins deux visas |
| **CA-6** | Les exports Excel et PDF s'ouvrent sans erreur | Rapports GSB, fiche FEB, point journalier |
| **CA-7** | Une sauvegarde a été restaurée pour de vrai | Base jetable, comptages comparés |
| **CA-8** | Aucun secret actif ne subsiste dans le dépôt | Mot de passe admin changé, ancien secret révoqué |
| **CA-9** | La saisie du point journalier est utilisable sur téléphone | Parcours complet à 375 px |
| **CA-10** | Chaque parcours est signé par un utilisateur de son métier | Quatre signataires désignés nominativement |

---

## 11. Hors périmètre

| Sujet | Raison |
|---|---|
| Référentiel de marques d'équipement | En attente d'arbitrage depuis août 2026 ; sans cette décision, la tâche ne peut pas être faite correctement |
| Reprise de l'historique antérieur à l'outil | Non demandée |
| Interface avec un système comptable tiers | Les références Sage sont saisies, non interfacées |
| Application mobile native | Le besoin mobile est couvert par le navigateur |

---

## 12. Écarts connus et points ouverts

### 12.1 Écarts entre l'exigence et l'implémentation

| Réf. | Écart | Gravité |
|---|---|---|
| EN-SEC-10 | Aucune protection CSRF applicative | Élevée |
| EN-SEC-11 | Mot de passe admin par défaut, ancien secret dans l'historique Git | Élevée |
| EF-ADM-1 | **Les quatre modules Achats (`achats`, `achats_dashboard`, `achats_param`, `achats_suivi`) protègent des écrans sans figurer dans la matrice Admin → Permissions** : leurs droits ne sont administrables que par migration SQL. La branche `vps-mysql` les expose déjà, `main` non — les deux divergent | Moyenne |
| EF-HAB-5 bis | Un rôle non paramétré affiche un tableau de bord complet alors qu'il n'accède à aucune page : deux impressions contradictoires pour le même compte | Faible, mais piège de diagnostic |
| EN-EXP-5 | Aucune table de suivi des migrations | Moyenne |
| EN-ERG-2 | Onze pages débordent à 375 px, libellés modaux manquants | Moyenne, relevée par la confirmation de l'usage mobile |

### 12.2 Valeurs provisoires signalées par le code lui-même

Le paramétrage initial du module Achats porte des valeurs de démarrage
arbitraires, faute de source métier disponible à la rédaction : bornes des
paliers de validation, libellés des types d'achat (DAF/DAI/DAH), familles
d'achat génériques, lignes budgétaires sans enveloppe. **Elles doivent être
ajustées dans les écrans de paramétrage avant la mise en service.**

### 12.3 Décisions attendues

| Réf. | Décision | Statut |
|---|---|---|
| D1 | Bascule progressive ou totale | **Tranchée** : deux à trois sites pilotes, généralisation ensuite |
| D2 | Saisie sur téléphone par les coordinateurs | **Tranchée** : oui — le responsive entre dans le périmètre |
| D3 | Signataires de la recette, un par métier | En attente |
| D4 | Date de livraison de la production VPS par le gestionnaire de base | En attente, conditionnée par la chaîne de validation DAF → responsables → PDG |

---

## Suivi des versions

| Version | Date | Objet |
|---|---|---|
| 1.0 | 21 septembre 2026 | Établissement initial, à partir du code au commit `437102d`. Couvre les dix domaines, les seize rôles, les 106 tables et les quarante-deux identifiants de module contrôlés dans le code. |
