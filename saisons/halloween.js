/*
 * Habillage Halloween : citrouilles, chauves-souris et fantômes dans le haut de page, et la soirée
 * « Le Grand Bal des Sorcières » du samedi 31 octobre mise en avant avec le bouton de réservation.
 * Ne fait rien si <html> n'a pas la classe « halloween » (posée dans l'en-tête jusqu'à la date de fin).
 */
(function () {
  var html = document.documentElement;
  if (!html.classList.contains("halloween")) return;
  var de = html.lang === "de";
  var SOIREE = "2026-10-31";
  var T = de ? {
    note: "Fröhliches Halloween!", bienvenue: "Buh! 👻",
    quand: "Samstag, 31. Oktober", demain: "Morgen Abend", ce_soir: "Heute Abend !",
    titre: "Halloween: Der große Hexenball",
    details: "Sondermenü 29,90 € · Kostümwettbewerb · Tanzabend",
    reserver: "Für den 31. reservieren", reste: function (n) { return "Nur noch " + n + (n > 1 ? " Plätze" : " Platz"); },
    complet: "Ausgebucht: rufen Sie uns an (Warteliste)", appeler: "Anrufen"
  } : {
    note: "Joyeux Halloween !", bienvenue: "Bouh ! 👻",
    quand: "Samedi 31 octobre", demain: "Demain soir", ce_soir: "C’est ce soir !",
    titre: "Le Grand Bal des Sorcières",
    details: "Menu spécial 29,90 € · concours du plus beau déguisement · soirée dansante",
    reserver: "Réserver pour le 31", reste: function (n) { return "Plus que " + n + " place" + (n > 1 ? "s" : ""); },
    complet: "Complet : appelez-nous pour la liste d’attente", appeler: "Nous appeler"
  };

  // Dessins (même principe que les feuilles d'automne du site)
  var sprite = document.querySelector("svg symbol#maple");
  if (sprite) sprite.parentNode.insertAdjacentHTML("beforeend",
    '<symbol id="hw-pumpkin" viewBox="0 0 100 100">' +
      '<path d="M47 24c-1-9 3-16 10-19l4 5c-6 2-8 8-7 14z" fill="#3f6b2a"/>' +
      '<ellipse cx="31" cy="60" rx="22" ry="30" fill="#d9590f"/><ellipse cx="69" cy="60" rx="22" ry="30" fill="#d9590f"/>' +
      '<ellipse cx="50" cy="60" rx="24" ry="33" fill="#ff8a1f"/>' +
      '<path d="M33 46l11 10H29zM67 46l4 10H56z" fill="#2a1306"/>' +
      '<path d="M28 68q22 16 44 0l-5 9-5-5-6 6-6-6-6 6-6-6-5 5z" fill="#2a1306"/>' +
    '</symbol>' +
    '<symbol id="hw-bat" viewBox="0 0 100 50">' +
      '<path d="M50 14l3-8 3 8c3 0 5 2 6 4 7-8 20-12 34-8-7 3-9 9-8 16-7-5-15-4-19 2-3-5-9-5-12-1-2 5-4 10-7 14-3-4-5-9-7-14-3-4-9-4-12 1-4-6-12-7-19-2 1-7-1-13-8-16 14-4 27 0 34 8 1-2 3-4 6-4l3-8z"/>' +
    '</symbol>' +
    '<symbol id="hw-ghost" viewBox="0 0 100 100">' +
      '<path d="M50 8C28 8 16 26 16 46v44l10-8 10 8 10-8 8 8 8-8 10 8 10-8 10 8V46C84 26 72 8 50 8z" fill="#f6f0ff"/>' +
      '<circle cx="40" cy="44" r="6" fill="#1a0f22"/><circle cx="60" cy="44" r="6" fill="#1a0f22"/><ellipse cx="50" cy="62" rx="6" ry="8" fill="#1a0f22"/>' +
    '</symbol>');

  // Les feuilles deviennent citrouilles, chauves-souris et fantômes
  var motifs = ["hw-pumpkin", "hw-bat", "hw-ghost", "hw-pumpkin", "hw-bat", "hw-ghost", "hw-pumpkin", "hw-bat"];
  document.querySelectorAll(".hero .leaves svg").forEach(function (svg, i) {
    var use = svg.querySelector("use");
    if (!use) return;
    var m = motifs[i % motifs.length];
    use.setAttribute("href", "#" + m);
    if (m === "hw-bat") {
      svg.classList.add("bat");
      svg.style.fill = "#0b0610";
      svg.style.filter = "drop-shadow(0 0 6px rgba(255,140,40,.55))";
      svg.setAttribute("viewBox", "0 0 100 50");
    }
  });

  // Chauves-souris qui traversent la page
  var ciel = document.createElement("div");
  ciel.className = "hw-sky"; ciel.setAttribute("aria-hidden", "true");
  ciel.innerHTML = '<svg viewBox="0 0 100 50" style="top:18vh"><use href="#hw-bat"/></svg><svg viewBox="0 0 100 50" style="top:46vh"><use href="#hw-bat"/></svg><svg viewBox="0 0 100 50" style="top:72vh"><use href="#hw-bat"/></svg>';
  document.body.appendChild(ciel);

  // Petits mots
  var note = document.querySelector(".logo-xl .note"); if (note) note.textContent = T.note;
  var bien = document.querySelector(".hero-photo .note"); if (bien) bien.textContent = T.bienvenue;
  var meta = document.querySelector('meta[name="theme-color"]'); if (meta) meta.setAttribute("content", "#0f0818");

  // La soirée du 31, tant qu'elle n'est pas passée
  var jour = new Date(2026, 9, 31), auj = new Date(); auj.setHours(0, 0, 0, 0);
  var ecart = Math.round((jour - auj) / 864e5);
  if (ecart < 0) return;
  var actions = document.querySelector(".hero-actions");
  if (!actions) return;
  var carte = document.createElement("div");
  carte.className = "hw-event";
  carte.innerHTML = '<div class="quand">🎃 ' + (ecart === 0 ? T.ce_soir : ecart === 1 ? T.demain + " · " + T.quand : T.quand) + '</div>' +
    '<h3>' + T.titre + '</h3><p>' + T.details + '</p><span class="reste" hidden></span>';
  actions.parentNode.insertBefore(carte, actions);
  var bouton = actions.querySelector("a.btn:not(.ghost)");
  if (bouton) {
    try { var u = new URL(bouton.href, location.href); u.searchParams.set("date", SOIREE); bouton.href = u.pathname + u.search; } catch (e) {}
    var icone = bouton.querySelector("svg");
    bouton.textContent = T.reserver;
    if (icone) bouton.prepend(icone);
  }
  fetch("/api/reservation.php?action=places&date=" + SOIREE + "&service=soir").then(function (r) { return r.json(); }).then(function (d) {
    var p = carte.querySelector(".reste");
    if (d.complet) {
      p.textContent = T.complet; p.hidden = false;
      if (bouton) { bouton.href = "tel:+33388863187"; bouton.textContent = T.appeler; }
    } else if (d.restant) { p.textContent = T.reste(d.restant); p.hidden = false; }
  }).catch(function () {});
})();
