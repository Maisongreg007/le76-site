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
