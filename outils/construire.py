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
from datetime import date
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
e = lambda s: html.escape(str(s or "").strip(), quote=True)


def lire(nom):
    return json.loads((RACINE / "contenu" / nom).read_text(encoding="utf-8"))


# Version allemande du site (/de/) : les textes viennent de contenu/traductions.json.
# Un texte sans traduction reste en français (rien ne casse quand la carte change).
LANGUE = "fr"
TRAD = {}


def tr(texte):
    t = str(texte or "").strip()
    return TRAD.get(t, t) if LANGUE == "de" else t


def titre_en_valeur(titre, mot):
    t = e(titre)
    m = e(mot)
    if m:
        i = t.lower().find(m.lower())
        if i >= 0:
            return t[:i] + "<em>" + t[i:i + len(m)] + "</em>" + t[i + len(m):]
    return t


def plat(p):
    classe = "item tag" if p.get("signature") else "item"
    prix = (p.get("prix") or "").strip()
    if LANGUE == "de":
        nom_de = (p.get("allemand") or "").strip() or tr(p.get("nom"))
        francais = (p.get("nom") or "").strip()
        ligne = f"<h4>{e(nom_de)}</h4>"
        if prix:
            ligne += f'<span class="dots"></span><span class="price">{e(prix)}</span>'
        out = f'<li class="{classe}"><div class="row">{ligne}</div>'
        if francais and francais != nom_de:
            out += f'<p class="de">{e(francais)}</p>'
        if (p.get("description") or "").strip():
            out += f'<p class="desc">{e(tr(p["description"]))}</p>'
        return out + "</li>"
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
        out += f'<span class="note badge-note">{e(tr(o["note_manuscrite"]))}</span>'
    if LANGUE == "de":
        out += f'<div class="sheet-head"><h3>{e((o.get("titre_allemand") or "").strip() or tr(o.get("titre")))}</h3>'
        out += f'<span class="de">{e(o.get("titre"))}</span>'
    else:
        out += f'<div class="sheet-head"><h3>{e(o.get("titre"))}</h3>'
        if (o.get("titre_allemand") or "").strip():
            out += f'<span class="de">{e(o["titre_allemand"])}</span>'
    if (o.get("presentation") or "").strip():
        out += f'<p>{e(tr(o["presentation"]))}</p>'
    out += "</div>"
    for r in o.get("rubriques") or []:
        if (r.get("titre") or "").strip():
            etoile = "<span class=star>★</span>" if r.get("etoile") else ""
            out += f'<h4 class="sub">{etoile}{e(tr(r["titre"]))}</h4>'
        plats = r.get("plats") or []
        if plats:
            out += '<ul class="items">' + "".join(plat(p) for p in plats) + "</ul>"
    enc = o.get("encadre") or {}
    if (enc.get("titre") or "").strip():
        out += (f'<div class="callout"><div class="emoji">{e(enc.get("emoji"))}</div>'
                f'<div><h4>{e(tr(enc["titre"]))}</h4><p>{e(tr(enc.get("texte")))}</p></div>'
                f'<div class="big">{e(enc.get("prix"))}</div></div>')
    if (o.get("remarque") or "").strip():
        out += f'<p class="fineprint" style="margin-top:14px">{e(tr(o["remarque"]))}</p>'
    if (o.get("bandeau") or "").strip():
        de = (o.get("bandeau_allemand") or "").strip()
        if LANGUE == "de":
            out += f'<div class="center"><span class="ribbon">{e(de or tr(o["bandeau"]))}</span></div>'
        else:
            de = f" · <span>{e(de)}</span>" if de else ""
            out += f'<div class="center"><span class="ribbon">{e(o["bandeau"])}{de}</span></div>'
    gl = o.get("glaces") or {}
    if (gl.get("titre") or "").strip():
        out += f'<div class="glaces"><h4>{e(tr(gl["titre"]))}</h4>'
        if (gl.get("cremes") or "").strip():
            out += f'<p><strong>{"Eiscreme:" if LANGUE == "de" else "Crèmes glacées :"}</strong> {e(tr(gl["cremes"]))}</p>'
        if (gl.get("sorbets") or "").strip():
            out += f'<p><strong>{"Sorbets:" if LANGUE == "de" else "Sorbets :"}</strong> {e(tr(gl["sorbets"]))}</p>'
        tarifs = gl.get("tarifs") or []
        if tarifs:
            out += '<div class="chips">' + "".join(
                f'<span>{e(tr(t.get("libelle")))} <b>{e(t.get("prix"))}</b></span>' for t in tarifs) + "</div>"
        out += "</div>"
    return out + "</div></div>"


