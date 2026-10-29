'use strict';
/* Précommande du camping — espace équipe. */

const $ = id => document.getElementById(id);
let VIEW = 'day', DAY = todayStr(), PRODUCTS = [], SETTINGS = null, OF = { from: '', to: '', status: '', q: '' }, busy = false;
const PAY = { especes: 'espèces', carte: 'carte', en_ligne: 'carte en ligne' };
const ST = { pending: ['À payer', 'p-warn'], confirmed: ['Payée · à remettre', 'p-ok'], collected: ['Retirée', 'p-blue'], cancelled: ['Annulée', 'p-red'] };
const WEEK = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

function todayStr() { return new Date().toLocaleDateString('sv-SE'); }
function addDays(d, n) { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toLocaleDateString('sv-SE'); }
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function eur(c) { return (c / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }); }
function dfr(d, o = { weekday: 'long', day: 'numeric', month: 'long' }) { return new Date(d + 'T12:00:00').toLocaleDateString('fr-FR', o); }
function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
function uid() { return crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2, 12); }
function parseCents(v) { const s = String(v).trim().replace(/\s|€/g, '').replace(',', '.'); if (!/^\d{1,4}(\.\d{1,2})?$/.test(s)) throw new Error('Montant invalide (ex. 1,90).'); const [i, d = ''] = s.split('.'); return +i * 100 + +(d + '00').slice(0, 2); }
function inCents(c) { return (c / 100).toFixed(2).replace('.', ','); }
function toast(m, err = false) { const e = $('toast'); e.textContent = m; e.className = 'show' + (err ? ' err' : ''); clearTimeout(toast.t); toast.t = setTimeout(() => e.className = '', err ? 5500 : 2800); }

async function api(action, body = {}) {
  let r;
  try { r = await fetch('../api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action, ...body }) }); }
  catch { throw new Error('Connexion impossible. Vérifie le réseau.'); }
  let j = {}; try { j = await r.json(); } catch { throw new Error('Réponse inattendue du serveur.'); }
  if (r.status === 401 && !['connexion', 'code', 'connexion_lien'].includes(action)) { showLogin(); throw new Error(j.error || 'Connecte-toi.'); }
  if (!r.ok || !j.ok) throw Object.assign(new Error(j.error || 'Erreur.'), { status: r.status });
  return j;
}

/* ================= Connexion ================= */
let INSTALLED = true;
async function start() {
  // Arrivée par le QR code « Connecter un autre appareil »
  const lien = new URLSearchParams(location.search).get('lien');
  if (lien) {
    history.replaceState(null, '', location.pathname);
    try { await api('connexion_lien', { lien }); } catch (e) { INSTALLED = true; return showLogin(e.message); }
  }
  try {
    const e = await api('etat');
    INSTALLED = e.installe;
    if (!e.connecte) return showLogin();
  } catch (e) { return showLogin(e.message); }
  $('login').hidden = true; $('app').hidden = false;
  await loadBase(); render(); relayStart();
}
function showLogin(msg) {
  $('app').hidden = true; $('login').hidden = false;
  $('code2').hidden = INSTALLED;
  $('loginHelp').textContent = INSTALLED ? 'Précommandes de pain du camping' : 'Première ouverture : choisis le code de l’équipe (4 à 8 chiffres).';
  $('loginBtn').textContent = INSTALLED ? 'Entrer' : 'Choisir ce code';
  $('loginErr').textContent = msg || '';
  setTimeout(() => $('code').focus(), 50);
}
$('loginForm').onsubmit = async ev => {
  ev.preventDefault();
  const code = $('code').value.trim();
  try {
    if (!INSTALLED) { if (code !== $('code2').value.trim()) throw new Error('Les deux codes ne sont pas identiques.'); await api('installer', { code }); }
    else await api('connexion', { code });
    $('code').value = $('code2').value = '';
    start();
  } catch (e) { $('loginErr').textContent = e.message; }
};
async function loadBase() {
  try { PRODUCTS = (await api('produits')).products; SETTINGS = (await api('reglages')).settings; } catch (e) { toast(e.message, true); }
}

/* ================= Navigation ================= */
document.querySelectorAll('#tabs button').forEach(b => b.onclick = () => { VIEW = b.dataset.v; render(); window.scrollTo(0, 0); });
function render() {
  document.querySelectorAll('#tabs button').forEach(b => b.classList.toggle('on', b.dataset.v === VIEW));
  ({ day: renderDay, orders: renderOrders, products: renderProducts, stats: renderStats, settings: renderSettings, log: renderLog })[VIEW]();
}

/* ================= Aujourd'hui ================= */
let dayTimer = null;
async function renderDay() {
  clearInterval(dayTimer);
  let j;
  try { j = await api('jour', { date: DAY }); } catch (e) { $('view').innerHTML = `<div class="empty">${esc(e.message)}</div>`; return; }
  const isToday = DAY === todayStr(), s = j.summary;
  const toPay = j.orders.filter(o => o.status === 'pending'), waiting = j.orders.filter(o => o.status === 'confirmed'), others = j.orders.filter(o => ['collected', 'cancelled'].includes(o.status));
  $('view').innerHTML = `
    <div class="top" style="flex-wrap:wrap">
      <div><h1>${isToday ? 'Aujourd’hui' : cap(dfr(DAY))}</h1><div class="sub">${cap(dfr(DAY, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}${j.open ? '' : ' · <span class="pill p-gray">fermé</span>'} · commandes clients ${j.orderable ? 'ouvertes jusqu’au ' + dfr(j.limit.slice(0, 10), { day: 'numeric', month: 'short' }) + ' à ' + j.limit.slice(11) : 'closes'}</div></div>
      <div class="row-btns no-print">
        <button class="btn-plain btn-sm" data-d="-1">‹</button><input type="date" id="dPick" value="${DAY}" style="width:auto;min-height:36px;padding:4px 10px"><button class="btn-plain btn-sm" data-d="1">›</button>
        ${isToday ? '' : '<button class="btn-plain btn-sm" data-d="0">Aujourd’hui</button>'}
        <button class="btn-soft btn-sm" id="dNew">+ Commande</button>
      </div>
    </div>
    <div class="row-btns no-print" style="margin:4px 0 6px">
      <button class="btn-plain btn-sm" id="dSheet">🧾 Feuille de vente · ${isToday ? 'aujourd’hui' : dfr(DAY, { day: 'numeric', month: 'short' })}</button>
      <button class="btn-soft btn-sm" id="dBaker">🥖 Commande boulanger · ${isToday ? 'demain' : dfr(DAY, { day: 'numeric', month: 'short' })}</button>
      </div>
    </div>
    <div class="group-title">À préparer · ${dfr(DAY, { day: 'numeric', month: 'long' })}</div>
    <div class="prep">${j.prep.length ? j.prep.map(p => `<div class="c"><div style="font-size:26px">${esc(p.emoji)}</div><div class="q">${p.total}</div><div>${esc(p.name)}</div><div class="r">${p.remaining} encore à remettre${p.pending ? ` · <b style="color:var(--orange)">+${p.pending} en attente de paiement</b>` : ''}</div></div>`).join('') : '<div class="c"><div class="mute">Aucune commande pour ce jour.</div></div>'}</div>
    <div class="stats" style="margin-top:10px">
      <div class="stat"><div class="k">Commandes validées</div><div class="v">${s.orders}</div></div>
      <div class="stat"><div class="k">À payer</div><div class="v" style="color:var(--orange)">${s.pending}</div><div class="small mute">${eur(s.pendingTotal)}</div></div>
      <div class="stat"><div class="k">Payées · à remettre</div><div class="v">${s.waiting}</div></div>
      <div class="stat"><div class="k">Retirées</div><div class="v">${s.collected}</div></div>
      <div class="stat"><div class="k">Chiffre du jour</div><div class="v">${eur(s.total)}</div></div>
      <div class="stat"><div class="k">Encaissé</div><div class="v">${eur(s.paid)}</div></div>
    </div>
    <div class="group-title no-print" style="display:flex;gap:10px;align-items:center;justify-content:space-between"><span>Payées · à remettre (${waiting.length})</span><input id="dSearch" type="search" placeholder="Nom, emplacement, téléphone ou code" style="max-width:280px;min-height:36px;padding:6px 10px;text-transform:none"></div>
    <div class="print-only"><h2>Commandes payées à remettre</h2></div>
    <div class="group orders dList">${waiting.length ? waiting.map(orderLi).join('') : '<div class="empty">Aucune commande payée en attente de retrait.</div>'}</div>
    <div class="group-title">À payer (${toPay.length}) · non validées, pas encore à préparer</div>
    <div class="group orders dList">${toPay.length ? toPay.map(orderLi).join('') : '<div class="empty">Aucune commande en attente de paiement.</div>'}</div>
    ${others.length ? `<div class="group-title">Retirées et annulées (${others.length})</div><div class="group orders">${others.map(orderLi).join('')}</div>` : ''}`;
  document.querySelectorAll('[data-d]').forEach(b => b.onclick = () => { DAY = +b.dataset.d === 0 ? todayStr() : addDays(DAY, +b.dataset.d); renderDay(); });
  $('dPick').onchange = e => { if (e.target.value) { DAY = e.target.value; renderDay(); } };
  $('dNew').onclick = () => orderSheet(null, DAY);
  $('dSheet').onclick = () => printSales(DAY);
  $('dBaker').onclick = () => bakerSheet(isToday ? addDays(DAY, 1) : DAY);
  $('dSearch').oninput = e => { const q = e.target.value.toLowerCase(); document.querySelectorAll('.dList .li').forEach(li => li.hidden = q && !li.dataset.s.includes(q)); };
  wireOrders(j.orders, renderDay);
  if (isToday) dayTimer = setInterval(() => { if (VIEW === 'day' && $('sheet').hidden && !document.activeElement?.matches('input')) renderDay(); }, 30000);
}
function orderLi(o) {
  const [lbl, cls] = ST[o.status];
  return `<div class="li ${o.status !== 'confirmed' ? 'done' : ''}" data-s="${esc((o.name + ' ' + o.pitch + ' ' + o.code + ' ' + o.phone + ' ' + o.phone.replace(/\D/g, '')).toLowerCase())}">
    <span class="main"><div class="t">${esc(o.name)} · empl. ${esc(o.pitch)} <span class="pill ${cls}">${lbl}</span> ${o.paid && o.paymentMethod ? `<span class="pill p-blue">${PAY[o.paymentMethod] || 'payée'}</span>` : ''}${o.status === 'confirmed' && !o.paid ? '<span class="pill p-warn">à payer au retrait</span>' : ''} ${o.origin === 'equipe' ? '<span class="pill p-gray">équipe</span>' : ''}${o.example ? '<span class="pill p-warn">exemple</span>' : ''}</div>
    <div class="d">${o.items.map(i => `${i.qty} × ${esc(i.name)}`).join(' · ')} — <b>${eur(o.total)}</b> · ${esc(o.code)}${o.phone ? ' · ' + esc(o.phone) : ''}${o.note ? ' · « ' + esc(o.note) + ' »' : ''}</div></span>
    <span class="acts no-print">
      ${o.status === 'pending' || (o.status === 'confirmed' && !o.paid) ? `<button class="btn-ok btn-sm" data-pay="${o.id}" data-m="especes">Espèces</button><button class="btn-ok btn-sm" data-pay="${o.id}" data-m="carte">Carte</button>` : ''}
      ${o.status === 'confirmed' && o.paid ? `<button class="btn-ok btn-sm" data-collect="${o.id}">Remise</button>` : ''}
      <button class="btn-plain btn-sm" data-open="${o.id}">…</button>
    </span></div>`;
}
function wireOrders(list, refresh) {
  document.querySelectorAll('[data-collect]').forEach(b => b.onclick = () => collect(+b.dataset.collect, '', refresh));
  document.querySelectorAll('[data-pay]').forEach(b => b.onclick = async () => {
    try { await api('encaisser', { id: +b.dataset.pay, method: b.dataset.m }); toast('Paiement encaissé (' + PAY[b.dataset.m] + ') : commande validée.'); refresh(); } catch (e) { toast(e.message, true); }
  });
  document.querySelectorAll('[data-open]').forEach(b => b.onclick = () => orderMenu(list.find(o => o.id === +b.dataset.open), refresh));
}
async function collect(id, method, refresh, force = false) {
  try { await api('retirer', { id, method, force }); toast('Remise enregistrée.'); refresh(); }
  catch (e) {
    if (e.status === 409 && /autre jour|était prévue/.test(e.message) && confirm(e.message + '\n\nRemettre quand même ?')) return collect(id, method, refresh, true);
    toast(e.message, true);
  }
}

/* ================= Fenêtres ================= */
function openSheet(title, html) { $('sheetTitle').textContent = title; $('sheetBody').innerHTML = html; $('sheet').hidden = $('sheetBack').hidden = false; }
function closeSheet() { $('sheet').hidden = $('sheetBack').hidden = true; $('sheetBody').innerHTML = ''; }
$('sheetClose').onclick = closeSheet; $('sheetBack').onclick = closeSheet;
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSheet(); });

