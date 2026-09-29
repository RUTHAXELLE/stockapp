#!/usr/bin/env python3
"""
Génère les versions autonomes (HTML puis PDF) des documents de docs/.

Les fichiers Markdown de docs/ sont la source de vérité. Ce script en tire
des pages HTML autonomes — police embarquée par CDN, feuille d'impression
incluse — destinées à être lues à l'écran ou converties en PDF pour envoi
par courriel.

Jusqu'ici ces pages étaient écrites à la main : c'est la raison pour
laquelle elles se sont désynchronisées du Markdown. Régénérer plutôt que
recopier.

    python tools/generer_docs_partage.py            # HTML seulement
    python tools/generer_docs_partage.py --pdf      # HTML puis PDF via Chrome

Le PDF passe par Chrome sans interface : il applique la feuille
`@media print` du document et rend les schémas mermaid avant impression.
"""
import argparse
import html
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

try:
    import markdown
except ImportError:
    sys.exit("Manquant : pip install markdown")

RACINE = Path(__file__).resolve().parent.parent
SRC = RACINE / "docs"
DST = RACINE / "docs" / "partage"

# Chemins usuels de Chrome sous Windows, essayés dans l'ordre.
CHROME_CANDIDATS = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    "google-chrome", "chromium", "chrome",
]

DOCS = [
    {
        "source": "CAHIER_DES_CHARGES.md",
        "sortie": "Cahier_des_charges_ERP_EMUCI",
        "titre": "Cahier des charges",
        "lede": "Ce que le système doit faire, pour qui, sous quelles contraintes, "
                "et à quoi se mesure qu'il le fait.",
    },
    {
        "source": "SPECIFICATION.md",
        "sortie": "Specification_ERP_EMUCI",
        "titre": "Spécification fonctionnelle et technique",
        "lede": "Ce que le système est, les règles qu'il applique, et les limites "
                "qu'il porte.",
    },
    {
        "source": "MANUEL_UTILISATION.md",
        "sortie": "Manuel_utilisation_ERP_EMUCI",
        "titre": "Manuel d'utilisation",
        "lede": "Comment se servir de l'application, écran par écran, selon son rôle.",
    },
]


def lire_entete(md: str) -> tuple[str, list[tuple[str, str]], str, str]:
    """Titre H1, métadonnées en gras, citation de provenance, et corps restant.

    La citation du chapeau — celle qui dit d'où sort le document — est
    récupérée séparément : sans cela elle disparaîtrait avec le reste de
    l'en-tête, alors que c'est elle qui donne sa portée au document.
    """
    lignes = md.split("\n")
    titre = ""
    metas: list[tuple[str, str]] = []
    provenance: list[str] = []
    i = 0
    while i < len(lignes):
        l = lignes[i].strip()
        if l.startswith("# ") and not titre:
            titre = l[2:].strip()
        elif l.startswith("**") and " : " in l:
            m = re.match(r"\*\*(.+?)\*\*\s*:\s*(.+)", l)
            if m:
                metas.append((m.group(1).strip(), m.group(2).strip()))
        elif l.startswith(">"):
            provenance.append(re.sub(r"^>\s?", "", l))
        elif l == "---":
            i += 1
            break
        i += 1
    return titre, metas, "\n".join(provenance).strip(), "\n".join(lignes[i:])


def retirer_sommaire(md: str) -> str:
    """Le sommaire du Markdown fait doublon avec la navigation générée.

    S'arrête au premier titre de n'importe quel niveau : borner sur `## `
    seul ferait disparaître un intertitre de partie écrit en `# `.
    """
    return re.sub(r"^## Sommaire\b.*?(?=^#{1,2} |\Z)", "", md, flags=re.S | re.M)


def proteger_mermaid(md: str) -> tuple[str, list[str]]:
    """Sort les blocs mermaid du flux Markdown, remplacés par un jeton."""
    blocs: list[str] = []

    def _capture(m):
        blocs.append(m.group(1))
        return f"\n\nMERMAIDJETON{len(blocs) - 1}\n\n"

    md = re.sub(r"```mermaid\n(.*?)```", _capture, md, flags=re.S)
    return md, blocs


def rendre_mermaid(corps: str, blocs: list[str]) -> str:
    for i, code in enumerate(blocs):
        fig = (f'<figure><pre class="mermaid">{html.escape(code.strip())}</pre></figure>')
        corps = re.sub(rf"<p>MERMAIDJETON{i}</p>", fig, corps)
    return corps