def bloc_carte(c):
    onglets = c.get("onglets") or []
    de = (c.get("intro_allemand") or "").strip()
    if LANGUE == "de":
        titre_de = tr(c.get("titre"))
        mots = titre_de.split()
        out = ['    <div class="center">',
               f'      <span class="kicker">{e(tr(c.get("petit_titre")))}</span>',
               f'      <h2 class="title">{titre_en_valeur(titre_de, mots[-1] if mots else "")}</h2>',
               f'      <p class="lead">{e(de or tr(c.get("intro")))}</p>',
               '    </div>', '',
               '    <div class="tabs" role="tablist" aria-label="Rubriken der Speisekarte">']
    else:
        de = f' <span style="font-style:italic">{e(de)}</span>' if de else ""
        out = ['    <div class="center">',
               f'      <span class="kicker">{e(c.get("petit_titre"))}</span>',
               f'      <h2 class="title">{titre_en_valeur(c.get("titre"), c.get("mot_en_valeur"))}</h2>',
               f'      <p class="lead">{e(c.get("intro"))}{de}</p>',
               '    </div>', '',
               '    <div class="tabs" role="tablist" aria-label="Rubriques de la carte">']
    for i, o in enumerate(onglets, 1):
        sel = "true" if i == 1 else "false"
        out.append(f'      <button role="tab" aria-selected="{sel}" data-tab="p-{i}">{e(tr(o.get("nom")))}</button>')
    out += ['    </div>', '']
    out += [onglet(o, i) for i, o in enumerate(onglets, 1)]
    out += ['', f'    <p class="fineprint">{e(tr(c.get("mention_bas")))}</p>']
    return "\n".join(out)


JOURS = ["lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"]
MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août",
        "septembre", "octobre", "novembre", "décembre"]


def jour_fr(d):
    return f"{JOURS[d.weekday()]} {d.day}{'er' if d.day == 1 else ''} {MOIS[d.month - 1]}"


TAGE = ["Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag", "Sonntag"]
MONATE = ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August",
          "September", "Oktober", "November", "Dezember"]


def jour_de(d):
    return f"{TAGE[d.weekday()]}, {d.day}. {MONATE[d.month - 1]}"


def heure_de(h):
    return re.sub(r"^(\d{1,2})h(\d{2})?$", lambda m: f"{m.group(1)}{':' + m.group(2) if m.group(2) else ''} Uhr", h.strip())


