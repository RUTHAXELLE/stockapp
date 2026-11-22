# Cahier des charges — ERP EMUCI

**Dépôt de référence** : GayeGuy/ERP-EMUCI-V2
**Version du logiciel** : branche `main`, commit `5866232` (19 novembre 2026)
**Version du cahier des charges** : 3.0
**Objet** : établir ce que le système doit faire, pour qui, sous quelles
contraintes, et à quoi se mesure qu'il le fait.

> **Provenance.** Ce document est établi par lecture du code source, du
> schéma de base et de la configuration des menus. Il décrit le besoin **tel
> que le produit l'implémente aujourd'hui**, et signale explicitement les
> écarts entre l'intention exprimée dans le code et ce qui est réellement en
> place.
>
> **Note sur cette version.** La version 3.0 intègre les exigences
> apparues du 24 septembre au 19 novembre 2026 : retrait du stock à la
> validation du point 18h et comparaison OptoPlate (§5.1), chaîne de
> validation du stock du jour (§5.2), inventaires (§5.3), commandes de site
> (§5.13), notifications par mail (§5.14), visa N+1 par département (§5.4)
> et habilitations par fonction (§4.3).
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

Quatre difficultés justifient l'outil :

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

**Le pilotage global manque d'un lieu unique.** Le suivi de production, de
stock et de parc existait par écrans dispersés, chacun répondant à une
question précise mais aucun n'offrant une lecture consolidée, comparable dans
le temps, pour la direction. Un tableau de bord d'indicateurs et un outil de
projection de stock répondent à ce besoin (§5.8, §5.11).

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
| **O7** | Donner à la direction une lecture consolidée et datée du parc et de la production | Le tableau de bord KPI couvre les sept familles d'indicateurs, comparées dans le temps |
| **O8** | Anticiper une rupture de stock avant qu'elle ne survienne | L'outil de simulation répond aux deux questions du comité de pilotage : autonomie restante, effet d'une ouverture de site |

---

## 3. Périmètre fonctionnel

Le produit est organisé en **dix domaines**, qui correspondent aux dix groupes
de navigation (`includes/groupes_config.php`).

| Domaine | Objet | Écrans principaux |
|---|---|---|
| **Tableau de bord** | Synthèse adaptée au rôle, vue exécutive, indicateurs consolidés | `dashboard`, `pdg_overview`, `kpi_dashboard` |
| **Stock** | Parc équipements (IT et opérationnel), articles, PMMA, bobines, vignettes, rivets, commandes de site, archives des bons de livraison | `equipements`, `articles`, `pmma`, `commandes`, `commandes_bl`, `mouvements_equipements` |
| **Bobines** | Cycle de vie du film : commande, validation du stock du matin, rapports, vue par site, traçabilité des endommagements | `commandes_bobines`, `validation_stock_matin`, `rapports_gsb`, `stock_bobines_vue`, `tracabilite_endommagements` |
| **Inventaire** | Inventaire périodique, écarts et export, sur quatre natures de stock | `inventaire_sessions`, `inventaire_{bobines,rivets,pmma,equipements}`, `ecarts_*`, `inventaire_export` |
| **Opérations** | Point journalier, demande d'intervention, données EMUCI, suivi des observations | `operations/point_journalier`, `point_emuci`, `import_emuci`, `observations` |
| **Informatique** | Interventions, rapport journalier IT, affectation des sous-rôles Support IT, transfert d'équipement | `interventions`, `rapport_journalier`, `affectations_it`, `affectations` |
| **Rapports** | Résumé superviseur, rapports généraux, exports, simulation & projection de stocks | `resume_superviseur`, `rapports`, `export`, `simulation_stocks` |
| **Demandes internes** | Demandes transverses avec circuit de validation | `demandes`, `demandes_new`, `demandes_a_valider`, `demandes_it`, `agents` |
| **Achats** | Expression de besoin (FEB), visas, suivi DA/BC, réceptions, paramétrage | `achats/*` (20 écrans) |
| **Administration** | Utilisateurs, permissions, nomenclatures, audit, délégations, sites, départements, référentiels de capacités, suivi des mails | `admin/*`, dont `admin/referentiels_operations` et `admin/mails` |

**Volumétrie de référence** : 85 écrans sous `pages/`, 7 points d'entrée à la
racine, 133 tables, 16 rôles, 21 sites actifs, 73 entrées de menu, et 58
identifiants de module contrôlés dans le code — dont 57 administrables depuis l'interface
(Admin → Permissions) et 1 (`inventaire_sessions`) résolu par délégation
nominative sans passer par la table des permissions. **Aucun module n'est
plus contrôlé dans le code sans être administrable depuis l'interface** —
c'était le cas des quatre modules Achats jusqu'en septembre 2026 (§12.1).

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

