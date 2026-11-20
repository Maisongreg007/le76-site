/*
 * Habillage de Noël et de l'Avent : guirlande lumineuse, neige, couronne de l'Avent (1 à 4 bougies).
 * Ne fait rien si <html> n'a pas la classe « noel » (posée dans l'en-tête aux bonnes dates).
 */
(function () {
  var html = document.documentElement;
  if (!html.classList.contains("noel")) return;
  var de = html.lang === "de";
  var q = location.search, now = Date.now();
  var P = function (d) { return Date.parse(d + "T00:00:00+01:00"); };

  // Dimanche de l'Avent en cours : 29 nov, 6, 13 et 20 déc. (aperçu : ?bougies=3)
  var n = now >= P("2026-12-20") ? 4 : now >= P("2026-12-13") ? 3 : now >= P("2026-12-06") ? 2 : 1;
  var mq = q.match(/[?&]bougies=([1-4])/); if (mq) n = +mq[1];
  var ordre = de ? ["1. Advent", "2. Advent", "3. Advent", "4. Advent"] : ["1er dimanche de l'Avent", "2e dimanche de l'Avent", "3e dimanche de l'Avent", "4e dimanche de l'Avent"];
  var dates = de ? ["29. November", "6. Dezember", "13. Dezember", "20. Dezember"] : ["29 novembre", "6 décembre", "13 décembre", "20 décembre"];

  var G = de
    ? { tag: "Frohe Weihnachten · Adventszeit · Weihnachten im 76",
        lead: "<strong>Im Advent trägt das 76 Weihnachtsfarben.</strong> Weihnachtsabend mit dem Orchester Santa Lucia am Samstag, 12. Dezember (Menü 27 €), Silvesterabend am 31. Dezember. Geschlossen vom 19. bis 26. Dezember. Denken Sie an unsere Geschenkgutscheine!",
        note: "Frohe Weihnachten", photo: "Frohe Festtage!", cap: "Advent im 76", dateLabel: "Lichter" }
    : { tag: "Joyeuses fêtes · Temps de l'Avent · Noël au 76",
        lead: "<strong>Pendant l'Avent, le 76 se met aux couleurs de Noël.</strong> Soirée de Noël avec l'orchestre Santa Lucia le samedi 12 décembre (menu 27 €), Réveillon le 31 décembre. Fermeture du 19 au 26 décembre inclus. Pensez à nos bons cadeaux !",
        note: "Joyeux Noël", photo: "Joyeuses fêtes !", cap: "L'Avent au 76" };

  var tg = document.querySelector(".hero .tagline"); if (tg) tg.textContent = G.tag;
  var ld = document.querySelector(".hero .lead"); if (ld) ld.innerHTML = G.lead;
  var nt = document.querySelector(".logo-xl .note"); if (nt) nt.textContent = G.note;
  var ph = document.querySelector(".hero-photo .note"); if (ph) ph.textContent = G.photo;
  var tc = document.querySelector('meta[name="theme-color"]'); if (tc) tc.setAttribute("content", "#0a1611");

  // Couronne de l'Avent
  var NS = "http://www.w3.org/2000/svg", cx = 120, cy = 112, rx = 98, ry = 22, h = "";
  var verts = ["#1f6e46", "#17583a", "#2a8657", "#12452d"];
  for (var i = 0; i < 70; i++) {
    var a = i / 70 * Math.PI * 2, x = cx + rx * Math.cos(a), y = cy + ry * Math.sin(a);
    h += '<ellipse cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" rx="11" ry="4.2" fill="' + verts[i % 4] + '" transform="rotate(' + ((a * 180 / Math.PI + 90 + (i % 2 ? 35 : -35)) | 0) + ' ' + x.toFixed(1) + ' ' + y.toFixed(1) + ')"/>';
  }
  for (var j = 0; j < 14; j++) {
    var b = j / 14 * Math.PI * 2 + .2;
    h += '<circle cx="' + (cx + rx * Math.cos(b)).toFixed(1) + '" cy="' + (cy + ry * Math.sin(b) + 1).toFixed(1) + '" r="3.2" fill="#c8322f"/><circle cx="' + (cx + rx * Math.cos(b) - 1).toFixed(1) + '" cy="' + (cy + ry * Math.sin(b)).toFixed(1) + '" r="1" fill="#ff9d94"/>';
  }
  var xs = [52, 94, 146, 188];
  for (var k = 0; k < 4; k++) {
    var x0 = xs[k], base = cy + ry * Math.sin(Math.acos((x0 - cx) / rx)) + 4, top = base - 62, on = k < n;
    h += '<rect x="' + (x0 - 6) + '" y="' + top + '" width="12" height="' + (base - top) + '" rx="2" fill="' + (on ? "#f6ecd6" : "#cfc4ad") + '"/>' +
         '<rect x="' + (x0 - 6) + '" y="' + top + '" width="4" height="' + (base - top) + '" rx="2" fill="rgba(0,0,0,.12)"/>' +
         '<rect x="' + (x0 - 7) + '" y="' + (base - 6) + '" width="14" height="7" rx="3" fill="#d6b062"/>' +
         '<line x1="' + x0 + '" y1="' + top + '" x2="' + x0 + '" y2="' + (top - 5) + '" stroke="#2b1d16" stroke-width="1.6"/>';
    if (on) h += '<circle class="nl-halo" cx="' + x0 + '" cy="' + (top - 12) + '" r="20" fill="url(#nl-lueur)"/>' +
         '<path class="nl-flamme" d="M' + x0 + ' ' + (top - 24) + ' C' + (x0 + 7) + ' ' + (top - 14) + ' ' + (x0 + 5) + ' ' + (top - 4) + ' ' + x0 + ' ' + (top - 4) + ' C' + (x0 - 5) + ' ' + (top - 4) + ' ' + (x0 - 7) + ' ' + (top - 14) + ' ' + x0 + ' ' + (top - 24) + 'Z" fill="#ffd96a"/>' +
         '<ellipse class="nl-flamme" cx="' + x0 + '" cy="' + (top - 9) + '" rx="2.4" ry="4.4" fill="#fff6d6"/>';
  }
  h += '<path d="M120 ' + (cy + ry + 2) + ' l-15 11 l5 -16z M120 ' + (cy + ry + 2) + ' l15 11 l-5 -16z" fill="#c8322f"/><circle cx="120" cy="' + (cy + ry + 1) + '" r="4.5" fill="#9d1f24"/>';
  var svg = '<svg viewBox="0 0 240 150" role="img" aria-label="' + ordre[n - 1] + '"><defs><radialGradient id="nl-lueur"><stop offset="0" stop-color="#ffd96a" stop-opacity=".75"/><stop offset="1" stop-color="#ffd96a" stop-opacity="0"/></radialGradient></defs>' + h + '</svg>';
  var bloc = document.createElement("div");
  bloc.className = "nl-avent";
  bloc.innerHTML = svg + '<p><b>' + G.cap + '</b>' + ordre[n - 1] + '<br>' + (n === 4 ? (de ? "Alle vier Kerzen brennen" : "Les quatre bougies sont allumées") : (n + (de ? " Kerze" + (n > 1 ? "n" : "") + " brennt" : " bougie" + (n > 1 ? "s allumées" : " allumée")))) + '</p>';
  var actions = document.querySelector(".hero .hero-actions");
  if (actions) actions.parentNode.insertBefore(bloc, actions); else if (ld) ld.insertAdjacentElement("afterend", bloc);

  var hero = document.getElementById("hero");
  if (!hero) return;

  // Guirlande lumineuse
  var cols = ["#c8322f", "#efd592", "#2a8657", "#efd592"], w = 1200, bulbs = "";
  for (var g = 0; g < 24; g++) {
    var gx = 25 + g * 50, gy = 18 + Math.sin(g / 24 * Math.PI * 8) * 0 + (g % 2 ? 10 : 4);
    bulbs += '<circle class="amp" style="animation-delay:-' + ((g * .37) % 2.6).toFixed(2) + 's" cx="' + gx + '" cy="' + (gy + 6) + '" r="4.5" fill="' + cols[g % 4] + '"/>';
  }
  var gl = document.createElement("div");
  gl.className = "nl-guirlande"; gl.setAttribute("aria-hidden", "true");
  gl.innerHTML = '<svg viewBox="0 0 ' + w + ' 46" preserveAspectRatio="none"><path class="fil" d="M0 6 Q25 22 50 12 T100 12 T150 12 T200 12 T250 12 T300 12 T350 12 T400 12 T450 12 T500 12 T550 12 T600 12 T650 12 T700 12 T750 12 T800 12 T850 12 T900 12 T950 12 T1000 12 T1050 12 T1100 12 T1150 12 T1200 12"/>' + bulbs + '</svg>';
  hero.insertBefore(gl, hero.firstChild);

  // Neige
  if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  var zone = document.createElement("div");
  zone.className = "nl-neige"; zone.setAttribute("aria-hidden", "true");
  var nb = window.innerWidth < 700 ? 22 : 46, f = "";
  for (var s = 0; s < nb; s++) {
    var t = 2.5 + Math.random() * 4.5;
    f += '<i style="left:' + (Math.random() * 100).toFixed(1) + '%;width:' + t.toFixed(1) + 'px;height:' + t.toFixed(1) + 'px;--dx:' + Math.round(Math.random() * 90 - 45) + 'px;animation-duration:' + (8 + Math.random() * 10).toFixed(1) + 's;animation-delay:-' + (Math.random() * 18).toFixed(1) + 's"></i>';
  }
  zone.innerHTML = f;
  hero.insertBefore(zone, hero.firstChild);
})();

