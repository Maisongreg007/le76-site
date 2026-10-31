/*
 * Habillage de novembre « Le 76 fête ses 10 ans » : feuilles dorées, paillettes, tampon « Depuis 2016 ».
 * Ne fait rien si <html> n'a pas la classe « novembre » (posée dans l'en-tête aux bonnes dates).
 */
(function () {
  var html = document.documentElement;
  if (!html.classList.contains("novembre")) return;
  var de = html.lang === "de";
  var T = de
    ? { note: "Seit 2016", bienvenue: "Danke für 10 Jahre!", sceau: "SEIT 2016 · 10 JAHRE · LE 76 · ", ans: "JAHRE" }
    : { note: "Depuis 2016", bienvenue: "Merci pour ces 10 ans !", sceau: "DEPUIS 2016 · 10 ANS · LE 76 · ", ans: "ANS" };

  // Feuilles d'automne en dorés
  var dores = ["#c9a14a", "#b8892f", "#e2bd6e", "#a87b2c", "#d6b066", "#9c7128", "#e9cf8f", "#b8892f"];
  document.querySelectorAll(".hero .leaves svg").forEach(function (svg, i) { svg.style.fill = dores[i % dores.length]; });

  // Petits mots
  var note = document.querySelector(".logo-xl .note"); if (note) note.textContent = T.note;
  var bien = document.querySelector(".hero-photo .note"); if (bien) bien.textContent = T.bienvenue;

  // Le tampon : sur le coin de la photo, ou sous le logo s'il n'y a pas de photo
  var sceau =
    '<svg class="nv-sceau" viewBox="0 0 140 140" aria-hidden="true">' +
      '<defs><path id="nv-cercle" d="M70 70 m-50 0 a50 50 0 1 1 100 0 a50 50 0 1 1 -100 0"/></defs>' +
      '<circle cx="70" cy="70" r="68" fill="#2b1d16"/>' +
      '<circle cx="70" cy="70" r="62" fill="none" stroke="#e2bd6e" stroke-width="1" opacity=".6"/>' +
      '<circle cx="70" cy="70" r="38" fill="none" stroke="#e2bd6e" stroke-width="1" opacity=".6"/>' +
      '<g class="tourne"><text fill="#e2bd6e" font-family="Jost, system-ui, sans-serif" font-size="11.5" font-weight="600" letter-spacing="2.6">' +
        '<textPath href="#nv-cercle">' + T.sceau + '</textPath></text></g>' +
      '<text x="70" y="78" text-anchor="middle" fill="#fff" font-family="Playfair Display, Georgia, serif" font-size="34" font-weight="800">10</text>' +
      '<text x="70" y="93" text-anchor="middle" fill="#e2bd6e" font-family="Jost, system-ui, sans-serif" font-size="8.5" font-weight="600" letter-spacing="2.5">' + T.ans + '</text>' +
    '</svg>';
  var photo = document.getElementById("heroPhoto");
  if (photo) photo.insertAdjacentHTML("beforeend", sceau);
  else {
    var logo = document.querySelector(".logo-xl");
    if (logo) { logo.parentNode.insertAdjacentHTML("afterend", sceau); var s = document.querySelector(".nv-sceau"); if (s) s.classList.add("seul"); }
  }

  // Paillettes dorées
  if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  var hero = document.getElementById("hero");
  if (!hero) return;
  var zone = document.createElement("div");
  zone.className = "nv-paillettes"; zone.setAttribute("aria-hidden", "true");
  var n = window.innerWidth < 700 ? 12 : 22, h = "";
  for (var i = 0; i < n; i++) {
    var taille = 4 + Math.random() * 7, losange = Math.random() < .35;
    h += '<i class="' + (losange ? "losange" : "") + '" style="left:' + (Math.random() * 100).toFixed(1) + '%;width:' + taille.toFixed(1) +
      'px;height:' + taille.toFixed(1) + 'px;--dx:' + Math.round(Math.random() * 80 - 40) + 'px;animation-duration:' +
      (9 + Math.random() * 9).toFixed(1) + 's;animation-delay:-' + (Math.random() * 18).toFixed(1) + 's"></i>';
  }
  zone.innerHTML = h;
  hero.insertBefore(zone, hero.firstChild);
})();