> Un dix-septième rôle, `gestionnaire`, existe en base sans être documenté
> ci-dessus : c'est un rôle générique antérieur à la spécialisation des
> profils de gestion (`gestionnaire_stock`, `gestionnaire_stock_bobines`,
> `gestionnaire_operation`), conservé sans compte actif rattaché pour ne pas
> casser l'historique d'audit — même logique que `maintenance_info`.

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

**EF-HAB-2** — Les sous-rôles ne filtrent **que** les modules techniques
ci-dessus. Tout autre module (demandes, rapport journalier, achats…)
s'évalue directement sur la table `permissions`, comme pour les autres rôles.

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

**EF-HAB-9** — Les fonctions métier qui ne sont pas des écrans se règlent
dans la matrice comme des modules, pour n'importe quel rôle : **Acheteur**,
**Magasin**, et le droit **Valider** de chacun des quatre inventaires. Tant
qu'une fonction n'est pas paramétrée, la liste de rôles historique
s'applique.

**EF-HAB-10** — Le droit `can_export` commande réellement les exports :
sans lui, le bouton n'apparaît pas et l'export est refusé.

**EF-HAB-11** — Une pièce jointe (BL, FEB, document fournisseur) ne s'ouvre
qu'avec le droit de lecture de l'écran auquel elle appartient.

**EF-HAB-12** — Le rôle `admin` n'est pas modifiable depuis la matrice ;
toute modification de droits est journalisée droit par droit.

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
texte reste lisible pour les points antérieurs. Chacune porte désormais un
**cycle de vie propre**, suivi depuis l'écran dédié (§5.9).

**EF-OP-3** — Le point suit le cycle : `brouillon` → `en_attente_validation` →
`valide`, avec `rejete` (affiché « Modif. demandée », motif obligatoire). Les
points 9h et 13h sont validés par le coordinateur ; le point 18h par le
superviseur.

**EF-OP-4** — Un point ne peut être soumis ni validé sans films utilisés,
rivets posés **et** PMMA utilisés, sauf journée sans production déclarée
avec motif. Le brouillon reste possible. Une bobine ne figure qu'une fois
dans un point.

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

**EF-OP-9** — Les données EMUCI sont importées par fichier (OptoPlate :
plaques posées et désactivations ; OptoTrace : quantité par bobine) et
rapprochées de la saisie terrain ; les sites inconnus de l'import sont isolés
pour rattachement manuel. L'import OptoTrace ne modifie ni le statut ni le
site d'une bobine connue de l'ERP.

**EF-OP-10** — Un film, un rivet ou un PMMA endommagé se déclare **unité par
unité** (étape, cause, numéro du consommable pour un film) depuis le point
journalier ; l'historique complet est consultable et exportable séparément
(§5.10).

**EF-OP-11** — **Le stock ne bouge qu'à la validation du point 18h par le
superviseur** : films (utilisés + endommagés), rivets par type, PMMA. La
validation vérifie tout avant de retirer, et refuse avec le détail si un
élément manque. Un point renvoyé en modification remet son stock en place.
Les films non conformes sont tracés sans sortir du stock de la bobine.

**EF-OP-12** — Avant de valider un point 18h, le superviseur voit la
**comparaison avec OptoPlate** (Primo par catégorie, duplicatas,
post-immat, endommagés, films par bobine). En cas d'écart, il valide avec une
justification obligatoire, conservée avec le point, ou demande une
modification.

**EF-OP-13** — Un site en **Re-Immatriculation** déclare aussi ses
duplicatas (avec les plaques réellement posées) et post-immat, comptés à
part de la production Primo.

**EF-OP-14** — Les chiffres de production de tous les tableaux de bord ne
comptent que les points 18h validés, plus les points historiques
reconstitués depuis OptoPlate.

**EF-OP-15** — Un site peut voir son point généré depuis OptoPlate tant
qu'il ne saisit pas, sans aucun mouvement de stock ; l'historique d'avant
l'ERP peut être reconstitué de la même façon, sans toucher un jour déjà
saisi.

### 5.2 Stock bobines

**EF-BOB-1** — Une bobine est un objet individuel identifié par son numéro,
porteur d'un type, d'une série, d'un stock système et d'un stock temps réel.

**EF-BOB-2** — Le stock ERP du soir de chaque site est comparé à l'import
OptoTrace de la même date dès que le point 18h est validé et l'import
chargé, dans n'importe quel ordre. Sans écart, le site est validé
automatiquement ; avec écart, le gestionnaire stock bobines décide. Quatre
issues : `Conforme`, `Avec écart` (écart autorisé), `Réajusté` (stock ERP
aligné sur EMUCI), `Bloqué`. Une décision du GSB n'est jamais écrasée par la
validation automatique.

