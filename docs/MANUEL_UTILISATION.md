# Manuel d'utilisation — ERP EMUCI

**Version du logiciel** : branche `main`, commit `5ecb558` (29 août 2026)
**Version du manuel** : 2.3
**Périmètre couvert** : 61 entrées de menu et les écrans hors menu,
10 modules, 16 rôles, 21 sites actifs

> **Provenance de ce document.** Il est établi à partir du code source, pas
> d'une session d'utilisation. La structure des menus, les rôles, les
> statuts, les circuits de validation et les règles de gestion sont exacts et
> vérifiables dans le dépôt. En revanche les détails d'écran (position d'un
> bouton, libellé exact d'un message) n'ont pas été relevés un par un.
>
> Là où le logiciel signale lui-même qu'une valeur est provisoire, le manuel
> le répercute plutôt que de la présenter comme arrêtée. Voir **« Ce qui reste
> à décider »**, en fin de document.

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
achats.

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
initiative**, voyez la section 2.3.

### 2.2 Mot de passe oublié

Cliquez sur « Mot de passe oublié ? » sous le formulaire de connexion,
saisissez votre adresse, et un lien de réinitialisation vous est envoyé par
e-mail. Le mot de passe que vous définissez par ce lien est définitif : il
ne déclenche pas de changement imposé.

**Compte bloqué, ou e-mail qui n'arrive pas** : contactez un administrateur.

### 2.3 Changer son mot de passe volontairement

Écran **Mon Profil**, section « Changer le mot de passe ». C'est le seul
endroit pour le faire hors contrainte.

### 2.4 Et ensuite

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

### 3.4 Les dix modules

