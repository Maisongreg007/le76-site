#!/usr/bin/env python3
"""Brief du matin du 76 : transforme un fichier JSON de données en une page HTML.

Usage : python3 generer.py donnees.json sortie.html

Le JSON attendu (tous les champs sont facultatifs sauf date et titre) :
{
  "date": "2026-10-24",
  "titre": "Phrase d'accroche du jour",
  "actes": [{"heure": "9h – 11h30", "texte": "…"}, x3],   # Matin, Midi, Soir
  "services": {"midi": {...réponse api/brief.php...}, "soir": {...}},
  "meteo": {"resume": "…", "min": 7, "max": 12, "pluie": 20},
  "a_surveiller": [{"titre": "…", "texte": "…", "lien": "https://…"}],
  "sections": [{"titre": "Côté réseaux", "items": [{"titre": "…", "texte": "…"}]}]
}
Tout le texte est échappé : rien n'est injecté comme HTML.
"""
import base64
import html
import json
import sys
from datetime import date
from pathlib import Path

JOURS = ["lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"]
MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août",
        "septembre", "octobre", "novembre", "décembre"]
POLICE = Path("/mnt/skills/examples/morning/assets/fonts/fraunces-latin-600-normal.woff2")

e = html.escape


def police_css() -> str:
    if POLICE.is_file():
        b = base64.b64encode(POLICE.read_bytes()).decode()
        return ("@font-face{font-family:'Fraunces';font-weight:600;font-style:normal;"
                f"src:url(data:font/woff2;base64,{b}) format('woff2');}}")
    return ""


def charge(s: dict | None) -> float:
    """Remplissage d'un service entre 0 et 1 (confirmés + en attente / capacité)."""
    if not s or not s.get("ouvert"):
        return 0.0
    cap = max(1, int(s.get("capacite") or 1))
    n = int(s.get("couverts_confirmes") or 0) + int(s.get("couverts_en_attente") or 0)
    return min(1.0, n / cap)


def terrain(services: dict, pluie: int) -> str:
    """Une ligne continue : un relief au midi, un au soir, hauteur = remplissage."""
    midi, soir = services.get("midi"), services.get("soir")
    cm, cs = charge(midi), charge(soir)
    base = 140
    hm = 18 + 95 * cm if midi and midi.get("ouvert") else 0
    hs = 18 + 95 * cs if soir and soir.get("ouvert") else 0
    d = (f"M0,{base} C60,{base} 90,{base-4} 140,{base-6} "
         f"S{280-70},{base-hm} 280,{base-hm} S350,{base-4} 420,{base-4} "
         f"S{560-80},{base-6} 520,{base-hs*0.55} S630,{base-hs} 700,{base-hs} "
         f"S780,{base} 840,{base}")
    out = [f'<path d="{d}" fill="none" stroke="#2E2C27" stroke-width="2.2" stroke-linecap="round"/>']
    # Matin : soleil levant (ou nuage s'il pleut)
    if pluie >= 50:
        out.append('<g stroke="#C6613F" stroke-width="1.8" fill="none" stroke-linecap="round">'
                   '<path d="M112,96 a14,14 0 0 1 26,-6 a12,12 0 0 1 20,10 h-46 z"/>'
                   '<path d="M120,110 l-4,10 M134,110 l-4,10 M148,110 l-4,10"/></g>')
    else:
        out.append(f'<path d="M118,{base-6} a22,22 0 0 1 44,0" fill="#C6613F"/>')
    for x, s, h in ((280, midi, hm), (700, soir, hs)):
        if s and s.get("ouvert"):
            r = 6 + 7 * charge(s)
            y = base - h
            out.append(f'<circle cx="{x}" cy="{y:.1f}" r="{r:.1f}" fill="#2E2C27"/>')
            if int(s.get("demandes_en_attente") or 0):
                out.append(f'<circle cx="{x+r+10:.1f}" cy="{y+6:.1f}" r="5" fill="#B4B3A8"/>')
            if s.get("soiree"):
                out.append(f'<g stroke="#2E2C27" stroke-width="1.6" fill="none">'
                           f'<path d="M{x},{y-r-8} v-26"/><path d="M{x},{y-r-34} h16 l-4,6 l4,6 h-16" fill="#2E2C27"/></g>')
        else:
            out.append(f'<circle cx="{x}" cy="{base-4}" r="5" fill="#B4B3A8"/>')
    # Fin de soirée : croissant de lune
    out.append('<path d="M800,40 a14,14 0 1 0 12,22 a11,11 0 1 1 -12,-22 z" fill="#2E2C27" opacity=".85"/>')
    return ('<svg viewBox="0 0 840 170" role="img" aria-label="Forme de la journée" '
            'class="terrain">' + "".join(out) + "</svg>")