**EF-BOB-3** — Pour chaque bobine en écart, l'outil présente les causes
possibles, chiffrées quand c'est possible. Après un réajustement, le
coordinateur répond avec ses corrections dans l'outil.

**EF-BOB-3 bis** — Une bobine arrive sur un site toujours **en stock** et
passe en utilisation par une demande d'utilisation. Statuts : en stock, en
cours, épuisée, retirée, absente, perdue. Le statut suit la quantité lors
d'un réajustement ou d'un recomptage.

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

**EF-INV-5** — Le chiffre compté est un **stock de fin de journée**. Le moment
du comptage (matin avant les activités = fin de journée de la veille, ou
soir = fin de journée du jour) est demandé avant la première saisie et
reste modifiable. Un comptage peut s'étaler sur plusieurs jours, chaque ligne
se rapportant à son propre jour.

**EF-INV-6** — Un inventaire ne peut pas être créé deux fois pour le même site,
la même date et la même périodicité.

**EF-INV-7** — Un inventaire sans objet à compter est refusé avec un message
explicite plutôt que créé vide ; dans une session, ce type est « sans objet »
et ne bloque pas la clôture, pas plus qu'un inventaire annulé.

**EF-INV-8** — Tout écart constaté devient un objet de suivi (`ecarts_bobines`,
`ecarts_rivets`, `ecarts_pmma`, `ecarts_equipements`) avec un statut `ouvert`
puis `resolu`.

**EF-INV-9** — Les corrections d'inventaire suivent un circuit d'autorisation :
`en_attente` → `autorise` ou `refuse` → `traite`.

**EF-INV-10** — La création d'un inventaire journalier par un coordinateur de
site n'est plus possible : elle dépend de la session ouverte par
l'administrateur. Tant que la session est ouverte, un inventaire manquant
peut être créé, même après sa période.

**EF-INV-11** — Un inventaire ne se valide que si toutes ses lignes sont
comptées et que le point 18h de chaque jour de comptage est validé. La
consommation validée après le comptage est déduite. Pour les rivets et le
PMMA, une entrée reçue entre la saisie et la validation est conservée.

**EF-INV-12** — Une ligne saisie est verrouillée et se rouvre par une
demande (du site vers les valideurs, ou des valideurs vers le site) ; seul
le site ou un administrateur la corrige. Le coordinateur ne voit jamais la
référence ni l'écart.

**EF-INV-13** — Une bobine non trouvée est déclarée absente et rangée à
l'entrepôt principal ; une bobine retrouvée s'ajoute en cours d'inventaire.
Un équipement trouvé hors système est déclaré par le site et traité par le
gestionnaire de stock ; un équipement manquant peut être déclaré perdu.

**EF-INV-14** — Un inventaire en cours peut être annulé avec un motif, sans
toucher au stock. Les inventaires s'exportent tous sites (Excel, PDF), hors
coordinateur.

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

**EF-DEM-6** — Le visa N+1 revient aux **N+1 du département du
bénéficiaire** de la demande (à défaut, du demandeur). Tous les N+1 du
département voient la demande, sont prévenus et peuvent la viser. Un
département sans N+1 est signalé et les administrateurs visent à sa place.

**EF-DEM-7** — Un brouillon se reprend et se soumet avec le même numéro.

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

**EF-ACH-12** — Les pièces déposées (pièces jointes, BL, bons de transfert,
fiches signées, documents fournisseurs) sont conservées en base, à l'abri
des redéploiements, et ne s'ouvrent qu'avec connexion et droit.

### 5.6 Informatique

**EF-IT-1** — Une intervention est préventive, curative ou **autre**
(installation de matériel, remplacement, diagnostic), portée par un
technicien, rattachée à un site et éventuellement à un équipement de ce site.

**EF-IT-2** — Un coordinateur peut signaler une panne (`demande_coordinateur`),
ce qui notifie les profils de maintenance. **Cette action exige le droit de
création sur `interventions`.**

**EF-IT-3** — Le transfert d'équipement entre sites et le retour en stock sont
tracés dans un historique des mouvements, séparé de l'écran d'affectation.

**EF-IT-5** — Un équipement porte un N° interne, un N° série fabricant, une
marque, un modèle, un emplacement et un utilisateur (agent). Le site peut le
mettre en utilisation, le déclarer épuisé (consommable) ou hors service ;
chaque changement est tracé. Un équipement affecté ou en intervention ne se
supprime pas.

**EF-IT-4** — Les sous-rôles Support IT s'affectent depuis un écran dédié.
**L'affectation et la promotion exigent le droit de modification sur
`affectations_it`.**