def decouper_sections(corps: str) -> tuple[str, list[dict]]:
    """Enveloppe chaque H2 dans une <section> et relève le plan pour la navigation."""
    plan: list[dict] = []
    compteur = {"n": 0}

    def _titre(m):
        # Une seule passe, pour que le plan suive l'ordre du document : traiter
        # les H1 puis les H2 en deux balayages rangeait toutes les parties en
        # tête du sommaire.
        if m.group(1) is not None:          # H1 au milieu du document = partie
            titre = re.sub(r"<.*?>", "", m.group(1)).strip()
            plan.append({"partie": titre})
            return f'<h1 class="partie">{html.escape(titre)}</h1>'

        brut = re.sub(r"<.*?>", "", m.group(2)).strip()
        compteur["n"] += 1
        ident = f"s{compteur['n']}"
        num, _, reste = brut.partition(".")
        if num.strip().isdigit():
            etiquette, texte = num.strip(), reste.strip()
        else:
            etiquette, texte = "", brut
        plan.append({"id": ident, "n": etiquette, "titre": texte or brut})
        marque = f'<span class="n">{etiquette}</span> ' if etiquette else ""
        return (f'</section>\n<section id="{ident}">'
                f'<h2>{marque}{html.escape(texte or brut)}</h2>')

    corps = re.sub(r"<h1>(.*?)</h1>|<h2>(.*?)</h2>", _titre, corps, flags=re.S)
    corps = corps + "\n</section>"
    corps = corps.replace("</section>\n<section", "<section", 1)  # pas de section vide en tête
    return corps, plan


def embellir(corps: str) -> str:
    """Tables défilantes, citations en encadrés."""
    corps = corps.replace("<table>", '<div class="tw"><table>').replace("</table>", "</table></div>")

    def _note(m):
        interne = m.group(1)
        # Un intitulé en gras en tête de citation devient le titre de l'encadré.
        t = re.match(r"\s*<p><strong>(.+?)</strong>(.*)", interne, re.S)
        classe = "note-info"
        bas = interne.lower()
        if any(k in bas for k in ("attention", "piège", "ne pas", "jamais", "risque")):
            classe = "note-warn"
        if any(k in bas for k in ("faille", "élévation", "aucune protection", "bloquant")):
            classe = "note-stop"
        if t:
            titre = re.sub(r"[.:]\s*$", "", t.group(1)).strip()
            reste = f"<p>{t.group(2)}" if t.group(2).strip() else ""
            return (f'<div class="note {classe}"><span class="note-t">{titre}</span>'
                    f"{reste}</div>")
        return f'<div class="note {classe}">{interne}</div>'

    return re.sub(r"<blockquote>(.*?)</blockquote>", _note, corps, flags=re.S)


def navigation(plan: list[dict]) -> str:
    lignes = ['<nav class="toc" aria-label="Sommaire">', '<p class="toc-t">Sommaire</p>', "<ol>"]
    for e in plan:
        if "partie" in e:
            lignes.append(f'<li class="part">{html.escape(e["partie"])}</li>')
        else:
            n = f'<span class="n">{e["n"]}</span>' if e["n"] else ""
            lignes.append(f'<li><a href="#{e["id"]}">{n}<span>{html.escape(e["titre"])}</span></a></li>')
    lignes += ["</ol>", "</nav>"]
    return "\n".join(lignes)