| Module | Ce qu'on y fait | Section |
|---|---|---|
| **Dashboard** | Tableau de bord du jour, vue exécutive | [11](#11-rapports-et-exports) |
| **Stock** | Équipements, articles, PMMA, bobines, rivets, commandes | [6](#6-le-stock) |
| **Bobines** | Commandes, validation du stock du matin, rapports | [7](#7-bobines-rivets-pmma-et-inventaires) |
| **Inventaire** | Inventaires physiques et écarts | [7](#7-bobines-rivets-pmma-et-inventaires) |
| **Opérations** | Point journalier, interventions, point et import EMUCI | [5](#5-le-point-journalier-et-emuci) |
| **Informatique** | Interventions, rapport journalier, affectations IT | [10](#10-linformatique) |
| **Rapports** | Résumé superviseur, rapports généraux, exports | [11](#11-rapports-et-exports) |
| **Demandes internes** | Demandes administratives et circuits de visa | [8](#8-les-demandes-internes) |
| **Achats** | Expression de besoin, visas, suivi, réceptions | [9](#9-les-achats) |
| **Administration** | Utilisateurs, rôles, permissions, sites, audit | [12](#12-ladministration) |

---

## 4. Les rôles

Seize rôles existent. Le vôtre détermine ce que vous voyez et ce que vous
pouvez faire.

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
change rien.

---

## 5. Le point journalier et EMUCI

C'est le relevé quotidien de production d'un site. Tout le reste en dépend.

### 5.1 Saisir un point — coordinateur de site

1. Module **Opérations**, puis **Point journalier**.
2. Renseigner la date, le type de point et les quantités : véhicules par
   catégorie, plaques posées, rivets utilisés, rivets endommagés, heures de
   travail.
3. Enregistrer.

| Statut | Ce que ça veut dire |
|---|---|
| **Brouillon** | Saisi mais pas transmis. **Ses chiffres ne comptent nulle part.** |
| **Validé** | Transmis et validé par le superviseur. Compte dans tous les indicateurs. |

> **Le piège à connaître.** Un brouillon oublié est invisible pour tout le
> monde sauf vous, et ses chiffres n'apparaissent dans aucun rapport. Le
> tableau de bord du superviseur affiche le nombre de points en attente
> précisément pour éviter cela.

### 5.2 Valider les points — superviseur opération

1. Le tableau de bord signale les points en attente de validation.
2. Ouvrir le point, vérifier les quantités.
3. Valider, ou rejeter en indiquant le motif.

### 5.3 Corriger un point déjà validé

Un point validé ne se modifie plus directement. Le coordinateur dépose une
**demande de correction de saisie**, que le superviseur traite. C'est ce qui
garantit qu'un chiffre validé ne bouge pas sans laisser de trace.

### 5.4 Import EMUCI

**Module Opérations → Import EMUCI.** Charge le fichier produit par la
plateforme nationale, qui donne le nombre de plaques par site et par
**statut de plaque** (en service, déclarée cassée, etc.).

1. Sélectionner le fichier et la date d'import.
2. Lancer l'import.
3. L'écran affiche un **résumé par site** et le **détail par site et
   statut**.

Si des colonnes attendues manquent, l'import est refusé avec la liste des
colonnes absentes : corrigez le fichier plutôt que de forcer.

### 5.5 Point EMUCI

**Module Opérations → Point EMUCI.** Compare ce que la plateforme nationale
a enregistré avec ce que les coordinateurs ont déclaré dans leurs points
journaliers, et met les **écarts** en évidence.

C'est l'écran de rapprochement : un écart signifie soit une erreur de
saisie, soit une plaque posée mais non remontée, soit l'inverse. Il se
commente directement à l'écran, pour que le motif reste attaché au chiffre.

---

## 6. Le stock

### 6.1 Équipements

Deux entrées de menu pour un même écran, filtré différemment :

- **Équipements Informatique** — postes, imprimantes, matériel IT ;
- **Équipements Opérationnel** — matériel de pose et de terrain.

Chaque équipement porte un numéro de série, une nomenclature, un site, un
état (neuf, bon, usagé, réformé), une date de mise en service et une date de
fin de cycle. Les fins de cycle proches sont signalées sur le tableau de
bord.

Droit requis : lecture sur le module `equipements`.

### 6.2 Articles

**Module Stock → Articles.** Le catalogue des consommables : code, libellé,
type, unité, seuil d'alerte, prix unitaire, stock global.

> **À savoir.** Le stock d'un article est **global**, pas ventilé par site.
> C'est pourquoi le compte des alertes de stock reste global même quand vous
> filtrez le tableau de bord sur un site.

### 6.3 Historique des mouvements

**Module Stock → Historique des mouvements.** Trace tous les mouvements
d'équipements : entrées, sorties, transferts, affectations. C'est l'écran à
ouvrir quand on cherche où est passé un matériel.

Non accessible au coordinateur de site.

### 6.4 PMMA

**Module Stock → PMMA.** Suivi du stock de PMMA par site et par type, avec
seuil d'alerte. Son inventaire et ses écarts ont leurs propres écrans
(section 7).

### 6.5 Rivets

**Module Stock → Rivets.** Le stock de rivets par site et par type. L'écran
sert aussi à **approvisionner** un site : on saisit la quantité livrée et le
stock du site est crédité.

Le seuil d'alerte est de **200 rivets** : en dessous, le site est signalé sur
les tableaux de bord et dans la synthèse du gestionnaire de stock.

Le coordinateur de site ne voit que le stock de son site.

### 6.6 Vignette

**Module Stock → Vignette.** Même écran que Bobines, filtré sur la catégorie
vignette : les types y sont Réservoir et Pare-brise, au lieu des types de
plaques.

---

## 7. Bobines, rivets, PMMA et inventaires

### 7.1 La validation du stock du matin

Chaque site déclare son stock en début de journée.

1. Module **Bobines**, puis **Validation stock jour**.
2. Saisir les quantités constatées.
3. Valider.

Trois résultats possibles : **validé**, **validé avec écarts**, ou **validé
automatiquement**. Un écart n'est pas une faute en soi, mais il est tracé et
doit être expliqué.

### 7.2 Commander des bobines

**Module Bobines → Commande bobines.** La demande passe par le gestionnaire
de stock bobines, qui la sert.

| Statut | Signification |
|---|---|
| **En attente** | Déposée, pas encore traitée |
| **Validée** | Acceptée, à servir |
| **Livrée / Reçue** | Servie |

### 7.3 Vue stock par site

**Module Bobines → Vue stock par site.** Tableau de synthèse du stock de
bobines, site par site. Écran de consultation : on n'y saisit rien.

### 7.4 Les bobines elles-mêmes

Une bobine porte un numéro, un type, une série, un site, et un compte de
films : total, utilisés, endommagés, restants.

| Statut | Signification |
|---|---|
| **En stock** | Reçue, pas encore ouverte |
| **En cours** | Ouverte et en cours d'utilisation |
| **Épuisée** | Films consommés |
| **Retirée** | Sortie du circuit |

Seules les bobines **en cours** comptent dans les films restants affichés
sur les tableaux de bord.

### 7.5 Les quatre inventaires

Le principe est le même pour les quatre : on ouvre une session d'inventaire,
on saisit le comptage physique, puis l'écran d'écarts compare au stock
théorique.

| Inventaire | Écran d'écarts | Ce qu'on compte |
|---|---|---|
| **Inventaire bobines** | Écarts bobines | Bobines et films restants |
| **Inventaire rivets** | Écarts rivets | Quantités de rivets par site |
| **Inventaire PMMA** | Écarts PMMA | PMMA par site et par type |
| **Inventaire équipements** | Écarts équipements | Présence physique du matériel |

**Déroulé type :**

1. Ouvrir une session d'inventaire sur l'écran correspondant.
2. Saisir le comptage physique, site par site.
3. Clôturer la session.
4. Ouvrir l'écran **Écarts** correspondant : il liste les différences entre
   le physique et le théorique.
5. Justifier chaque écart.

> **Un écart non justifié reste ouvert.** C'est voulu : la trace de
> l'explication vaut autant que la correction du chiffre.

---

## 8. Les demandes internes

Neuf types de demandes administratives, chacune avec son circuit de visas.

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

Un brouillon s'y reprend et se complète.

### 8.6 Viser une demande

1. Module **Demandes internes**, puis **À valider**.
2. Ouvrir la demande, lire les champs et les visas déjà donnés.
3. Approuver, ou rejeter. **Un rejet exige un motif** : la personne doit
   savoir quoi corriger.

Le **N+1** n'est pas un rôle mais le responsable du département du
demandeur. Il est résolu automatiquement.

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

- **Le N+1** n'est pas un rôle. C'est le responsable du département du
  demandeur, résolu automatiquement.
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

1. Ouvrir **Nouvelle intervention**.
2. Décrire le problème observé, désigner l'équipement et le site.
3. Enregistrer.

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

### 10.2 Rapport journalier

**Module Informatique → Rapport journalier.** Le compte rendu quotidien de
l'activité informatique.

Droit requis : lecture sur `rapport_journalier`.

### 10.3 Affectations IT

**Module Informatique → Affectations IT.** Gestion du support informatique :
qui prend en charge quoi.

Droit requis : lecture sur `affectations_it`.

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
chiffres se réactualisent sans recharger la page.

### 11.3 Les écrans de rapport

| Écran | Contenu |
|---|---|
| **Résumé superviseur** | Synthèse des opérations pour l'encadrement |
| **Rapports généraux** | Analyses transverses |
| **Exports** | Extraction des données |
| **Rapports & Exports** (Bobines) | Spécifique au stock bobines |

La plupart des écrans de liste proposent aussi un export direct, si votre
rôle a le droit d'exporter sur ce module.

---

## 12. L'administration

### 12.1 Utilisateurs

**Administration → Utilisateurs.** Créer un compte, modifier ses
informations, l'activer ou le désactiver, lui attribuer un rôle et un site.

La saisie est contrôlée : une adresse e-mail ou un numéro de téléphone mal
formé est signalé en rouge dès que vous quittez le champ.

> **Un compte désactivé n'est pas supprimé.** Ses données et sa trace dans
> le journal d'audit restent. C'est ce qui permet de savoir qui a fait quoi,
> même après un départ.

### 12.2 Permissions

**Administration → Permissions.** Chaque rôle reçoit, module par module,
cinq droits : **lire**, **créer**, **modifier**, **supprimer**,
**exporter**. Trente-sept modules sont gérés.

> **Configuration absente n'est pas refus.** Un rôle dont aucune permission
> n'est renseignée voit le profil par défaut, pas une page vide. C'est
> délibéré : une configuration oubliée ne doit pas ressembler à un refus.
> Dès que ses permissions sont renseignées, elles s'appliquent pleinement.

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

Le **type** du site compte : seuls les sites de type **magasin** portent le
stock central utilisé par les réceptions d'achats (section 9.7).

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

---

## 12 bis. Les écrans hors menu

Certains écrans ne figurent dans aucun menu : on y arrive depuis une liste,
un bouton, ou automatiquement. Ils comptent autant que les autres.

### Mon Profil

Vos informations personnelles, et la section **« Changer le mot de passe »**
(section 2.3). Accessible depuis votre nom, en bas de la barre latérale.

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

Écran de réception des commandes sur site, atteint depuis le suivi des
commandes. Droit requis : **modification** sur le module `receptions` pour
enregistrer une réception, lecture seule pour la consulter.

### Import stock bobines

Chargement du stock de bobines depuis un fichier **.xlsx**. Comme pour
l'import EMUCI, les colonnes attendues sont vérifiées et l'import est refusé
si l'une manque.

### Impression du point journalier

Un point journalier s'exporte en PDF depuis sa fiche.

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
Vérifiez que vos points journaliers sont **validés** et non restés en
brouillon. Un brouillon ne compte nulle part.

**Je ne peux pas soumettre une demande interne.**
Vous n'êtes probablement pas rattaché à un département. Le message d'erreur
le dit explicitement. Un administrateur doit vous assigner.

**Je ne peux plus modifier un point journalier.**
Il est validé. Déposez une demande de correction de saisie.

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

**Le compte des alertes de stock ne change pas quand je filtre sur un site.**
C'est normal : le stock des articles est global, pas ventilé par site. Voir
section 6.2.

---

## 14. À qui s'adresser

| Besoin | Interlocuteur |
|---|---|
| Compte, mot de passe, permissions | Administrateur |
| Rattachement à un département | Administrateur |
| Problème d'accès aux plateformes | Support IT, via une demande interne |
| Anomalie de données | Superviseur du module concerné |
| Paliers, familles, lignes budgétaires | Service financier |

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

> **Tenir ce manuel à jour.** Il décrit l'état du logiciel au commit
> indiqué. À chaque évolution fonctionnelle notable, mettez à jour la
> section concernée et ajoutez une ligne au tableau ci-dessus. Un manuel qui
> ne dit pas sur quelle version il porte ne vaut rien.