### 5.7 Administration

**EF-ADM-1** — La matrice rôle × module est éditable depuis l'interface, pour
la totalité des 57 modules administrables (§3).

**EF-ADM-2** — La liste des modules envoyée au serveur lors de la sauvegarde
doit être **dérivée de la liste affichée**, et non recopiée à la main : une
liste recopiée se désynchronise et remet silencieusement à zéro les droits des
modules oubliés.

**EF-ADM-3** — Le journal d'audit conserve auteur, action, module, entité,
description, valeurs avant/après, adresse IP et horodatage.

**EF-ADM-4** — La création d'un utilisateur impose une validation stricte des
champs et un changement de mot de passe à la première connexion.

**EF-ADM-5** — Un site porte son opération (Primo ou Re-Immatriculation) et,
le cas échéant, la date à partir de laquelle son point est généré depuis
OptoPlate.

### 5.8 Tableau de bord KPI

Écran centralisé, distinct du tableau de bord opérationnel (`dashboard`, orienté
action du jour) et de la vue exécutive (`pdg_overview`, narrative). Il donne une
lecture visuelle de **sept familles d'indicateurs**, chacune avec son chiffre
de tête, son détail et sa courbe d'évolution.

**EF-KPI-1** — Les sept familles couvertes sont : Production, Bobines, PMMA,
Rivets, Commandes, Équipements, Sites (production comparée et classement).

**EF-KPI-2** — La production se lit sur la période analysée, comparée à la
période de comparaison (EF-KPI-9) : plaques posées, engins traités, plaques
par jour écoulé, et courbe d'évolution des deux périodes superposées. Chaque
échelle — jour, semaine, mois, année — s'obtient par le type de période.
**Aucun chiffre de production n'est calé sur la date du jour indépendamment
des filtres** : un bandeau de ce type, présent jusqu'au 24 septembre 2026,
répétait la comparaison et ignorait la période choisie.

**EF-KPI-3** — Le taux d'utilisation des bobines se calcule sur ce qui a
**quitté** la bobine (différence entre dotation et reliquat), et non sur un
compteur qui n'est alimenté que par le point journalier — un parc
majoritairement issu d'import resterait sinon affiché à un taux proche de zéro.

**EF-KPI-3 bis** — La **couverture** indique combien de jours le stock des
bobines actives (les retirées exclues) tient au rythme des 30 derniers jours,
sur la sélection de sites. Quand un format s'épuise avant la moyenne, il est
nommé avec son échéance : un format ne remplace pas un autre. La consommation
suit la règle RG-8.

**EF-KPI-4** — Le taux de disponibilité du parc équipements compte comme
disponibles les états `ok`, `neuf`, `bon` et `usage` — pas `ok` seul, qui sous-
évaluait fortement un parc saisi en langage courant plutôt qu'en état
technique binaire. Un état inconnu tombe dans « autre état », jamais dans
« disponible ».

**EF-KPI-5** — Le taux de satisfaction des commandes se lit sur les **six
dernières périodes**, pour distinguer une dégradation en cours d'un
redressement.

**EF-KPI-6** — Un utilisateur peut filtrer par périmètre (sélection de sites,
ou site unique) et par granularité temporelle ; le coordinateur de site reste
verrouillé sur son site. Une sélection de sites vide vaut « tout le
périmètre ».

**EF-KPI-7** — Un utilisateur peut **enregistrer une combinaison de filtres**
— périmètre, période analysée et période de comparaison comprises — comme vue
nommée, personnelle ou partagée, et la supprimer. Seul son propriétaire peut
supprimer une vue, y compris partagée.

**EF-KPI-8** — L'écran est protégé par le module `kpi_dashboard`, `can_read`.

**EF-KPI-9** — L'utilisateur choisit la **période analysée (A)** et la
**période de comparaison (B)**, de même granularité :

| Comparer à | Période B |
|---|---|
| Période précédente (défaut) | La période juste avant A — sans aucun réglage |
| Même période l'an dernier | A décalée d'un an (sans objet en annuel, où elle se confond avec la précédente) |
| Période choisie | Un jour, une semaine, un mois ou une année libres |

La semaine se choisit dans une liste (« S38 · 14/09 → 20/09 »), et non par un
champ date : le choix porte sur une semaine, pas sur un jour.

**EF-KPI-10** — Règle de durée :

- en comparaison automatique (période précédente), si A est en cours, B est
  arrêtée au même rang (1ᵉʳ → 24 août contre 1ᵉʳ → 24 septembre) ;
- dès que l'utilisateur choisit lui-même B, les deux périodes sont comparées
  **entières** ; si A est en cours, l'écran le signale ;
