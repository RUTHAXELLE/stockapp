# Manuel d'utilisation — ERP EMUCI

**Dépôt de référence** : GayeGuy/ERP-EMUCI-V2
**Version du logiciel** : branche `main`, commit `5866232` (19 novembre 2026)
**Version du manuel** : 5.0
**Périmètre couvert** : 73 entrées de menu réparties sur 10 modules, les
écrans hors menu, 16 rôles, 21 sites actifs

> **Provenance de ce document.** Il est établi à partir du code source, pas
> d'une session d'utilisation. La structure des menus, les rôles, les
> statuts, les circuits de validation et les règles de gestion sont exacts et
> vérifiables dans le dépôt. En revanche les détails d'écran (position d'un
> bouton, libellé exact d'un message) n'ont pas été relevés un par un.
>
> Là où le logiciel signale lui-même qu'une valeur est provisoire, le manuel
> le répercute plutôt que de la présenter comme arrêtée. Voir **« Ce qui reste
> à décider »**, en fin de document.
>
> **Note sur cette version.** La version 5.0 couvre les évolutions du 24
> septembre au 19 novembre 2026 : stock retiré à la validation du point 18h
> et comparaison OptoPlate, commandes de site (bobines, réception ligne par
> ligne, BL), validation du stock du jour, inventaires (moment du comptage,
> comptage sur plusieurs jours, absentes, équipements perdus, export),
> équipements, articles, mails, permissions et visa N+1.

---

## Sommaire