def items(liste: list) -> str:
    o = ['<ol class="items">']
    for i, it in enumerate(liste, 1):
        titre = e(str(it.get("titre", "")))
        lien = str(it.get("lien") or "")
        if lien.startswith("https://"):
            titre = f'<a href="{e(lien)}">{titre}</a>'
        o.append(f'<li><span class="num">{i}</span><div><p class="t">{titre}</p>'
                 f'<p class="s">{e(str(it.get("texte", "")))}</p></div></li>')
    o.append("</ol>")
    return "".join(o)


def main() -> None:
    d = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    jour = date.fromisoformat(d["date"])
    ligne_date = f"{JOURS[jour.weekday()].capitalize()} {jour.day} {MOIS[jour.month-1]} {jour.year}"
    meteo = d.get("meteo") or {}
    actes = (d.get("actes") or [])[:3]

    bas = []
    surv = d.get("a_surveiller") or []
    bas.append('<section><h2>À surveiller</h2>' +
               (items(surv) if surv else '<p class="calme">Rien ne presse ce matin.</p>') + "</section>")
    for sec in d.get("sections") or []:
        if sec.get("items"):
            bas.append(f'<section><h2>{e(sec["titre"])}</h2>{items(sec["items"])}</section>')

    page = f"""<!doctype html>
<html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Brief du 76</title>
<style>
{police_css()}
:root{{--bg:#FCFCFB;--wash:#F9F9F7;--ink:#2E2C27;--soft:#6B6A63;--grey:#B4B3A8;--hair:#E4E3DC;--clay:#C6613F}}
*{{box-sizing:border-box}}
body{{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,"Segoe UI",sans-serif}}
.band{{padding:40px 16px}} .haut{{background:var(--wash);border-bottom:1px solid #E1E1DF}}
.in{{max-width:860px;margin:0 auto}}
.date{{color:var(--soft);font-size:14px;margin:0 0 6px}}
h1{{font-family:'Fraunces',Georgia,serif;font-weight:600;font-size:40px;line-height:1.15;margin:0 0 22px;letter-spacing:-.01em}}
.terrain{{width:100%;height:auto;display:block}}
.actes{{display:grid;grid-template-columns:repeat(3,1fr);margin-top:10px}}
.acte{{padding:0 18px}} .acte:first-child{{padding-left:0}}
.acte+.acte{{border-left:1px solid var(--hair)}}
.acte b{{display:block;font-size:14px}} .acte p{{margin:4px 0 0;color:var(--soft);font-size:14px}}
h2{{font-size:15px;font-weight:600;margin:0 0 12px}}
section+section{{margin-top:34px}}
.items{{list-style:none;margin:0;padding:0}}
.items li{{display:flex;gap:14px;padding:10px 0;border-top:1px solid var(--hair)}}
.items li:first-child{{border-top:0}}
.num{{color:var(--grey);font-size:14px;min-width:14px;padding-top:1px}}
.t{{margin:0;font-weight:600}} .t a{{color:inherit}}
.s{{margin:2px 0 0;color:var(--soft);font-size:15px}}
.calme{{color:var(--soft);margin:0}}
@media (max-width:640px){{
 h1{{font-size:30px}} .actes{{grid-template-columns:1fr}}
 .acte{{padding:12px 0}} .acte+.acte{{border-left:0;border-top:1px solid var(--hair)}}
}}
</style></head><body>
<div class="band haut"><div class="in">
<p class="date">{e(ligne_date)}</p>
<h1>{e(d.get("titre", ""))}</h1>
{terrain(d.get("services") or {}, int(meteo.get("pluie") or 0))}
<div class="actes">{"".join(f'<div class="acte"><b>{e(a.get("heure",""))}</b><p>{e(a.get("texte",""))}</p></div>' for a in actes)}</div>
</div></div>
<div class="band"><div class="in">{"".join(bas)}</div></div>
</body></html>"""
    Path(sys.argv[2]).write_text(page, encoding="utf-8")


if __name__ == "__main__":
    main()