function orderMenu(o, refresh) {
  const url = location.origin + location.pathname.replace(/equipe\/.*$/, '') + '?c=' + encodeURIComponent(o.token);
  openSheet('Commande ' + o.code, `
    <div class="group">
      <div class="row"><span class="mute">Client</span><span class="val">${esc(o.name)} · empl. ${esc(o.pitch)}</span></div>
      <div class="row"><span class="mute">Retrait</span><span class="val">${cap(dfr(o.date))}</span></div>
      <div class="row"><span class="mute">Détail</span><span class="val">${o.items.map(i => `${i.qty} × ${esc(i.name)}`).join(', ')}</span></div>
      <div class="row"><span class="mute">Total</span><span class="val"><b>${eur(o.total)}</b> <span class="small mute">(achat ${eur(o.cost)}, marge ${eur(o.total - o.cost)})</span></span></div>
      <div class="row"><span class="mute">Statut</span><span class="val"><span class="pill ${ST[o.status][1]}">${ST[o.status][0]}</span> ${o.paid ? `<span class="pill p-blue">payée · ${PAY[o.paymentMethod] || ''}</span>` : '<span class="pill p-warn">non payée</span>'}</span></div>
      ${o.phone ? `<div class="row"><span class="mute">Téléphone</span><span class="val"><a class="link" href="tel:${esc(o.phone)}">${esc(o.phone)}</a></span></div>` : ''}
      ${o.note ? `<div class="row"><span class="mute">Remarque</span><span class="val">${esc(o.note)}</span></div>` : ''}
      <div class="row"><span class="mute">Passée le</span><span class="val small">${new Date(o.createdAt.replace(' ', 'T')).toLocaleString('fr-FR')}${o.origin === 'equipe' ? ' · par l’équipe' : ''}</span></div>
      ${o.collectedAt ? `<div class="row"><span class="mute">Retirée le</span><span class="val small">${new Date(o.collectedAt.replace(' ', 'T')).toLocaleString('fr-FR')}</span></div>` : ''}
    </div>
    <div style="display:grid;gap:8px;margin-top:14px">
      ${!o.paid && o.status !== 'cancelled' && o.status !== 'collected' ? `<button class="btn-ok" data-a="pay-especes">Espèces encaissées · valider</button><button class="btn-ok" data-a="pay-carte">Carte encaissée · valider</button>` : ''}
      ${o.status === 'confirmed' && o.paid ? `<button class="btn-ok" data-a="collect">Remise au client</button>` : ''}
      ${o.status === 'collected' ? `<button class="btn-plain" data-a="undo">Annuler le retrait</button>` : ''}
      ${o.paid && o.status === 'confirmed' ? `<button class="btn-plain" data-a="unpay">Annuler le paiement (erreur d’encaissement)</button>` : ''}
      ${o.status !== 'cancelled' ? `<button class="btn-plain" data-a="edit">Modifier la commande</button><button class="btn-danger" data-a="cancel">Annuler la commande${o.paid ? ' (pense à rembourser)' : ''}</button>` : `<button class="btn-plain" data-a="restore">Rétablir la commande</button>`}
      <a class="btn-plain" style="display:grid;place-items:center;text-decoration:none;color:inherit" href="${esc(url)}" target="_blank" rel="noopener">Voir le bon du client</a>
      <button class="btn-plain" data-a="copy">Copier le lien du bon</button>
      ${o.phone ? `<a class="btn-plain" style="display:grid;place-items:center;text-decoration:none;color:inherit" href="${esc(smsLink(o, url))}">Renvoyer le bon par SMS</a>` : ''}
    </div>`);
  const act = async (action, body, msg) => { try { await api(action, { id: o.id, ...body }); closeSheet(); toast(msg); refresh(); } catch (e) { toast(e.message, true); } };
  $('sheetBody').querySelectorAll('[data-a]').forEach(b => b.onclick = async () => {
    const a = b.dataset.a;
    if (a === 'collect') { closeSheet(); collect(o.id, '', refresh); }
    if (a === 'pay-especes' || a === 'pay-carte') act('encaisser', { method: a.slice(4) }, 'Paiement encaissé : commande validée.');
    if (a === 'unpay') act('annuler_paiement', {}, 'Paiement annulé : la commande repasse « à payer ».');
    if (a === 'undo') act('annuler_retrait', {}, 'Retrait annulé.');
    if (a === 'restore') act('retablir', {}, 'Commande rétablie.');
    if (a === 'edit') orderSheet(o, o.date, refresh);
    if (a === 'copy') { try { await navigator.clipboard.writeText(url); toast('Lien copié.'); } catch { prompt('Lien du bon :', url); } }
    if (a === 'cancel') { const r = prompt('Motif de l’annulation (facultatif)', ''); if (r !== null) act('annuler_equipe', { reason: r }, 'Commande annulée.'); }
  });
}