1. [À quoi sert l'application](#1-à-quoi-sert-lapplication)
2. [Se connecter](#2-se-connecter)
3. [S'orienter](#3-sorienter)
4. [Les rôles](#4-les-rôles)
5. [Le point journalier et EMUCI](#5-le-point-journalier-et-emuci)
6. [Le stock](#6-le-stock)
7. [Bobines, rivets, PMMA et inventaires](#7-bobines-rivets-pmma-et-inventaires)
8. [Les demandes internes](#8-les-demandes-internes)
9. [Les achats](#9-les-achats)
10. [L'informatique](#10-linformatique)
11. [Rapports et exports](#11-rapports-et-exports)
12. [L'administration](#12-ladministration)
12 bis. [Les écrans hors menu](#12-bis-les-écrans-hors-menu)
13. [Questions fréquentes](#13-questions-fréquentes)
14. [À qui s'adresser](#14-à-qui-sadresser)

---

## 1. À quoi sert l'application

ERP EMUCI suit la production de plaques d'immatriculation et la chaîne de
stock qui l'alimente : points journaliers par site, bobines de film,
consommables, rivets, PMMA, équipements, maintenance, demandes internes et
achats — et donne à la direction un tableau de bord d'indicateurs et un
outil de projection de stock pour piloter l'ensemble.

Elle est utilisée tous les jours par les mêmes personnes, sur vingt et un
sites. Chacun n'y voit que ce que son rôle lui ouvre.

---

## 2. Se connecter

1. Ouvrir l'adresse de l'application dans un navigateur.
2. Saisir son adresse e-mail professionnelle et son mot de passe.
3. Valider.

### 2.1 La première connexion : changement de mot de passe imposé

Quand un administrateur **crée votre compte** ou **réinitialise votre mot de
passe**, celui qu'il vous communique est provisoire.

À votre connexion suivante, l'application vous envoie sur l'écran
**« Changer mot de passe »** et **bloque tout le reste** tant que vous ne
l'avez pas fait. Ce n'est pas une suggestion : aucun autre écran ne s'ouvre,
et toute action renvoie « Vous devez changer votre mot de passe avant de
continuer ».

1. Saisir le mot de passe provisoire reçu.
2. Saisir le nouveau, deux fois.
3. Valider.

La contrainte est alors levée et vous arrivez sur l'accueil.

> **Attention aux espaces.** Un mot de passe collé depuis un e-mail traîne
> souvent une espace au début ou à la fin. L'application les retire des deux
> côtés, à la saisie comme à la connexion, mais mieux vaut coller proprement.

Une fois la contrainte levée, cet écran n'est plus accessible : il vous
renverrait à l'accueil. Pour changer votre mot de passe **de votre propre
initiative**, voyez la section 2.4.

### 2.2 Cinq erreurs de mot de passe bloquent le compte

**Cinq tentatives de connexion infructueuses d'affilée verrouillent le compte
pendant quinze minutes.**

Le message vous indique le nombre de minutes restantes. Pendant ce délai, même
le bon mot de passe est refusé : il n'y a rien à faire d'autre qu'attendre, ou
demander à un administrateur de réinitialiser votre mot de passe — une
réinitialisation lève le verrou immédiatement.

Le compteur repart à zéro dès qu'une connexion réussit. Trois erreurs
aujourd'hui et deux demain ne bloquent donc pas le compte si une connexion
correcte s'est intercalée.

> **Si vous êtes bloqué sans avoir fait cinq erreurs**, signalez-le : cela
> peut vouloir dire que quelqu'un d'autre essaie votre adresse. Chaque
> verrouillage est inscrit au journal d'audit avec l'heure.

### 2.3 Mot de passe oublié

Cliquez sur « Mot de passe oublié ? » sous le formulaire de connexion,
saisissez votre adresse, et un lien de réinitialisation vous est envoyé par
e-mail. Le mot de passe que vous définissez par ce lien est définitif : il
ne déclenche pas de changement imposé.

**E-mail qui n'arrive pas** : contactez un administrateur.

### 2.4 Changer son mot de passe volontairement

Écran **Mon Profil**, section « Changer le mot de passe ». C'est le seul
endroit pour le faire hors contrainte.

### 2.5 Et ensuite

Vous arrivez sur l'**accueil**, qui est le point de départ de toute la
navigation : voir la section 3.

---

## 3. S'orienter

La navigation fonctionne en **deux temps** : on choisit un module sur
l'accueil, puis on circule dans ses écrans par la barre latérale.

### 3.1 L'accueil, le point de départ

C'est l'écran d'arrivée après connexion, et il a sa propre mise en page,
différente du reste de l'application.

Il présente sous forme de **cartes** les modules auxquels votre rôle donne
accès. Vous ne voyez que les vôtres : peu de cartes n'est pas un défaut
d'affichage, c'est votre périmètre.

Cliquer sur une carte ouvre le module, et vous dépose sur **le premier écran
que votre rôle peut réellement ouvrir** dans ce module — pas sur une page
fixe. C'est pourquoi deux personnes cliquant sur la même carte peuvent
atterrir sur des écrans différents.

Revenir à l'accueil **quitte le module en cours**.

### 3.2 La barre latérale, une fois dans un module

Elle ne liste **jamais les dix modules**. Elle contient :

- le bouton **Accueil**, toujours présent, pour revenir au choix des modules ;
- le **nom du module en cours** ;
- les **écrans de ce module**, et eux seuls.

C'est la source de confusion la plus fréquente : si vous cherchez un écran
qui appartient à un autre module, il ne sera pas dans la barre. Repassez par
l'accueil.

En bas de la barre figure votre **carte utilisateur** : nom, rôle, un lien
vers **Mon Profil** et le bouton de **déconnexion**.

### 3.3 La barre du haut

Sur tous les écrans d'un module :

- le **titre de l'écran** en cours ;
- une **cloche de notifications**, avec le nombre de messages non lus ;
- un **menu utilisateur** déroulant, menant à Mon Profil et à la déconnexion.

**Les listes se mettent à jour seules.** Commandes, réceptions, équipements,
bobines et interventions se relisent toutes les 30 secondes, à l'arrivée
d'une notification et au retour sur l'onglet — sauf pendant une saisie.
Les longues listes sont découpées en pages de 25 lignes.

**Session expirée.** Après 15 minutes d'inactivité, un bandeau « Votre
session a expiré » apparaît, avec un lien pour se reconnecter dans un
nouvel onglet : la saisie en cours n'est pas perdue.

### 3.4 Les dix modules

| Module | Ce qu'on y fait | Section |
|---|---|---|
| **Dashboard** | Tableau de bord du jour, vue exécutive, tableau de bord KPI | [11](#11-rapports-et-exports) |
| **Stock** | Équipements, articles, PMMA, bobines, rivets, commandes de site, archives des BL | [6](#6-le-stock) |
| **Bobines** | Commandes, validation du stock du matin, rapports, traçabilité des endommagements | [7](#7-bobines-rivets-pmma-et-inventaires) |
| **Inventaire** | Sessions, inventaires physiques, écarts, export des inventaires | [7](#7-bobines-rivets-pmma-et-inventaires) |
| **Opérations** | Point journalier, interventions, commandes, point et import EMUCI, suivi des observations | [5](#5-le-point-journalier-et-emuci) |
| **Informatique** | Interventions, rapport journalier, affectations IT | [10](#10-linformatique) |
| **Rapports** | Résumé superviseur, rapports généraux, exports, simulation & projection de stocks | [11](#11-rapports-et-exports) |
| **Demandes internes** | Demandes administratives et circuits de visa | [8](#8-les-demandes-internes) |
| **Achats** | Expression de besoin, visas, suivi, réceptions | [9](#9-les-achats) |
| **Administration** | Utilisateurs, permissions, sites, départements, mails, audit, référentiels & capacités | [12](#12-ladministration) |

---

## 4. Les rôles

Seize rôles existent, plus un rôle historique (`gestionnaire`) conservé sans
compte actif. Le vôtre détermine ce que vous voyez et ce que vous pouvez
faire.

| Rôle | Fonction |
|---|---|
| `superadmin` | Super Administrateur, accès total |
| `admin` | Administrateur, accès total |
| `lecteur` | PDG, consultation et vue exécutive |
| `directeur_general` | Directeur Général, visas de direction |
| `daf` | Directeur Administratif et Financier |
| `raf` | Responsable Administratif et Financier |
| `superviseur_operation` | Supervision des opérations, validation des points |
| `superviseur_achat` | Supervision des achats |
| `superviseur_it` | Supervision informatique |
| `coordinateur_site` | Terrain, saisie du point journalier de son site |
| `gestionnaire_operation` | Gestion opérationnelle |
| `gestionnaire_stock` | Gestion du stock |
| `gestionnaire_stock_bobines` | Gestion du stock de bobines |
| `controleur_production` | Contrôle de production |
| `maintenance_info` | Maintenance informatique |
| `support_it` | Support informatique |

**Le coordinateur de site est verrouillé sur son site.** Il ne voit que ses
propres données, et modifier l'adresse dans la barre du navigateur n'y
change rien. C'est vrai partout dans l'application, y compris sur le tableau
de bord KPI (§11.4).

---

## 5. Le point journalier et EMUCI

C'est le relevé quotidien de production d'un site. Tout le reste en dépend :
le stock, les tableaux de bord et la validation du stock du jour.

### 5.1 Saisir un point — coordinateur de site

1. Module **Opérations**, puis **Point journalier**.
2. Choisir le type de point : **9h**, **13h** ou **18h** (18h par défaut).
3. Renseigner les quantités :
    - véhicules posés par catégorie (VP, camion, semi-remorque, moto) et
      plaques posées ;
    - **films par bobine** : pour chaque bobine en utilisation, les films
      utilisés, endommagés et **non conformes** (films reçus hors règles) ;
    - **rivets** posés et endommagés, par type (gonflables, éclatés) ;
    - **PMMA** utilisés, endommagés et non conformes, par type ;
    - heures de travail et observations.
4. Enregistrer.

**Chaque bobine ne figure qu'une fois dans un point.** Pour ajouter une
bobine, utilisez « + Ajouter une bobine » ; le serveur refuse un point où
la même bobine apparaît deux fois.

**Tout élément endommagé se justifie.** Dès qu'une quantité endommagée est
saisie (film, rivet ou PMMA), une fenêtre demande une déclaration par unité :
étape (vérification, lamination, percée, fissurée…), cause en texte libre et,
pour un film, le **numéro du consommable**. Ces déclarations alimentent la
Traçabilité des endommagements (§7.8). Elles sont rechargées quand on
rouvre le point : inutile de tout re-justifier.

**Sites en Re-Immatriculation.** Sur un site dont l'opération est
« Re-Immatriculation » (§12.4), la section des véhicules s'intitule
Re-Immatriculation et deux sections s'ajoutent : **Duplicatas** et
**Post-Immat**. Pour un duplicata de VP ou de camion, indiquez les
**plaques réellement posées** (1 ou 2 par véhicule). Le V/H se calcule sur
le total des engins posés des trois sections.

**Journée sans production.** Sur un point 18h, cochez « Aucune production
ce jour » et choisissez le motif (commentaire obligatoire pour « Autre ») :
le point est enregistré à zéro, sans mouvement de stock.

| Statut | Ce que ça veut dire |
|---|---|
| **Brouillon** | Saisi mais pas transmis. Visible de vous seul. **Ses chiffres ne comptent nulle part.** |
| **Soumis** (en attente de validation) | Transmis au superviseur. Ne compte pas encore. |
| **Validé** | Validé par le superviseur (point 18h) ou par vous-même (points 9h et 13h). |
| **Modif. demandée** | Renvoyé par le superviseur avec un motif. Bouton **Corriger** sur la carte du point, puis resoumettre. |

> **Ce qu'il faut pour soumettre.** Un point ne peut être soumis (18h) ou
> validé que s'il contient des **films utilisés**, des **rivets posés** et
> des **PMMA utilisés** — sauf journée sans production. Un site en attente
> de stock peut enregistrer un brouillon : le badge liste ce qui manque.

> **Brouillon et « soumis » ne sont pas la même chose.** Un brouillon
> n'est visible que de vous : le superviseur ne le voit pas et ne peut donc
> pas le valider. Un brouillon abandonné se supprime avec « Supprimer le
> brouillon ».

### 5.2 Quand le stock bouge : seulement à la validation du point 18h

**Les films, rivets et PMMA ne sortent du stock qu'à la validation du point
18h par le superviseur.** Un brouillon, un point soumis, un point renvoyé en
modification, les points 9h et 13h : aucun mouvement de stock.

- Les films **non conformes** sont déclarés et tracés, mais ne sortent pas
  du stock de la bobine. Les PMMA non conformes, eux, sortent du stock
  utilisable.
- Si un élément manque au moment de la validation (bobine, rivets d'un
  type, PMMA), la validation est refusée avec le détail, et rien n'est
  modifié.
- Un point renvoyé en modification après validation remet son stock en
  place ; il sera retiré à nouveau à la validation suivante.

### 5.3 Valider les points 18h — superviseur opération

1. Le tableau de bord signale les points **soumis**.
2. Ouvrir le point. Avant la validation, l'écran **Comparaison OptoPlate**
   met côte à côte ce que le coordinateur a déclaré et ce que la
   plateforme a enregistré pour le site et le jour : Primo par catégorie,
   Duplicatas, Post-Immat, endommagés, plaques des duplicatas et **films par
   bobine**, avec l'écart de chaque ligne.
3. Trois issues :
    - **aucun écart** : valider ;
    - **écart** : valider avec une **justification obligatoire**, conservée
      avec le point (détail et PDF) ;
    - ou **Demander une modification** au coordinateur, avec un motif.

> **Ce que la comparaison sait déjà.** Une plaque imprimée un jour et posée
> plus tard compte en film le jour de l'impression et en engin le jour de la
> pose. Un véhicule désactivé sur un site et réimprimé sur un autre est
> reconnu comme duplicata ou post-immat. Les films déjà retirés d'une
> bobine entrée dans l'ERP après avoir servi ne comptent pas comme écart.

### 5.4 Import EMUCI

**Module Opérations → Import EMUCI.** Charge les fichiers des plateformes
nationales. Deux onglets :

- **OptoTrace (stock bobines)** : la quantité de films de chaque bobine vue
  par la plateforme. L'import **ne change ni le statut ni le site** d'une
  bobine déjà connue de l'ERP (affectations, transferts et points
  journaliers en décident) : il ne met à jour que la quantité EMUCI et le
  format.
- **OptoPlate (plaques)** : les plaques posées, avec leurs désactivations
  (duplicata, modification POST, incident) dans le même fichier. Le fichier
  peut être ré-enregistré par Excel : l'encodage, le séparateur et les
  variantes de noms de colonnes sont reconnus. Après l'import, l'écran
  propose les jours de pose réellement contenus dans le fichier.

L'encadré **« Alimenter le dashboard »** (onglet OptoPlate) reconstitue des
points « historique » à partir des plaques d'avant la mise en service de
l'ERP : **Simuler** montre ce qui serait créé, rien n'est écrit avant
« Alimenter le dashboard ». Aucun stock n'est modifié, un jour déjà saisi
n'est jamais touché.

> **Le droit requis.** Lancer un import demande le droit de **création** sur
> le module Import EMUCI. La limite de téléversement est de 50 Mo.

### 5.5 Suivi des observations

Les observations que vous saisissez dans le point journalier (une alerte,
une relance, un incident…) ont leur propre écran de suivi : **module
Opérations → Suivi des observations**.

**Cycle de vie d'une observation :**

| Statut | Signification |
|---|---|
| **En attente** | Signalée, pas encore prise en charge |
| **En cours** | Prise en charge par le superviseur |
| **Traitée** | Le superviseur a agi |
| **Clôturée** | Confirmée close |
| **Escaladée** | En attente depuis trop longtemps, relancée trois fois sans réponse |

- **Vous (coordinateur, auteur de l'observation)** pouvez la **relancer**
  tant qu'elle reste en attente, et **confirmer sa clôture** une fois
  traitée. Ces deux gestes ne demandent que de pouvoir consulter l'écran.
- **Le superviseur** la **prend en charge**, la **traite** et la **clôture**
  — ces trois actions demandent le droit de modification.

> **Une observation en attente ne se clôture pas directement.** Elle doit
> d'abord être prise en charge. Au bout de trois relances sans réponse, elle
> passe automatiquement au statut **Escaladée**.

L'écran se filtre par statut et s'exporte en Excel.

### 5.6 Point EMUCI

**Module Opérations → Point EMUCI.** Compare ce que la plateforme nationale
a enregistré avec ce que les coordinateurs ont déclaré dans leurs points
journaliers, et met les **écarts** en évidence. Un écart se commente
directement à l'écran, pour que le motif reste attaché au chiffre.

### 5.7 Sites dont le point est généré depuis OptoPlate

Un site qui produit sans saisir son point peut être réglé, dans
Administration → Sites, sur **« Point généré depuis OptoPlate, à partir
du… »**. À chaque import OptoPlate, son point de chaque jour est créé ou
recalculé depuis les plaques posées (engins, plaques, duplicatas,
post-immat, films par bobine), sans heures, rivets, PMMA ni mouvement de
stock. Le site sort de ce mode dès son premier point saisi.

---

## 6. Le stock

### 6.1 Équipements

Deux entrées de menu pour un même écran, filtré différemment :

- **Équipements Informatique** — postes, imprimantes, matériel IT ;
- **Équipements Opérationnel** — matériel de pose et de terrain.

Chaque équipement porte un **N° interne** (généré), un **N° série
fabricant**, une marque, un modèle, un site, un **emplacement** dans le
site, un **utilisateur** (agent de l'annuaire), un état (neuf, bon, usagé,
réformé) et ses dates de mise en service et de fin de cycle.

| Statut | Signification |
|---|---|
| **En stock** | Disponible au dépôt ou sur le site |
| **Affecté** / **En utilisation** | Mis en service sur le site (date et agent) |
| **Hors service** | Déclaré hors service, avec motif |
| **Épuisé** | Consommable arrivé au bout ; retiré des comptes, fiche conservée |
| **Perdu** | Déclaré perdu après un inventaire (§7.6) ; sorti du parc |

Le coordinateur (pour son site) et les profils qui peuvent modifier
disposent de trois actions : **Mettre en utilisation**, **Déclarer épuisé**
(consommables seulement pour le coordinateur), **Déclarer hors service**.
Chaque changement est tracé dans l'historique des mouvements.

- **Importer** un fichier Excel/CSV crée les équipements en masse (modèle
  téléchargeable) ; un N° série déjà connu est refusé comme doublon.
- **Doublons** liste les fiches en double et permet de les retirer.
- **Supprimer** retire une fiche du parc (elle reste en base) ; refusé
  pendant une affectation ou une intervention non résolue.
- Le **retour en stock** remet l'équipement « en stock » et retire son
  utilisateur et son site d'affectation.

Le tableau affiche quelques lignes avec défilement ; la colonne Actions
reste visible à droite. L'export Excel suit le droit « Exporter ».

### 6.2 Articles

**Module Stock → Articles.** Le catalogue des consommables : code, libellé,
type, unité, famille, seuil d'alerte, prix unitaire.

- Le **stock central** d'un article est celui des sites de type **magasin**
  et **entrepôt**. La distribution sort d'abord du magasin, puis de
  l'entrepôt pour le reste.
- Le **coordinateur** consulte le stock de son site et enregistre une
  **Sortie** (consommation, perte, casse, autre) ; il ne sort que du stock
  de son site, jamais au-delà.
- Un article des familles **« Matériel et outillage industriel »** ou
  **« Consommables informatiques »** ne va pas dans le stock d'articles du
  site : à la réception (ou à la distribution), chaque unité devient une
  **fiche d'équipement** du site.
- L'onglet **Historique** retrace les réceptions, distributions et sorties.
- **Importer** met à jour le catalogue et, en option, le stock par site.

### 6.3 Historique des mouvements

**Module Stock → Historique des mouvements.** Trace tous les mouvements
d'équipements : entrées, sorties, transferts, affectations, mises en
utilisation, pertes. C'est l'écran à ouvrir quand on cherche où est passé
un matériel. Non accessible au coordinateur de site.

### 6.4 PMMA

**Module Stock → PMMA.** Stock de PMMA par site et par type. Le stock est
compté en **unités**, et l'équivalent en **cartons** est affiché partout
(et sous le champ Quantité des fenêtres d'entrée et de sortie).

- Le **coordinateur** consulte le stock de son site : il arrive par la
  réception des commandes et sort par le point journalier. Il n'a ni
  entrée, ni sortie, ni import.
- Le **gestionnaire de stock** n'approvisionne que l'entrepôt et le magasin.
- L'**import** se fait en cartons (plus unités en vrac) ; chaque ligne
  remplace le stock du site et du type.
- La consommation affichée repose sur les points 18h validés.

### 6.5 Rivets

**Module Stock → Rivets.** Le stock de rivets par site et **par type**
(gonflables, éclatés). **Approvisionner** et **Ajuster** demandent toujours
le type. Le gestionnaire de stock n'approvisionne que l'entrepôt et le
magasin.

Le seuil d'alerte est de **200 rivets** : en dessous, le site est signalé.
Le coordinateur ne voit que le stock de son site.

### 6.6 Vignette

**Module Stock → Vignette.** Même écran que Bobines, filtré sur les
vignettes (séries TL et WSL).

### 6.7 Commandes de site

**Module Stock → Commandes** (ou Opérations → Commandes selon le profil).
C'est le circuit unique d'approvisionnement d'un site : articles, PMMA,
rivets, **bobines**, **vignettes**, équipements informatiques et
opérationnels. Les unités possibles sont UNITÉ, CARTON, BOBINE et BOÎTE.

1. **Le coordinateur commande** (une seule commande en attente par site).
2. **Le superviseur traite les lignes** : valider, rejeter ou demander une
   modification, ligne par ligne. Une commande « À corriger » n'est
   corrigée que par le coordinateur du site, sur les seules lignes signalées.
3. **Lignes bobines et vignettes** : le GSB ou le gestionnaire de stock choisit les rouleaux précis à
   l'entrepôt (la somme de leurs films doit égaler la quantité demandée),
   puis « Valider la sélection ». Ils restent à l'entrepôt, réservés.
4. **Le gestionnaire expédie** : pour chaque ligne, il choisit le dépôt de
   sortie (entrepôt ou magasin, stock affiché en direct) et la quantité
   livrée. Le dépôt est débité à l'expédition. Le **bon de livraison**
   (BL) s'imprime dès l'expédition.
5. **Le coordinateur réceptionne**, ligne par ligne : tout reçu, partiel
   (avec la quantité) ou rien, en cochant les rouleaux arrivés. Tout manque
   exige un motif, et le **BL signé** (PDF) est obligatoire. Le site n'est
   crédité que de ce qu'il a reçu ; les bobines y arrivent **en stock**.
6. **Chaque manque ouvre un écart**, traité par le gestionnaire (par le GSB
   pour les rouleaux) : remise au stock du dépôt d'origine, renvoi (nouvelle
   commande) ou perte.

| Statut | Signification |
|---|---|
| **À valider** (« En attente de validation » pour le coordinateur) | Attend le superviseur |
| **À corriger** | Lignes renvoyées au coordinateur |
| **À préparer** (« En préparation ») | Validée, bobines à choisir puis expédition |
| **En livraison** | Expédiée, à réceptionner |
| **Reçu** | Réceptionnée par le site |
| **Rejeté** / **Annulé** | Refusée, ou annulée avec motif |

Chaque ligne a aussi son propre statut : Validé, En livraison, Non livré,
Livré partiellement, Livré, Non reçu, Reçu partiel.

> **Ce que voit le coordinateur.** Ses seules commandes, sans prix ni stock
> central, et sans le BL (le site signe celui qui arrive avec la
> marchandise). La liste montre d'abord ce que chacun doit traiter.

#### Commander à un autre site (inter-site)

Quand un site manque d'une matière qu'un autre site a en stock, il peut la
lui commander au lieu de l'entrepôt. La commande suit le même circuit, avec
trois différences : qui prépare, qui transporte, et d'où sort le stock.

1. **Le coordinateur du site demandeur** coche « Commander à un autre
   site », choisit le **site fournisseur** et donne un **motif**. Seuls les
   bobines, vignettes, rivets, PMMA et articles du catalogue se commandent
   ainsi (un équipement passe par une affectation). Pour chaque ligne, la
   colonne « Chez le fournisseur » indique **Disponible** ou
   **Insuffisant**, sans montrer les quantités de l'autre site.
2. **Le superviseur valide** ligne par ligne, comme pour toute commande.
3. **Le coordinateur du site fournisseur** (ou le GSB) choisit les bobines à
   céder, en stock ou entamées. Une bobine saisie dans un point pas encore
   validé ne peut pas être choisie. Il peut céder moins que demandé, ou
   tout ce qu'il faut pour couvrir la demande, mais pas un rouleau inutile.
   Les quantités affichées sont les films restants. Les bobines choisies sont réservées : elles disparaissent du point
   journalier du site fournisseur et ne peuvent plus être mises en
   utilisation.
4. **Le gestionnaire de stock envoie un chauffeur** : bouton « Enlever
   chez … », nom du chauffeur obligatoire. L'enlèvement débite le stock du
   site fournisseur. Le BL « Transfert inter-site » part avec le chauffeur ;
   le site fournisseur le signe à la remise, le site demandeur à l'arrivée.
5. **Le site demandeur réceptionne**, ligne par ligne, comme d'habitude.
   Un manque remis en stock retourne au **site fournisseur**.

Une commande inter-site porte la mention « depuis … » dans la liste. Le
coordinateur du site fournisseur la voit aussi, mais il ne la réceptionne
pas et ne l'annule pas. Un site peut avoir en même temps une commande à
l'entrepôt et une commande inter-site en attente de validation.

> **Commande bobines.** L'ancien écran Bobines → Commande bobines ne prend
> plus de nouvelle demande : il renvoie vers Commandes. Les commandes déjà
> en cours y vont jusqu'à leur réception.

### 6.8 Archives des BL

**Module Stock → Archives des BL** (magasin, superviseurs, administration).
Les commandes expédiées avec leur BL émis et leur BL signé, conservés en
base. Filtres par site, période, numéro et BL manquant ; export ZIP des BL
signés.

---

## 7. Bobines, rivets, PMMA et inventaires

### 7.1 La validation du stock du jour

**Module Bobines → Validation stock jour.** Chaque soir, le stock de
bobines déclaré par l'ERP est comparé à la plateforme OptoTrace.

La comparaison se fait toute seule dès que deux conditions sont réunies,
dans n'importe quel ordre :

1. le **point 18h** du site est validé par le superviseur ;
2. l'**import OptoTrace** de la date est chargé.

Le stock ERP du soir est alors comparé à l'import de cette date, bobine par
bobine. Sans écart, le site est validé automatiquement. Avec un écart, le
GSB est prévenu. En attendant, le site figure dans la rubrique « en
attente » avec la raison (point non validé, import manquant).

Pour chaque bobine en écart, le GSB voit les **causes possibles**, chiffrées
quand c'est possible : plaques imprimées ce jour mais posées plus tard,
export pris après la journée, films retirés par des points postérieurs, non
conformes, même écart déjà constaté… Il décide :

| Décision | Effet | Statut |
|---|---|---|
| **Autoriser l'écart** | L'écart est accepté et tracé | Avec écart |
| **Réajuster le stock** | Le stock ERP est aligné sur EMUCI ; le coordinateur répond avec ses corrections | Réajusté |
| **Refuser / Bloquer** | Le site est bloqué jusqu'à résolution | Bloqué |
| (aucun écart) | Validation automatique | Conforme |

Le GSB dispose de deux onglets : **Validation en cours** et **Historique
30 jours**. Le coordinateur voit les réajustements qui l'attendent, à
toutes les dates, et y répond.

### 7.2 Commander des bobines

Les bobines se commandent désormais par **Stock → Commandes**, ligne
« Bobine » (§6.7). Le GSB y choisit les rouleaux ; ils passent au site à la
réception, en stock.

### 7.3 Vue stock par site

**Module Bobines → Vue stock par site.** Tableau de synthèse du stock de
bobines, site par site, type par type. Le coordinateur ne voit que son
site. Exports CSV, Excel, PDF (tous les types, par blocs de huit) et
PowerPoint, selon le droit « Exporter ».

### 7.4 Les bobines elles-mêmes

Une bobine porte un numéro, un type, un format, une série, un site, et un
compte de films : initial, utilisés, endommagés, restants.

| Statut | Signification |
|---|---|
| **En stock** | Arrivée sur le site, pas encore ouverte |
| **En cours** (en utilisation) | Ouverte, après une **demande d'utilisation** approuvée |
| **Épuisée** | Films consommés (repasse « en cours » si un recomptage lui rend des films) |
| **Retirée** | Sortie du circuit |
| **Absente** | Non trouvée à l'inventaire ; rangée à l'entrepôt principal, hors stock disponible |
| **Perdue** | Perte déclarée par le GSB |

- Une bobine arrive toujours sur un site **en stock** (réception d'une
  commande, transfert, affectation) ; elle passe en utilisation par une
  demande d'utilisation.
- **Corriger la quantité initiale** (hors coordinateur) : pour une bobine
  livrée avec 499 films au lieu de 500. Seule la quantité initiale change ;
  le stock restant ne bouge pas.
- L'onglet **Absentes** liste les bobines non retrouvées ; le GSB peut y
  déclarer la perte.

### 7.5 Les quatre inventaires

| Inventaire | Écran d'écarts | Ce qu'on compte |
|---|---|---|
| **Inventaire bobines** | Écarts bobines | Chaque bobine, et ses films restants |
| **Inventaire rivets** | Écarts rivets | Les quantités, **par type de rivet** |
| **Inventaire PMMA** | Écarts PMMA | Les quantités, **par type de PMMA** |
| **Inventaire équipements** | Écarts équipements | La **présence** du matériel — trouvé ou manquant |

#### La session d'inventaire

L'administration ouvre une **session** depuis *Sessions d'inventaire*
(périodicité mensuelle, trimestrielle, semestrielle ou annuelle). La session
crée, pour chaque site, les inventaires des types qui ont quelque chose à
compter. Un type sans rien à compter est « Sans objet » et ne bloque pas la
clôture ; un inventaire annulé non plus. Tant que la session est ouverte,
un inventaire manquant peut encore être créé, même après sa période.

#### Le comptage

1. **Choisir le moment du comptage.** Avant la première saisie, la fiche
   demande si le comptage est fait **le matin avant les activités** ou **le
   soir après les activités**, avec une proposition selon l'heure. Rien ne
   se saisit tant que ce choix n'est pas confirmé ; il reste modifiable
   ensuite.
    - **Matin** : le chiffre compté est le stock de fin de journée de la
      veille. Le point du soir du même jour reste possible.
    - **Soir** : le chiffre compté est le stock de fin de journée du jour.
2. **Saisir chaque ligne.** Toutes les lignes doivent être comptées avant
   la validation (0 ou « absente » compris). Une ligne saisie est
   verrouillée. Le comptage peut s'étaler sur **plusieurs jours** : chaque
   ligne se rapporte à son propre jour.
3. **Demander une modification.** Une ligne verrouillée se rouvre par une
   demande : le coordinateur la demande au GSB, ou le GSB et le superviseur
   la demandent au site. Seul le site (ou un administrateur) corrige la
   ligne rouverte.
4. **Valider.** La validation n'est possible qu'après le **point 18h
   validé** de chaque jour de comptage. La consommation des points validés
   après le comptage est déduite. L'inventaire validé prend la date du
   dernier jour de comptage.

> **Le coordinateur ne voit pas l'écart.** Pendant le comptage, la
> quantité de référence et l'écart lui sont masqués : il compte ce qu'il
> voit.

> **Rivets et PMMA : les entrées sont conservées.** La référence est notée
> au moment de la saisie ; une livraison reçue entre la saisie et la
> validation n'est pas effacée.

**Bobines.** Une bobine non trouvée se déclare **« Absente »** : à la
validation, elle part à l'entrepôt principal au statut absente, et le GSB
est prévenu. Une bobine **retrouvée** s'ajoute avec « Ajouter une bobine
retrouvée » ; à la validation, elle est affectée au site avec la quantité
comptée. Les bobines épuisées encore vues par OptoTrace sont aussi à
compter.

**Équipements.** Le coordinateur déclare un équipement **trouvé hors
système** (type, marque, modèle, n° de série, état, emplacement, photo
facultative) ; le gestionnaire de stock crée la fiche, le transfère depuis
son ancien site ou refuse. Une déclaration en attente bloque la validation.

**Qui valide.** Le droit « Valider » de chaque type se règle dans
Administration → Permissions (modules *Inventaire … — valider*). Par
défaut : administrateurs, GSB, gestionnaire de stock et superviseur ; le
GSB ne valide pas les équipements.

**Annuler un inventaire.** Les profils qui valident peuvent annuler un
inventaire en cours, avec un motif. Aucun stock n'est touché.

### 7.6 Les écarts d'inventaire

Les écrans **Écarts** listent les différences entre le compté et le stock
de référence. Un écart non justifié reste ouvert ; un écart déjà ouvert
n'est pas recompté au prochain inventaire.

Dans **Écarts équipements**, un équipement manquant peut être **déclaré
perdu** (profils qui valident l'inventaire des équipements, motif
obligatoire) : l'écart est fermé, l'équipement sort du parc au statut
« Perdu » et le mouvement est tracé.

### 7.7 Export des inventaires

**Module Inventaire → Export des inventaires.** Export de tous les sites
en Excel (synthèse puis détail par type) ou en PDF. Filtres : période,
types, statut, site et **nom de la session**. Chaque ligne de l'aperçu
s'exporte aussi seule. La « Référence » est le stock attendu au moment du
comptage (référence − compté = écart). Non ouvert au coordinateur.

### 7.8 Traçabilité des endommagements

**Module Bobines → Traçabilité endommagements.** L'historique des éléments
endommagés, **un par un** : films, rivets et PMMA, avec l'étape, la cause et
le numéro du consommable. L'écran ne fait que **lire** : la déclaration se
fait dans le point journalier (§5.1). Le coordinateur n'y voit que son
site ; les brouillons ne sont visibles que de leur auteur. Filtres par
élément, site, période, étape et cause ; export selon le droit.

### 7.9 Simulation & projection de stocks

**Module Rapports → Simulation & projection.** Un outil de calcul, pas un
écran de saisie : il **ne modifie jamais** le stock réel, il projette.

| Vous vous demandez… | Choisissez le mode |
|---|---|
| « Avec le stock que j'ai, jusqu'à quand puis-je travailler ? » | **Projection de stock** |
| « Si j'ouvre un nouveau site, mon stock tient-il ? Sinon, combien commander ? » | **Ouverture de site** |
| Les deux à la fois | **Les deux** |

- La projection se calcule **par format de bobine** (contenance de
  Référentiels & capacités, §12.8).
- **Bobines et vignettes ont chacune leur propre autonomie affichée.**
- En mode « Ouverture de site », sans consommation estimée, l'écran retient
  la consommation moyenne d'un site existant.

> **D'où vient la consommation observée.** Des films déclarés dans les
> points journaliers (utilisés et endommagés). Un brouillon ne compte pas.

> **Le lien de la simulation se partage** : tous les paramètres sont dans
> l'adresse de la page.

---

## 8. Les demandes internes

Neuf types de demandes administratives, chacune avec son circuit de visas.

> **Les quatre écrans du module sont désormais filtrés par vos droits.**
> Désactiver « Demandes internes » pour un rôle empêche réellement d'y
> accéder — lecture pour consulter, **création pour déposer une demande**.
>
> Le filtrage fin par circuit continue de s'appliquer **en plus** : avoir le
> droit de lecture ne montre pas les demandes des autres, cela ouvre
> seulement l'écran.

### 8.1 Les types disponibles

| Type | Objet |
|---|---|
| Autorisation d'absence | Congé, permission, absence exceptionnelle |
| Création d'accès NSIIV | Ouverture d'accès aux plateformes |
| Basculement d'accès | Transfert d'accès d'un site à un autre |
| Basculement de compte EMUCI | Changement de poste ou de profil |
| Transfert d'agent | Mutation temporaire ou définitive |
| Création de site | Ouverture d'un nouveau site |
| Changement de géolocalisation | Correction des coordonnées d'un site |
| Imputation courrier entrant | Affectation d'un courrier à un service |
| Demande exceptionnelle | Ce qui n'entre dans aucune des cases ci-dessus |

### 8.2 Déposer une demande

1. Module **Demandes internes**, puis **Nouvelle demande**.
2. Choisir le type. Le **circuit de visa s'affiche en haut du formulaire** :
   vous savez d'avance par qui votre demande va passer.
3. Remplir le formulaire. Les champs marqués d'un astérisque rouge sont
   obligatoires.
4. **Enregistrer le brouillon** pour reprendre plus tard sans rien
   transmettre, ou **Soumettre la demande** pour lancer le circuit.

> **Prérequis.** Vous devez être rattaché à un département pour soumettre
> une demande. Sans rattachement, un message explicite vous le dit :
> demandez à un administrateur de vous assigner.

### 8.3 Les aides à la saisie

- **Annuaire des agents.** Commencez à taper un nom, choisissez dans la
  liste, et l'e-mail, la fonction, le matricule, le département et la
  direction se remplissent seuls.
- **Autorisation d'absence.** Choisissez un type de permission (« Décès
  conjoint (5j) », « Mariage du travailleur (4j) »…) et la durée, la date de
  fin et la date de reprise se calculent automatiquement. La reprise saute
  au prochain jour ouvré, week-ends et jours fériés ivoiriens compris. En
  saisie manuelle, les quatre champs restent cohérents entre eux : le
  dernier que vous touchez commande, les autres suivent.

### 8.4 Le cycle de vie d'une demande

| Statut | Signification |
|---|---|
| **Brouillon** | Non transmise |
| **En attente** | Attend un visa |
| **En cours** | Un visa a été donné, le circuit continue |
| **Approuvée** | Tous les visas obtenus |
| **Approuvée pour traitement** | Approuvée et transmise au service qui exécute |
| **Rejetée** | Refusée à une étape, avec motif |

**Exemple de circuit**, autorisation d'absence :

`Visa N+1` → `Visa Administration` → `Visa Direction Générale`

### 8.5 Mes demandes

**Module Demandes internes → Mes demandes.** La liste de tout ce que vous
avez déposé, avec le statut et l'étape en cours. C'est ici qu'on vient voir
« où en est ma demande ».

Un brouillon s'y reprend avec **« Reprendre le brouillon »** : il se
complète et se soumet avec le même numéro.

### 8.6 Viser une demande

1. Module **Demandes internes**, puis **À valider**.
2. Ouvrir la demande, lire les champs et les visas déjà donnés.
3. Approuver, ou rejeter. **Un rejet exige un motif** : la personne doit
   savoir quoi corriger.

Le **N+1** n'est pas un rôle : ce sont les responsables (N+1) d'un
département, désignés dans Administration → Départements.

- **Le département retenu est celui du bénéficiaire.** Quand la demande
  porte sur un agent d'un autre département, ce sont les N+1 du
  département de cet agent qui visent. À défaut, ceux du demandeur.
- **Tous les N+1 du département** voient la demande, sont prévenus et
  peuvent la viser ou la rejeter ; le premier qui agit fait avancer le
  circuit. La page À valider l'indique par « N+1 : département ».
- **Département sans N+1** : les administrateurs et le demandeur sont
  prévenus, un bandeau le signale sur la fiche, et un administrateur vise
  à sa place.
- Si le demandeur est lui-même N+1 du département retenu, l'étape N+1 est
  validée d'office.

### 8.7 Traitements IT

**Module Demandes internes → Traitements IT.** Une fois une demande d'accès
approuvée, elle arrive ici pour exécution : c'est l'informatique qui crée
réellement le compte ou l'accès, puis marque la demande comme traitée.

La distinction compte : **approuvée** veut dire « autorisée », **traitée**
veut dire « faite ».

### 8.8 Types & circuits

**Module Demandes internes → Types & circuits.** Écran d'administration :
créer un type de demande, définir ses étapes de visa et leur ordre.

Un type porte un code, un libellé, une description, et la liste ordonnée de
ses étapes. Chaque étape désigne le rôle qui doit viser.

### 8.9 Circuits avancés

**Module Demandes internes → Circuits avancés.** Paramétrage fin des rôles
de validation, au-delà de l'enchaînement simple des étapes.

**Qui peut viser quelle étape** découle du rôle ERP, sans réglage à faire :

| Rôle ERP | Étapes qu'il peut viser |
|---|---|
| `raf` | Visa RAF |
| `daf` | Visa DAF |
| `support_it`, `superviseur_it`, `maintenance_info` | Visa IT |
| `directeur_general`, `lecteur` (PDG) | Visa Direction Générale |
| `admin`, `superadmin` | Toutes les étapes |

Trois règles s'ajoutent :

- **Le N+1** n'est pas un rôle : ce sont les N+1 du département du
  bénéficiaire (§8.6).
- **Une étape rattachée à un département** peut être visée par n'importe
  quel membre de ce département. C'est ce qui fait vivre le visa
  « Administration », dont le rôle ERP correspondant a été supprimé.
- **Personne ne vise sa propre demande**, administrateurs compris.

Des droits attribués manuellement du temps de l'ancien écran restent
honorés, le temps de la transition.

### 8.10 Annuaire des agents

**Module Demandes internes → Annuaire agents.** La liste des agents, avec
matricule, nom, prénom, e-mail, téléphone, fonction, département, direction,
site, grade et statut.

Il alimente le remplissage automatique des formulaires de demande. Il
s'alimente par import depuis un fichier Excel.

> **Un annuaire vide vide aussi les formulaires.** Si le remplissage
> automatique ne propose personne, c'est ici qu'il faut regarder d'abord.

---

## 9. Les achats

Le circuit part d'un besoin exprimé par un service et va jusqu'à la
réception de la marchandise.

> **Acheteur et Magasin sont des options de la matrice.** Dans
> Administration → Permissions → Achats, les lignes **Acheteur** (file
> d'attente, prise en charge et traitement des FEB) et **Magasin**
> (préparation et expédition des commandes, écarts et litiges de
> réception, réceptions magasin, alertes de stock bas) se cochent pour
> n'importe quel rôle.

> **Les pièces déposées sont conservées en base** (pièces jointes de FEB,
> BL, bons de transfert, fiches signées, documents fournisseurs) et ne
> s'ouvrent qu'avec le droit de lecture correspondant.

### 9.1 Dashboard Achats

**Module Achats → Dashboard Achats.** Vue d'ensemble du module : volumes,
FEB en cours, en attente de visa, en retard. Point d'entrée pour qui pilote
les achats.

### 9.2 Déposer une fiche d'expression de besoin

1. Module **Achats**, puis **Nouvelle FEB**.
2. Renseigner l'en-tête : exercice, département, ligne budgétaire, niveau
   d'urgence.
3. Ajouter les lignes : article, quantité, famille.
4. Enregistrer en brouillon, ou soumettre.

| Niveau d'urgence | Usage |
|---|---|
| **Normale** | Cas courant |
| **Urgente** | Besoin rapproché |
| **Critique** | Bloque l'activité |

### 9.3 Le cycle d'une FEB

| Statut | Signification |
|---|---|
| **Brouillon** | En cours de rédaction |
| **Soumise** | Transmise au service achats |
| **Prise en charge** | Un acheteur s'en est saisi |
| **En validation** | Dans le circuit de visas |
| **Confirmée** | Validée, prête à être commandée |
| **Clôturée** | Terminée |
| **Rejetée** | Refusée |

### 9.4 Mes FEB

**Module Achats → Mes FEB.** Vos propres fiches, avec leur statut. On y
reprend un brouillon et on y suit l'avancement d'une demande soumise.

### 9.5 File d'attente Achats

**Module Achats → File d'attente Achats.** Réservé aux acheteurs : les FEB soumises
qui attendent une prise en charge.

Un acheteur **prend en charge** une fiche, ce qui la lui attribue. Il peut
aussi la **restituer** à la file, ou un responsable peut la **réattribuer** à
quelqu'un d'autre.

L'ancienneté est calculée en **heures ouvrées** : une fiche déposée vendredi
soir n'est pas comptée en retard le lundi matin.

### 9.6 Mes visas

**Module Achats → Mes visas.** Les FEB qui attendent votre signature.
Approuver ou rejeter, avec motif en cas de refus.

Le **circuit de visas dépend du montant** : voir les paliers, section 9.9.

### 9.7 Suivre et réceptionner

Une fois confirmée, la FEB devient une commande suivie dans
**Suivi Achats (DA/BC)**.

| Statut de suivi | Signification |
|---|---|
| **En attente** | Pas encore commandé |
| **Commandé** | Bon de commande émis |
| **En retard** | Délai dépassé |
| **Livrée** | Marchandise arrivée |
| **Réceptionnée** | Contrôlée et entrée en stock |

La réception se fait dans **Achats → Réceptions**. Elle **débite le stock du
magasin** et **crédite le stock du département** demandeur.

**File d'attente équipements** (même module) : les exemplaires
d'équipements créés à la réception d'une demande d'immobilisation, en
attente d'affectation. L'affectation se propose ici et se vise dans Mes
visas. Ouvert au suivi des achats et aux N+1.

### 9.8 Stock par département

**Module Achats → Stock par département.** Ce que chaque département détient
après réception. À distinguer du stock magasin, qui est le stock central.

### 9.9 Paliers de validation

**Module Achats → Paliers de validation.** Un palier définit, pour une
tranche de montant, qui doit signer.

| Champ | Rôle |
|---|---|
| **Borne minimale** | Montant à partir duquel le palier s'applique |
| **Borne maximale** | Montant jusqu'auquel il s'applique |
| **Libellé** | Nom du palier |
| **Signataires** | Qui doit viser |
| **Ordre** | Position dans l'enchaînement |
| **Actif** | Palier en vigueur ou non |

> **Les paliers doivent couvrir tout l'intervalle des montants.**
> L'application le vérifie : un trou entre deux paliers laisserait des FEB
> sans circuit de visa. Si l'écran refuse votre saisie, c'est probablement
> cela.

**Les paliers livrés à l'installation :**

| Montant (XOF) | Palier | Signataires |
|---|---|---|
| 0 à 500 000 | RAF seul | RAF |
| 500 001 à 5 000 000 | RAF + DAF | RAF, DAF |
| Au-delà de 5 000 000 | RAF + DAF + PDG | RAF, DAF, PDG |

> **Ces bornes sont provisoires.** La migration qui les installe le dit
> explicitement : « bornes de démarrage arbitraires, aucun seuil réel
> fourni ». Elles fonctionnent, mais elles n'ont pas été arrêtées par la
> direction. Tant qu'elles ne le sont pas, une FEB peut suivre un circuit qui
> ne correspond pas à la règle de l'entreprise.

### 9.10 Fournisseurs

**Module Achats → Fournisseurs.** Le référentiel des fournisseurs, utilisé
lors du passage en commande.

### 9.11 Familles & types

**Module Achats → Familles & types.** Classement des articles achetés.

**Chaque famille porte un compte comptable**, suivant le plan SYSCOHADA :
trente-cinq familles sont installées, toutes rattachées à un compte. Quelques
exemples :

| Compte | Famille |
|---|---|
| 2442 | Équipements |
| 604 | Consommables informatiques, consommables opérations (rivets) |
| 605 | Fournitures de bureau, entretien, restauration |
| 611 | Transport et logistique |
| 624 | Maintenance |
| 628 | Frais de télécommunications |

**Le code analytique** d'une ligne se compose de trois segments, repris des
codes du site, du département et de la famille :

`SITE / DÉPARTEMENT / FAMILLE`

Choisir la famille détermine donc à la fois l'imputation comptable et le
troisième segment du code analytique. C'est la seule décision à prendre à la
saisie ; le reste se déduit.

> **Les types d'achat portent des libellés provisoires.** DAF, DAI et DAH
> sont installés comme « Demande d'Achat Fournitures / Immobilisation /
> Hors-marché », mais la migration signale que le sigle exact n'est pas
> documenté. À confirmer auprès du service financier.

### 9.12 Lignes budgétaires

**Module Achats → Lignes budgétaires.** Les enveloppes sur lesquelles les
FEB s'imputent. Chaque fiche en désigne une.

### 9.13 Paramètres généraux

**Module Achats → Paramètres généraux.** Réglages transverses du module :
exercice en cours, délais, numérotation des FEB.

Le numéro d'une FEB est attribué automatiquement, par exercice.

---

## 10. L'informatique

### 10.1 Interventions & maintenance

**Module Informatique → Interventions**, ou **Opérations → Demande d'intervention** (même écran, deux portes d'entrée).

**Déclarer un problème :**

1. Choisir le type : **Préventive**, **Curative** ou **Autre** (installation
   de matériel, remplacement, diagnostic…).
2. Choisir le site : la liste des équipements ne propose que ceux de ce
   site.
3. Décrire le problème ou l'intervention, puis enregistrer.

Les onglets et compteurs (Préventives, Curatives, Autres) suivent ces
types. Toute intervention ouverte se termine avec le bouton **Terminer**.

**Traiter l'intervention** (technicien) : renseigner les travaux prévus,
puis les travaux effectués et les pièces remplacées.

| Statut après intervention | Signification |
|---|---|
| **En attente** | Déclarée, pas encore traitée |
| **En cours** | Prise en charge |
| **Partiel** | Traitée partiellement, à reprendre |
| **Résolu** | Terminée |

Une intervention porte aussi sa durée en minutes et peut recevoir un
rapport en pièce jointe.

> **Signaler une panne demande le droit de création.** L'entrée *Opérations →
> Demande d'intervention* permet à un coordinateur de signaler une panne, ce
> qui notifie les profils de maintenance. Cette action exige le droit de
> **création** sur le module Interventions : ouvrir l'écran ne suffit pas.
> Si le signalement est refusé alors que l'écran s'ouvre, c'est ce droit
> qu'il faut demander.

### 10.2 Rapport journalier

**Module Informatique → Rapport journalier.** Le compte rendu quotidien de
l'activité informatique.

Droit requis : lecture sur `rapport_journalier`.

### 10.3 Affectations IT

**Module Informatique → Affectations IT.** Gestion du support informatique :
qui prend en charge quoi, en affectant à un compte un ou plusieurs
**sous-rôles** — Maintenance, Contrôleur Production, Gestionnaire Bobines.

| Action | Droit requis sur `affectations_it` |
|---|---|
| Consulter l'écran | Lecture |
| Activer ou retirer un sous-rôle, promouvoir un compte en Support IT | **Modification** |

> **Un compte Support IT sans sous-rôle actif n'a accès à rien.** C'est le
> piège de ce profil : promouvoir quelqu'un sans lui activer un sous-rôle
> dans la foulée lui retire tout accès, sans message d'explication. Faites
> toujours les deux gestes ensemble.

### 10.4 Transfert d'équipement

**Module Informatique → Transfert équipement.**

1. Saisir le **numéro de série** de l'équipement (l'écran le recherche à la
   frappe).
2. Indiquer la destination.
3. Renseigner la **raison du transfert**.
4. Valider.

Le transfert est tracé et apparaît dans l'historique des mouvements
(section 6.3).

Droit requis : **création** sur `affectations`. La lecture seule ne suffit
pas ici, puisque l'écran sert à agir.

---

## 11. Rapports et exports

### 11.1 Le tableau de bord

Il montre ce qui vous concerne aujourd'hui, différemment selon votre métier.
Ce n'est pas l'écran d'arrivée après connexion : c'est l'accueil qui l'est
(section 3.1). On atteint le tableau de bord par le module **Dashboard**.

**La rangée d'indicateurs**, en tête, donne les chiffres du périmètre. Ils
s'affichent selon vos droits : chaque indicateur dépend d'une permission, et
vous ne voyez que ceux des modules qui vous sont ouverts.

| Indicateur | Dépend du droit de lecture sur |
|---|---|
| Engins traités, Plaques posées | `operations` |
| Bobines actives | `bobines` |
| Commandes à servir | `commandes_bobines` |
| Parc actif, Hors service, En maintenance, Fin de cycle ≤ 30 j | `equipements` |

**En dessous**, deux blocs de tête — « Production du mois » et « Taux de
validation » (points validés sur points saisis) — puis les blocs de détail,
et enfin les graphes.

**Un bouton Exporter** figure en haut à droite si vous avez le droit de
lecture sur le module `rapports`.

Un **filtre par site** est disponible pour les profils qui couvrent plusieurs
sites. Le coordinateur, lui, est verrouillé sur le sien.

> **Le rôle PDG (`lecteur`) garde la version précédente** du tableau de bord,
> volontairement : la vue exécutive lui sert de tableau de bord, et la rangée
> d'indicateurs ferait double emploi.

> **Ce qui compte comme production.** Tous les chiffres de production
> (accueil, tableau de bord, vue exécutive, Dashboard KPI) ne comptent que
> les **points 18h validés par le superviseur**, plus les points
> « historique » reconstitués depuis OptoPlate. Les points 9h et 13h, les
> brouillons et les points en attente n'y entrent pas. Le suivi de saisie
> (points en attente, taux de validation) porte, lui, sur tous les points.

> **Si le tableau de bord affiche un mois qui n'est pas le mois en cours**,
> c'est voulu : quand le mois courant n'a encore aucun relevé validé, la
> synthèse retombe sur le dernier mois qui en a, et le libellé le nomme.
> Mieux vaut un chiffre daté qu'un zéro trompeur.

### 11.2 Vue exécutive

**Module Dashboard → Vue exécutive.** La vue de direction : indicateurs
consolidés, répartitions en anneaux, évolution sur douze mois, performance
par site, alertes.

Elle est **accessible à tous les comptes**, pas réservée à la direction. Un
filtre par site et par mois permet d'en resserrer le périmètre, et les
chiffres se réactualisent sans recharger la page. Le sélecteur d'année
propose aussi **« Depuis le début de l'activité »** ; la Performance
mensuelle offre une entrée « Tous les sites ».

Sur un périmètre qui compte des sites en Re-Immatriculation, une section
**Duplicatas & Post-Immat** s'ajoute.

### 11.3 Les écrans de rapport

| Écran | Contenu |
|---|---|
| **Résumé superviseur** | Synthèse des opérations pour l'encadrement |
| **Rapports généraux** | Analyses transverses |
| **Exports** | Extraction des données |
| **Rapports & Exports** (Bobines) | Spécifique au stock bobines |
| **Simulation & projection** | Voir section 7.9 |

La plupart des écrans de liste proposent aussi un export direct, si votre
rôle a le droit d'exporter sur ce module.

### 11.4 Dashboard KPI

**Module Dashboard → Dashboard KPI.** Un troisième tableau de bord, distinct
du tableau de bord du jour (11.1) et de la vue exécutive (11.2) : une lecture
visuelle de **sept familles d'indicateurs**, chacune avec son chiffre de
tête, son détail et sa courbe d'évolution.

#### Choisir les deux périodes à comparer

En haut de l'écran, quatre réglages se suivent :

1. **Le type** : Journalier, Hebdomadaire, Mensuel ou Annuel.
2. **La période analysée** : le jour, la semaine, le mois ou l'année qui vous
   intéresse.
3. **« Comparer à »** :

    - **vs période précédente** — le réglage par défaut : septembre contre
      août, la semaine 39 contre la semaine 38 ;
    - **vs même période l'an dernier** — septembre 2026 contre septembre 2025
      (non proposé en Annuel, où c'est la même chose que la période
      précédente) ;
    - **vs période choisie…** — fait apparaître un quatrième réglage.

4. **La période de comparaison**, si vous l'avez choisie librement : par
   exemple juin contre avril, ou la semaine 6 contre la semaine 12.

Les semaines se choisissent dans une liste qui donne leurs dates
(« S38 · 14/09 → 20/09 »).

L'en-tête rappelle en permanence ce qui est comparé : « Juin 2026 · comparé à
Avril 2026 ».

> **Quand la période analysée n'est pas terminée.** Avec **« vs période
> précédente »**, l'écran compare à nombre de jours égal : le 24 septembre,
> septembre (24 jours) est comparé au 1ᵉʳ → 24 août, et l'en-tête l'indique.
> Dès que vous choisissez vous-même la période de comparaison, les deux
> périodes sont comparées **entières** : un message vous le rappelle, car une
> période à moitié écoulée paraît forcément en baisse. Fiez-vous alors à la
> tuile **« Plaques par jour »**, qui divise chaque total par ses jours
> réellement écoulés et reste comparable — c'est aussi elle qu'il faut lire
> pour comparer février (28 jours) à mars (31 jours).

#### Ce que montre chaque famille

| Famille | Ce qu'elle montre |
|---|---|
| **Production** | Plaques posées, engins traités et plaques par jour sur la période analysée, comparés à la période de comparaison ; courbe des deux périodes superposées |
| **Bobines** | Actives, épuisées, retirées ; taux d'utilisation réel ; détail par série ; **couverture** : combien de jours le stock tient au rythme des 30 derniers jours, et le premier format qui s'épuisera — **état à ce jour** |
| **PMMA** | Consommation comparée entre les deux périodes, consommation par type ; stock et alertes de seuil **à ce jour** |
| **Rivets** | Consommation comparée entre les deux périodes ; stock et sites sous seuil **à ce jour** |
| **Commandes** | Total, servies, en cours ; taux de satisfaction et délai de la période de comparaison ; taux sur six périodes |
| **Équipements** | Disponibles, hors service, en maintenance, affectés — **état à ce jour** |
| **Sites** | Production par site sur les deux périodes, et classement |
| **Duplicatas & Post-Immat** | Pour les sites en Re-Immatriculation : mêmes comparaisons que la production |
| **Fiabilité & pertes** | Sites sans point soumis au jour de référence, rapprochement avec EMUCI (plaques déclarées contre plaques posées), taux de films endommagés, causes et étapes |

> **Pourquoi certains panneaux ne changent pas avec les périodes.** Le parc
> de bobines, les stocks disponibles et les équipements sont des photos de
> l'instant : l'application ne garde pas le stock de juin. Ils affichent donc
> toujours la situation du jour, avec la mention « à ce jour ».

> **Voir le jour, la semaine, le mois ou l'année en cours.** Choisissez le
> type de période correspondant et laissez « vs période précédente » : la
> journée est comparée à la veille, la semaine en cours aux mêmes jours de
> la semaine passée, le mois et l'année à nombre de jours égal.

> **Seuls les points 18h validés comptent.** Les consommations de PMMA et
> de rivets, comme la production, ne tiennent compte que de ces points.
> Un site qui n'a rien produit sur la période analysée mais avait produit sur
> la période de comparaison reste au classement : c'est justement ce qu'une
> comparaison doit faire voir.

**Le bandeau « Points d'attention »**, en tête, résume ce qui demande une
action : sites sans point soumis, points à valider par le superviseur,
sites en écart avec EMUCI, jours de stock de films ou d'un format qui
baissent, interventions ouvertes… Chaque ligne mène à l'écran de détail,
comme le titre de chaque panneau. Les **sites attendus** sont les sites
de pose actifs qui détiennent une bobine active.

**Filtrer et enregistrer une vue.** Vous pouvez sélectionner un ou plusieurs
sites (aucune sélection = tout votre périmètre) et les périodes, puis
**enregistrer cette combinaison comme vue** — personnelle ou partagée avec
d'autres. La vue retient aussi la période de comparaison. Seul vous pouvez
supprimer une vue que vous avez créée, même partagée.

Le coordinateur de site reste verrouillé sur son site, comme partout
ailleurs.

Droit requis : lecture sur `kpi_dashboard`.

---

## 12. L'administration

### 12.1 Utilisateurs

**Administration → Utilisateurs.** Créer un compte, modifier ses
informations, l'activer ou le désactiver, lui attribuer un rôle et un site.

La saisie est contrôlée : une adresse e-mail ou un numéro de téléphone mal
formé est signalé en rouge dès que vous quittez le champ.

La case **« Recevoir les notifications par mail »** coupe ou rétablit les
mails d'une personne (§12.9). Chacun peut aussi la régler dans Mon Profil.

> **Un compte désactivé n'est pas supprimé.** Ses données et sa trace dans
> le journal d'audit restent. C'est ce qui permet de savoir qui a fait quoi,
> même après un départ.

### 12.2 Permissions

**Administration → Permissions.** Chaque rôle reçoit, module par module,
cinq droits : **lire**, **créer**, **modifier**, **supprimer**,
**exporter**. Cinquante-sept modules sont gérés depuis cet écran, rangés
par onglets de groupe.

- **Exporter** commande réellement les exports (PMMA, stock bobines,
  rapports GSB, inventaires, rivets, équipements, résumé superviseur,
  sites) : sans ce droit, le bouton disparaît.
- Certains modules sont des **fonctions** plutôt que des écrans : options
  **Acheteur** et **Magasin** (§9), droit **Valider** de chaque inventaire
  (§7.5). Ils se cochent pour n'importe quel rôle.
- Les **pièces jointes** (BL, FEB, documents fournisseurs) ne s'ouvrent
  qu'avec le droit de lecture de l'écran correspondant.
- Le rôle **Admin** n'est pas modifiable. Chaque modification est inscrite
  au journal d'audit, droit par droit (avant / après).
- Un rôle **créé depuis cet écran** voit les menus des modules qui lui
  sont ouverts.
- **Support IT** : ses sous-rôles (§10.3) ne règlent que les modules
  techniques ; pour le reste, il suit la matrice comme les autres rôles.

> **Configuration absente n'est pas refus sur le tableau de bord — mais
> l'est sur les pages.** Un rôle dont aucune permission n'est renseignée
> voit un tableau de bord complet ; ses pages, elles, restent toutes
> fermées. Ne concluez donc jamais de l'apparence de l'accueil : c'est le
> fait d'ouvrir la page elle-même qui dit le vrai. Dès que les permissions
> d'un rôle sont renseignées, elles s'appliquent pleinement, sur les deux
> plans.

Si quelqu'un signale qu'il ne voit pas un écran auquel il devrait avoir
accès, vérifiez d'abord ses permissions ici avant de conclure à un défaut du
logiciel.

### 12.3 Délégations

**Administration → Délégations.** Un utilisateur peut déléguer
temporairement ses visas à un autre. Utile pour les congés : sans cela, une
demande reste bloquée à l'étape d'une personne absente.

### 12.4 Sites

**Administration → Sites.** Le référentiel des sites : nom, type,
localisation, coordonnées géographiques, état actif ou non.

Le **type** du site compte : les sites de type **magasin** et **entrepôt**
sont les dépôts qui portent le stock central (articles, PMMA, rivets,
bobines) et d'où partent les commandes de site.

- **Opération** (sites de saisie ou de pose) : **Primo** ou
  **Re-Immatriculation**. Un site en Re-Immatriculation saisit aussi des
  duplicatas et des post-immat (§5.1).
- **Point généré depuis OptoPlate, à partir du…** : voir §5.7.
- Le **calculateur de capacité** se base sur les équipements actifs des
  entrepôts et magasins (tous ou au choix), selon les états retenus.

### 12.5 Départements

**Administration → Départements.** Le référentiel des départements et le
rattachement des utilisateurs.

> **Ce rattachement n'est pas décoratif.** Il détermine le N+1 dans les
> circuits de visa, et il conditionne la possibilité même de déposer une
> demande interne. Un utilisateur non rattaché ne peut rien soumettre.

### 12.6 Nomenclatures

**Administration → Nomenclatures.** Le catalogue des types d'équipements :
code, catégorie, libellé, description, **durée de vie en mois**, **seuil
d'alerte**, **durée d'amortissement**.

La durée de vie alimente le calcul des fins de cycle signalées sur le
tableau de bord.

### 12.7 Journal d'audit

**Administration → Audit.** La trace des actions faites dans l'application :
qui, quoi, quand.

C'est l'écran à ouvrir quand une donnée a changé et que personne ne sait
pourquoi.

### 12.8 Référentiels & capacités

**Administration → Référentiels & capacités.** Les quantités de
conditionnement — combien de films dans une bobine selon sa série, combien
d'unités dans un carton de PMMA — qui alimentent les stocks créés et
l'outil de simulation (section 7.9).

- **Par série de bobines** : posez une capacité en une saisie pour toutes les
  versions de la série. Vous pouvez aussi corriger un type individuel juste
  en dessous, si un modèle particulier diffère.
- **Pour le PMMA** : le **code** d'un type n'est jamais modifiable ici — le
  changer romprait le lien avec le stock et l'historique déjà enregistrés
  sous ce code. Créez plutôt un nouveau type si besoin.
- L'écran signale les types **présents dans les stocks mais absents du
  catalogue** : mieux vaut les compléter que les laisser retomber sur une
  valeur par défaut silencieuse.
- Il gère aussi, **par rôle**, l'écran d'ouverture par défaut du Dashboard
  KPI (section 11.4) — ce que chacun voit en arrivant, avant d'avoir touché
  un filtre.

> **Une capacité fausse ne se voit pas immédiatement.** Elle se répercute
> silencieusement sur toutes les projections de stock et sur tout nouveau
> stock créé ensuite. Vérifiez cet écran après tout changement de
> conditionnement chez un fournisseur.

### 12.9 Mails

**Administration → Mails** (droit de modification sur les utilisateurs).
Chaque notification de l'application part aussi par mail, en file
d'attente, environ toutes les cinq minutes. L'écran suit ces envois :

- état de l'envoi, compteurs des dernières 48 heures, alerte si des mails
  attendent depuis plus de 15 minutes ;
- liste filtrable, avec pour chaque mail son état et le **motif
  d'erreur** en cas de refus ;
- **relance** d'un mail ou de tous les échecs, et **mail de test**.

Un mail n'est pas envoyé si la notification a déjà été lue, si le
destinataire n'a pas d'adresse valide, s'il a plus de 48 heures, ou si la
personne a coupé ses mails — dans ce dernier cas, même les mails déjà en
attente sont abandonnés. Trois échecs d'affilée marquent le mail en
échec.

---

## 12 bis. Les écrans hors menu

Certains écrans ne figurent dans aucun menu : on y arrive depuis une liste,
un bouton, ou automatiquement. Ils comptent autant que les autres.

### Mon Profil

Vos informations personnelles, la section **« Changer le mot de passe »**
(section 2.4) et l'interrupteur **« Recevoir mes notifications par
mail »** : coupé, vos notifications restent visibles dans l'application. Accessible depuis votre nom, en bas de la barre latérale.

### Détail d'une FEB, et traitement

Depuis **Mes FEB**, **File d'attente Achats** ou **Mes visas**, on ouvre le
**détail** d'une fiche : son en-tête, ses lignes, son circuit et l'historique
de ses visas.

L'écran de **traitement** est celui de l'acheteur : c'est là qu'il complète
la fiche, la bascule en commande, et suit son exécution.

Trois exports PDF existent : la fiche seule, la fiche avec son circuit de
validation, et le bon de commande.

### Détail d'un inventaire

Les écrans **Inventaire bobines**, **rivets**, **PMMA** et **équipements**
listent les sessions. On ouvre une session pour arriver sur son **détail**,
où se fait réellement le comptage, ligne par ligne.

Une session en cours porte le statut **brouillon** tant qu'elle n'est pas
clôturée.

### Réceptions de commandes

La réception d'une commande de site se fait depuis la liste des
Commandes (§6.7), par le coordinateur du site destinataire.

### Import stock bobines

Chargement du stock de bobines depuis un fichier **.xlsx**. Comme pour
l'import EMUCI, les colonnes attendues sont vérifiées et l'import est refusé
si l'une manque.

### Impression du point journalier

Un point journalier s'exporte en PDF depuis sa fiche.

> **Un brouillon ne s'imprime que par son auteur.** Tant qu'un point est en
> brouillon, seul celui qui l'a saisi peut l'imprimer. Une fois le point
> soumis puis validé, il redevient consultable selon les droits habituels.

> **Un raccourci à connaître.** L'ancienne adresse `consommables.php` renvoie
> désormais vers **Articles** : le module a été renommé, les anciens liens et
> favoris continuent de fonctionner.

---

## 13. Questions fréquentes

**L'application me renvoie sans arrêt sur « Changer mot de passe ».**
Votre mot de passe est provisoire, attribué par un administrateur. Tant qu'il
n'est pas changé, aucun autre écran ne s'ouvre. Voir section 2.1.

**Je veux changer mon mot de passe mais l'écran me renvoie à l'accueil.**
L'écran « Changer mot de passe » ne sert qu'au changement imposé. Pour le
faire de votre propre initiative, passez par **Mon Profil**. Voir section 2.3.

**Je ne vois pas un module sur l'accueil.**
Votre rôle ne l'ouvre pas. Ce n'est pas un défaut d'affichage. Voyez un
administrateur si vous pensez que c'est une erreur de configuration.

**Je cherche un écran mais il n'est pas dans la barre latérale.**
La barre ne montre que les écrans du module en cours. Repassez par l'accueil
et ouvrez le module auquel cet écran appartient. Voir section 3.2.

**Mes chiffres n'apparaissent pas dans les rapports.**
Seuls les **points 18h validés par le superviseur** comptent. Un brouillon,
un point soumis ou un point 9h/13h n'apparaît pas. Voir section 11.1.

**Je ne peux pas soumettre une demande interne.**
Vous n'êtes probablement pas rattaché à un département. Le message d'erreur
le dit explicitement. Un administrateur doit vous assigner.

**Je ne peux plus modifier un point journalier.**
Il est soumis ou validé. Le superviseur peut vous le renvoyer avec
« Demander une modification » : le bouton **Corriger** apparaît alors sur
la carte du point.

**Le stock de ma bobine n'a pas baissé après mon point.**
C'est normal tant que le point 18h n'est pas validé par le superviseur :
le stock ne sort qu'à cette validation. Voir section 5.2.

**Ma bobine reçue n'apparaît pas dans mon point journalier.**
Elle est arrivée « en stock ». Faites une demande d'utilisation : elle
apparaîtra une fois en cours. Voir section 7.4.

**L'inventaire refuse ma saisie : « choisissez le moment du comptage ».**
Indiquez d'abord si vous comptez le matin avant les activités ou le soir,
puis confirmez. Voir section 7.5.

**L'inventaire ne se valide pas.**
Vérifiez que toutes les lignes sont comptées et que le point 18h de chaque
jour de comptage est validé. Voir section 7.5.

**Le tableau de bord affiche un mois qui n'est pas le mois en cours.**
C'est voulu. Voir section 11.1.

**Une demande est bloquée dans le circuit.**
Regardez à quelle étape elle se trouve : le visa attendu est peut-être celui
d'une personne absente. Une délégation peut débloquer la situation.

**Le remplissage automatique ne propose aucun agent.**
L'annuaire des agents est vide ou n'a pas été importé. Voir section 8.10.

**L'écran des paliers refuse ma saisie.**
Les paliers doivent couvrir tout l'intervalle des montants, sans trou. Voir
section 9.9.

**Ma demande d'accès est approuvée mais je n'ai toujours pas l'accès.**
Approuvée veut dire autorisée ; il reste le traitement par l'informatique.
Voir section 8.7.

**Je ne reçois pas les notifications par mail.**
Vérifiez l'interrupteur dans Mon Profil. Un administrateur peut voir dans
Administration → Mails si un envoi a échoué et pourquoi. Voir section 12.9.

**Mon observation reste en attente alors que j'ai relancé plusieurs fois.**
Au bout de trois relances, elle passe au statut Escaladée, ce qui la rend
plus visible pour le superviseur — mais c'est toujours lui qui doit la
prendre en charge. Voir section 5.5.

**Sur le Dashboard KPI, ma période en cours paraît en forte baisse.**
Vous comparez probablement à une période choisie, donc entière : une période
à moitié écoulée paraît forcément en baisse. Regardez la tuile « Plaques par
jour », ou revenez à « vs période précédente », qui compare à nombre de
jours égal. Voir section 11.4.

**La simulation de stock affiche un résultat différent de ce que j'attendais.**
Vérifiez d'abord les capacités de conditionnement dans Référentiels &
capacités (section 12.8) : une capacité mal renseignée pour un format fausse
toute projection qui l'utilise.

---

## 14. À qui s'adresser

| Besoin | Interlocuteur |
|---|---|
| Compte, mot de passe, permissions | Administrateur |
| Rattachement à un département | Administrateur |
| Problème d'accès aux plateformes | Support IT, via une demande interne |
| Anomalie de données | Superviseur du module concerné |
| Paliers, familles, lignes budgétaires | Service financier |
| Capacités de conditionnement, résultat de simulation inattendu | Administrateur (écran Référentiels & capacités) |

---

## Ce qui reste à décider

Rien de ce qui suit n'est un trou dans la documentation : les mécanismes
sont en place et décrits. Ce sont des **valeurs provisoires que le logiciel
signale lui-même** comme n'ayant pas été arrêtées par l'entreprise.

| Point | Section | Ce qui est en place | Ce qui manque |
|---|---|---|---|
| Paliers de validation | 9.9 | Trois paliers fonctionnels (500 000 et 5 000 000 XOF) | Les seuils réels, la migration les dit « arbitraires » |
| Types d'achat | 9.11 | DAF, DAI, DAH avec des libellés développés | Le sigle exact, non documenté dans la spécification |
| Lignes budgétaires | 9.12 | Six lignes, comportement « alerte » | Les enveloppes, toutes à vide : aucun montant n'est plafonné |

Tant que les paliers ne sont pas arrêtés, une FEB peut suivre un circuit de
visa qui ne correspond pas à la règle de l'entreprise. C'est le point le plus
sensible des trois.

---

## Suivi des versions de ce manuel

| Version | Date | Base logicielle | Modifications |
|---|---|---|---|
| 1.0 | 2026-08-29 | `5ecb558` | Première édition : 4 circuits principaux, rôles, permissions |
| 2.0 | 2026-08-29 | `5ecb558` | Édition complète : les 61 écrans, structure par module |
| 2.1 | 2026-08-29 | `5ecb558` | Changement de mot de passe imposé à la première connexion ; ajout des écrans hors menu |
| 2.2 | 2026-08-29 | `5ecb558` | Navigation corrigée : l'accueil est le point de départ, la barre latérale ne montre que le module en cours ; tableau de bord v2 |
| 2.3 | 2026-08-29 | `5ecb558` | Règles de visa, paliers et plan comptable documentés : ils étaient dans le code, non « à préciser » |
| 2.4 | 2026-08-29 | `5ecb558` | Point journalier : quatre statuts, non deux — « en attente de validation » et « rejeté » manquaient |
| 3.0 | 2026-09-21 | `437102d` (RUTHAXELLE/stockapp) | Verrouillage du compte après cinq erreurs (2.2) ; inventaires réécrits — la session est ouverte par l'administration, un coordinateur ne crée plus son inventaire (7.5) ; conséquences visibles des correctifs de droits : brouillon imprimable par son seul auteur, import EMUCI et signalement de panne en droit de création, affectations IT en droit de modification, écrans Demandes réellement filtrés ; limite de téléversement portée à 50 Mo |
| 4.0 | 2026-09-24 | `6b268a6` (GayeGuy/ERP-EMUCI-V2) | **Corrigé le dépôt de référence** : la v3.0 avait été rédigée depuis RUTHAXELLE/stockapp, qui n'était plus la référence de `main` depuis fin août 2026. **Ajouté** : Suivi des observations (5.5), Traçabilité endommagements (7.6), Simulation & projection de stocks (7.7), Dashboard KPI (11.4), Référentiels & capacités (12.8). 70 entrées de menu, 56 modules gérés depuis Admin → Permissions (contre 37 précédemment documentés) |
| 4.1 | 2026-09-24 | `59e3b0d` (GayeGuy/ERP-EMUCI-V2) | Dashboard KPI : choix libre de la période de comparaison, règle « à date égale » ou « périodes entières », tuile « Plaques par jour », bandeau « Aujourd'hui », panneaux « à ce jour » (11.4) ; nouvelle question fréquente |
| 4.2 | 2026-09-24 | `ab9f7ff` (GayeGuy/ERP-EMUCI-V2) | Dashboard KPI : bandeau « Aujourd'hui » retiré ; comment afficher le jour, la semaine, le mois ou l'année en cours (11.4) |
| 4.3 | 2026-09-24 | `2d9e32f` (GayeGuy/ERP-EMUCI-V2) | Couverture du panneau Bobines (11.4) et consommation observée de la simulation (7.7) calculées sur les points journaliers |
| 5.0 | 2026-11-19 | `5866232` (GayeGuy/ERP-EMUCI-V2) | Point journalier : stock retiré à la validation du 18h, comparaison OptoPlate, non conformes, endommagements justifiés, Re-Immatriculation, journée sans production (5) ; commandes de site et archives des BL (6.7, 6.8) ; équipements, articles, PMMA, rivets (6) ; validation du stock du jour (7.1) ; statuts des bobines (7.4) ; inventaires réécrits, écarts, export (7.5 à 7.7) ; visa N+1 du département du bénéficiaire (8.6) ; options Acheteur et Magasin (9) ; interventions « Autre » (10.1) ; production = points 18h validés, Points d'attention et Fiabilité & pertes (11) ; permissions, sites, mails (12.2, 12.4, 12.9) ; 73 entrées de menu |

> **Tenir ce manuel à jour.** Il décrit l'état du logiciel au commit
> indiqué. À chaque évolution fonctionnelle notable, mettez à jour la
> section concernée et ajoutez une ligne au tableau ci-dessus. Un manuel qui
> ne dit pas sur quelle version il porte ne vaut rien.
