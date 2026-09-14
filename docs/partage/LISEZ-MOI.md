# Documents à partager

Deux fichiers HTML **autonomes**, destinés à être joints à un e-mail.

| Document | Public | Contenu |
|---|---|---|
| Manuel d'utilisation | Coordinateurs, superviseurs, tout utilisateur | Comment se servir de l'application |
| Spécification | Équipe technique, direction | Ce que le système est, ses règles et ses limites |

Chacun existe en **deux formats** :

| Format | Usage |
|---|---|
| `.pdf` | **À joindre à un e-mail.** 28 pages, texte cherchable, rien à installer chez le destinataire. |
| `.html` | Pour lire à l'écran ou régénérer le PDF après une modification. |

## Régénérer les PDF

Ils sont produits depuis les fichiers HTML par le moteur de rendu de Chrome,
qui applique la feuille d'impression du document.

```bash
chrome --headless=new --disable-gpu --no-pdf-header-footer   --print-to-pdf="<chemin absolu>\Specification_ERP_EMUCI.pdf"   "http://localhost:8080/docs/partage/Specification_ERP_EMUCI.html"
```

Le chemin de sortie doit être **absolu et au format Windows**. Avec un chemin
relatif ou de style Unix, Chrome annonce avoir écrit le fichier mais le
dépose ailleurs, sans erreur : vérifiez la date du fichier après coup.

À défaut, ouvrir le `.html` et faire `Ctrl+P` puis « Enregistrer au format
PDF » donne le même résultat.

## Deux points à connaître avant d'envoyer

**Les polices sont embarquées dans les PDF** : ils s'affichent partout à
l'identique, y compris hors ligne. Les fichiers HTML, eux, chargent leurs
polices depuis Google Fonts et retombent sur la police système si le
destinataire est hors ligne.

**Les deux documents portent leur version logicielle** : commit `5ecb558` du
29 août 2026. Ils décrivent l'application à cette date. Après une évolution
fonctionnelle, régénérez-les plutôt que de renvoyer ceux-ci.

## Régénérer après une mise à jour

Les sources sont `docs/MANUEL_UTILISATION.md` et `docs/SPECIFICATION.md`.
Mettez-les à jour, puis reconstruisez les fichiers de partage.