def bloc_agenda(s):
    """Prochaines dates : seules les dates à venir sont affichées (les dates
    passées sont aussi masquées dans le navigateur, entre deux mises à jour)."""
    aujourd_hui = date.today()
    lignes = []
    for a in s.get("agenda") or []:
        try:
            debut = date.fromisoformat(str(a.get("date")).strip())
            fin = date.fromisoformat(str(a.get("date_fin")).strip()) if (a.get("date_fin") or "").strip() else debut
        except ValueError:
            continue
        if fin >= aujourd_hui and (a.get("titre") or "").strip():
            lignes.append((debut, fin, a))
    lignes.sort(key=lambda x: x[0])
    lignes = lignes[: int(s.get("agenda_nombre") or 6)]
    if not lignes:
        return []
    out = [f'    <h3 class="ag-title">{e(tr(s.get("agenda_titre") or "Les prochaines dates"))}</h3>',
           '    <ul class="agenda">']
    for debut, fin, a in lignes:
        if LANGUE == "de":
            quand = jour_de(debut) if fin == debut else f"{jour_de(debut)} bis {jour_de(fin)}"
            if (a.get("heure") or "").strip():
                quand += f" · {heure_de(a['heure'])}"
        else:
            quand = jour_fr(debut) if fin == debut else f"du {jour_fr(debut)} au {jour_fr(fin)}"
            if (a.get("heure") or "").strip():
                quand += f" · {a['heure'].strip()}"
        out.append(f'      <li data-fin="{fin.isoformat()}"><span class="ag-when">{e(quand)}</span>'
                   f'<span class="ag-what"><b>{e(tr(a.get("titre")))}</b>'
                   + (f'<small>{e(tr(a.get("details")))}</small>' if (a.get("details") or "").strip() else "")
                   + '</span></li>')
    out.append('    </ul>')
    return out


def bloc_soirees(s):
    titre = tr(s.get("titre"))
    mot = "Abende" if LANGUE == "de" else s.get("mot_en_valeur")
    out = ['    <div class="center">',
           f'      <span class="kicker">{e(tr(s.get("petit_titre")))}</span>',
           f'      <h2 class="title">{titre_en_valeur(titre, mot)}</h2>',
           f'      <p class="lead">{e(tr(s.get("intro")))}</p>',
           '    </div>',
           '    <div class="ev-grid">']
    for v in s.get("soirees") or []:
        info = e(tr(v.get("info")))
        if v.get("date_karaoke_auto"):
            info += ' <strong id="karaoke">' + ("1. Freitag im Monat" if LANGUE == "de" else "1er vendredi du mois") + '</strong>'
        if v.get("lien_telephone"):
            pied = f'<a class="next" href="tel:+33388863187" style="text-decoration:none">{info}</a>'
        elif info:
            pied = f'<span class="next">{info}</span>'
        else:
            pied = ""
        out += ['      <div class="ev">',
                f'        <div class="when">{e(tr(v.get("quand")))}</div>',
                f'        <h3>{e(tr(v.get("titre")))}</h3>',
                f'        <p>{e(tr(v.get("description")))}</p>']
        if pied:
            out.append(f'        {pied}')
        if (v.get("symbole") or "").strip():
            out.append(f'        <div class="deco">{e(v["symbole"])}</div>')
        out.append('      </div>')
    out.append('    </div>')
    out += bloc_agenda(s)
    return "\n".join(out)


def remplacer(texte, nom, contenu):
    motif = re.compile(rf"(<!-- {nom}:DEBUT[^>]*-->\n).*?(\n\s*<!-- {nom}:FIN -->)", re.S)
    if not motif.search(texte):
        sys.exit(f"Balises {nom}:DEBUT / {nom}:FIN introuvables dans index.html")
    return motif.sub(lambda m: m.group(1) + contenu + m.group(2), texte, count=1)


def construire(page, langue):
    global LANGUE
    LANGUE = langue
    texte = page.read_text(encoding="utf-8")
    nouveau = remplacer(texte, "CARTE", bloc_carte(lire("carte.json")))
    nouveau = remplacer(nouveau, "SOIREES", bloc_soirees(lire("soirees.json")))
    if nouveau != texte:
        page.write_text(nouveau, encoding="utf-8")
        print(f"{page.relative_to(RACINE)} mis à jour")
    else:
        print(f"{page.relative_to(RACINE)} : rien à changer")


def main():
    global TRAD
    f = RACINE / "contenu" / "traductions.json"
    if f.exists():
        TRAD = {str(t.get("fr", "")).strip(): str(t.get("de", "")).strip()
                for t in json.loads(f.read_text(encoding="utf-8")) if str(t.get("de", "")).strip()}
    construire(RACINE / "index.html", "fr")
    if (RACINE / "de" / "index.html").exists():
        construire(RACINE / "de" / "index.html", "de")


if __name__ == "__main__":
    main()