- dans tous les cas, une **moyenne de plaques par jour écoulé** est affichée,
  seule mesure juste quand les deux périodes n'ont pas la même durée.

**EF-KPI-11** — Suivent la comparaison A/B : la production, les
consommations de PMMA et de rivets, les commandes (taux de satisfaction et
délai de B affichés), la courbe d'évolution et le classement des sites. Les
stocks, le parc de bobines et les équipements restent des **photos de
l'instant**, signalées « à ce jour » : la base ne conserve pas l'historique du
stock.

**EF-KPI-12** — Les consommations de PMMA et de rivets excluent les points en
brouillon, comme la production : deux panneaux voisins comptent sur la même
base. Un site sans production sur A mais actif sur B reste affiché au
classement — c'est précisément ce qu'une comparaison doit faire voir.

**EF-KPI-13** — Le tableau de bord KPI ouvre sur un bandeau **« Points
d'attention »** (sites sans point soumis, points à valider, écarts avec
EMUCI, couverture de stock basse, interventions ouvertes), chaque ligne
menant à l'écran de détail. Un panneau **« Fiabilité & pertes »** contrôle la
saisie, le rapprochement avec EMUCI et le taux de films endommagés, sans
classement nominatif.

### 5.9 Suivi des observations

**EF-OBS-1** — Chaque observation saisie dans un point journalier suit un
cycle de vie propre : `en_attente` → `en_cours` → `traite` → `cloture`, avec
une branche `escalade` au-delà de trois relances.

**EF-OBS-2** — Le coordinateur auteur peut **relancer** (après un délai
minimal depuis la dernière relance) et **confirmer la clôture** d'une
observation qui le concerne — ces deux actions ne demandent que le droit de
lecture.

**EF-OBS-3** — La prise en charge, le traitement et la clôture relèvent du
superviseur et exigent le droit de **modification** sur `observations`.

**EF-OBS-4** — Une observation `en_attente` ne peut pas être clôturée
directement : elle doit d'abord être prise en charge.

**EF-OBS-5** — Au-delà de trois relances sans traitement, l'observation
bascule automatiquement au statut `escalade`, visible et filtrable comme tel
— plutôt qu'une notification qui se perdrait dans la liste.

**EF-OBS-6** — La liste est exportable en Excel, avec le même périmètre et les
mêmes filtres que l'écran.

**EF-OBS-7** — L'historique d'une observation n'est jamais supprimable.

### 5.10 Traçabilité des endommagements

**EF-TRA-1** — Chaque film endommagé se déclare **individuellement**, avec sa
cause, au moment de la saisie du point journalier — une bobine peut avoir
plusieurs films endommagés à des moments et pour des causes différents, et
c'est ce niveau de détail que l'écran restitue.

**EF-TRA-2** — L'écran de traçabilité est **strictement en lecture** : la
saisie reste exclusivement dans le point journalier, pour ne conserver qu'un
seul chemin d'écriture.

**EF-TRA-3** — Le coordinateur de site ne voit que les endommagements de son
site ; les autres rôles filtrent librement.

**EF-TRA-4** — L'écran est exportable, sur le même périmètre que les filtres
appliqués.

### 5.11 Simulation & projection de stocks

**EF-SIM-1** — L'outil est un outil de **projection en lecture seule** : il ne
lit que l'historique et n'écrit jamais aucun stock réel.

**EF-SIM-2** — Deux modes de simulation, choisis explicitement (jamais déduits
du remplissage des champs) :

| Mode | Question posée |
|---|---|
| `stock` | Avec N bobines/vignettes, jusqu'à quand l'activité tient-elle ? |
| `ouverture` | L'ouverture d'un ou plusieurs nouveaux sites est-elle absorbée par le stock actuel ? Sinon, combien commander pour tenir jusqu'à une date donnée ? |
| `les_deux` | Les deux questions combinées |

**EF-SIM-3** — La projection de stock se fait **format par format** (chaque
conditionnement de bobine a sa propre contenance en films), la quantité de
chaque ligne étant éditable ; l'agrégat est la somme des lignes retenues — une
moyenne de parc appliquée à une quantité globale produit un stock projeté
sensiblement faux.

**EF-SIM-4** — L'autonomie est calculée **séparément pour les bobines et pour
les vignettes** : un chiffre unique mélangeant les deux masquerait l'échéance
la plus proche des deux.

**EF-SIM-5** — Pour un scénario d'ouverture de site, le périmètre matière
(formats de bobines, types de PMMA) prévu sur les nouveaux sites est choisi
explicitement — par défaut, l'ensemble des formats existants, pour reproduire
le comportement antérieur. Sans consommation estimée saisie, la consommation
moyenne d'un site existant est retenue par défaut, plutôt qu'un zéro qui
rendrait l'ouverture indolore dans la simulation.