def construire(doc: dict, css: str, commit: str) -> str:
    md = (SRC / doc["source"]).read_text(encoding="utf-8")
    titre_h1, metas, provenance, reste = lire_entete(md)
    reste = retirer_sommaire(reste)
    reste, mermaids = proteger_mermaid(reste)

    corps = markdown.markdown(
        reste, extensions=["tables", "fenced_code", "sane_lists", "attr_list"],
        output_format="html5",
    )
    corps = rendre_mermaid(corps, mermaids)
    corps = embellir(corps)
    corps, plan = decouper_sections(corps)

    chips = "".join(
        f'<span class="chip"><b>{html.escape(k)}</b> {html.escape(v)}</span>'
        for k, v in metas[:3]
    )
    bloc_provenance = ""
    if provenance:
        interne = markdown.markdown(provenance, extensions=["sane_lists"])
        bloc_provenance = embellir(f"<blockquote>{interne}</blockquote>")
    besoin_mermaid = bool(mermaids)
    script = ("""
<script type="module">
  import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
  mermaid.initialize({startOnLoad:true, theme:'neutral',
    themeVariables:{fontFamily:'DM Sans, sans-serif', fontSize:'13px'}});
  await mermaid.run();
  document.body.dataset.pret = '1';   // repère pour l'export PDF
</script>""" if besoin_mermaid else
              "\n<script>document.body.dataset.pret = '1';</script>")

    return f"""<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{html.escape(doc["titre"])} — ERP EMUCI</title>
<meta name="description" content="{html.escape(doc["titre"])} de l'ERP EMUCI, arrêté au commit {commit}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700&display=swap" rel="stylesheet">
<style>
{css}
h1.partie{{font-size:1.05rem;letter-spacing:.08em;text-transform:uppercase;
  color:var(--accent-ink);margin:2.6rem 0 .4rem;padding-bottom:.35rem;
  border-bottom:2px solid var(--accent)}}
figure .mermaid{{background:transparent}}
</style>
</head>
<body>
<div class="shell">
{navigation(plan)}
<main>
<header class="head">
<p class="eyebrow">ERP EMUCI</p>
<h1>{html.escape(titre_h1 or doc["titre"])}</h1>
<p class="lede">{html.escape(doc["lede"])}</p>
<div class="meta">{chips}</div>
</header>
{bloc_provenance}
{corps}
<footer><p>Document interne EMUCI — projet NSIIV.</p></footer>
</main>
</div>
<div class="impr-pied" style="margin-top:10mm;padding-top:4mm;border-top:1px solid #c3ccdf;
     font-size:9pt;color:#4d5876">
  {html.escape(doc["titre"])} — ERP EMUCI — document interne. État du logiciel au commit
  <code>{commit}</code>. Toute évolution fonctionnelle rend une section caduque :
  vérifiez la version avant de vous y fier.
</div>
{script}
</body>
</html>
"""


def trouver_chrome() -> str | None:
    for c in CHROME_CANDIDATS:
        if os.path.sep in c or ":" in c:
            if Path(c).exists():
                return c
        elif shutil.which(c):
            return shutil.which(c)
    return None


def en_pdf(html_path: Path, pdf_path: Path) -> bool:
    chrome = trouver_chrome()
    if not chrome:
        print("  Chrome introuvable — PDF non généré.")
        return False
    profil = tempfile.mkdtemp(prefix="chrome_pdf_")
    try:
        subprocess.run(
            [chrome, "--headless=new", "--disable-gpu", "--no-sandbox",
             f"--user-data-dir={profil}",
             "--no-pdf-header-footer",
             "--virtual-time-budget=20000",       # laisse mermaid et les polices se rendre
             f"--print-to-pdf={pdf_path}",
             html_path.resolve().as_uri()],
            check=True, capture_output=True, timeout=180,
        )
        return pdf_path.exists()
    except subprocess.CalledProcessError as e:
        print("  Chrome a échoué :", e.stderr.decode(errors="replace")[:300])
        return False
    finally:
        shutil.rmtree(profil, ignore_errors=True)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", action="store_true", help="générer aussi les PDF")
    args = ap.parse_args()

    commit = subprocess.run(["git", "rev-parse", "--short", "HEAD"],
                            capture_output=True, text=True, cwd=RACINE).stdout.strip() or "inconnu"
    css_src = DST / "Specification_ERP_EMUCI.html"
    if not css_src.exists():
        sys.exit("Feuille de style de référence introuvable : " + str(css_src))
    css = "\n".join(re.findall(r"<style>(.*?)</style>",
                               css_src.read_text(encoding="utf-8"), re.S))

    DST.mkdir(parents=True, exist_ok=True)
    for doc in DOCS:
        page = construire(doc, css, commit)
        out = DST / (doc["sortie"] + ".html")
        out.write_text(page, encoding="utf-8")
        print(f"HTML  {out.name}  ({len(page) // 1024} Ko)")
        if args.pdf:
            pdf = DST / (doc["sortie"] + ".pdf")
            if pdf.exists():
                pdf.unlink()
            t0 = time.time()
            if en_pdf(out, pdf):
                print(f"PDF   {pdf.name}  ({pdf.stat().st_size // 1024} Ko, "
                      f"{time.time() - t0:.0f}s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
