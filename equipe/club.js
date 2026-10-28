/*
 * Le 76 · Le Club — onglet « Club » de l'appli équipe.
 * Utilise les outils de l'appli (window.LE76 : api, sheet, toast…) : même connexion, même style.
 * Toute l'équipe : chercher / scanner une carte, compter la visite, offrir le cadeau, utiliser une offre.
 * Responsable : offres par e-mail (aperçu → validation), affiche QR des tables, réglages, export.
 */
(() => {
'use strict';
const X = () => window.LE76;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const MOIS = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
const jourCourt = d => { if (!d) return '—'; const [y, m, j] = d.slice(0, 10).split('-').map(Number); return `${j} ${MOIS[m - 1]}${y !== new Date().getFullYear() ? ' ' + y : ''}`; };
const auj = () => { const x = new Date(); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`; };
const ilya = d => { if (!d) return 'jamais venu'; const n = Math.round((new Date(auj()) - new Date(d.slice(0, 10))) / 864e5); return n === 0 ? "aujourd'hui" : n === 1 ? 'hier' : n < 30 ? `il y a ${n} j` : `le ${jourCourt(d)}`; };
const plur = (n, s, p) => `${n} ${n > 1 ? (p || s + 's') : s}`;
const JOURS = [[1, 'L'], [2, 'M'], [3, 'M'], [4, 'J'], [5, 'V'], [6, 'S'], [7, 'D']];
const STATUT_OFFRE = { brouillon: ['Brouillon', ''], simulee: ['Simulée', 'sim'], envoi: ['Envoi en cours', 'att'], envoyee: ['Envoyée', 'ok'] };
const C = { base: '', reglages: {}, minuterie: null };

/* ---------- Styles propres au Club (mêmes couleurs que l'appli) ---------- */
document.head.insertAdjacentHTML('beforeend', `<style>
  .cl-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 22px; }
  @media (max-width: 560px) { .cl-stats { grid-template-columns: 1fr 1fr; } }
  .cl-stat { background: var(--paper); border: 1px solid var(--line); border-radius: var(--r); padding: 12px 14px; }
  .cl-stat b { display: block; font: 700 1.6rem/1.1 var(--serif); letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
  .cl-stat span { font-size: .78rem; color: var(--muted); }
  .cl-stat.or b { color: var(--gold); }
  .cl-chercher { display: flex; gap: 10px; margin-bottom: 18px; }
  .cl-chercher .search { margin: 0; flex: 1; height: 50px; border-radius: 13px; font-size: 1.05rem; }
  .cl-chercher .btn { flex: none; min-height: 50px; padding: 0 16px; }
  .cl-chercher .btn svg { width: 22px; height: 22px; }
  @media (max-width: 420px) { .cl-chercher .btn span { display: none; } }
  .cl-m { display: flex; align-items: center; gap: 13px; width: 100%; padding: 12px 18px; min-height: 64px; border: 0; border-top: .5px solid var(--line); background: transparent; text-align: left; }
  .cl-m:first-of-type { border-top: 0; }
  .group > h3 + .cl-m { border-top: 0; }
  .cl-m:active { background: var(--sand); }
  .cl-av { width: 42px; height: 42px; flex: none; border-radius: 50%; display: grid; place-items: center; font: 700 1.05rem/1 var(--serif); background: var(--sand); color: var(--brown); }
  .cl-av.ok { background: var(--green-bg); color: var(--green); }
  .cl-m .grow { flex: 1; min-width: 0; }
  .cl-m .grow b { display: block; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cl-m .grow small { display: block; color: var(--muted); font-size: .83rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cl-prog { flex: none; font-weight: 700; font-size: .9rem; font-variant-numeric: tabular-nums; color: var(--muted); }
  .cl-pastille { flex: none; border-radius: 999px; padding: 4px 10px; font-size: .78rem; font-weight: 700; background: #fdeccf; color: var(--amber); }
  .cl-tampons { display: grid; grid-template-columns: repeat(var(--n), 1fr); gap: 7px; margin: 14px 0 8px; max-width: 340px; }
  .cl-tampon { aspect-ratio: 1; border-radius: 50%; border: 1.5px dashed var(--faint); display: grid; place-items: center; font-weight: 700; color: transparent; font-size: .9rem; }
  .cl-tampon.plein { background: var(--gold); border: 0; color: #fff; }
  .cl-tampon.k { border-style: solid; border-color: var(--gold); color: var(--gold); }
  .cl-carte { background: var(--paper); border: 1px solid var(--line); border-radius: var(--r); padding: 16px 18px; margin-bottom: 16px; }
  .cl-carte .l1 { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
  .cl-carte .num { font: 600 .95rem/1 ui-monospace, "SF Mono", Menlo, monospace; color: var(--muted); }
  .cl-carte p { margin: 4px 0 0; color: var(--muted); font-size: .9rem; }
  .cl-actions { display: grid; gap: 10px; margin-bottom: 18px; }
  .cl-actions .btn { min-height: 58px; font-size: 1.08rem; }
  .btn.gold { background: var(--gold); color: var(--ink); }
  .cl-fait { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: var(--green-bg); color: var(--green); border-radius: 12px; padding: 14px 16px; font-weight: 600; }
  .cl-fait button { border: 0; background: transparent; color: var(--green); text-decoration: underline; font-size: .88rem; font-weight: 500; }
  .cl-offre { padding: 13px 18px; border-top: .5px solid var(--line); display: flex; gap: 12px; align-items: center; }
  .cl-offre:first-of-type, .group > h3 + .cl-offre { border-top: 0; }
  .cl-offre .grow b { display: block; font-weight: 600; }
  .cl-offre .grow small { display: block; color: var(--muted); font-size: .83rem; margin-top: 2px; }
  .cl-tag { display: inline-block; font-size: .64rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; border-radius: 5px; padding: 2px 6px; margin-left: 6px; vertical-align: 2px; background: var(--fill); color: var(--muted); }
  .cl-tag.ok { background: var(--green-bg); color: var(--green); }
  .cl-tag.att { background: var(--amber-bg); color: var(--amber); }
  .cl-tag.sim { background: #e9e4f7; color: #5a4fcf; }
  .cl-tag.err { background: #f8dcd6; color: var(--rust-dark); }
  .cl-o { width: 100%; text-align: left; border: 0; background: transparent; padding: 14px 18px; border-top: .5px solid var(--line); display: block; }
  .group > h3 + .cl-o, .group > .cl-o:first-child { border-top: 0; }
  .cl-o:active { background: var(--sand); }
  .cl-o b { font-weight: 600; font-size: 1.02rem; }
  .cl-o small { display: block; color: var(--muted); font-size: .84rem; margin-top: 3px; }
  .cl-jours { display: flex; gap: 6px; margin-top: 6px; }
  .cl-jours button { flex: 1; height: 42px; border-radius: 10px; border: 1px solid var(--line); background: #fff; font-weight: 600; color: var(--muted); }
  .cl-jours button.on { background: var(--rust); border-color: var(--rust); color: #fff; }
  .cl-modeles { display: flex; gap: 8px; overflow-x: auto; margin: -4px 0 18px; padding-bottom: 4px; }
  .cl-modeles button { flex: none; border: 1px solid var(--line); background: #fff; border-radius: 999px; padding: 8px 14px; font-size: .88rem; font-weight: 500; color: var(--brown); }
  .cl-apercu { width: 100%; height: 540px; border: 1px solid var(--line); border-radius: 12px; background: #fbf5ec; display: block; }
  .cl-chiffres { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
  .cl-video { position: relative; border-radius: 16px; overflow: hidden; background: #000; aspect-ratio: 4 / 3; margin-bottom: 14px; }
  .cl-video video { width: 100%; height: 100%; object-fit: cover; display: block; }
  .cl-video i { position: absolute; inset: 18%; border: 3px solid rgba(255,255,255,.85); border-radius: 18px; box-shadow: 0 0 0 999px rgba(0,0,0,.25); }
  .cl-qr { background: #fff; border-radius: 18px; padding: 18px; max-width: 320px; margin: 0 auto 16px; }
  .cl-qr svg { width: 100%; height: auto; display: block; }
  .cl-prog-envoi { height: 8px; border-radius: 4px; background: var(--fill); overflow: hidden; margin: 10px 0 6px; }
  .cl-prog-envoi i { display: block; height: 100%; background: var(--green); transition: width .4s; }
  .cl-journal { font-size: .88rem; }
  .cl-journal .row { min-height: 0; padding: 10px 18px; }
</style>`);

const ICONE_SCAN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/></svg>';

function initiale(p) { return esc((p || '?').trim().charAt(0).toUpperCase()); }
function ligneMembre(m) {
  const k = m.compteurs;
  return `<button class="cl-m" data-m="${m.id}">
    <span class="cl-av ${k.venu_aujourdhui ? 'ok' : ''}">${k.venu_aujourdhui ? '✓' : initiale(m.prenom)}</span>
    <span class="grow"><b>${esc(m.prenom)}${m.langue === 'de' ? ' 🇩🇪' : ''}</b><small>${esc(m.carte)} · ${plur(k.visites, 'visite')} · ${k.venu_aujourdhui ? "venu aujourd'hui" : ilya(k.derniere)}</small></span>
    ${k.cadeaux_dispo ? `<span class="cl-pastille">🎁 ${k.cadeaux_dispo > 1 ? '×' + k.cadeaux_dispo : 'à offrir'}</span>` : `<span class="cl-prog">${k.tampons}/${k.seuil}</span>`}
  </button>`;
}

/* ---------- Vue principale ---------- */
let rechercheT;
async function vue(silencieux) {
  const { api, enTete, S } = X();
  enTete('Le Club');
  const main = document.getElementById('main');
  const q = document.getElementById('clQ')?.value || '';
  if (!silencieux) main.innerHTML = '<div class="loading">Chargement…</div>';
  const d = await api('club_accueil');
  if (S.vue !== 'club') return;
  if (!d.ok) { main.innerHTML = `<div class="loading">${esc(d.erreur)}</div>`; return; }
  C.base = d.base; C.reglages = d.reglages;
  if (silencieux && document.activeElement?.id === 'clQ') return; // ne pas couper une saisie
  const s = d.stats;
  main.innerHTML = `
    <div class="cl-chercher"><input class="search" id="clQ" type="search" placeholder="Prénom, e-mail ou n° de carte" autocomplete="off" autocapitalize="none" value="${esc(q)}">
      <button class="btn dark" id="clScan" aria-label="Scanner une carte">${ICONE_SCAN}<span>Scanner</span></button></div>
    <div id="clRes"></div>
    <div id="clAccueil">
      <div class="cl-stats">
        <div class="cl-stat"><b>${s.membres}</b><span>membres du Club</span></div>
        <div class="cl-stat"><b>${s.abonnes}</b><span>acceptent les offres</span></div>
        <div class="cl-stat"><b>${s.visites_semaine}</b><span>visites en 7 jours</span></div>
        <div class="cl-stat or"><b>${s.cadeaux_a_offrir}</b><span>cadeaux à offrir</span></div>
      </div>
      ${d.envois.restants ? `<div class="tip">📨 <b>Envoi des offres en cours :</b> ${plur(d.envois.restants, 'e-mail')} en attente, envoyés par petits lots${d.envois.pause ? ' (plafond horaire atteint, reprise automatique)' : ''}.</div>` : ''}
      <div class="group"><h3>Venus aujourd'hui · ${d.aujourdhui.length}</h3>
        ${d.aujourdhui.length ? d.aujourdhui.map(ligneMembre).join('') : '<div class="row"><div class="grow" style="color:var(--muted)">Scanne la carte d’un client ou cherche son prénom pour compter sa visite.</div></div>'}</div>
      ${d.recents.length ? `<div class="group"><h3>Derniers passages</h3>${d.recents.map(ligneMembre).join('')}</div>` : ''}
      ${S.moi.admin ? `<div class="group"><h3>Responsable</h3>
        <button class="row link-row" data-a="offres"><span>Offres par e-mail<small style="display:block;color:var(--muted);font-size:.82rem">${C.reglages.envoi_reel ? 'Envoi réel activé' : 'Envoi réel coupé : simulations seulement'}</small></span><span>›</span></button>
        <button class="row link-row" data-a="affiche"><span>Affiche QR pour les tables<small style="display:block;color:var(--muted);font-size:.82rem">À imprimer, en français et en allemand</small></span><span>›</span></button>
        <button class="row link-row" data-a="reglages"><span>Réglages du Club<small style="display:block;color:var(--muted);font-size:.82rem">${esc(C.reglages.seuil)} visites = ${esc((C.reglages.cadeau || '').toLowerCase())}</small></span><span>›</span></button>
        <button class="row link-row" data-a="journal"><span>Journal du Club</span><span>›</span></button>
        <a class="row link-row" href="${X().API}?action=club_export" style="text-decoration:none;color:inherit"><span>Télécharger les membres (Excel)</span><span>›</span></a>
      </div>` : ''}
      <p style="text-align:center;color:var(--muted);font-size:.8rem">Page client : <a href="${C.base}/club/" target="_blank">le76.fr/club</a></p>
    </div>`;
  const champ = document.getElementById('clQ');
  champ.oninput = () => { clearTimeout(rechercheT); rechercheT = setTimeout(chercher, 220); };
  champ.onkeydown = async e => {
    if (e.key !== 'Enter') return;
    e.preventDefault(); clearTimeout(rechercheT);
    const r = await api('club_chercher', { q: champ.value });
    if (r.ok && r.resultats.length === 1) { fiche(r.resultats[0].id); champ.value = ''; chercher(); } else chercher();
  };
  document.getElementById('clScan').onclick = scanner;
  main.onclick = e => {
    const m = e.target.closest('[data-m]'); if (m) return fiche(+m.dataset.m);
    const a = e.target.closest('[data-a]')?.dataset.a;
    if (a === 'offres') ecranOffres();
    else if (a === 'affiche') affiche();
    else if (a === 'reglages') ecranReglages();
    else if (a === 'journal') ecranJournal();
  };
  if (q) chercher();
}

async function chercher() {
  const champ = document.getElementById('clQ'); if (!champ) return;
  const q = champ.value.trim(), res = document.getElementById('clRes'), acc = document.getElementById('clAccueil');
  if (q.length < 2) { res.innerHTML = ''; acc.hidden = false; return; }
  const r = await X().api('club_chercher', { q });
  if (champ.value.trim() !== q) return;
  acc.hidden = true;
  res.innerHTML = `<div class="group">${r.ok && r.resultats.length ? r.resultats.map(ligneMembre).join('')
    : `<div class="row"><div class="grow">Personne pour « ${esc(q)} ».<small>Nouveau client ? Appuie sur + pour lui créer sa carte.</small></div></div>`}</div>`;
}

/* ---------- Fiche client ---------- */
function tampons(k) {
  const plein = k.cadeaux_dispo > 0 && k.tampons === 0 ? k.seuil : k.tampons;
  let h = '';
  for (let i = 1; i <= k.seuil; i++) h += `<div class="cl-tampon ${i <= plein ? 'plein' : i === k.seuil ? 'k' : ''}">${i <= plein ? '✓' : i === k.seuil ? '🎁' : ''}</div>`;
  return `<div class="cl-tampons" style="--n:${k.seuil}">${h}</div>`;
}

async function fiche(cle) {
  const { api, toast } = X();
  const r = await api('club_fiche', typeof cle === 'object' ? cle : { id: cle });
  if (!r.ok) return toast(r.erreur, true);
  ouvrirFiche(r.membre);
}

function ouvrirFiche(m) {
  const { api, toast, sheet, S } = X();
  document.querySelector('.veil')?.remove();
  const k = m.compteurs;
  const offres = m.offres_recues;
  sheet(`
    <h2 style="margin-bottom:6px">${esc(m.prenom)}${m.langue === 'de' ? ' 🇩🇪' : ''}</h2>
    <p style="margin:-8px 0 16px;color:var(--muted);font-size:.92rem">${esc(m.email)}</p>
    <div class="cl-carte"><div class="l1"><b>${plur(k.visites, 'visite')}</b><span class="num">${esc(m.carte)}</span></div>
      ${tampons(k)}
      <p>${k.cadeaux_dispo ? `<b style="color:var(--gold)">🎁 ${k.cadeaux_dispo > 1 ? k.cadeaux_dispo + ' cadeaux' : 'Cadeau'} à offrir</b>` : `Encore ${plur(k.reste, 'visite')} avant le cadeau`} · dernière visite ${ilya(k.derniere)}${k.cadeaux_remis ? ` · ${plur(k.cadeaux_remis, 'cadeau offert', 'cadeaux offerts')}` : ''}</p></div>
    <div class="cl-actions">
      ${k.venu_aujourdhui
        ? `<div class="cl-fait"><span>✓ Visite d'aujourd'hui comptée</span><button data-x="annuler">Annuler</button></div>`
        : `<button class="btn green block" data-x="visite">✓ Compter la visite d'aujourd'hui</button>`}
      ${k.cadeaux_dispo ? `<button class="btn gold block" data-x="cadeau">🎁 Offrir : ${esc((C.reglages.cadeau || 'le cadeau').toLowerCase())}</button>` : ''}
    </div>
    ${offres.length ? `<div class="group"><h3>Offres reçues</h3>${offres.map(o => `<div class="cl-offre"><div class="grow"><b>${esc(o.titre)}${o.simulation ? '<span class="cl-tag sim">simulation</span>' : ''}</b>
      <small>${o.utilise_le ? `Utilisée le ${jourCourt(o.utilise_le)}${o.utilise_par ? ' · ' + esc(o.utilise_par) : ''}` : o.bloque ? esc(o.bloque) : esc(o.conditions)}</small></div>
      ${o.utilise_le ? '<span class="cl-tag ok">utilisée</span>' : o.bloque ? '' : `<button class="btn small" data-x="utiliser" data-e="${o.envoi_id}">Utiliser</button>`}</div>`).join('')}</div>` : ''}
    <div class="group"><h3>Offres par e-mail</h3>
      <div class="row"><div class="grow">${m.offres ? 'Accepte les offres' : 'N’accepte pas les offres'}<small>${m.offres ? `Depuis le ${jourCourt(m.offres_le)}` : m.offres_retire_le ? `Désinscrit le ${jourCourt(m.offres_retire_le)}` : 'La carte fonctionne sans'}</small></div>${X().sw('', m.offres, 'id="clAccord"')}</div></div>
    <div class="group">
      <button class="row link-row" data-x="montrer"><span>Mettre la carte sur son téléphone<small style="display:block;color:var(--muted);font-size:.82rem">Le client scanne un QR avec son appareil photo</small></span><span>›</span></button>
      <button class="row link-row" data-x="modifier"><span>Modifier prénom, e-mail, langue</span><span>›</span></button>
      <button class="row link-row" data-x="historique"><span>Historique</span><span>›</span></button>
      ${S.moi.admin ? '<button class="row link-row" data-x="supprimer"><span style="color:var(--rust)">Supprimer ce client et ses données</span><span>›</span></button>' : ''}
    </div>
    ${m.note ? `<div class="tip">📝 ${esc(m.note)}</div>` : ''}
    <button class="btn ghost block" data-fermer>Fermer</button>`, (el, fermer) => {
    const agir = async (action, data = {}, msg) => {
      el.querySelectorAll('button').forEach(b => b.disabled = true);
      const r = await api(action, { id: m.id, ...data });
      if (!r.ok) { toast(r.erreur, true); el.querySelectorAll('button').forEach(b => b.disabled = false); return; }
      if (r.cadeau_gagne) toast(`🎉 ${r.membre.compteurs.visites}e visite : ${m.prenom} gagne son cadeau !`);
      else if (msg) toast(msg);
      ouvrirFiche(r.membre);
      if (S.vue === 'club') vue(true);
    };
    el.onclick = async e => {
      const b = e.target.closest('[data-x]'); if (!b) return;
      const x = b.dataset.x;
      if (x === 'visite') agir('club_visite', {}, `Visite comptée pour ${m.prenom}`);
      else if (x === 'annuler') { if (confirm(`Annuler la visite d'aujourd'hui de ${m.prenom} ?`)) agir('club_visite_annuler', {}, 'Visite annulée'); }
      else if (x === 'cadeau') { if (confirm(`Offrir maintenant à ${m.prenom} : ${(C.reglages.cadeau || 'le cadeau').toLowerCase()} ?`)) agir('club_cadeau', {}, 'Cadeau offert 🎁'); }
      else if (x === 'utiliser') { if (confirm(`Utiliser cette offre pour ${m.prenom} ? Elle ne pourra plus resservir.`)) agir('club_offre_utiliser', { envoi_id: +b.dataset.e }, 'Offre utilisée'); }
      else if (x === 'montrer') montrerCarte(m);
      else if (x === 'modifier') { fermer(); modifier(m); }
      else if (x === 'historique') historique(m);
      else if (x === 'supprimer') {
        if (prompt(`Supprimer définitivement ${m.prenom}, ses visites, cadeaux et offres ? Tape SUPPRIMER pour confirmer.`) !== 'SUPPRIMER') return;
        const r = await api('club_supprimer', { id: m.id });
        r.ok ? (fermer(), toast('Client supprimé'), vue(true)) : toast(r.erreur, true);
      }
    };
    el.querySelector('#clAccord').onchange = e => {
      const oui = e.target.checked;
      if (oui && !confirm(`${m.prenom} vient-il de te dire OUI pour recevoir les offres par e-mail ?\n\nNe coche que si le client l'a demandé lui-même.`)) { e.target.checked = false; return; }
      agir('club_accord', { accord: oui }, oui ? 'Accord enregistré' : 'Ne recevra plus les offres');
    };
  });
}

function montrerCarte(m) {
  X().sheet(`<h2>La carte de ${esc(m.prenom)}</h2>
    <div class="tip">Le client ouvre l’<b>appareil photo</b> de son téléphone et vise ce code : sa carte s’ouvre. Il peut ensuite l’ajouter à son écran d’accueil.</div>
    <div class="cl-qr">${qrSvg(m.lien_carte, { border: 2 })}</div>
    <button class="btn ghost block" data-fermer>Fermer</button>`);
}

function historique(m) {
  const lignes = [
    ...m.visites.map(v => [v.quand, `Visite${v.par ? ' · ' + esc(v.par) : ''}`]),
    ...m.cadeaux.map(c => [c.quand, `🎁 ${esc(c.libelle)}${c.par ? ' · ' + esc(c.par) : ''}`]),
    ...m.accords.map(a => [a.quand, `${a.accord ? 'Accepte' : 'Refuse'} les offres · ${({ inscription: 'à l’inscription', carte: 'depuis sa carte', lien: 'lien de l’e-mail', 'email-1clic': 'désinscription de sa messagerie', equipe: 'noté par ' + esc(a.par || 'l’équipe') })[a.source] || esc(a.source)}`]),
    [m.inscrit_le, `Inscription ${({ table: 'par le QR des tables', site: 'sur le site', equipe: 'au comptoir' + (m.inscrit_par ? ' par ' + esc(m.inscrit_par) : '') })[m.origine] || ''}`],
  ].sort((a, b) => b[0].localeCompare(a[0]));
  X().sheet(`<h2>Historique de ${esc(m.prenom)}</h2><div class="group cl-journal">${lignes.map(([q, t]) => `<div class="row"><div class="grow">${t}</div><small style="color:var(--muted)">${jourCourt(q)}</small></div>`).join('')}</div>
    <button class="btn ghost block" data-fermer>Fermer</button>`);
}

function modifier(m) {
  const { api, toast, sheet } = X();
  sheet(`<h2>Modifier</h2><form id="clMod">
    <label class="field">Prénom<input name="prenom" value="${esc(m.prenom)}" required></label>
    <label class="field">E-mail<input name="email" type="email" value="${esc(m.email)}" required autocapitalize="none"></label>
    <div class="field">Langue des e-mails<div class="seg" id="clLg"><button type="button" data-l="fr" class="${m.langue !== 'de' ? 'on' : ''}">Français</button><button type="button" data-l="de" class="${m.langue === 'de' ? 'on' : ''}">Allemand</button></div></div>
    <label class="field">Note pour l’équipe <small>(facultatif, ex. allergie, table préférée)</small><textarea name="note" maxlength="300">${esc(m.note)}</textarea></label>
    <button class="btn block">Enregistrer</button></form>`, (el, fermer) => {
    let lg = m.langue;
    el.querySelector('#clLg').onclick = e => { const b = e.target.closest('[data-l]'); if (!b) return; lg = b.dataset.l; el.querySelectorAll('#clLg button').forEach(x => x.classList.toggle('on', x === b)); };
    el.querySelector('#clMod').onsubmit = async ev => {
      ev.preventDefault(); const f = ev.target;
      const r = await api('club_modifier', { id: m.id, prenom: f.prenom.value, email: f.email.value, langue: lg, note: f.note.value });
      r.ok ? (fermer(), toast('Enregistré'), ouvrirFiche(r.membre)) : toast(r.erreur, true);
    };
  });
}

/* ---------- Nouveau client au comptoir ---------- */
function nouveau() {
  const { api, toast, sheet } = X();
  sheet(`<h2>Nouvelle carte du Club</h2><form id="clNv">
    <label class="field">Prénom du client<input name="prenom" required autocomplete="off"></label>
    <label class="field">Son e-mail<input name="email" type="email" required autocomplete="off" autocapitalize="none" inputmode="email"></label>
    <div class="field">Langue<div class="seg" id="clLg"><button type="button" data-l="fr" class="on">Français</button><button type="button" data-l="de">Allemand</button></div></div>
    <label class="checkline"><input type="checkbox" name="offres"> Le client accepte de recevoir les offres par e-mail</label>
    <div class="tip">Ne coche la case que si le client a dit <b>oui</b>. Sa carte fonctionne aussi sans.</div>
    <button class="btn block">Créer la carte</button></form>`, (el, fermer) => {
    let lg = 'fr';
    el.querySelector('#clLg').onclick = e => { const b = e.target.closest('[data-l]'); if (!b) return; lg = b.dataset.l; el.querySelectorAll('#clLg button').forEach(x => x.classList.toggle('on', x === b)); };
    el.querySelector('#clNv').onsubmit = async ev => {
      ev.preventDefault(); const f = ev.target;
      const r = await api('club_inscrire', { prenom: f.prenom.value, email: f.email.value, langue: lg, offres: f.offres.checked });
      if (!r.ok) return toast(r.erreur, true);
      fermer(); toast(r.existe ? `Cette adresse a déjà une carte : celle de ${r.membre.prenom}` : `Carte créée pour ${r.membre.prenom}`);
      ouvrirFiche(r.membre); vue(true);
    };
  });
  setTimeout(() => document.querySelector('#clNv [name=prenom]')?.focus(), 300);
}

/* ---------- Scanner une carte avec la caméra ---------- */
function chargerJsQR() {
  if (window.jsQR) return Promise.resolve();
  const sources = ['https://cdnjs.cloudflare.com/ajax/libs/jsQR/1.4.0/jsQR.min.js', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js'];
  return sources.reduce((p, src) => p.catch(() => new Promise((ok, ko) => {
    const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = ko; document.head.appendChild(s);
  })), Promise.reject());
}
function codeDepuis(texte) {
  const m = String(texte).toUpperCase().match(/76-?([ACDEFGHJKMNPQRTUVWXY34679]{5})/);
  return m ? '76-' + m[1] : null;
}
async function scanner() {
  const { toast, sheet } = X();
  let flux = null, arret = false, face = 'environment';
  const fermerFlux = () => { arret = true; flux?.getTracks().forEach(t => t.stop()); };
  const fermer = sheet(`<h2>Scanner la carte</h2>
    <div class="cl-video"><video playsinline muted autoplay></video><i></i></div>
    <p class="sub" id="clScanMsg" style="text-align:center;margin:0 0 14px">Vise le QR de la carte du client.</p>
    <div class="actions"><button class="btn ghost" id="clFace">Changer de caméra</button><button class="btn ghost" data-fermer>Fermer</button></div>
    <label class="field" style="margin-top:16px">Ou tape le numéro<input id="clNum" placeholder="76-…" autocapitalize="characters" autocomplete="off"></label>`, el => {
    el.querySelector('#clNum').onkeydown = e => { if (e.key === 'Enter') { const c = codeDepuis(e.target.value) || e.target.value; fermerFlux(); document.querySelector('.veil')?.remove(); fiche({ carte: c }); } };
    el.querySelector('#clFace').onclick = () => { face = face === 'environment' ? 'user' : 'environment'; demarrer(); };
  });
  const veil = document.querySelector('.veil');
  new MutationObserver((_, o) => { if (!document.body.contains(veil)) { fermerFlux(); o.disconnect(); } }).observe(document.body, { childList: true });
  const msg = t => { const p = document.getElementById('clScanMsg'); if (p) p.textContent = t; };
  const video = veil.querySelector('video'), canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
  async function demarrer() {
    flux?.getTracks().forEach(t => t.stop());
    try { flux = await navigator.mediaDevices.getUserMedia({ video: { facingMode: face, width: { ideal: 1280 } }, audio: false }); }
    catch { msg('Caméra indisponible : autorise-la dans les réglages, ou tape le numéro ci-dessous.'); return; }
    video.srcObject = flux; await video.play().catch(() => {});
  }
  try { await chargerJsQR(); } catch { msg('Lecteur de QR indisponible (pas d’internet ?). Tape le numéro de la carte.'); return; }
  await demarrer();
  (function boucle() {
    if (arret) return;
    if (video.readyState >= 2 && video.videoWidth) {
      const w = 640, h = Math.round(video.videoHeight * w / video.videoWidth);
      canvas.width = w; canvas.height = h; ctx.drawImage(video, 0, 0, w, h);
      const r = window.jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' });
      const c = r && codeDepuis(r.data);
      if (c) { if (navigator.vibrate) navigator.vibrate(60); fermerFlux(); fermer(); fiche({ carte: c }); return; }
    }
    requestAnimationFrame(boucle);
  })();
}

/* ---------- Offres par e-mail (responsable) ---------- */
async function ecranOffres() {
  const { api, toast, sheet } = X();
  const d = await api('club_offres'); if (!d.ok) return toast(d.erreur, true);
  C.reglages = { ...C.reglages, ...d.reglages };
  document.querySelector('.veil')?.remove();
  sheet(`<h2>Offres par e-mail</h2>
    ${d.reglages.envoi_reel ? `<div class="tip">✉️ <b>Envoi réel activé</b> depuis ${esc('reservation@le76.fr')}, ${d.reglages.par_heure} e-mails maximum par heure. Rien ne part sans ta validation.</div>`
      : `<div class="tip">🧪 <b>Envoi réel coupé.</b> Tu peux tout préparer et valider : les offres sont seulement <b>simulées</b>, aucun e-mail ne part. À activer dans Réglages du Club.</div>`}
    ${d.envois.restants ? `<div class="tip">📨 ${plur(d.envois.restants, 'e-mail')} en attente d’envoi${d.envois.pause ? ' (plafond horaire atteint, reprise automatique)' : ''}.</div>` : ''}
    <button class="btn block" id="clNvO" style="margin-bottom:20px">+ Nouvelle offre</button>
    <div class="group">${d.offres.length ? d.offres.map(o => {
      const [lib, cls] = STATUT_OFFRE[o.statut] || [o.statut, ''];
      return `<button class="cl-o" data-o="${o.id}" data-s="${o.statut}"><b>${esc(o.titre)}</b><span class="cl-tag ${cls}">${lib}</span>
        <small>${esc(o.cible_texte)} · ${esc(o.conditions)}</small>
        ${o.statut !== 'brouillon' ? `<small>${plur(o.nb_dest, 'destinataire')}${o.simulation ? '' : ` · ${o.envoyes} envoyé${o.envoyes > 1 ? 's' : ''}`}${o.en_attente ? ` · ${o.en_attente} en attente` : ''}${o.erreurs ? ` · <span style="color:var(--rust)">${o.erreurs} en erreur</span>` : ''}${o.exclus ? ` · ${o.exclus} désinscrit${o.exclus > 1 ? 's' : ''} avant l’envoi` : ''} · <b>${plur(o.utilises, 'utilisation')}</b></small>` : ''}</button>`;
    }).join('') : '<div class="row">Aucune offre pour l’instant.</div>'}</div>
    <button class="btn ghost block" data-fermer>Fermer</button>`, el => {
    el.querySelector('#clNvO').onclick = () => editeur(null);
    el.onclick = e => { const b = e.target.closest('[data-o]'); if (!b) return; b.dataset.s === 'brouillon' ? apercu(+b.dataset.o) : detailOffre(+b.dataset.o); };
  });
}

const MODELES = [
  { nom: 'Midi en semaine', titre: 'Midi en semaine', cible: 'tous', jours: [2, 3, 4], services: ['midi'],
    objet: 'Un petit plaisir vous attend ce midi au 76', message: 'Bonjour {prenom},\n\nCette semaine, du mardi au jeudi midi, votre café gourmand est offert avec le plat du jour.\n\nRéservez au 03 88 86 31 87 ou sur le76.fr.\n\nÀ très vite !',
    objet_de: 'Eine kleine Freude erwartet Sie mittags im Le 76', message_de: 'Hallo {prenom},\n\ndiese Woche, Dienstag bis Donnerstag mittags, schenken wir Ihnen zum Tagesgericht einen Café gourmand.\n\nReservierung: +33 3 88 86 31 87 oder le76.fr.\n\nBis bald!' },
  { nom: 'On vous a manqué', titre: 'Retrouvailles', cible: 'absents', jours: [1, 2, 3, 4, 5, 6, 7], services: ['midi', 'soir'],
    objet: 'Ça fait un moment… on vous garde une place !', message: 'Bonjour {prenom},\n\nÇa fait un moment qu’on ne vous a pas vu au 76 ! Pour votre retour, le dessert vous est offert avec votre repas.\n\nÀ très vite,\nGreg, Vanessa et toute l’équipe',
    objet_de: 'Lange nicht gesehen… wir halten Ihnen einen Platz frei!', message_de: 'Hallo {prenom},\n\nwir haben Sie schon eine Weile nicht im Le 76 gesehen! Zu Ihrer Rückkehr schenken wir Ihnen das Dessert zum Essen.\n\nBis bald,\nGreg, Vanessa und das ganze Team' },
  { nom: 'Merci aux habitués', titre: 'Merci aux habitués', cible: 'habitues', jours: [2, 3, 4, 5, 7], services: ['midi', 'soir'],
    objet: 'Merci d’être un fidèle du 76', message: 'Bonjour {prenom},\n\nMerci de venir si souvent ! Pour vous remercier, l’apéritif maison vous est offert lors de votre prochaine visite.\n\nÀ bientôt au 76 !',
    objet_de: 'Danke für Ihre Treue', message_de: 'Hallo {prenom},\n\ndanke, dass Sie so oft kommen! Als Dankeschön ist der Aperitif des Hauses bei Ihrem nächsten Besuch gratis.\n\nBis bald im Le 76!' },
];

function editeur(o) {
  const { api, toast, sheet } = X();
  const j0 = auj(), j14 = (() => { const x = new Date(); x.setDate(x.getDate() + 14); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`; })();
  const v = o || { titre: '', objet: '', message: 'Bonjour {prenom},\n\n', objet_de: '', message_de: '', debut: j0, fin: j14, jours: '1234567', services: 'midi,soir', cible: 'tous' };
  let jours = new Set(String(v.jours).split('').map(Number)), services = new Set(String(v.services).split(','));
  document.querySelector('.veil')?.remove();
  sheet(`<h2>${o ? 'Modifier l’offre' : 'Nouvelle offre'}</h2>
    ${o ? '' : `<div class="cl-modeles">${MODELES.map((m, i) => `<button type="button" data-mod="${i}">✨ ${esc(m.nom)}</button>`).join('')}</div>`}
    <form id="clOf">
      <label class="field">Nom de l’offre <small>(pour toi, les clients ne le voient pas)</small><input name="titre" value="${esc(v.titre)}" maxlength="80" required></label>
      <label class="field">Objet de l’e-mail<input name="objet" value="${esc(v.objet)}" maxlength="120" required></label>
      <label class="field">Message <small>· {prenom} est remplacé par le prénom du client</small><textarea name="message" rows="7" maxlength="2000" required>${esc(v.message)}</textarea></label>
      <details ${v.objet_de ? 'open' : ''} style="margin-bottom:16px"><summary style="cursor:pointer;color:var(--rust);font-weight:500">🇩🇪 Version allemande (pour les clients allemands)</summary>
        <label class="field" style="margin-top:12px">Betreff<input name="objet_de" value="${esc(v.objet_de)}" maxlength="120"></label>
        <label class="field">Nachricht <small>· {prenom} = Vorname</small><textarea name="message_de" rows="6" maxlength="2000">${esc(v.message_de)}</textarea></label></details>
      <div class="cols"><label class="field">Valable du<input type="date" name="debut" value="${esc(v.debut)}" required></label><label class="field">au<input type="date" name="fin" value="${esc(v.fin)}" required></label></div>
      <div class="field">Jours où l’offre est valable <small>(pour remplir les services calmes)</small><div class="cl-jours" id="clJours">${JOURS.map(([n, l]) => `<button type="button" data-j="${n}" class="${jours.has(n) ? 'on' : ''}">${l}</button>`).join('')}</div></div>
      <div class="field">Services<div class="cl-jours" id="clServ"><button type="button" data-sv="midi" class="${services.has('midi') ? 'on' : ''}">Midi</button><button type="button" data-sv="soir" class="${services.has('soir') ? 'on' : ''}">Soir</button></div></div>
      <div class="field">À qui ?<div class="seg" id="clCible">
        <button type="button" data-c="tous" class="${v.cible === 'tous' ? 'on' : ''}">Tous</button>
        <button type="button" data-c="habitues" class="${v.cible === 'habitues' ? 'on' : ''}">Habitués</button>
        <button type="button" data-c="absents" class="${v.cible === 'absents' ? 'on' : ''}">À retrouver</button></div>
        <small style="display:block;margin-top:6px">Toujours parmi les clients qui ont accepté les offres.</small></div>
      <button class="btn block">Enregistrer et voir l’aperçu</button>
      ${o ? '<button type="button" class="btn ghost block" id="clSuppr" style="margin-top:10px">Supprimer ce brouillon</button>' : ''}
    </form>`, (el, fermer) => {
    let cible = v.cible;
    const f = el.querySelector('#clOf');
    el.querySelector('#clJours').onclick = e => { const b = e.target.closest('[data-j]'); if (!b) return; const n = +b.dataset.j; jours.has(n) ? jours.delete(n) : jours.add(n); b.classList.toggle('on', jours.has(n)); };
    el.querySelector('#clServ').onclick = e => { const b = e.target.closest('[data-sv]'); if (!b) return; const s = b.dataset.sv; services.has(s) ? services.delete(s) : services.add(s); b.classList.toggle('on', services.has(s)); };
    el.querySelector('#clCible').onclick = e => { const b = e.target.closest('[data-c]'); if (!b) return; cible = b.dataset.c; el.querySelectorAll('#clCible button').forEach(x => x.classList.toggle('on', x === b)); };
    el.querySelectorAll('[data-mod]').forEach(b => b.onclick = () => {
      const m = MODELES[+b.dataset.mod];
      for (const k of ['titre', 'objet', 'message', 'objet_de', 'message_de']) f[k].value = m[k];
      el.querySelector('details').open = true;
      jours = new Set(m.jours); services = new Set(m.services); cible = m.cible;
      el.querySelectorAll('#clJours button').forEach(x => x.classList.toggle('on', jours.has(+x.dataset.j)));
      el.querySelectorAll('#clServ button').forEach(x => x.classList.toggle('on', services.has(x.dataset.sv)));
      el.querySelectorAll('#clCible button').forEach(x => x.classList.toggle('on', x.dataset.c === cible));
      toast('Modèle chargé : adapte le texte à ton envie');
    });
    el.querySelector('#clSuppr')?.addEventListener('click', async () => {
      if (!confirm('Supprimer ce brouillon ?')) return;
      const r = await api('club_offre_supprimer', { id: o.id }); r.ok ? (fermer(), ecranOffres()) : toast(r.erreur, true);
    });
    f.onsubmit = async ev => {
      ev.preventDefault();
      const r = await api('club_offre_enregistrer', { id: o?.id, titre: f.titre.value, objet: f.objet.value, message: f.message.value, objet_de: f.objet_de.value, message_de: f.message_de.value,
        debut: f.debut.value, fin: f.fin.value, jours: [...jours], services: [...services], cible });
      r.ok ? apercu(r.offre.id) : toast(r.erreur, true);
    };
  });
}

/** Aperçu obligatoire avant toute validation : le message tel que reçu + la liste exacte des destinataires. */
async function apercu(id) {
  const { api, toast, sheet } = X();
  const d = await api('club_offre_apercu', { id }); if (!d.ok) return toast(d.erreur, true);
  const a = d.apercu, o = d.offre, reel = d.envoi_reel;
  document.querySelector('.veil')?.remove();
  sheet(`<h2>Aperçu avant ${reel ? 'envoi' : 'simulation'}</h2>
    <div class="cl-chiffres"><span class="chip ${a.nb ? 'ok' : 'off'}">${plur(a.nb, 'destinataire')}</span>${a.nb_de ? `<span class="chip">🇩🇪 ${a.nb_de} en allemand</span>` : ''}<span class="chip">${esc(o.cible_texte)}</span><span class="chip">${plur(a.sans_accord, 'client')} sans accord : exclu${a.sans_accord > 1 ? 's' : ''}</span></div>
    <div class="tip">${esc(a.conditions)}</div>
    ${a.nb_de && !a.de.traduit ? '<div class="warn">Pas de version allemande : les clients allemands recevront le texte français.</div>' : ''}
    <div class="seg" id="clLgA" style="margin-bottom:10px"><button type="button" class="on" data-l="fr">Version française</button><button type="button" data-l="de">Version allemande</button></div>
    <iframe class="cl-apercu" id="clIf" sandbox title="Aperçu de l’e-mail"></iframe>
    <details style="margin:14px 0 18px"><summary style="cursor:pointer;color:var(--rust);font-weight:500">Voir les ${plur(a.nb, 'destinataire')}</summary>
      <div class="group" style="margin-top:10px">${a.destinataires.map(x => `<div class="row"><div class="grow">${esc(x.prenom)}${x.langue === 'de' ? ' 🇩🇪' : ''}<small>${esc(x.email)} · ${plur(x.visites, 'visite')} · ${ilya(x.derniere)}</small></div></div>`).join('') || '<div class="row">Personne dans ce groupe.</div>'}</div></details>
    <div class="actions">
      <button class="btn ghost" id="clModif">Modifier</button>
      <button class="btn ghost" id="clTest">M’envoyer un essai</button>
      <button class="btn ${reel ? '' : 'dark'} full" id="clGo" ${a.nb ? '' : 'disabled'}>${reel ? `Envoyer à ${plur(a.nb, 'client')}…` : `Simuler l’envoi à ${plur(a.nb, 'client')}`}</button>
    </div>`, (el, fermer) => {
    const ifr = el.querySelector('#clIf');
    const montrer = l => { ifr.srcdoc = (l === 'de' ? a.de : a.fr).html; };
    montrer('fr');
    el.querySelector('#clLgA').onclick = e => { const b = e.target.closest('[data-l]'); if (!b) return; el.querySelectorAll('#clLgA button').forEach(x => x.classList.toggle('on', x === b)); montrer(b.dataset.l); };
    el.querySelector('#clModif').onclick = () => editeur(o);
    el.querySelector('#clTest').onclick = async () => {
      const r0 = await api('club_reglages');
      const adr = r0.ok && r0.reglages.adresse_test;
      if (!adr) return toast('Indique d’abord ton adresse de test dans Réglages du Club.', true);
      if (!confirm(`Envoyer un VRAI e-mail d’essai à ${adr} ?\n(Uniquement à toi, aucun client ne le reçoit.)`)) return;
      const r = await api('club_test', { id, langue: el.querySelector('#clLgA .on').dataset.l });
      r.ok ? toast(r.demo ? 'Démo : rien n’est réellement envoyé' : `Essai envoyé à ${r.adresse}`) : toast(r.erreur, true);
    };
    el.querySelector('#clGo').onclick = async () => {
      const texte = reel
        ? `Envoyer « ${o.objet} » à ${plur(a.nb, 'client')} ?\n\nLes e-mails partiront de reservation@le76.fr par petits lots. Ce n'est pas annulable.`
        : `Simuler l'envoi à ${plur(a.nb, 'client')} ?\n\nAucun e-mail ne part : la liste est seulement figée pour tester.`;
      if (!confirm(texte)) return;
      const r = await api('club_offre_valider', { id, attendu: a.nb });
      if (!r.ok) { toast(r.erreur, true); if (/changé/.test(r.erreur)) apercu(id); return; }
      fermer();
      toast(r.resultat.simulation ? `Simulation enregistrée : ${plur(r.resultat.nb, 'destinataire')}, aucun e-mail envoyé` : `C’est parti : ${plur(r.resultat.nb, 'e-mail')} en cours d’envoi`);
      detailOffre(id);
    };
  });
}

/** Suivi d'une offre validée : envois, erreurs, utilisations. Pendant un envoi réel, relance les lots. */
async function detailOffre(id) {
  const { api, toast, sheet } = X();
  const d = await api('club_offre_envois', { id }); if (!d.ok) return toast(d.erreur, true);
  const o = d.offre, e = d.envois;
  const [lib, cls] = STATUT_OFFRE[o.statut] || [o.statut, ''];
  const fait = e.filter(x => ['envoye', 'erreur', 'exclu'].includes(x.statut)).length;
  document.querySelector('.veil')?.remove();
  const STE = { envoye: ['envoyé', 'ok'], attente: ['en attente', 'att'], envoi: ['en cours', 'att'], erreur: ['erreur', 'err'], exclu: ['exclu', ''], simule: ['simulé', 'sim'] };
  sheet(`<h2>${esc(o.titre)} <span class="cl-tag ${cls}" style="vertical-align:6px">${lib}</span></h2>
    <p class="sub" style="margin:-8px 2px 14px">${esc(o.cible_texte || '')} · ${esc(o.conditions || '')}</p>
    ${o.statut === 'envoi' ? `<div class="tip"><b>Envoi en cours</b> · ${fait}/${e.length}<div class="cl-prog-envoi"><i style="width:${Math.round(100 * fait / Math.max(1, e.length))}%"></i></div>Tu peux fermer : l’envoi continue tout seul tant que l’appli tourne sur un iPad.</div>` : ''}
    ${o.simulation ? '<div class="tip">🧪 Simulation : aucun e-mail n’est parti. L’équipe peut tester l’utilisation de l’offre depuis la fiche des clients.</div>' : ''}
    <div class="cl-chiffres"><span class="chip">${plur(e.length, 'destinataire')}</span><span class="chip ok">${plur(o.utilises || e.filter(x => x.utilise_le).length, 'utilisation')}</span>${o.erreurs ? `<span class="chip off">${o.erreurs} en erreur</span>` : ''}</div>
    <div class="group">${e.map(x => { const [t, c] = STE[x.statut] || [x.statut, '']; return `<div class="row"><div class="grow">${esc(x.prenom)}<small>${esc(x.email)}${x.erreur ? ' · ' + esc(x.erreur) : ''}${x.utilise_le ? ` · utilisée le ${jourCourt(x.utilise_le)}` : ''}</small></div><span class="cl-tag ${x.utilise_le ? 'ok' : c}">${x.utilise_le ? 'utilisée' : t}</span></div>`; }).join('')}</div>
    <div class="actions">
      ${o.erreurs ? '<button class="btn ghost full" id="clRel">Relancer les envois en erreur</button>' : ''}
      <button class="btn ghost" id="clDup">Dupliquer</button><button class="btn ghost" id="clRet">Retour</button>
    </div>`, (el, fermer) => {
    el.querySelector('#clRet').onclick = () => ecranOffres();
    el.querySelector('#clDup').onclick = async () => { const r = await api('club_offre_dupliquer', { id }); r.ok ? editeur(r.offre) : toast(r.erreur, true); };
    el.querySelector('#clRel')?.addEventListener('click', async () => {
      if (!confirm('Relancer les envois en erreur ? (3 essais maximum par client)')) return;
      const r = await api('club_relancer', { id }); r.ok ? (toast(`${plur(r.relances, 'envoi')} relancé${r.relances > 1 ? 's' : ''}`), detailOffre(id)) : toast(r.erreur, true);
    });
    // Pendant l'envoi : un lot toutes les 6 secondes tant que la fenêtre est ouverte
    if (o.statut === 'envoi') {
      clearTimeout(C.minuterie);
      C.minuterie = setTimeout(async () => { if (!document.body.contains(el)) return; await api('club_envoyer'); if (document.body.contains(el)) detailOffre(id); }, 6000);
    }
  });
}

/* ---------- Affiche QR pour les tables ---------- */
function affiche() {
  const url = location.origin + C.base + '/club/?t=1';
  const seuil = C.reglages.seuil || 6, cadeau = C.reglages.cadeau || 'Un dessert offert';
  const w = window.open('', '_blank');
  if (!w) return X().toast('Autorise les fenêtres pour imprimer l’affiche.', true);
  const bloc = `<div class="a"><div class="logo">Le <span>76</span></div><div class="k">Le Club · Der Club</div>
    <h1>Vos visites comptent</h1><h2>Ihre Besuche zählen</h2><div class="qr">${qrSvg(url, { border: 2 })}</div>
    <p class="p"><b>${seuil} visites = ${esc(cadeau.toLowerCase())}</b><br>Scannez avec l’appareil photo · Mit der Kamera scannen</p>
    <p class="s">Gratuit, sans application · Kostenlos, ohne App</p></div>`;
  w.document.write(`<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Affiche Club · Le 76</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,800;1,500&family=Jost:wght@400;600&family=Caveat:wght@700&display=swap" rel="stylesheet">
    <style>@page{size:A4;margin:0}*{box-sizing:border-box}body{margin:0;font-family:Jost,system-ui,sans-serif;color:#2b1d16;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .page{width:210mm;height:297mm;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr}
    .a{border:.3mm dashed #d8c6ad;padding:10mm 9mm;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#fbf5ec}
    .logo{font:800 26px/1 'Playfair Display',Georgia,serif}.logo span{font:700 34px/1 Caveat,cursive;color:#c98a3a}
    .k{font-weight:600;font-size:10px;letter-spacing:.3em;text-transform:uppercase;color:#c98a3a;margin:6px 0 8px}
    h1{font:italic 500 22px/1.1 'Playfair Display',Georgia,serif;color:#b7372a;margin:0}h2{font:500 14px/1.2 Jost,sans-serif;color:#7a6a5c;margin:3px 0 8px}
    .qr{width:46mm;background:#fff;border-radius:4mm;padding:2mm}.qr svg{width:100%;display:block}
    .p{font-size:11.5px;line-height:1.4;margin:8px 0 2px}.p b{font-size:14px}.s{font-size:9.5px;color:#7a6a5c;margin:2px 0 0}
    .bar{position:fixed;top:10px;right:10px}@media print{.bar{display:none}}</style></head>
    <body><div class="bar"><button onclick="print()" style="font:600 15px system-ui;padding:10px 18px;border-radius:10px;border:0;background:#b7372a;color:#fff">Imprimer</button></div>
    <div class="page">${bloc.repeat(4)}</div></body></html>`);
  w.document.close();
}

/* ---------- Réglages, journal ---------- */
async function ecranReglages() {
  const { api, toast, sheet, sw } = X();
  const d = await api('club_reglages'); if (!d.ok) return toast(d.erreur, true);
  const r = d.reglages;
  sheet(`<h2>Réglages du Club</h2><form id="clReg">
    <div class="group"><h3>Fidélité</h3>
      <div class="row"><div class="grow">Visites pour un cadeau</div><input type="number" name="seuil" min="2" max="30" value="${r.seuil}"></div>
      <div class="row" style="display:block"><label class="field" style="margin:0">Le cadeau<input name="cadeau" value="${esc(r.cadeau)}" maxlength="80"></label></div>
      <div class="row" style="display:block"><label class="field" style="margin:0">Auf Deutsch<input name="cadeau_de" value="${esc(r.cadeau_de)}" maxlength="80"></label></div>
      <div class="row"><div class="grow">Inscriptions ouvertes<small>Page le76.fr/club et QR des tables</small></div>${sw('inscriptions', r.inscriptions)}</div>
    </div>
    <div class="group"><h3>Groupes de clients</h3>
      <div class="row"><div class="grow">« Habitués » à partir de<small>visites</small></div><input type="number" name="habitues" min="2" max="50" value="${r.habitues}"></div>
      <div class="row"><div class="grow">« À retrouver » : pas venus depuis<small>jours</small></div><input type="number" name="absents" min="7" max="365" value="${r.absents}"></div>
    </div>
    <div class="group"><h3>E-mails</h3>
      <div class="row"><div class="grow"><b>Envoi réel des e-mails</b><small>${d.demo ? 'Impossible dans la démo.' : 'Coupé : tout est simulé, rien ne part. Activé : les offres validées et l’e-mail de bienvenue partent vraiment.'}</small></div>${sw('envoi_reel', r.envoi_reel, d.demo ? 'disabled' : '')}</div>
      <div class="row"><div class="grow">E-mail de bienvenue<small>Avec le lien de la carte, à l’inscription</small></div>${sw('bienvenue', r.bienvenue)}</div>
      <div class="row"><div class="grow">Maximum par heure<small>Pour rester sous la limite de la messagerie Hostinger</small></div><input type="number" name="par_heure" min="5" max="300" value="${r.par_heure}"></div>
      <div class="row" style="display:block"><label class="field" style="margin:0">Ton adresse pour les essais<input name="adresse_test" type="email" value="${esc(r.adresse_test)}" placeholder="greg@…" autocapitalize="none"></label></div>
      <div class="row"><div class="grow">Messagerie<small>${d.smtp && d.smtp.actif ? 'Hostinger (SMTP) · ' + esc(d.smtp.utilisateur || '') : 'Envoi par l’hébergement (réglable dans Plus → Réglages des réservations)'}</small></div></div>
    </div>
    ${d.sauvegardes.length ? `<div class="group"><h3>Sauvegardes automatiques</h3><div class="row"><div class="grow">${d.sauvegardes.length} jour${d.sauvegardes.length > 1 ? 's' : ''} gardé${d.sauvegardes.length > 1 ? 's' : ''}<small>Copie complète chaque jour (réservations + Club), hors du site, 30 jours. Dernière : ${jourCourt(d.sauvegardes[0].jour)}</small></div></div></div>` : ''}
    <button class="btn block">Enregistrer</button></form>`, (el, fermer) => {
    el.querySelector('[name=envoi_reel]').onchange = e => {
      if (e.target.checked && !confirm('Activer l’envoi réel ?\n\nLes offres que tu valideras partiront vraiment par e-mail, et chaque nouvel inscrit recevra son e-mail de bienvenue.')) e.target.checked = false;
    };
    el.querySelector('#clReg').onsubmit = async ev => {
      ev.preventDefault(); const f = ev.target;
      const x = await api('club_reglages_ecrire', { reglages: { seuil: f.seuil.value, cadeau: f.cadeau.value, cadeau_de: f.cadeau_de.value, inscriptions: f.inscriptions.checked,
        habitues: f.habitues.value, absents: f.absents.value, envoi_reel: f.envoi_reel.checked, bienvenue: f.bienvenue.checked, par_heure: f.par_heure.value, adresse_test: f.adresse_test.value } });
      x.ok ? (fermer(), toast('Réglages enregistrés'), vue(true)) : toast(x.erreur, true);
    };
  });
}

async function ecranJournal() {
  const { api, toast, sheet } = X();
  const d = await api('club_journal'); if (!d.ok) return toast(d.erreur, true);
  const NOMS = { inscription: 'Inscription', visite: 'Visite', visite_annulee: 'Visite annulée', cadeau: 'Cadeau offert', offre_utilisee: 'Offre utilisée', accord_offres: 'Accepte les offres',
    retrait_offres: 'Refuse les offres', modification: 'Fiche modifiée', suppression: 'Client supprimé', campagne_simulee: 'Campagne simulée', campagne_validee: 'Campagne envoyée', relance: 'Relance', essai: 'E-mail d’essai', reglage: 'Réglage', purge: 'Effacement automatique (3 ans)' };
  sheet(`<h2>Journal du Club</h2><div class="group cl-journal">${d.journal.map(j => `<div class="row"><div class="grow"><b style="font-weight:600">${NOMS[j.action] || esc(j.action)}</b>${j.prenom ? ' · ' + esc(j.prenom) : ''}<small>${esc(j.detail)}${j.qui ? ' · ' + esc(j.qui) : ''}</small></div><small style="color:var(--muted);white-space:nowrap">${jourCourt(j.quand)} ${j.quand.slice(11, 16)}</small></div>`).join('') || '<div class="row">Rien pour l’instant.</div>'}</div>
    <button class="btn ghost block" data-fermer>Fermer</button>`);
}

window.LE76club = { vue, nouveau, fiche: c => fiche(c) };
})();