**EF-SIM-6** — Les paramètres de simulation sont portés par l'URL : la page
est rejouable et partageable par lien, sans état à enregistrer.

**EF-SIM-6 bis** — La consommation observée, globale comme par format, suit
la règle RG-8 : points journaliers soumis et saisie manuelle.

**EF-SIM-7** — L'écran est protégé par le module `simulation_stocks`,
`can_read`.

### 5.12 Référentiels & capacités

**EF-REF-1** — Les capacités de conditionnement (films par bobine selon la
série, unités par carton PMMA) sont administrables depuis un écran dédié,
plutôt qu'en dur dans le code — un changement de conditionnement fournisseur
ne doit pas exiger un déploiement.

**EF-REF-2** — Une capacité de série s'applique à toutes les versions de cette
série en une seule saisie ; l'édition par type individuel reste possible en
complément.

**EF-REF-3** — Le code d'un type de PMMA n'est jamais modifiable depuis cet
écran : il est la clé qui relie le catalogue au stock existant et à tout
l'historique de consommation.

**EF-REF-4** — Une capacité nulle ou négative est refusée à la saisie.

**EF-REF-5** — Les types de rivets ou de PMMA présents dans les stocks mais
absents du catalogue de référence sont signalés explicitement, plutôt que de
retomber silencieusement sur une capacité par défaut.

**EF-REF-6** — L'écran administre aussi l'**écran d'ouverture par défaut du
tableau de bord KPI**, par rôle : ce qu'un utilisateur voit en premier avant
tout filtrage. Un défaut vide se traduit par l'absence de ligne, pas par une
valeur vide enregistrée.

**EF-REF-7** — L'écran est protégé par le module `referentiels_operations`,
`can_read` ; c'est un écran d'administration, pas de paramétrage
d'exploitation courante — une capacité fausse se propage silencieusement à
toutes les projections et à tout stock créé ensuite, sans se signaler à
l'écran.

### 5.13 Commandes de site

**EF-CMD-1** — Un site s'approvisionne par une commande unique couvrant
articles, PMMA, rivets, bobines, vignettes et équipements, avec une seule
commande en attente par site.

**EF-CMD-2** — Le superviseur décide ligne par ligne : valider, rejeter ou
demander une modification. Seul le coordinateur du site corrige les lignes
renvoyées.

**EF-CMD-3** — Pour les lignes de bobines et de vignettes, le gestionnaire
stock bobines ou le gestionnaire de stock choisit les rouleaux précis à
l'entrepôt ; la somme de leurs
films égale la quantité demandée. Les rouleaux restent réservés à l'entrepôt
jusqu'à la réception.

**EF-CMD-4** — À l'expédition, chaque ligne est débitée d'un dépôt choisi
(entrepôt ou magasin), sans jamais passer en négatif. Un bon de livraison
est imprimable dès l'expédition.

**EF-CMD-5** — Le site réceptionne **ligne par ligne** (tout, partiel, rien),
motif obligatoire pour tout manque, bon de livraison signé obligatoire et
archivé. Le site n'est crédité que de ce qu'il a reçu.

**EF-CMD-6** — Chaque manque ouvre un écart, traité par une remise au dépôt
d'origine, un renvoi ou une perte imputée au dépôt d'origine.

**EF-CMD-7** — Le coordinateur ne voit que les commandes de son site, sans
prix ni stock central. Les bons de livraison signés sont consultables et
exportables par le magasin, les superviseurs et l'administration.

### 5.14 Notifications par mail

**EF-MAIL-1** — Toute notification de l'application est aussi envoyée par
mail, en file d'attente, sans bloquer l'action qui la déclenche.

**EF-MAIL-2** — Chaque utilisateur peut couper ses mails (et un
administrateur pour lui) ; les notifications restent visibles dans
l'application, et les mails déjà en attente sont abandonnés.

**EF-MAIL-3** — L'administration suit les envois : état, motif d'erreur,
relance, mail de test, alerte si des mails attendent trop longtemps.

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

**EN-EXP-5** — Aucune table ne trace les migrations appliquées ; l'état de la
base se déduisait jusqu'ici uniquement par inspection de sa structure. Un
outil d'inventaire (`tools/inventaire_migrations.php`, ajouté le 11 septembre
2026) extrait désormais, pour chacun des 78 fichiers de `sql/`, les objets
qu'il déclare créer et vérifie leur présence réelle sur la base ciblée,
classant chaque migration `appliquée`, `absente` ou `partielle`. **⚠ écart
résiduel** : c'est un outil de détection, pas une table de suivi — il ne
remplace pas un enregistrement systématique de ce qui a été joué.

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
| **Deux moteurs de base** | PostgreSQL en recette, MySQL en production cible (branche `vps-mysql`, présente sur le dépôt de référence). Toute requête doit être écrite ou traduite pour les deux. |
| **Accès base centralisé** | Uniquement par les aides `db_*`, jamais PDO directement. |
| **Configuration par variables d'environnement** | Aucun secret dans le dépôt. |
| **Exports** | Excel via PhpSpreadsheet, PDF via Dompdf, PPTX via ZipArchive natif. |