function smsLink(o, url) {
  const txt = { fr: 'Votre bon de commande', de: 'Ihr Abholschein', en: 'Your voucher', nl: 'Uw bon' }[o.lang] || 'Votre bon de commande';
  const sep = /iPhone|iPad|Macintosh/.test(navigator.userAgent) ? '&' : '?';
  return `sms:${o.phone.replace(/[^\d+]/g, '')}${sep}body=${encodeURIComponent(`${txt} ${o.code} : ${url}`)}`;
}

/** Nouvelle commande (comptoir, téléphone) ou modification par l'équipe. */
function orderSheet(o, date, refresh = render) {
  const cart = o ? Object.fromEntries(o.items.map(i => [i.id, i.qty])) : {};
  const prods = PRODUCTS.filter(p => p.active || cart[p.id]);
  openSheet(o ? 'Modifier ' + o.code : 'Nouvelle commande', `
    <label class="field"><span>Jour de retrait</span><input type="date" id="oDate" value="${o ? o.date : date}" min="${todayStr()}"></label>
    <div class="group">${prods.map(p => `<div class="li"><span class="emoji">${esc(p.emoji)}</span><span class="main"><div class="t">${esc(p.name)}</div><div class="d">${eur(p.price)}</div></span><input type="number" min="0" max="${SETTINGS?.max_per_product || 50}" inputmode="numeric" data-q="${p.id}" value="${cart[p.id] || ''}" placeholder="0" style="width:80px;text-align:center"></div>`).join('')}</div>
    <div class="fields3" style="margin-top:12px"><label class="field"><span>Prénom</span><input id="oFirst" maxlength="40" value="${esc(o?.firstName || '')}"></label><label class="field"><span>Nom</span><input id="oName" maxlength="60" value="${esc(o?.lastName || '')}"></label><label class="field"><span>Emplacement</span><input id="oPitch" maxlength="30" value="${esc(o?.pitch || '')}"></label></div>
    <div class="fields2"><label class="field"><span>Téléphone</span><input id="oPhone" type="tel" maxlength="30" value="${esc(o?.phone || '')}"></label><label class="field"><span>Remarque</span><input id="oNote" maxlength="200" value="${esc(o?.note || '')}"></label></div>
    ${o ? '' : `<label class="field"><span>Paiement</span><select id="oPay"><option value="especes">Espèces encaissées</option><option value="carte">Carte encaissée</option><option value="later">À payer au retrait (validée par l’équipe)</option><option value="">En attente de paiement (comme un campeur)</option></select></label>`}
    <div class="total"><span>Total</span><b id="oTotal">${eur(0)}</b></div>
    <button class="btn-main" id="oSave">${o ? 'Enregistrer' : 'Créer la commande'}</button>`);
  const items = () => [...document.querySelectorAll('[data-q]')].map(i => ({ id: +i.dataset.q, qty: +(i.value || 0) })).filter(i => i.qty > 0);
  const upd = () => $('oTotal').textContent = eur(items().reduce((a, i) => a + i.qty * (o?.items.find(x => x.id === i.id)?.price ?? PRODUCTS.find(p => p.id === i.id).price), 0));
  document.querySelectorAll('[data-q]').forEach(i => i.oninput = upd); upd();
  const key = uid();
  $('oSave').onclick = async () => {
    if (busy) return; busy = true;
    try {
      const body = { date: $('oDate').value, firstName: $('oFirst').value, lastName: $('oName').value, pitch: $('oPitch').value, phone: $('oPhone').value, note: $('oNote').value, items: items() };
      if (o) await api('modifier_equipe', { id: o.id, ...body }); else await api('creer', { key, method: $('oPay').value, ...body });
      closeSheet(); toast(o ? 'Commande modifiée.' : 'Commande créée.'); if (!o) DAY = body.date; refresh();
    } catch (e) { toast(e.message, true); } finally { busy = false; }
  };
}

/* ================= Commandes ================= */
async function renderOrders() {
  if (!OF.from) { OF.from = addDays(todayStr(), -7); OF.to = addDays(todayStr(), 14); }
  let j; try { j = await api('commandes', OF.q.trim() ? { ...OF, from: '', to: '' } : OF); } catch (e) { $('view').innerHTML = `<div class="empty">${esc(e.message)}</div>`; return; }
  const total = j.orders.filter(o => ['confirmed', 'collected'].includes(o.status)).reduce((a, o) => a + o.total, 0);
  $('view').innerHTML = `
    <div class="top"><div><h1>Commandes</h1><div class="sub">${j.orders.length} commande(s) · ${eur(total)} validées (payées)</div></div>
      <div class="row-btns"><button class="btn-soft btn-sm" id="oNew">+ Commande</button><a class="btn-plain btn-sm" style="display:inline-flex;align-items:center;text-decoration:none;color:inherit" href="../api.php?action=export&from=${OF.from}&to=${OF.to}">Exporter (Excel / CSV)</a></div></div>
    <div class="group pad" style="margin-top:14px"><div class="filters">
      <input type="date" id="fFrom" value="${OF.from}"><input type="date" id="fTo" value="${OF.to}">
      <select id="fSt"><option value="">Tous statuts</option><option value="pending">À payer</option><option value="confirmed">Payées, à remettre</option><option value="collected">Retirées</option><option value="cancelled">Annulées</option></select>
      <input id="fQ" type="search" placeholder="Nom, emplacement, téléphone, code (toutes dates)" value="${esc(OF.q)}">
    </div></div>
    <div class="group orders" style="margin-top:12px">${j.orders.length ? j.orders.map(o => orderLi(o).replace('<span class="main">', `<span class="main"><div class="small mute">${cap(dfr(o.date, { weekday: 'short', day: 'numeric', month: 'short' }))}</div>`)).join('') : '<div class="empty">Aucune commande.</div>'}</div>`;
  $('fSt').value = OF.status;
  const f = (id, k, ev = 'change') => $(id).addEventListener(ev, e => { OF[k] = e.target.value; clearTimeout(f.t); f.t = setTimeout(renderOrders, ev === 'input' ? 350 : 0); });
  f('fFrom', 'from'); f('fTo', 'to'); f('fSt', 'status'); f('fQ', 'q', 'input');
  $('oNew').onclick = () => orderSheet(null, addDays(todayStr(), 1), renderOrders);
  wireOrders(j.orders, renderOrders);
}

