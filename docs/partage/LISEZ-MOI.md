# Documents à partager

Deux fichiers HTML **autonomes**, destinés à être joints à un e-mail.

| Fichier | Public | Contenu |
|---|---|---|
| `Manuel_utilisation_ERP_EMUCI.html` | Coordinateurs, superviseurs, tout utilisateur | Comment se servir de l'application |
| `Specification_ERP_EMUCI.html` | Équipe technique, direction | Ce que le système est, ses règles et ses limites |

## Comment s'en servir

- **Ouvrir** : double-clic. Aucun logiciel à installer, n'importe quel
  navigateur suffit.
- **Convertir en PDF** : ouvrir le fichier, puis `Ctrl+P` et
  « Enregistrer au format PDF ». La mise en page d'impression est prévue :
  le sommaire passe en première page, les tableaux et les schémas ne sont
  pas coupés en deux.
- **Envoyer** : joindre le fichier tel quel. Il ne dépend d'aucun serveur.

## Deux points à connaître avant d'envoyer

**Les polices viennent de Google Fonts.** Un destinataire hors ligne verra le
document dans la police système de sa machine. La mise en page et la
lisibilité restent correctes, seul le dessin des caractères change.

**Les deux documents portent leur version logicielle** : commit `5ecb558` du
29 août 2026. Ils décrivent l'application à cette date. Après une évolution
fonctionnelle, régénérez-les plutôt que de renvoyer ceux-ci.

## Régénérer après une mise à jour

Les sources sont `docs/MANUEL_UTILISATION.md` et `docs/SPECIFICATION.md`.
Mettez-les à jour, puis reconstruisez les fichiers de partage.