---

## 8. Données de référence

**114 tables**, regroupées par domaine :

| Domaine | Tables principales |
|---|---|
| Comptes et droits | `users`, `roles`, `permissions`, `support_it_roles`, `delegations`, `sessions`, `audit_log` |
| Organisation | `sites`, `departements`, `user_departements`, `configurations_site`, `agents` |
| Opérations | `op_points_journaliers`, `op_films_utilises`, `op_pmma_utilises`, `op_types_vehicule`, `points_emuci`, `import_optoplate`, `import_optotrace`, `import_sessions_emuci`, `emuci_sites_inconnus`, `corrections_point_emuci`, `op_observations`, `op_observation_relances`, `op_endommagements` |
| Bobines | `op_bobines`, `op_types_bobines`, `mouvements_bobines`, `consommations_bobines`, `commandes_bobines`, `demandes_bobines`, `corrections_bobines`, `validations_stock_matin`, `comparaisons_stock` |
| Rivets et PMMA | `op_stock_rivets`, `stock_pmma`, `stock_pmma_site`, `op_types_pmma` |
| Inventaire | `inventaire_sessions`, `inventaire_session_sites`, `inventaires_{bobines,rivets,pmma,equipements}`, `inventaire_details_*`, `inventaire_corrections_*`, `ecarts_{bobines,rivets,pmma,equipements}` |
| Parc | `equipements`, `equipement_affectations`, `mouvements_equipements`, `affectations_equipements`, `nomenclatures`, `nomenclature_liens`, `interventions_maintenance` |
| Stock général | `articles`, `consommables`, `stock_site`, `stock_consommables_site`, `stock_fin_mois`, `mouvements_stock`, `commandes`, `commande_lignes`, `commande_compteurs`, `distributions_site`, `distribution_lignes`, `receptions_site`, `receptions_consommables`, `receptions_fournisseur`, `reception_lignes`, `livraisons_consommables`, `litige_messages` |
| Demandes internes | `di_demandes`, `di_etapes`, `di_types`, `di_roles`, `di_user_roles`, `di_plateformes` |
| Achats | `feb`, `feb_lignes`, `feb_offres`, `feb_pieces_jointes`, `feb_receptions`, `feb_receptions_departement`, `feb_expeditions`, `feb_suivi`, `feb_compteurs`, `fournisseurs`, `familles_achat`, `achat_types`, `achat_paliers`, `achat_parametres`, `lignes_budgetaires`, `budget_validations`, `stock_departement` |
| Transverse | `notifications`, `rapports_journaliers_info`, `points_journaliers_info`, `bilans_mensuels_bobines`, `vues_enregistrees`, `preferences_utilisateur`, `defauts_affichage` |

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

**RG-7 — Un outil de simulation ne modifie jamais un stock réel.** La
projection (§5.11) est un calcul en lecture seule ; aucune de ses actions
n'écrit dans les tables de stock.

**RG-8 — La consommation de films compte toutes les sorties.** Les films
quittent une bobine par le point journalier (films utilisés et endommagés,
points soumis) ou par la saisie manuelle de consommation ; les deux
décrémentent le stock et sont additionnés. La couverture du tableau de bord
KPI et la simulation de stocks reposent sur cette consommation.

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
| **CA-11** | Le tableau de bord KPI ouvre ses sept familles sans erreur, quel que soit le périmètre filtré | Un compte par rôle ayant accès au module `kpi_dashboard` |
| **CA-11 bis** | Le tableau de bord KPI compare deux périodes choisies par l'utilisateur, dans les quatre granularités | Un mois contre un mois non consécutif, une semaine contre une autre, et le rapprochement avec un calcul direct en base |
| **CA-12** | La simulation de stock répond aux deux cas d'usage du comité de pilotage sans écrire aucun stock réel | Comparaison du stock avant/après simulation |
| **CA-13** | Le stock ne bouge qu'à la validation du point 18h, et la comparaison OptoPlate s'affiche avant | Un point de chaque statut, stock relevé avant et après |
| **CA-14** | Une commande de site parcourt le circuit jusqu'à la réception, avec un manque traité en écart | Une ligne bobine et une ligne article, réception partielle |
| **CA-15** | Un inventaire compté le matin se valide après le point 18h du jour, sans double déduction | Un site pilote, rivets et bobines |
| **CA-16** | Une notification part par mail, et s'arrête quand l'utilisateur coupe ses mails | Mail de test et suivi dans Administration → Mails |

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
| EF-HAB-5 bis | Un rôle non paramétré affiche un tableau de bord complet alors qu'il n'accède à aucune page : deux impressions contradictoires pour le même compte | Faible, mais piège de diagnostic |
| EN-EXP-5 | Un outil de détection des migrations existe désormais (11 septembre 2026), mais aucune table ne trace systématiquement ce qui a été joué sur chaque environnement | Moyenne, réduite depuis la v1.0 |
| EN-ERG-2 | Onze pages débordent à 375 px, libellés modaux manquants | Moyenne, relevée par la confirmation de l'usage mobile |
| EF-MAIL-1 | Sur le serveur VPS, l'envoi des mails dépend de la configuration SMTP (`MAIL_*`) et de la tâche planifiée, à finaliser | Moyenne |

