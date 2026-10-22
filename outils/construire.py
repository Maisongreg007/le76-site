#!/usr/bin/env python3
"""Reconstruit la carte et les soirées de index.html à partir des fichiers
contenu/carte.json et contenu/soirees.json.

Ne pas modifier à la main les zones entre les balises <!-- ...:DEBUT --> et
<!-- ...:FIN --> de index.html : elles sont réécrites par ce script.
Lancé automatiquement par GitHub (.github/workflows/construire.yml).
"""
import html
import json
import re
import sys
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
e = lambda s: html.escape(str(s or "").strip(), quote=True)


def lire(nom):
    return json.loads((RACINE / "contenu" / nom).read_text(encoding="utf-8"))


def titre_en_valeur(titre, mot):
    t = e(titre)
    m = e(mot)
    if m and m in t:
        return t.replace(m, f"<em>{m}</em>", 1)
    return t


def plat(p):
    classe = "item tag" if p.get("signature") else "item"
    prix = (p.get("prix") or "").strip()
    ligne = f"<h4>{e(p.get('nom'))}</h4>"
    if prix:
        ligne += f'<span class="dots"></span><span class="price">{e(prix)}</span>'
    out = f'<li class="{classe}"><div class="row">{ligne}</div>'
    if (p.get("allemand") or "").strip():
        out += f'<p class="de">{e(p["allemand"])}</p>'
    if (p.get("description") or "").strip():
        out += f'<p class="desc">{e(p["description"])}</p>'
    return out + "</li>"


def onglet(o, i):
    pid = f"p-{i}"
    classe = "panel bar" if o.get("deux_colonnes") else "panel "
    out = f'<div class="{classe}" id="{pid}" role="tabpanel"><div class="sheet">'
    if (o.get("note_manuscrite") or "").strip():
        out += f'<span class="note badge-note">{e(o["note_manuscrite"])}</span>'
    out += f'<div class="sheet-head"><h3>{e(o.get("titre"))}</h3>'
    if (o.get("titre_allemand") or "").strip():
        out += f'<span class="de">{e(o["titre_allemand"])}</span>'
    if (o.get("presentation") or "").strip():
        out += f'<p>{e(o["presentation"])}</p>'
    out += "</div>"
    for r in o.get("rubriques") or []:
        if (r.get("titre") or "").strip():
            etoile = "<span class=star>★</span>" if r.get("etoile") else ""
            out += f'<h4 class="sub">{etoile}{e(r["titre"])}</h4>'
        plats = r.get("plats") or []
        if plats:
            out += '<ul class="items">' + "".join(plat(p) for p in plats) + "</ul>"
    enc = o.get("encadre") or {}
    if (enc.get("titre") or "").strip():
        out += (f'<div class="callout"><div class="emoji">{e(enc.get("emoji"))}</div>'
                f'<div><h4>{e(enc["titre"])}</h4><p>{e(enc.get("texte"))}</p></div>'
                f'<div class="big">{e(enc.get("prix"))}</div></div>')
    if (o.get("remarque") or "").strip():
        out += f'<p class="fineprint" style="margin-top:14px">{e(o["remarque"])}</p>'
    if (o.get("bandeau") or "").strip():
        de = (o.get("bandeau_allemand") or "").strip()
        de = f" · <span>{e(de)}</span>" if de else ""
        out += f'<div class="center"><span class="ribbon">{e(o["bandeau"])}{de}</span></div>'
    gl = o.get("glaces") or {}
    if (gl.get("titre") or "").strip():
        out += f'<div class="glaces"><h4>{e(gl["titre"])}</h4>'
        if (gl.get("cremes") or "").strip():
            out += f'<p><strong>Crèmes glacées :</strong> {e(gl["cremes"])}</p>'
        if (gl.get("sorbets") or "").strip():
            out += f'<p><strong>Sorbets :</strong> {e(gl["sorbets"])}</p>'
        tarifs = gl.get("tarifs") or []
        if tarifs:
            out += '<div class="chips">' + "".join(
                f'<span>{e(t.get("libelle"))} <b>{e(t.get("prix"))}</b></span>' for t in tarifs) + "</div>"
        out += "</div>"
    return out + "</div></div>"


def bloc_carte(c):
    onglets = c.get("onglets") or []
    de = (c.get("intro_allemand") or "").strip()
    de = f' <span style="font-style:italic">{e(de)}</span>' if de else ""
    out = ['    <div class="center">',
           f'      <span class="kicker">{e(c.get("petit_titre"))}</span>',
           f'      <h2 class="title">{titre_en_valeur(c.get("titre"), c.get("mot_en_valeur"))}</h2>',
           f'      <p class="lead">{e(c.get("intro"))}{de}</p>',
           '    </div>', '',
           '    <div class="tabs" role="tablist" aria-label="Rubriques de la carte">']
    for i, o in enumerate(onglets, 1):
        sel = "true" if i == 1 else "false"
        out.append(f'      <button role="tab" aria-selected="{sel}" data-tab="p-{i}">{e(o.get("nom"))}</button>')
    out += ['    </div>', '']
    out += [onglet(o, i) for i, o in enumerate(onglets, 1)]
    out += ['', f'    <p class="fineprint">{e(c.get("mention_bas"))}</p>']
    return "\n".join(out)


def bloc_soirees(s):
    out = ['    <div class="center">',
           f'      <span class="kicker">{e(s.get("petit_titre"))}</span>',
           f'      <h2 class="title">{titre_en_valeur(s.get("titre"), s.get("mot_en_valeur"))}</h2>',
           f'      <p class="lead">{e(s.get("intro"))}</p>',
           '    </div>',
           '    <div class="ev-grid">']
    for v in s.get("soirees") or []:
        info = e(v.get("info"))
        if v.get("date_karaoke_auto"):
            info += ' <strong id="karaoke">1er vendredi du mois</strong>'
        if v.get("lien_telephone"):
            pied = f'<a class="next" href="tel:+33388863187" style="text-decoration:none">{info}</a>'
        elif info:
            pied = f'<span class="next">{info}</span>'
        else:
            pied = ""
        out += ['      <div class="ev">',
                f'        <div class="when">{e(v.get("quand"))}</div>',
                f'        <h3>{e(v.get("titre"))}</h3>',
                f'        <p>{e(v.get("description"))}</p>']
        if pied:
            out.append(f'        {pied}')
        if (v.get("symbole") or "").strip():
            out.append(f'        <div class="deco">{e(v["symbole"])}</div>')
        out.append('      </div>')
    out.append('    </div>')
    return "\n".join(out)


def remplacer(texte, nom, contenu):
    motif = re.compile(rf"(<!-- {nom}:DEBUT[^>]*-->\n).*?(\n\s*<!-- {nom}:FIN -->)", re.S)
    if not motif.search(texte):
        sys.exit(f"Balises {nom}:DEBUT / {nom}:FIN introuvables dans index.html")
    return motif.sub(lambda m: m.group(1) + contenu + m.group(2), texte, count=1)


def main():
    page = RACINE / "index.html"
    texte = page.read_text(encoding="utf-8")
    nouveau = remplacer(texte, "CARTE", bloc_carte(lire("carte.json")))
    nouveau = remplacer(nouveau, "SOIREES", bloc_soirees(lire("soirees.json")))
    if nouveau != texte:
        page.write_text(nouveau, encoding="utf-8")
        print("index.html mis à jour")
    else:
        print("Rien à changer")


if __name__ == "__main__":
    main()
