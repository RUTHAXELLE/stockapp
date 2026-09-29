#!/usr/bin/env python3
"""
Prépare les versions « pour diffusion » (hors équipe technique) des trois
documents de docs/, puis les rend en HTML et PDF dans docs/partage/pour_direction/.

Les sources de docs/ restent la référence et ne sont pas modifiées : elles
portent, pour l'équipe technique, le dépôt Git, les commits et l'historique
de la correction de dépôt (RUTHAXELLE → GayeGuy). Ces détails n'ont pas
d'intérêt pour un lecteur métier. Le contenu fonctionnel est identique.

    python tools/nettoyer_docs_diffusion.py

Échoue si une mention interne subsiste après nettoyage : une nouvelle
tournure dans les sources doit être ajoutée ici, pas laissée passer.
"""
import re
import shutil
import subprocess
import sys
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
DOCS = RACINE / "docs"
SORTIE = DOCS / "partage" / "pour_direction"
FICHIERS = ["CAHIER_DES_CHARGES.md", "SPECIFICATION.md", "MANUEL_UTILISATION.md"]
SORTIES = ["Cahier_des_charges_ERP_EMUCI", "Specification_ERP_EMUCI", "Manuel_utilisation_ERP_EMUCI"]
INTERDITS = re.compile(r"RUTHAXELLE|GayeGuy|[Dd]épôt de référence|commit `")


def sans_colonne_base(texte: str) -> str:
    """Retire la colonne « Base logicielle » (hash de commit) du suivi des versions."""
    texte = texte.replace("| Version | Date | Base logicielle | Modifications |\n|---|---|---|---|",
                          "| Version | Date | Modifications |\n|---|---|---|")
    return re.sub(r"^(\| [\d.]+ \| [\d-]+ \| )`[0-9a-f]{7}`(?: \([^)]*\))? \| ", r"\1", texte, flags=re.M)


def nettoyer(texte: str) -> str:
    t = texte
    t = re.sub(r"^\*\*Dépôt de référence\*\* : .*\n", "", t, flags=re.M)
    t = re.sub(r"^\*\*Version du logiciel\*\* : branche `main`, commit `[0-9a-f]+` \((.*?)\)$",
               r"**Version du logiciel** : \1", t, flags=re.M)
    # Encart « Note sur cette version » : paragraphe de citation jusqu'à la
    # ligne « > » vide suivante, ou jusqu'à la fin de la citation.
    t = re.sub(r"^> \*\*Note sur cette version\.\*\*.*?(?:\n>\n|\n(?=\n))", "", t, flags=re.M | re.S)
    t = re.sub(r"\n>\n\n", "\n\n", t)

    # Cahier des charges
    t = t.replace("(branche `vps-mysql`, présente sur le dépôt de référence)", "(branche `vps-mysql`)")
    t = re.sub(r"> \*\*Résolu depuis la v1\.0\.\*\*.*?(?=\n\n)",
               "> **Résolu depuis la v1.0.** Les quatre modules Achats (`achats`,\n"
               "> `achats_dashboard`, `achats_param`, `achats_suivi`) figurent désormais\n"
               "> dans la matrice Admin → Permissions : un administrateur peut ajuster\n"
               "> leurs droits depuis l'interface, sans migration SQL.", t, flags=re.S)
    t = re.sub(r"^\| 1\.0 \| 21 septembre 2026 \| .*$",
               "| 1.0 | 21 septembre 2026 | Établissement initial. Couvre les dix domaines, les seize rôles, "
               "les 106 tables et les quarante-deux identifiants de module contrôlés dans le code. |", t, flags=re.M)
    t = re.sub(r"^\| 2\.0 \| 24 septembre 2026 \| \*\*Corrigé depuis le dépôt de référence GayeGuy/ERP-EMUCI-V2\*\* "
               r"\(commit `[0-9a-f]+`\)\. ", "| 2.0 | 24 septembre 2026 | ", t, flags=re.M)
    t = t.replace("constaté résolu sur ce dépôt.", "constaté résolu.")

    # Spécification
    t = t.replace("| `main` | `vps-mysql` — présente sur le dépôt de référence GayeGuy/ERP-EMUCI-V2 |",
                  "| `main` | `vps-mysql` |")
    t = re.sub(r"\*\*Aucun module contrôlé dans le code n'est absent de la matrice\.\*\* Ce n'était\n.*?"
               r"depuis l'interface comme tous les autres\.",
               "**Aucun module contrôlé dans le code n'est absent de la matrice.** Les quatre\n"
               "modules Achats (`achats`, `achats_dashboard`, `achats_param`, `achats_suivi`)\n"
               "ont longtemps protégé des écrans sans figurer dans le tableau `$modules` de\n"
               "`pages/admin/permissions.php`, obligeant à passer par une migration SQL pour\n"
               "ajuster leurs droits. Ils sont désormais administrables depuis l'interface\n"
               "comme tous les autres.", t, flags=re.S)
    t = re.sub(r"> \*\*Résolu depuis la v3\.0\.\*\*.*?(?=\n\n)",
               "> **Résolu depuis la v3.0.** Le point « quatre modules Achats hors matrice »\n"
               "> ne se vérifie plus : ces quatre modules sont administrables depuis\n"
               "> l'interface au même titre que les cinquante-deux autres (7.1).", t, flags=re.S)
    t = sans_colonne_base(t)
    t = t.replace("| 3.0 | 2026-09-21 | Remise à niveau sur 29 commits.", "| 3.0 | 2026-09-21 | Remise à niveau.")
    t = re.sub(r"\*\*Corrigé le dépôt de référence\*\* : la v3\.0 avait été (?:établie|rédigée) depuis "
               r"RUTHAXELLE/stockapp, [^.]*\. ", "", t)
    t = t.replace("ne se vérifie pas sur ce dépôt (7.1, 14.2)", "ne se vérifie plus (7.1, 14.2)")

    # Manuel
    t = t.replace("Il décrit l'état du logiciel au commit\n> indiqué.",
                  "Il décrit l'état du logiciel à la date\n> indiquée.")
    return t


def main() -> int:
    originaux = {f: (DOCS / f).read_bytes() for f in FICHIERS}
    try:
        for f in FICHIERS:
            propre = nettoyer(originaux[f].decode("utf-8").replace("\r\n", "\n"))
            restes = [l for l in propre.splitlines() if INTERDITS.search(l)]
            if restes:
                print(f"{f} : mention interne non nettoyée —\n  " + "\n  ".join(restes))
                return 1
            (DOCS / f).write_text(propre, encoding="utf-8", newline="\n")
        # Le générateur reprend sa feuille de style d'une page déjà produite ;
        # à défaut, on lui fournit celle de la diffusion précédente.
        ref = DOCS / "partage" / "Specification_ERP_EMUCI.html"
        if not ref.exists() and (SORTIE / ref.name).exists():
            shutil.copy(SORTIE / ref.name, ref)
        subprocess.run([sys.executable, str(RACINE / "tools" / "generer_docs_partage.py"), "--pdf"], check=True)
        SORTIE.mkdir(parents=True, exist_ok=True)
        for nom in SORTIES:
            for ext in (".html", ".pdf"):
                shutil.move(str(DOCS / "partage" / (nom + ext)), str(SORTIE / (nom + ext)))
        print("Versions de diffusion écrites dans", SORTIE)
    finally:
        # Les sources reprennent leur contenu exact, octet pour octet.
        for f, contenu in originaux.items():
            (DOCS / f).write_bytes(contenu)
    return 0


if __name__ == "__main__":
    sys.exit(main())