> **Résolu depuis la v1.0.** Les quatre modules Achats (`achats`,
> `achats_dashboard`, `achats_param`, `achats_suivi`) étaient contrôlés dans
> le code sans figurer dans la matrice Admin → Permissions sur le dépôt lu
> pour la v1.0. Sur `GayeGuy/ERP-EMUCI-V2`, dépôt de référence, ils y
> figurent déjà : un administrateur peut ajuster leurs droits depuis
> l'interface, sans migration SQL.

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
| 1.0 | 21 septembre 2026 | Établissement initial, à partir du code au commit `437102d`. **Rédigé par erreur depuis le dépôt RUTHAXELLE/stockapp**, divergent de la référence depuis fin août 2026. Couvre les dix domaines, les seize rôles, les 106 tables et les quarante-deux identifiants de module contrôlés dans le code sur ce dépôt. |
| 2.0 | 24 septembre 2026 | **Corrigé depuis le dépôt de référence GayeGuy/ERP-EMUCI-V2** (commit `6b268a6`). Ajout de cinq domaines fonctionnels absents de la v1.0 : tableau de bord KPI (§5.8), suivi des observations (§5.9), traçabilité des endommagements (§5.10), simulation & projection de stocks (§5.11), référentiels & capacités (§5.12). Volumétrie recalculée : 81 écrans, 114 tables, 57 identifiants de module. Écart EF-ADM-1 (modules Achats hors matrice) constaté résolu sur ce dépôt. |
| 2.1 | 24 septembre 2026 | Tableau de bord KPI : comparaison de la période analysée à une période choisie — précédente, même période l'an dernier ou libre (EF-KPI-9) ; règle de durée à date égale en automatique, périodes entières au choix, moyenne par jour (EF-KPI-10) ; panneaux qui suivent la comparaison et photos de l'instant (EF-KPI-11) ; brouillons exclus des consommations PMMA/rivets, sites actifs sur B seulement conservés au classement (EF-KPI-12) ; bandeau « Aujourd'hui » (EF-KPI-2) ; critère CA-11 bis. |
| 2.2 | 24 septembre 2026 | Tableau de bord KPI : bandeau « Aujourd'hui » retiré — il doublait la comparaison et ignorait les filtres (EF-KPI-2). |
| 2.3 | 24 septembre 2026 | Consommation de films calculée sur toutes les sorties, points journaliers compris (RG-8) : couverture du tableau de bord KPI (EF-KPI-3 bis) et simulation (EF-SIM-6 bis). Seule la saisie manuelle était lue jusque-là : la couverture restait incalculable sur un parc géré par points journaliers. |
| 3.0 | 19 novembre 2026 | Remise à niveau sur les évolutions d'octobre et novembre. **Ajouté** : retrait du stock à la validation du 18h, comparaison OptoPlate, Re-Immatriculation, production sur points 18h validés, point généré depuis OptoPlate (EF-OP-11 à 15) ; validation du stock du jour par chaîne point 18h → import (EF-BOB-2, 3 bis) ; inventaires — fin de journée, moment du comptage, verrous, absentes, équipements trouvés et perdus, annulation, export (EF-INV-11 à 14) ; visa des N+1 du bénéficiaire (EF-DEM-6, 7) ; archivage des pièces (EF-ACH-12) ; interventions « autre » et fiche équipement (EF-IT-1, 5) ; habilitations par fonction, export et pièces jointes (EF-HAB-9 à 12) ; commandes de site (§5.13) ; notifications par mail (§5.14) ; critères CA-13 à CA-16. Volumétrie : 85 écrans, 133 tables, 58 identifiants de module. |