/* ================= Produits ================= */
async function renderProducts() {
  try { PRODUCTS = (await api('produits')).products; } catch (e) { toast(e.message, true); }
  $('view').innerHTML = `
    <div class="top"><div><h1>Produits</h1><div class="sub">Prix de vente, prix d’achat, traductions, limites par jour</div></div><button class="btn-soft btn-sm" id="pNew">+ Produit</button></div>
    <div class="group" style="margin-top:14px">${PRODUCTS.map((p, i) => `
      <div class="li"><span class="emoji">${esc(p.emoji)}</span>
        <span class="main"><div class="t">${esc(p.name)} ${p.active ? '' : '<span class="pill p-gray">retiré de la vente</span>'}</div>
        <div class="d">Vente ${eur(p.price)} · achat ${eur(p.cost)} · marge ${eur(p.price - p.cost)} (${p.price ? Math.round((p.price - p.cost) / p.price * 100) : 0} %)${p.dailyLimit !== null ? ' · ' + p.dailyLimit + ' max/jour' : ''}</div></span>
        <span class="acts"><button class="btn-plain btn-sm" data-up="${i}" ${i ? '' : 'disabled'}>↑</button><button class="btn-plain btn-sm" data-edit="${p.id}">Modifier</button></span></div>`).join('')}</div>
    <p class="small mute" style="margin:10px 6px">Changer un prix ne modifie pas les commandes déjà passées : elles gardent le prix du jour de la commande. Un produit retiré de la vente n’est plus proposé aux campeurs.</p>`;
  $('pNew').onclick = () => productSheet(null);
  document.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => productSheet(PRODUCTS.find(p => p.id === +b.dataset.edit)));
  document.querySelectorAll('[data-up]').forEach(b => b.onclick = async () => {
    const ids = PRODUCTS.map(p => p.id), i = +b.dataset.up;[ids[i - 1], ids[i]] = [ids[i], ids[i - 1]];
    try { await api('ordre_produits', { ids }); renderProducts(); } catch (e) { toast(e.message, true); }
  });
}
function productSheet(p) {
  openSheet(p ? 'Modifier ' + p.name : 'Nouveau produit', `
    <div class="fields2"><label class="field"><span>Nom (français)</span><input id="pName" maxlength="50" value="${esc(p?.name || '')}"></label><label class="field"><span>Icône (emoji)</span><input id="pEmoji" maxlength="8" value="${esc(p?.emoji || '🥖')}"></label></div>
    <div class="fields3"><label class="field"><span>Allemand</span><input id="pDe" maxlength="50" value="${esc(p?.names?.de || '')}"></label><label class="field"><span>Anglais</span><input id="pEn" maxlength="50" value="${esc(p?.names?.en || '')}"></label><label class="field"><span>Néerlandais</span><input id="pNl" maxlength="50" value="${esc(p?.names?.nl || '')}"></label></div>
    <div class="fields3"><label class="field"><span>Prix de vente (€)</span><input id="pPrice" inputmode="decimal" value="${p ? inCents(p.price) : ''}"></label><label class="field"><span>Prix d’achat (€)</span><input id="pCost" inputmode="decimal" value="${p ? inCents(p.cost) : ''}"></label><label class="field"><span>Maximum par jour</span><input id="pLimit" inputmode="numeric" placeholder="illimité" value="${p?.dailyLimit ?? ''}"></label></div>
    <label class="field" style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="pActive" ${!p || p.active ? 'checked' : ''}> En vente</label>
    <div class="total"><span>Marge par pièce</span><b id="pMargin"></b></div>
    <button class="btn-main" id="pSave">Enregistrer</button>`);
  const m = () => { try { const a = parseCents($('pPrice').value), b = parseCents($('pCost').value || '0'); $('pMargin').textContent = eur(a - b) + (a ? ` (${Math.round((a - b) / a * 100)} %)` : ''); } catch { $('pMargin').textContent = '—'; } };
  $('pPrice').oninput = $('pCost').oninput = m; m();
  $('pSave').onclick = async () => {
    try {
      const lim = $('pLimit').value.trim();
      if (lim && !/^\d{1,5}$/.test(lim)) throw new Error('Maximum par jour : un nombre entier, ou vide pour illimité.');
      const product = { id: p?.id, name: $('pName').value, emoji: $('pEmoji').value, names: { de: $('pDe').value, en: $('pEn').value, nl: $('pNl').value }, price: parseCents($('pPrice').value), cost: parseCents($('pCost').value || '0'), dailyLimit: lim === '' ? null : +lim, active: $('pActive').checked };
      PRODUCTS = (await api('produit', { product })).products; closeSheet(); toast('Produit enregistré.'); renderProducts();
    } catch (e) { toast(e.message, true); }
  };
}

