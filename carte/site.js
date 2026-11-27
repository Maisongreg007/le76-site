/* Site le76.fr : la partie « La carte » affiche automatiquement la carte publiée dans l'appli /carte/.
   Sans carte publiée, la carte écrite dans contenu/carte.json reste affichée. */
(function () {
  'use strict';
  var script = document.currentScript, DE = script && script.dataset.langue === 'de';
  var sec = document.getElementById('carte');
  if (!sec) return;
  var wrap = sec.querySelector('.wrap');
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var prix = function (v) {
    var t = String(v == null ? '' : v).trim(); if (!t) return '';
    if (/[a-zA-Z+]/.test(t.replace(/€/g, ''))) return t;
    var n = Number(t.replace(/\s|€/g, '').replace(',', '.'));
    if (!isFinite(n) || !/\d/.test(t)) return t;
    return (Number.isInteger(n) ? String(n) : n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })) + ' €';
  };
  var SAISON = { automne: "d'automne", hiver: "d'hiver", printemps: 'de printemps', ete: "d'été", fetes: 'des fêtes' };
  var boisson = function (s) { return /boisson|cocktail|ap[ée]ritif|soft|digestif|getr[äa]nk/i.test((s.titre || '') + ' ' + (s.titreDe || '')); };

  function prixLigne(p) {
    if (p.vol || p.vol2 || p.prix2) {
      var a = [];
      if (p.prix) a.push((p.vol ? esc(p.vol) + ' ' : '') + esc(prix(p.prix)));
      if (p.prix2) a.push((p.vol2 ? esc(p.vol2) + ' ' : '') + esc(prix(p.prix2)));
      return a.join(' · ');
    }
    return esc(prix(p.prix));
  }
  function plat(p) {
    var nom = DE ? (p.nomDe || p.nom) : p.nom, autre = DE ? (p.nomDe ? p.nom : '') : p.nomDe;
    var desc = DE ? (p.descDe || p.desc) : p.desc, px = prixLigne(p);
    var h = '<li class="item"><div class="row"><h4>' + esc(nom) + (p.note && !DE ? ' <small>' + esc(p.note) + '</small>' : '') + '</h4>';
    if (px) h += '<span class="dots"></span><span class="price">' + px + '</span>';
    h += '</div>';
    if (autre && autre !== nom) h += '<p class="de">' + esc(autre) + '</p>';
    if (desc) h += '<p class="desc">' + esc(desc) + '</p>';
    return h + '</li>';
  }
  function rubrique(s, premiere) {
    var titre = DE ? (s.titreDe || s.titre) : s.titre, autre = DE ? s.titre : s.titreDe;
    var acc = DE ? (s.accrocheDe || s.accroche) : s.accroche, h = '', it = s.items || [];
    if (premiere) {
      h += '<div class="sheet-head"><h3>' + esc(titre || '') + '</h3>' + (autre ? '<span class="de">' + esc(autre) + '</span>' : '') + (acc ? '<p>' + esc(acc) + '</p>' : '') + '</div>';
    } else if (titre) {
      h += '<h4 class="sub">' + esc(titre) + '</h4>' + (acc ? '<p class="desc">' + esc(acc) + '</p>' : '');
    }
    if (s.style === 'texte') {
      h += '<div class="glaces">' + it.map(function (p) {
        var n = DE ? (p.nomDe || p.nom) : p.nom, d = DE ? (p.descDe || p.desc) : p.desc;
        return '<p>' + (n ? '<strong>' + esc(n) + ' :</strong> ' : '') + esc(d) + '</p>';
      }).join('') + '</div>';
    } else if (s.style === 'encadre') {
      h += '<div class="glaces"><div class="chips">' + it.map(function (p) {
        return '<span>' + esc(DE ? (p.nomDe || p.nom) : p.nom) + (p.prix ? ' <b>' + esc(prix(p.prix)) + '</b>' : '') + '</span>';
      }).join('') + '</div></div>';
    } else if (it.length) {
      h += '<ul class="items">' + it.map(plat).join('') + '</ul>';
    }
    if (s.bandeau) {
      var b = String(s.bandeau).split(/\s+-\s+/);
      h += '<div class="center"><span class="ribbon">' + esc(DE ? (b[1] || b[0]) : b[0]) + (!DE && b[1] ? ' · <span>' + esc(b[1]) + '</span>' : '') + '</span></div>';
    }
    return h;
  }
  function afficher(pub) {
    var m = pub.meta || {}, pages = m.pages || [], secs = pub.sections || [];
    var de = function (pg) { var l = secs.filter(function (s) { return s.page === pg.id; }); return l; };
    var ordre = function (a, b) { return (a.ordre || 0) - (b.ordre || 0); };
    var tabs = '', panels = '';
    pages.forEach(function (pg, i) {
      var l = de(pg).sort(ordre); if (!l.length) return;
      var id = 'pc-' + (i + 1), nom = DE ? (l[0].titreDe || pg.nom) : pg.nom;
      tabs += '<button role="tab" aria-selected="false" data-tab="' + id + '">' + esc(nom) + '</button>';
      var bar = l.every(boisson) ? 'panel bar' : 'panel';
      panels += '<div class="' + bar + '" id="' + id + '" role="tabpanel"><div class="sheet">' + l.map(function (s, k) { return rubrique(s, k === 0); }).join('') + '</div></div>';
    });
    if (!tabs) return;
    var titre = DE ? 'Unsere <em>Speisekarte</em>' : 'La <em>carte</em> ' + esc(SAISON[m.saison] || '');
    var lead = DE ? 'Alle Gerichte auf Deutsch und Französisch.' : 'Tous nos plats, en français et en allemand.';
    var liens = '<p class="center" style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap"><a class="btn ghost" href="/carte/#carte">' +
      (DE ? 'Die ganze Speisekarte ansehen (alle Seiten)' : 'Voir la carte complète (toutes les pages)') + '</a>' +
      (pub.allergenes ? '<a class="btn ghost" href="/carte/#allergenes">' + (DE ? 'Allergene' : 'Allergènes') + '</a>' : '') + '</p>';
    wrap.innerHTML = '<div class="center"><span class="kicker">Cuisine · Partage · Convivialité</span><h2 class="title">' + titre + '</h2><p class="lead">' + lead + '</p></div>' +
      '<div class="tabs" role="tablist" aria-label="' + (DE ? 'Rubriken der Speisekarte' : 'Rubriques de la carte') + '">' + tabs + '</div>' + panels +
      '<p class="fineprint">' + esc(m.pied || '') + (DE ? ' · Alle Preise inkl. Service' : '') + '</p>' + liens;
    var bt = wrap.querySelectorAll('.tabs button');
    var ouvrir = function (id) {
      bt.forEach(function (t) { t.setAttribute('aria-selected', String(t.dataset.tab === id)); });
      wrap.querySelectorAll('.panel').forEach(function (p) { p.classList.toggle('active', p.id === id); });
    };
    bt.forEach(function (t) { t.addEventListener('click', function () { ouvrir(t.dataset.tab); }); });
    if (bt.length) ouvrir(bt[0].dataset.tab);
  }
  fetch('/api/carte.php?action=publiee').then(function (r) { return r.json(); }).then(function (d) {
    if (d && d.publie) afficher(d.publie);
  }).catch(function () {});
})();