/* ---- Décor de Noël : scène de village enneigé (Père Noël, bonhomme de neige, rennes, sapins, cadeaux) + traîneau volant ---- */
(function () {
  var html = document.documentElement;
  if (!html.classList.contains("noel")) return;
  var hero = document.getElementById("hero");
  if (!hero) return;
  var de = html.lang === "de";

  var defs =
    '<defs>' +
    '<g id="nl-renne">' +
      '<g stroke="#5a3a20" stroke-width="5" stroke-linecap="round"><path d="M-18 8 L-20 36"/><path d="M-8 10 L-8 36"/><path d="M12 10 L14 36"/><path d="M22 8 L26 34"/></g>' +
      '<ellipse cx="0" cy="0" rx="30" ry="16" fill="#9a6a3e"/><ellipse cx="2" cy="6" rx="22" ry="8" fill="#c79a6a" opacity=".55"/>' +
      '<path d="M22 -6 Q34 -22 40 -26 L50 -20 Q40 -6 30 4Z" fill="#9a6a3e"/>' +
      '<ellipse cx="50" cy="-26" rx="12" ry="9" fill="#a8774a" transform="rotate(-18 50 -26)"/>' +
      '<circle cx="60" cy="-24" r="3.6" fill="#3a2414"/><circle cx="51" cy="-30" r="2" fill="#1b1209"/><circle cx="52" cy="-30.6" r=".7" fill="#fff"/>' +
      '<path d="M42 -33 L36 -41 L44 -37Z" fill="#8a5a34"/>' +
      '<g stroke="#d9c39a" stroke-width="2.6" fill="none" stroke-linecap="round"><path d="M46 -34 L44 -52 M44 -46 L36 -52 M45 -41 L53 -47 M44 -52 L46 -62"/></g>' +
      '<path d="M-30 -2 q-8 -2 -10 4 q4 6 10 2z" fill="#f4ead4"/>' +
      '<path d="M26 -8 q8 -2 10 3 q-4 5 -10 4z" fill="#c8322f"/>' +
    '</g>' +
    '<g id="nl-sapin">' +
      '<rect x="-7" y="0" width="14" height="22" fill="#5a3a20"/>' +
      '<path d="M0 -92 L36 -40 L16 -40 L46 4 L-46 4 L-16 -40 L-36 -40Z" fill="#17583a"/>' +
      '<path d="M0 -92 L22 -56 L8 -58 L30 -26 L-30 -26 L-8 -58 L-22 -56Z" fill="#1f6e46"/>' +
      '<path d="M-36 -40 q8 8 16 0 q6 8 16 0 q6 8 12 0 q8 8 16 0 q4 6 12 0 L36 -40 L16 -40 L0 -44 L-16 -40Z" fill="#f4faf8" opacity=".92"/>' +
      '<path d="M-46 4 q10 8 20 0 q8 8 18 0 q10 8 20 0 q8 8 20 0 L46 4Z" fill="#f4faf8" opacity=".92"/>' +
      '<circle cx="-14" cy="-20" r="3.6" fill="#c8322f"/><circle cx="12" cy="-12" r="3.6" fill="#efd592"/><circle cx="-6" cy="-52" r="3.2" fill="#efd592"/><circle cx="10" cy="-64" r="3" fill="#c8322f"/><circle cx="-24" cy="-6" r="3.4" fill="#efd592"/><circle cx="26" cy="-34" r="3.4" fill="#c8322f"/>' +
    '</g>' +
    '<g id="nl-cadeau-r"><rect x="-22" y="-26" width="44" height="26" rx="2" fill="#c8322f"/><rect x="-4" y="-26" width="8" height="26" fill="#efd592"/><rect x="-22" y="-16" width="44" height="6" fill="#efd592" opacity=".9"/><path d="M0 -26 q-14 -16 -16 -6 q4 8 16 6z M0 -26 q14 -16 16 -6 q-4 8 -16 6z" fill="#efd592"/></g>' +
    '<g id="nl-cadeau-v"><rect x="-18" y="-22" width="36" height="22" rx="2" fill="#1f8a58"/><rect x="-3.5" y="-22" width="7" height="22" fill="#f4ead4"/><path d="M0 -22 q-12 -14 -14 -5 q3 7 14 5z M0 -22 q12 -14 14 -5 q-3 7 -14 5z" fill="#f4ead4"/></g>' +
    '<g id="nl-cadeau-o"><rect x="-16" y="-18" width="32" height="18" rx="2" fill="#d6b062"/><rect x="-3" y="-18" width="6" height="18" fill="#c8322f"/><path d="M0 -18 q-10 -12 -12 -4 q3 6 12 4z M0 -18 q10 -12 12 -4 q-3 6 -12 4z" fill="#c8322f"/></g>' +
    '</defs>';

  var etoiles = "";
  for (var i = 0; i < 46; i++) {
    etoiles += '<circle cx="' + (Math.random() * 1200).toFixed(0) + '" cy="' + (Math.random() * 150).toFixed(0) + '" r="' + (0.6 + Math.random() * 1.6).toFixed(1) + '" fill="#fff6dc" opacity="' + (0.4 + Math.random() * 0.6).toFixed(2) + '"/>';
  }

  var pere =
    '<g transform="translate(868 252)">' +
      '<rect x="-26" y="-14" width="20" height="16" rx="4" fill="#15110d"/><rect x="6" y="-14" width="20" height="16" rx="4" fill="#15110d"/>' +
      '<path d="M-34 -22 L34 -22 L26 -118 Q0 -128 -26 -118Z" fill="#c8322f"/>' +
      '<path d="M-34 -22 Q0 -12 34 -22 L34 -34 Q0 -24 -34 -34Z" fill="#f6fbfa"/>' +
      '<rect x="-30" y="-74" width="60" height="9" fill="#15110d"/><rect x="-7" y="-76" width="14" height="13" rx="2" fill="#efd592"/><rect x="-3" y="-72" width="6" height="5" fill="#15110d"/>' +
      '<path d="M0 -118 L0 -34" stroke="#f6fbfa" stroke-width="5" opacity=".9"/>' +
      '<g class="nl-bras"><path d="M26 -108 Q56 -112 62 -142" stroke="#c8322f" stroke-width="15" fill="none" stroke-linecap="round"/><circle cx="62" cy="-146" r="9" fill="#15110d"/><circle cx="62" cy="-146" r="6" fill="#f6fbfa"/></g>' +
      '<path d="M-26 -108 Q-48 -92 -44 -66" stroke="#c8322f" stroke-width="15" fill="none" stroke-linecap="round"/><circle cx="-44" cy="-62" r="8" fill="#f6fbfa"/>' +
      '<circle cx="0" cy="-138" r="21" fill="#f2c9a5"/><circle cx="-8" cy="-142" r="2.2" fill="#2b1d16"/><circle cx="8" cy="-142" r="2.2" fill="#2b1d16"/><circle cx="0" cy="-136" r="4" fill="#e8a687"/>' +
      '<path d="M-22 -138 Q-26 -108 0 -98 Q26 -108 22 -138 Q10 -124 0 -126 Q-10 -124 -22 -138Z" fill="#f6fbfa"/>' +
      '<path d="M-12 -132 Q0 -122 12 -132 Q0 -138 -12 -132Z" fill="#fff"/>' +
      '<path d="M-24 -144 Q0 -190 30 -150 L22 -146 Q0 -166 -22 -142Z" fill="#c8322f"/><path d="M-24 -144 Q0 -160 24 -144 L24 -134 Q0 -148 -24 -134Z" fill="#f6fbfa"/>' +
      '<circle cx="34" cy="-152" r="8" fill="#f6fbfa"/>' +
    '</g>';

  var bonhomme =
    '<g class="nl-bonhomme" transform="translate(300 252)">' +
      '<g stroke="#5a3a20" stroke-width="4" stroke-linecap="round"><path d="M-28 -90 L-66 -112 M-56 -106 L-62 -124 M30 -90 L68 -70 M58 -76 L66 -92"/></g>' +
      '<circle cx="0" cy="-36" r="38" fill="#f6fbfa"/><circle cx="0" cy="-92" r="29" fill="#f6fbfa"/><circle cx="0" cy="-138" r="22" fill="#fbfffe"/>' +
      '<circle cx="-5" cy="-60" r="2.8" fill="#2b1d16"/><circle cx="-5" cy="-44" r="2.8" fill="#2b1d16"/><circle cx="-5" cy="-28" r="2.8" fill="#2b1d16"/>' +
      '<circle cx="-8" cy="-142" r="2.6" fill="#2b1d16"/><circle cx="8" cy="-142" r="2.6" fill="#2b1d16"/><path d="M-1 -136 L24 -131 L-1 -128Z" fill="#e8892b"/>' +
      '<path d="M-10 -126 q10 6 20 0" stroke="#2b1d16" stroke-width="2" fill="none" stroke-linecap="round"/>' +
      '<rect x="-23" y="-119" width="46" height="11" rx="4" fill="#c8322f"/><path d="M12 -112 L22 -90 L10 -88 L4 -108Z" fill="#a52a27"/>' +
      '<rect x="-17" y="-184" width="34" height="26" fill="#15110d"/><rect x="-27" y="-161" width="54" height="7" rx="3" fill="#15110d"/><rect x="-17" y="-168" width="34" height="6" fill="#c8322f"/>' +
    '</g>';

  var cadeaux =
    '<use href="#nl-cadeau-r" x="990" y="252"/><use href="#nl-cadeau-v" x="1034" y="252"/><use href="#nl-cadeau-o" x="1012" y="226"/>' +
    '<use href="#nl-cadeau-v" x="150" y="252"/><use href="#nl-cadeau-r" x="186" y="252" transform="translate(0 0) scale(.8)" style="transform-origin:186px 252px"/>';

  var scene =
    '<svg viewBox="0 0 1200 300" preserveAspectRatio="xMidYMax slice" role="img" aria-label="' + (de ? "Verschneites Weihnachtsdorf" : "Décor de Noël : Père Noël, bonhomme de neige et rennes") + '">' + defs +
    '<defs><linearGradient id="nl-ciel" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#08130e"/><stop offset=".7" stop-color="#12372c"/><stop offset="1" stop-color="#1c4a3a"/></linearGradient></defs>' +
    '<rect width="1200" height="300" fill="url(#nl-ciel)"/>' + etoiles +
    '<g><circle cx="1040" cy="64" r="30" fill="#fbf1cf"/><circle cx="1052" cy="58" r="27" fill="#12372c"/></g>' +
    '<path d="M0 214 Q200 170 420 204 T820 196 T1200 190 L1200 300 L0 300Z" fill="#cfe0dc"/>' +
    '<use href="#nl-sapin" transform="translate(60 214) scale(1.15)"/><use href="#nl-sapin" transform="translate(120 224) scale(.8)"/>' +
    '<use href="#nl-sapin" transform="translate(560 208) scale(.9)"/><use href="#nl-sapin" transform="translate(1150 208) scale(1.2)"/><use href="#nl-sapin" transform="translate(1100 220) scale(.75)"/>' +
    '<path d="M0 246 Q260 214 520 242 T1060 238 T1200 236 L1200 300 L0 300Z" fill="#f4faf8"/>' +
    '<path d="M0 270 Q300 250 640 268 T1200 262 L1200 300 L0 300Z" fill="#ffffff"/>' +
    bonhomme + pere +
    '<g transform="translate(690 236) scale(.95)"><use href="#nl-renne"/></g><g transform="translate(560 262) scale(.8) translate(0 0)"></g>' +
    cadeaux +
    '</svg>';

  var bande = document.createElement("div");
  bande.className = "nl-scene";
  bande.setAttribute("role", "img");
  bande.innerHTML = scene;
  hero.insertAdjacentElement("afterend", bande);

  // Le traîneau du Père Noël qui traverse le haut de page
  var traineau =
    '<svg viewBox="0 0 360 120" aria-hidden="true">' + defs +
    '<g class="nl-vol">' +
      '<g transform="translate(250 62) scale(.9)"><use href="#nl-renne"/></g>' +
      '<g transform="translate(176 66) scale(.9)"><use href="#nl-renne"/></g>' +
      '<path d="M200 56 L134 66 M270 54 L200 56" stroke="#d6b062" stroke-width="1.6" fill="none"/>' +
      '<g transform="translate(76 76)">' +
        '<path d="M-60 -2 Q-70 -26 -48 -34 L50 -34 Q60 -20 48 -2Z" fill="#c8322f"/>' +
        '<path d="M-48 -34 Q-52 -50 -34 -52 L-30 -34Z" fill="#a52a27"/>' +
        '<path d="M-64 14 Q-8 22 56 14 Q66 12 62 4" stroke="#d6b062" stroke-width="4" fill="none" stroke-linecap="round"/>' +
        '<path d="M-44 -2 L-48 14 M0 -2 L-2 16 M40 -2 L38 16" stroke="#d6b062" stroke-width="2.6"/>' +
        '<ellipse cx="-30" cy="-44" rx="26" ry="16" fill="#8a5a34"/><path d="M-50 -48 q4 -14 16 -12" stroke="#d6b062" stroke-width="2" fill="none"/>' +
        '<circle cx="16" cy="-44" r="12" fill="#f2c9a5"/><path d="M6 -40 Q16 -22 26 -40 Q16 -34 6 -40Z" fill="#fff"/><path d="M4 -50 Q16 -72 32 -50 L28 -46 Q16 -60 6 -46Z" fill="#c8322f"/><circle cx="34" cy="-52" r="5" fill="#fff"/>' +
        '<rect x="-54" y="-40" width="30" height="8" fill="#15110d" opacity="0"/>' +
      '</g>' +
    '</g></svg>';
  var vol = document.createElement("div");
  vol.className = "nl-traineau"; vol.setAttribute("aria-hidden", "true");
  vol.innerHTML = traineau;
  hero.insertBefore(vol, hero.firstChild);
})();