/* ================= Chiffres ================= */
let SF = null;
async function renderStats() {
  SF ??= { from: todayStr().slice(0, 8) + '01', to: todayStr() };
  let j; try { j = await api('chiffres', SF); } catch (e) { $('view').innerHTML = `<div class="empty">${esc(e.message)}</div>`; return; }
  const sum = k => j.days.reduce((a, d) => a + d[k], 0), tot = sum('total'), cost = sum('cost');
  const maxDay = Math.max(1, ...j.days.map(d => d.total));
  $('view').innerHTML = `
    <div class="top"><div><h1>Chiffres</h1><div class="sub">Commandes validées (payées) uniquement</div></div>
      <div class="row-btns"><input type="date" id="sFrom" value="${SF.from}" style="width:auto;min-height:36px"><input type="date" id="sTo" value="${SF.to}" style="width:auto;min-height:36px"></div></div>
    <div class="stats" style="margin-top:14px">
      <div class="stat"><div class="k">Commandes</div><div class="v">${sum('orders')}</div></div>
      <div class="stat"><div class="k">Chiffre d’affaires</div><div class="v">${eur(tot)}</div></div>
      <div class="stat"><div class="k">Coût d’achat</div><div class="v">${eur(cost)}</div></div>
      <div class="stat"><div class="k">Marge</div><div class="v pos">${eur(tot - cost)}</div></div>
      <div class="stat"><div class="k">Encaissé</div><div class="v">${eur(sum('paid'))}</div></div>
      <div class="stat"><div class="k">Non retirées</div><div class="v ${sum('noshow') ? 'neg' : ''}">${sum('noshow')}</div></div>
    </div>
    <div class="cols2">
      <div><div class="group-title">Par produit</div><div class="group"><table class="t"><thead><tr><th>Produit</th><th class="r">Qté</th><th class="r">Ventes</th><th class="r">Marge</th></tr></thead>
        <tbody>${j.products.length ? j.products.map(p => `<tr><td>${esc(p.emoji)} ${esc(p.name)}</td><td class="r num">${p.qty}</td><td class="r num">${eur(p.total)}</td><td class="r num pos">${eur(p.total - p.cost)}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">Aucune vente.</td></tr>'}</tbody></table></div></div>
      <div><div class="group-title">Par jour</div><div class="group"><table class="t"><thead><tr><th>Jour</th><th class="r">Cmd</th><th class="r">Ventes</th><th style="width:30%"></th></tr></thead>
        <tbody>${j.days.length ? j.days.map(d => `<tr><td>${cap(dfr(d.date, { weekday: 'short', day: 'numeric', month: 'short' }))}</td><td class="r num">${d.orders}${d.noshow ? ` <span class="pill p-red">${d.noshow} non retirée(s)</span>` : ''}</td><td class="r num">${eur(d.total)}</td><td><div style="height:8px;border-radius:4px;background:var(--accent);width:${Math.round(d.total / maxDay * 100)}%"></div></td></tr>`).join('') : '<tr><td colspan="4" class="empty">Aucune commande.</td></tr>'}</tbody></table></div></div>
    </div>`;
  $('sFrom').onchange = e => { SF.from = e.target.value; renderStats(); };
  $('sTo').onchange = e => { SF.to = e.target.value; renderStats(); };
}

/* ================= Réglages ================= */
async function renderSettings() {
  let j; try { j = await api('reglages'); SETTINGS = j.settings; } catch (e) { toast(e.message, true); return; }
  const s = SETTINGS, publicUrl = location.origin + location.pathname.replace(/equipe\/.*$/, '');
  $('view').innerHTML = `
    <div class="top"><div><h1>Réglages</h1><div class="sub">Horaires, heure limite, jours d’ouverture, fermetures</div></div></div>
    <div class="cols2">
      <div>
        <div class="group-title">Commandes en ligne</div>
        <div class="group pad">
          <label class="field" style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="sOpen" ${s.ordering_open ? 'checked' : ''}> Les campeurs peuvent commander</label>
          <label class="field"><span>Titre de la page</span><input id="sTitle" maxlength="60" value="${esc(s.title)}"></label>
          <label class="field"><span>Lieu de retrait</span><input id="sPlace" maxlength="80" value="${esc(s.place)}"></label>
          <label class="field"><span>Message affiché aux campeurs</span><textarea id="sMsg" maxlength="400">${esc(s.message)}</textarea></label>
        </div>
        <div class="group-title">Retrait</div>
        <div class="group pad">
          <div class="fields2"><label class="field"><span>De</span><input type="time" id="sFrom" value="${s.pickup_from}"></label><label class="field"><span>À</span><input type="time" id="sTo" value="${s.pickup_to}"></label></div>
          <div class="field"><span class="small mute">Jours d’ouverture</span><div class="chips" style="margin-top:6px">${WEEK.map((w, i) => `<button type="button" class="chip ${s.open_days.includes(i + 1) ? 'on' : ''}" data-wd="${i + 1}">${w}</button>`).join('')}</div></div>
        </div>
        <div class="group-title">Heure limite pour commander</div>
        <div class="group pad">
          <div class="fields2"><label class="field"><span>Jusqu’à</span><select id="sCd">${[0, 1, 2, 3].map(n => `<option value="${n}" ${s.cutoff_days === n ? 'selected' : ''}>${n === 0 ? 'le jour même' : n === 1 ? 'la veille' : n + ' jours avant'}</option>`).join('')}</select></label>
          <label class="field"><span>à</span><input type="time" id="sCt" value="${s.cutoff_time}"></label></div>
          <div class="fields2"><label class="field"><span>Commandes jusqu’à (jours à l’avance)</span><input id="sAhead" inputmode="numeric" value="${s.days_ahead}"></label>
          <label class="field"><span>Maximum par produit et par commande</span><input id="sMax" inputmode="numeric" value="${s.max_per_product}"></label></div>
        </div>
        <div class="group-title">Imprimante des bons</div>
        <div class="group pad" id="prBox"><p class="small mute">Chargement…</p></div>
      </div>
      <div>
        <div class="group-title">Saison</div>
        <div class="group pad">
          <div class="fields2"><label class="field"><span>Début (vide = toujours)</span><input type="date" id="sSs" value="${s.season_start}"></label><label class="field"><span>Fin</span><input type="date" id="sSe" value="${s.season_end}"></label></div>
        </div>
        <div class="group-title">Fermetures exceptionnelles</div>
        <div class="group pad">
          <div class="chips" id="sClosed">${s.closed_dates.map(d => `<button type="button" class="chip" data-del="${d}">${dfr(d, { weekday: 'short', day: 'numeric', month: 'short' })} ✕</button>`).join('') || '<span class="mute small">Aucune</span>'}</div>
          <div class="row-btns" style="margin-top:10px"><input type="date" id="sAddDate" style="width:auto"><button class="btn-plain btn-sm" id="sAdd">Ajouter</button></div>
        </div>
        <div class="group-title">Prochains jours ouverts aux campeurs</div>
        <div class="group pad small">${j.dates.length ? j.dates.map(d => `${cap(dfr(d.date, { weekday: 'long', day: 'numeric', month: 'short' }))} <span class="mute">(jusqu’au ${dfr(d.limit.slice(0, 10), { day: 'numeric', month: 'short' })} ${d.limit.slice(11)})</span>`).join('<br>') : '<span class="mute">Aucun pour le moment.</span>'}</div>
        <div class="group-title">Affiche pour l’accueil</div>
        <div class="group pad"><p class="small mute">Une affiche A4 avec le QR code de la page de commande, à imprimer pour l’accueil, le snack ou les sanitaires.</p><button class="btn-plain btn-sm" id="sPoster">Ouvrir l’affiche</button> <a class="link small" href="${esc(publicUrl)}" target="_blank" rel="noopener">${esc(publicUrl.replace(/^https?:\/\//, ''))}</a></div>
        <div class="group-title">Paiement par carte en ligne (Stripe)</div>
        <div class="group pad" id="cardBox"><p class="small mute">Chargement…</p></div>
        <div class="group-title">Ventes d’exemple</div>
        <div class="group pad"><p class="small mute">Des commandes fictives sur 3 semaines pour voir l’outil « en vrai » (préparation, retraits, chiffres). Elles sont marquées « exemple » et se suppriment d’un clic avant l’ouverture.</p>
          <div class="row-btns"><button class="btn-plain btn-sm" id="exAdd">Ajouter des ventes d’exemple</button><button class="btn-danger btn-sm" id="exDel">Supprimer les exemples</button></div><p class="small mute" id="exCount" style="margin-top:8px"></p></div>
        <div class="group-title">Code de l’équipe</div>
        <div class="group pad"><div class="fields2"><input id="cOld" type="password" inputmode="numeric" placeholder="Code actuel"><input id="cNew" type="password" inputmode="numeric" placeholder="Nouveau code (4 à 8 chiffres)"></div><button class="btn-plain btn-sm" id="cSave" style="margin-top:10px">Changer le code</button> <button class="btn-plain btn-sm" id="cOut" style="margin-top:10px">Se déconnecter</button>
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line, rgba(128,128,128,.25))"><button class="btn-soft btn-sm" id="cLink">Connecter un autre appareil</button>
          <p class="small mute" style="margin-top:6px">Affiche un QR code à scanner avec le téléphone ou l’iPad à connecter, sans taper le code. Valable 10 minutes, une seule fois.</p><div id="cLinkBox"></div></div></div>
      </div>
    </div>
    <div class="basket"><button class="btn-main" id="sSave">Enregistrer les réglages</button></div>`;
  let days = s.open_days.slice(), closed = s.closed_dates.slice();
  document.querySelectorAll('[data-wd]').forEach(b => b.onclick = () => { const d = +b.dataset.wd; days = days.includes(d) ? days.filter(x => x !== d) : [...days, d]; b.classList.toggle('on'); });
  const drawClosed = () => { $('sClosed').innerHTML = closed.map(d => `<button type="button" class="chip" data-del="${d}">${dfr(d, { weekday: 'short', day: 'numeric', month: 'short' })} ✕</button>`).join('') || '<span class="mute small">Aucune</span>'; $('sClosed').querySelectorAll('[data-del]').forEach(b => b.onclick = () => { closed = closed.filter(x => x !== b.dataset.del); drawClosed(); }); };
  drawClosed();
  $('sAdd').onclick = () => { const d = $('sAddDate').value; if (d && !closed.includes(d)) { closed.push(d); closed.sort(); drawClosed(); } };
  $('sSave').onclick = async () => {
    const settings = { ...s, ordering_open: $('sOpen').checked, title: $('sTitle').value, place: $('sPlace').value, message: $('sMsg').value,
      pickup_from: $('sFrom').value, pickup_to: $('sTo').value, open_days: days, cutoff_days: +$('sCd').value, cutoff_time: $('sCt').value,
      days_ahead: +$('sAhead').value, max_per_product: +$('sMax').value, season_start: $('sSs').value, season_end: $('sSe').value, closed_dates: closed,
      printer_on: SETTINGS.printer_on, printer_ip: SETTINGS.printer_ip };
    try { const r = await api('reglages_enregistrer', { settings }); SETTINGS = r.settings; toast('Réglages enregistrés.'); renderSettings(); } catch (e) { toast(e.message, true); }
  };
  $('sPoster').onclick = () => poster(publicUrl, s);
  const exCount = async () => { try { const n = (await api('nb_exemples')).count; $('exCount').textContent = n ? n + ' commande(s) d’exemple en place.' : 'Aucune commande d’exemple.'; $('exDel').disabled = !n; $('exAdd').disabled = !!n; } catch { } };
  exCount();
  cardBox();
  printerBox();
  $('exAdd').onclick = async () => { try { const r = await api('exemples'); toast(r.created + ' commandes d’exemple ajoutées.'); exCount(); } catch (e) { toast(e.message, true); } };
  $('exDel').onclick = async () => { if (!confirm('Supprimer toutes les commandes d’exemple ? Les vraies commandes ne sont pas touchées.')) return; try { const r = await api('supprimer_exemples'); toast(r.deleted + ' commandes d’exemple supprimées.'); exCount(); } catch (e) { toast(e.message, true); } };
  $('cSave').onclick = async () => { try { await api('code', { old: $('cOld').value, new: $('cNew').value }); toast('Code changé. Les autres appareils devront le saisir.'); $('cOld').value = $('cNew').value = ''; } catch (e) { toast(e.message, true); } };
  $('cLink').onclick = async () => {
    try {
      const r = await api('lien_appareil');
      $('cLinkBox').innerHTML = `<div style="background:#fff;padding:12px;border-radius:12px;width:220px;margin-top:10px">${qrSvg(r.url)}</div>
        <p class="small" style="margin-top:8px">Scanne avec l’appareil photo de l’iPhone ou de l’iPad. Valable jusqu’à ${esc(r.expires)}.</p>
        <p class="small mute" style="word-break:break-all;user-select:all">${esc(r.url)}</p>`;
    } catch (e) { toast(e.message, true); }
  };
  $('cOut').onclick = async () => { try { await api('deconnexion'); } catch { } showLogin(); };
}
function poster(url, s) {
  const w = window.open('', '_blank');
  if (!w) return toast('Autorise les fenêtres pour ouvrir l’affiche.', true);
  w.document.write(`<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Affiche</title><style>
    @page{size:A4;margin:14mm}body{font-family:-apple-system,system-ui,sans-serif;text-align:center;color:#1d1d1f;margin:0}
    h1{font-size:44px;margin:10mm 0 2mm}h2{font-size:22px;font-weight:500;color:#555;margin:0 0 8mm}.qr{width:105mm;margin:0 auto}.qr svg{width:100%}
    .langs{font-size:19px;line-height:1.6;margin-top:8mm}.u{font-size:20px;font-weight:600;margin-top:5mm}.e{font-size:60px}</style></head><body>
    <div class="e">🥖 🥐</div><h1>${esc(s.title)}</h1><h2>${esc(s.place)} · ${s.pickup_from.replace(':', ' h ')} – ${s.pickup_to.replace(':', ' h ')}</h2>
    <div class="qr">${qrSvg(url)}</div><div class="u">${esc(url.replace(/^https?:\/\//, ''))}</div>
    <div class="langs">Commandez la veille, retirez le matin.<br>Am Vortag bestellen, morgens abholen.<br>Order the day before, collect in the morning.<br>De dag ervoor bestellen, 's ochtends ophalen.</div>
    <script>setTimeout(()=>print(),400)<\/script></body></html>`);
  w.document.close();
}

/* ================= Documents à imprimer ================= */
const PRINT_CSS = `@page{size:A4;margin:12mm}*{box-sizing:border-box}body{font-family:-apple-system,system-ui,sans-serif;color:#1d1d1f;margin:0;font-size:13px}
  h1{font-size:24px;margin:0}h2{font-size:15px;margin:16px 0 6px}.sub{color:#555;margin:2px 0 12px}.warn{border:1.5px solid #c25e00;color:#8a4300;border-radius:8px;padding:8px 10px;margin:8px 0}
  table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #ccc;padding:6px 6px;text-align:left;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#555;border-bottom:1.5px solid #1d1d1f}
  .n{text-align:right;font-variant-numeric:tabular-nums}.box{width:18px;height:18px;border:1.5px solid #1d1d1f;border-radius:4px;display:inline-block}
  .tot{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 4px}.tot div{border:1.5px solid #1d1d1f;border-radius:10px;padding:6px 12px;font-size:15px}.tot b{font-size:20px}
  .big td{font-size:20px;padding:12px 8px}.big .q{font-size:30px;font-weight:700}.hand{border-bottom:1.5px dotted #888;min-width:70px;display:inline-block;height:24px}
  .pay{font-weight:600}.due{color:#b00020;font-weight:700}.foot{margin-top:14px;color:#777;font-size:11px}.bar{position:fixed;top:10px;right:10px;display:flex;gap:6px}
  .bar button{font:inherit;font-size:14px;padding:8px 14px;border-radius:10px;border:0;background:#1d1d1f;color:#fff}@media print{.bar{display:none}}`;
function printWin(title, body, shareText) {
  const w = window.open('', '_blank');
  if (!w) { toast('Autorise les fenêtres pour imprimer.', true); return; }
  w.document.write(`<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(title)}</title><style>${PRINT_CSS}</style></head><body>
    <div class="bar">${shareText ? '<button id="share">Envoyer</button>' : ''}<button onclick="print()">Imprimer</button></div>${body}
    <div class="foot">Imprimé le ${new Date().toLocaleString('fr-FR', { dateStyle: 'full', timeStyle: 'short' })} · le76.fr/pain</div></body></html>`);
  w.document.close();
  if (shareText) w.document.getElementById('share').onclick = async () => {
    try { if (w.navigator.share) { await w.navigator.share({ title, text: shareText }); return; } } catch { return; }
    w.location.href = 'sms:?&body=' + encodeURIComponent(shareText);
  };
  setTimeout(() => { try { w.focus(); w.print(); } catch { } }, 400);
}
const payTxt = o => o.paid ? `<span class="pay">Payé · ${esc(PAY[o.paymentMethod] || '')}</span>` : `<span class="due">À encaisser ${eur(o.total)}</span>`;
const itemsTxt = o => o.items.map(l => `<b>${l.qty}</b> × ${esc(l.name)}`).join('<br>');
const prepTotals = j => j.prep.filter(p => p.total || p.pending);

async function printSales(date) {
  let j; try { j = await api('jour', { date }); } catch (e) { return toast(e.message, true); }
  const ok = j.orders.filter(o => ['confirmed', 'collected'].includes(o.status)), pend = j.orders.filter(o => o.status === 'pending');
  const row = o => `<tr><td><span class="box"></span></td><td><b>${esc(o.pitch)}</b></td><td>${esc(o.lastName.toUpperCase())} ${esc(o.firstName)}<br><span style="color:#777">${esc(o.code)}${o.note ? ' · ' + esc(o.note) : ''}</span></td><td>${itemsTxt(o)}</td><td>${payTxt(o)}${o.status === 'collected' ? '<br>✓ déjà retirée' : ''}</td></tr>`;
  const head = '<tr><th style="width:30px">✓</th><th>Empl.</th><th>Client</th><th>Commande</th><th>Paiement</th></tr>';
  printWin('Feuille de vente ' + date, `
    <h1>🧾 Pain · feuille de vente</h1><div class="sub">${cap(dfr(date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))} · retrait ${SETTINGS?.pickup_from || ''}–${SETTINGS?.pickup_to || ''} · ${ok.length} commande(s)</div>
    <div class="tot">${prepTotals(j).map(p => `<div>${esc(p.emoji)} <b>${p.total}</b> ${esc(p.name)}</div>`).join('') || '<div>Aucune commande</div>'}</div>
    <h2>Commandes à remettre (par emplacement)</h2>
    <table>${head}${ok.map(row).join('') || '<tr><td colspan="5">Aucune commande.</td></tr>'}</table>
    ${pend.length ? `<h2>Non payées (${pend.length}) · ne pas préparer sans paiement</h2><table>${head}${pend.map(row).join('')}</table>` : ''}`);
}

/* ================= Commande boulanger ================= */
// Quantités à commander au boulanger pour un jour : précommandes payées + ajout pour la vente au comptoir
// (l'ajout est retenu sur l'appareil, d'un jour à l'autre).
function bakerExtra() { try { return JSON.parse(localStorage.getItem('pain_boulanger_extra') || '{}') || {}; } catch { return {}; } }
function bakerExtraSave(x) { try { localStorage.setItem('pain_boulanger_extra', JSON.stringify(x)); } catch { } }
async function bakerSheet(date) {
  let j; try { j = await api('jour', { date }); } catch (e) { return toast(e.message, true); }
  const extra = bakerExtra();
  const rows = PRODUCTS.filter(p => p.active || j.prep.some(x => x.id === p.id && x.total)).map(p => {
    const x = j.prep.find(y => y.id === p.id) || {};
    return { p, q: x.total || 0, w: x.pending || 0 };
  });
  const open = new Date() < new Date(j.limit.replace(' ', 'T'));
  const quand = dfr(date, { weekday: 'long', day: 'numeric', month: 'long' });
  const tot = r => r.q + (+extra[r.p.id] || 0);
  const message = () => `Bonjour, voici la commande pour ${quand} :\n` + rows.filter(r => tot(r)).map(r => `${tot(r)} × ${r.p.name}`).join('\n') + '\nMerci !';
  const draw = () => {
    openSheet('Commande boulanger', `
      <div class="row-btns" style="justify-content:center;margin-bottom:10px">
        <button class="btn-plain btn-sm" id="bPrev">‹</button><b style="font-size:17px;min-width:200px;text-align:center">${cap(quand)}</b><button class="btn-plain btn-sm" id="bNext">›</button>
      </div>
      ${open ? `<p class="small" style="background:rgba(255,149,0,.14);color:var(--orange);padding:10px 12px;border-radius:12px">⚠️ Les campeurs peuvent encore commander jusqu’au ${dfr(j.limit.slice(0, 10), { weekday: 'long' })} à ${j.limit.slice(11)} : chiffres provisoires.</p>` : ''}
      <div class="group" style="margin-top:10px">${rows.map(r => `
        <div class="li" style="align-items:center">
          <span style="font-size:28px">${esc(r.p.emoji)}</span>
          <span class="main"><div class="t">${esc(r.p.name)}</div><div class="d">${r.q} précommandé(s)${r.w ? ` · <span style="color:var(--orange)">+${r.w} pas encore payé(s)</span>` : ''}</div></span>
          <span style="display:flex;align-items:center;gap:6px" title="En plus pour la vente au comptoir"><span class="small mute">+</span>
            <input type="number" min="0" max="999" inputmode="numeric" data-x="${r.p.id}" value="${+extra[r.p.id] || ''}" placeholder="0" style="width:64px;text-align:center"></span>
          <span style="font-size:30px;font-weight:700;min-width:56px;text-align:right" data-t="${r.p.id}">${tot(r)}</span>
        </div>`).join('')}</div>
      <p class="small mute" style="margin:6px 4px 14px">Le chiffre en gros = à commander. La case « + » ajoute de quoi vendre au comptoir ; elle est retenue sur cet appareil.</p>
      <div class="row-btns" style="flex-wrap:wrap">
        ${SETTINGS?.printer_ip ? '<button class="btn-main" id="bPrint" style="flex:1">🖨️ Imprimer le ticket</button>' : ''}
        <button class="btn-soft" id="bSend" style="flex:1">💬 Envoyer au boulanger</button>
      </div>
      <div class="row-btns" style="margin-top:8px"><button class="btn-plain btn-sm" id="bCopy">Copier le texte</button><button class="btn-plain btn-sm" id="bA4">Feuille A4</button></div>`);
    $('bPrev').onclick = () => bakerSheet(addDays(date, -1));
    $('bNext').onclick = () => bakerSheet(addDays(date, 1));
    document.querySelectorAll('[data-x]').forEach(i => i.oninput = () => {
      const v = Math.max(0, Math.min(999, parseInt(i.value, 10) || 0));
      if (v) extra[i.dataset.x] = v; else delete extra[i.dataset.x];
      bakerExtraSave(extra);
      const r = rows.find(y => String(y.p.id) === i.dataset.x);
      document.querySelector(`[data-t="${i.dataset.x}"]`).textContent = tot(r);
    });
    if ($('bPrint')) $('bPrint').onclick = async () => {
      try { await api('boulanger_imprimer', { date, extra }); toast(RELAY.alive ? 'Ticket envoyé à l’imprimante.' : 'Ticket en attente : il sortira dès qu’un appareil du camping joindra l’imprimante.'); relayTick(); }
      catch (e) { toast(e.message, true); }
    };
    $('bSend').onclick = async () => {
      const text = message();
      try { if (navigator.share) { await navigator.share({ text }); return; } } catch { return; }
      location.href = 'sms:?&body=' + encodeURIComponent(text);
    };
    $('bCopy').onclick = async () => { try { await navigator.clipboard.writeText(message()); toast('Texte copié : colle-le dans un SMS ou WhatsApp.'); } catch { toast('Copie impossible sur cet appareil.', true); } };
    $('bA4').onclick = () => printBaker(date);
  };
  draw();
}

async function printBaker(date) {
  let j; try { j = await api('jour', { date }); } catch (e) { return toast(e.message, true); }
  const cost = id => (PRODUCTS.find(p => p.id === id) || {}).cost || 0;
  const rows = PRODUCTS.filter(p => p.active || j.prep.some(x => x.id === p.id && x.total)).map(p => ({ p, q: (j.prep.find(x => x.id === p.id) || {}).total || 0, w: (j.prep.find(x => x.id === p.id) || {}).pending || 0 }));
  const open = new Date() < new Date(j.limit.replace(' ', 'T'));
  const achat = rows.reduce((a, r) => a + r.q * cost(r.p.id), 0);
  const txt = `Commande pain pour le ${dfr(date, { weekday: 'long', day: 'numeric', month: 'long' })} (Le 76 · camping) :\n` + rows.filter(r => r.q).map(r => `- ${r.q} ${r.p.name}`).join('\n') + '\nMerci !';
  printWin('Bon boulanger ' + date, `
    <h1>🥖 Commande boulangerie</h1><div class="sub">Pour le <b>${dfr(date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</b> · ${esc(SETTINGS?.place || '')}</div>
    ${open ? `<div class="warn">⚠️ Les campeurs peuvent encore commander jusqu’au ${dfr(j.limit.slice(0, 10), { weekday: 'long' })} à ${j.limit.slice(11)} : chiffres provisoires. Réimprime après cette heure.</div>` : ''}
    <table class="big"><tr><th>Produit</th><th class="n">Précommandes payées</th><th class="n">+ Vente comptoir</th><th class="n">Total à commander</th></tr>
    ${rows.map(r => `<tr><td>${esc(r.p.emoji)} ${esc(r.p.name)}${r.w ? `<div style="font-size:12px;color:#8a4300">+ ${r.w} en attente de paiement</div>` : ''}</td><td class="n q">${r.q}</td><td class="n"><span class="hand"></span></td><td class="n"><span class="hand"></span></td></tr>`).join('')}</table>
    <p class="sub" style="margin-top:12px">Coût d’achat estimé des précommandes : <b>${eur(achat)}</b> · ${j.summary.orders} commande(s) payée(s)</p>`, txt);
}

async function cardBox() {
  let v; try { v = (await api('stripe')).stripe; } catch (e) { $('cardBox').innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }
  $('cardBox').innerHTML = `
    <p class="small mute">Les campeurs peuvent payer leur commande par carte (et Apple Pay / Google Pay) depuis leur bon. La commande est validée automatiquement dès que Stripe confirme le paiement. L’argent arrive sur ton compte Stripe puis sur ton compte bancaire. La clé reste sur le serveur et n’est jamais affichée.</p>
    <label class="field" style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="stOn" ${v.enabled ? 'checked' : ''}> Proposer le paiement par carte en ligne</label>
    <label class="field"><span>Clé secrète Stripe (Développeurs → Clés API → « Clé secrète »)</span><input id="stKey" type="password" autocomplete="off" spellcheck="false" placeholder="${v.hasKey ? 'Clé enregistrée (' + esc(v.keyHint) + ') : laisse vide pour la garder' : 'sk_live_…'}"></label>
    <div class="row-btns"><button class="btn-soft btn-sm" id="stSave">Enregistrer</button>${v.hasKey ? '<button class="btn-plain btn-sm" id="stTest">Tester la connexion</button><button class="btn-plain btn-sm" id="stDel">Retirer la clé</button>' : ''}</div>
    <p class="small" id="stMsg" style="margin-top:8px">${v.enabled ? '<span class="pill p-ok">Carte en ligne active</span>' : '<span class="pill p-gray">Carte en ligne désactivée</span>'}${v.hasKey && v.test ? ' <span class="pill p-warn">Mode test : aucun vrai paiement</span>' : ''}</p>
    <details class="small mute" style="margin-top:8px"><summary>Notification Stripe (facultatif)</summary><p>Pour que les paiements apparaissent même si le campeur ferme la page trop vite : dans Stripe → Développeurs → Webhooks → Ajouter une destination, événement <b>checkout.session.completed</b>, adresse :<br><code style="user-select:all">${esc(v.webhook)}</code><br>Sans cela, les paiements sont quand même vérifiés quand tu ouvres l’espace équipe.</p></details>`;
  const save = async extra => {
    try { await api('stripe_enregistrer', { stripe: { enabled: $('stOn').checked, key: $('stKey').value, ...extra } }); toast('Réglages Stripe enregistrés.'); cardBox(); } catch (e) { toast(e.message, true); }
  };
  $('stSave').onclick = () => save({});
  if ($('stDel')) $('stDel').onclick = () => { if (confirm('Retirer la clé Stripe ? La carte en ligne sera désactivée.')) { $('stOn').checked = false; save({ removeKey: true }); } };
  if ($('stTest')) $('stTest').onclick = async () => {
    $('stMsg').textContent = 'Test en cours…';
    try { const r = await api('stripe_tester'); $('stMsg').innerHTML = `<span class="pill p-ok">Connexion OK</span> ${esc(r.name || '')} ${r.live ? '' : '<span class="pill p-warn">mode test</span>'}${r.charges ? '' : ' <span class="pill p-warn">Compte Stripe pas encore activé pour encaisser</span>'}`; }
    catch (e) { $('stMsg').innerHTML = `<span class="pill p-red">${esc(e.message)}</span>`; }
  };
}

/* ================= Imprimante des bons ================= */
/*
 * Le site est sur Internet, l'imprimante sur le réseau du camping : c'est cette page, ouverte sur
 * un appareil du camping (iPad de la caisse…), qui sert de relais. Toutes les 5 secondes elle vérifie
 * que l'imprimante répond, récupère les bons en attente et les lui envoie (Epson ePOS-Print).
 */
const RELAY = { ip: '', alive: null, running: false, started: false };
const EPOS_NS = 'http://www.epson-pos.com/schemas/2011/03/epos-print';
async function eposSend(ip, body) {
  const ctl = new AbortController(), t = setTimeout(() => ctl.abort(), 15000);
  const xml = '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' + body + '</s:Body></s:Envelope>';
  try {
    const r = await fetch(`https://${ip}/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000`, {
      method: 'POST', mode: 'cors', signal: ctl.signal, body: xml,
      headers: { 'Content-Type': 'text/xml; charset=utf-8', 'If-Modified-Since': 'Thu, 01 Jan 1970 00:00:00 GMT', 'SOAPAction': '""' },
    });
    const x = await r.text();
    const ok = /<response\b[^>]*\bsuccess="true"/.test(x);
    return { reached: true, ok, code: ok ? '' : ((x.match(/<response\b[^>]*\bcode="([^"]*)"/) || [])[1] || 'réponse inattendue') };
  } catch { return { reached: false, ok: false, code: 'injoignable' }; }
  finally { clearTimeout(t); }
}
async function relayTick() {
  if (RELAY.running || document.hidden || $('app').hidden) return;
  RELAY.running = true;
  try {
    if (!RELAY.ip) { RELAY.ip = (await api('imprimante_relais')).ip || ''; if (!RELAY.ip) { RELAY.alive = null; return; } }
    const ip = RELAY.ip;
    RELAY.alive = (await eposSend(ip, `<epos-print xmlns="${EPOS_NS}"/>`)).reached;   // simple « tu es là ? », n'imprime rien
    let r = await api('imprimante_relais', { alive: RELAY.alive, ip });
    for (let tour = 0; r.jobs && r.jobs.length && tour < 5; tour++) {
      const results = [];
      for (const j of r.jobs) {
        const s = await eposSend(ip, j.xml);
        results.push({ job: j.job, ok: s.ok, code: s.code });
        if (!s.reached) RELAY.alive = false;
      }
      r = await api('imprimante_relais', { alive: RELAY.alive, ip, results });
    }
    RELAY.ip = r.ip || '';
  } catch { } finally { RELAY.running = false; }
}
function relayStart() {
  if (RELAY.started) return;
  RELAY.started = true;
  relayTick(); setInterval(relayTick, 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) relayTick(); });
}

let printerTimer = null;
async function printerBox() {
  clearTimeout(printerTimer);
  const box = $('prBox'); if (!box) return;
  let p; try { p = (await api('imprimante')).printer; } catch (e) { box.innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }
  if (!$('prBox')) return;
  const ouvert = box.querySelector('details')?.open;
  const sec = p.seen ? Math.max(0, Math.round((new Date(p.now.replace(' ', 'T')) - new Date(p.seen.replace(' ', 'T'))) / 1000)) : null;
  const depuis = s => s < 120 ? s + ' s' : s < 7200 ? Math.round(s / 60) + ' min' : s < 172800 ? Math.round(s / 3600) + ' h' : Math.round(s / 86400) + ' jours';
  const ici = !p.ip ? '' : RELAY.alive === true ? '<span class="pill p-ok">Cet appareil imprime les bons</span>'
    : RELAY.alive === false ? '<span class="pill p-red">Cet appareil ne joint pas l’imprimante</span>' : '<span class="pill p-gray">Vérification…</span>';
  const global = !p.ip ? '<span class="pill p-gray">Adresse de l’imprimante à renseigner</span>'
    : sec !== null && sec < 30 ? '' : `<span class="pill p-warn">${sec === null ? 'Aucun appareil n’a encore joint l’imprimante' : 'Aucun appareil relais depuis ' + depuis(sec)}</span>`;
  const last = p.last ? `Dernier bon ${p.last.code ? esc(p.last.code) : 'de test'} : ${p.last.statut === 'imprime' ? 'imprimé' : 'pas imprimé' + (p.last.erreur ? ' (' + esc(p.last.erreur) + ')' : '')}${p.last.fini_le ? ' à ' + esc(p.last.fini_le.slice(11, 16)) : ''}` : '';
  box.innerHTML = `
    <p class="small mute">Un bon de préparation sort sur l’imprimante Epson dès qu’une commande est payée. Les bons sont envoyés par cette page équipe, ouverte et écran allumé sur un appareil du camping (iPad de la caisse par exemple). Une commande payée pendant la nuit sort dès que la page est rouverte.</p>
    <label class="field" style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="prOn" ${p.on ? 'checked' : ''}> Imprimer un bon à chaque commande payée</label>
    <div class="row-btns"><input id="prIp" inputmode="decimal" placeholder="Adresse de l’imprimante, ex. 192.168.192.54" value="${esc(p.ip)}" style="flex:1;min-width:0"><button class="btn-plain btn-sm" id="prIpSave">Enregistrer</button></div>
    <p class="small" style="margin:10px 0 4px">${ici} ${global}${p.waiting ? ` <span class="pill p-warn">${p.waiting} en attente</span>` : ''}</p>
    ${last ? `<p class="small mute" style="margin:0 0 6px">${last}</p>` : ''}
    <div class="row-btns" style="margin-top:8px"><button class="btn-plain btn-sm" id="prTest">Imprimer un ticket test</button>${p.waiting ? '<button class="btn-plain btn-sm" id="prClear">Vider la file</button>' : ''}</div>
    ${p.ip ? `<details class="small" style="margin-top:10px" ${ouvert || (ouvert === undefined && RELAY.alive === false) ? 'open' : ''}><summary>Première fois sur un appareil</summary>
      <p class="mute">L’appareil doit être sur le Wi-Fi du camping. Ouvre une fois <a class="link" href="https://${esc(p.ip)}/" target="_blank" rel="noopener">https://${esc(p.ip)}</a> : Safari affiche « Cette connexion n’est pas privée », c’est normal (c’est ton imprimante). Touche <b>Afficher les détails</b> puis <b>visiter ce site web</b>, reviens ici : l’appareil imprime alors les bons.</p></details>` : ''}`;
  $('prOn').onchange = async e => {
    try { const r = await api('reglages_enregistrer', { settings: { ...SETTINGS, printer_on: e.target.checked } }); SETTINGS = r.settings; toast(e.target.checked ? 'Les bons s’imprimeront à chaque commande payée.' : 'Impression des bons arrêtée.'); }
    catch (err) { e.target.checked = !e.target.checked; toast(err.message, true); }
  };
  $('prIpSave').onclick = async () => {
    try { const r = await api('reglages_enregistrer', { settings: { ...SETTINGS, printer_ip: $('prIp').value.trim() } }); SETTINGS = r.settings; RELAY.ip = SETTINGS.printer_ip; RELAY.alive = null; toast('Adresse enregistrée.'); await relayTick(); printerBox(); }
    catch (err) { toast(err.message, true); }
  };
  $('prTest').onclick = async () => { try { await api('imprimante_tester'); toast(RELAY.alive ? 'Ticket envoyé : il sort dans quelques secondes.' : 'Ticket en attente : il sortira dès qu’un appareil du camping joindra l’imprimante.'); relayTick().then(printerBox); } catch (e) { toast(e.message, true); } };
  if ($('prClear')) $('prClear').onclick = async () => { if (!confirm('Supprimer les bons en attente ? Ils ne seront pas imprimés.')) return; try { await api('imprimante_vider'); printerBox(); } catch (e) { toast(e.message, true); } };
  printerTimer = setTimeout(printerBox, 5000);
}

/* ================= Journal ================= */
async function renderLog() {
  let j; try { j = await api('journal'); } catch (e) { toast(e.message, true); return; }
  $('view').innerHTML = `<div class="top"><div><h1>Journal</h1><div class="sub">Les 300 dernières actions</div></div></div>
    <div class="group" style="margin-top:14px"><div class="table-wrap"><table class="t"><thead><tr><th>Quand</th><th>Qui</th><th>Action</th><th>Commande</th><th>Détail</th></tr></thead>
    <tbody>${j.log.map(l => `<tr><td class="num">${new Date(l.quand.replace(' ', 'T')).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}</td><td>${esc(l.qui)}</td><td>${esc(l.action)}</td><td>${esc(l.code || '')}</td><td class="small">${esc(l.detail)}</td></tr>`).join('')}</tbody></table></div></div>`;
}

start();
