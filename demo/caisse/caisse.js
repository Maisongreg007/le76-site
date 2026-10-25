'use strict';
/* ComptaCaisse 2.0 — interface. Les règles de calcul et de contrôle restent sur le serveur (api.php). */

/* ================= Constantes ================= */
const VERSION = '2.0';
const REGISTERS = { restaurant: 'Le 76', camping: 'Camping' };
const INCOME = { sales: 'Recettes espèces du restaurant', float: 'Apport de fonds de caisse', refund: 'Remboursement reçu', other: 'Autres entrées' };
const EXPENSE = { supplier: 'Achat fournisseur', small: 'Petite dépense', refund: 'Remboursement', withdrawal: 'Prélèvement autorisé', other: 'Autres sorties' };
const LEGACY = { invoices: 'Paiement de factures', goods: 'Achats de marchandises', extras: 'Extras / personnel', maintenance: 'Entretien / matériel' };
const BANK = { prepared: 'Préparé', deposited: 'Déposé', credited: 'Crédité', cancelled: 'Annulé' };
const BANK_NEXT = { prepared: ['deposited', 'credited', 'cancelled'], deposited: ['credited'] };
const UNITS = [
  ['b500', 50000, 'Billet de 500 €', 'Billets'], ['b200', 20000, 'Billet de 200 €', 'Billets'], ['b100', 10000, 'Billet de 100 €', 'Billets'],
  ['b50', 5000, 'Billet de 50 €', 'Billets'], ['b20', 2000, 'Billet de 20 €', 'Billets'], ['b10', 1000, 'Billet de 10 €', 'Billets'], ['b5', 500, 'Billet de 5 €', 'Billets'],
  ['c200', 200, 'Pièce de 2 €', 'Pièces'], ['c100', 100, 'Pièce de 1 €', 'Pièces'], ['c50', 50, 'Pièce de 0,50 €', 'Pièces'], ['c20', 20, 'Pièce de 0,20 €', 'Pièces'],
  ['c10', 10, 'Pièce de 0,10 €', 'Pièces'], ['c5', 5, 'Pièce de 0,05 €', 'Pièces'], ['c2', 2, 'Pièce de 0,02 €', 'Pièces'], ['c1', 1, 'Pièce de 0,01 €', 'Pièces'],
  ['r200', 5000, 'Rouleau de 2 € (50 €)', 'Rouleaux'], ['r100', 2500, 'Rouleau de 1 € (25 €)', 'Rouleaux'], ['r50', 2000, 'Rouleau de 0,50 € (20 €)', 'Rouleaux'],
  ['r20', 800, 'Rouleau de 0,20 € (8 €)', 'Rouleaux'], ['r10', 400, 'Rouleau de 0,10 € (4 €)', 'Rouleaux'], ['r5', 250, 'Rouleau de 0,05 € (2,50 €)', 'Rouleaux'],
  ['r2', 100, 'Rouleau de 0,02 € (1 €)', 'Rouleaux'], ['r1', 50, 'Rouleau de 0,01 € (0,50 €)', 'Rouleaux'],
];
const UNIT = Object.fromEntries(UNITS.map(u => [u[0], u]));
const NOTE_KEYS = { b200: 200, b100: 100, b50: 50, b20: 20, b10: 10, b5: 5 };   // format "banknotes" du comptage
const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/* ================= État ================= */
let S = null, REV = 0, REG = 'restaurant', USER = null, CSRF = '', ALLOWED = ['restaurant'];
let VIEW = 'home', PERIOD = ym(today()), MONTH_VIEW = 'days';
let JF = { from: '', to: '', type: '', cat: '', min: '', max: '', q: '', showCancelled: false };
let busy = false;

/* ================= Outils ================= */
const $ = id => document.getElementById(id);
function today() { const d = new Date(); return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`; }
function nowTime() { const d = new Date(); return `${p2(d.getHours())}:${p2(d.getMinutes())}`; }
function p2(n) { return String(n).padStart(2, '0'); }
function ym(d) { return d.slice(0, 7); }
function uid() { return crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2); }
function clone(x) { return JSON.parse(JSON.stringify(x)); }
function eur(c) { return (c / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }); }
function signed(c) { return c === 0 ? eur(0) : (c > 0 ? '+' : '−') + eur(Math.abs(c)); }
function dateFr(d, opt = { day: 'numeric', month: 'short', year: 'numeric' }) { return new Date(d + 'T12:00:00').toLocaleDateString('fr-FR', opt); }
function monthFr(m) { return MONTHS[+m.slice(5, 7) - 1] + ' ' + m.slice(0, 4); }
function addDays(d, n) { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); }
function monthEnd(m) { const x = new Date(+m.slice(0, 4), +m.slice(5, 7), 0); return `${x.getFullYear()}-${p2(x.getMonth() + 1)}-${p2(x.getDate())}`; }
function prevMonth(m) { const x = new Date(+m.slice(0, 4), +m.slice(5, 7) - 2, 1); return `${x.getFullYear()}-${p2(x.getMonth() + 1)}`; }
function nextMonth(m) { const x = new Date(+m.slice(0, 4), +m.slice(5, 7), 1); return `${x.getFullYear()}-${p2(x.getMonth() + 1)}`; }
function localTime(iso) { const d = new Date(iso); return isNaN(d) ? '' : `${p2(d.getHours())}:${p2(d.getMinutes())}`; }
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
/** "12,50" → 1250 centimes, sans passer par un nombre à virgule. */
function parseCents(text, { allowZero = false } = {}) {
  const s = String(text ?? '').trim().replace(/\s|€/g, '').replace(',', '.');
  if (!/^\d{1,9}(\.\d{1,2})?$/.test(s)) throw new Error('Saisis un montant valide, avec deux décimales au maximum (ex. 12,50).');
  const [i, d = ''] = s.split('.');
  const c = Number(i) * 100 + Number((d + '00').slice(0, 2));
  if (!allowZero && c <= 0) throw new Error('Le montant doit être supérieur à zéro.');
  return c;
}
function centsToInput(c) { return (c / 100).toFixed(2).replace('.', ','); }
function toast(msg, err = false) { const t = $('toast'); t.textContent = msg; t.className = 'show' + (err ? ' err' : ''); clearTimeout(toast.t); toast.t = setTimeout(() => t.className = '', err ? 6000 : 3000); }
function isAdmin() { return USER?.role === 'admin'; }
function canWrite() { return USER && USER.role !== 'reader'; }

/* ================= Règles de calcul (identiques au serveur) ================= */
function effect(e) {
  if (e.deleted) return 0;
  if (e.kind === 'in') return e.cents;
  if (e.category === 'bank' && ['prepared', 'cancelled'].includes(e.bankStatus || 'deposited')) return 0;
  return -e.cents;
}
function balanceAt(date) { let t = S.opening.cents; for (const e of S.entries) if (e.date <= date) t += effect(e); return t; }
function kindOf(e) { return e.category === 'bank' ? 'bank' : e.category === 'transfer' ? (e.kind === 'in' ? 'in' : 'out') : e.kind; }
function catLabel(e) {
  if (e.category === 'transfer') return e.kind === 'in' ? 'Transfert depuis ' + REGISTERS[e.transferPeer] : 'Transfert vers ' + REGISTERS[e.transferPeer];
  if (e.category === 'bank') return 'Dépôt · ' + BANK[e.bankStatus || 'deposited'];
  if (e.kind === 'in') return INCOME[e.incomeCategory] || 'Entrée';
  return EXPENSE[e.expenseCategory] || LEGACY[e.expenseCategory] || 'Autres sorties';
}
function typeLabel(e) { return e.category === 'bank' ? 'Dépôt bancaire' : e.category === 'transfer' ? 'Transfert' : e.kind === 'in' ? 'Entrée' : 'Sortie'; }
function catKey(e) { return e.category === 'bank' ? 'bank:' + (e.bankStatus || 'deposited') : e.category === 'transfer' ? 'transfer' : e.kind === 'in' ? 'in:' + (e.incomeCategory || 'sales') : 'out:' + (e.expenseCategory || 'other'); }
function closedMonths() { const m = {}; for (const c of S.closures || []) if (!c.reopened) m[c.month] = c; return m; }
function isClosed(date) { return !!closedMonths()[ym(date)]; }

/** Totaux d'une période [from, to] : formule du cahier des charges. */
function periodStats(from, to) {
  const opening = from <= S.opening.date ? S.opening.cents : balanceAt(addDays(from, -1));
  let ins = 0, outs = 0, bank = 0, pending = 0, count = 0;
  for (const e of S.entries) {
    if (e.deleted || e.date < from || e.date > to) continue;
    const k = kindOf(e), v = effect(e);
    count++;
    if (k === 'bank') { if (v < 0) bank -= v; else if ((e.bankStatus || 'deposited') === 'prepared') pending += e.cents; }
    else if (v > 0) ins += v; else outs -= v;
  }
  return { opening, ins, outs, bank, pending, closing: opening + ins - outs - bank, count };
}
function lastCheck(upto = '9999-12-31') {
  return S.checks.filter(c => !c.deleted && c.date <= upto).sort((a, b) => (a.date + a.at).localeCompare(b.date + b.at)).pop() || null;
}

/* ================= Serveur ================= */
async function api(action, body, extra = '') {
  const opt = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: {} };
  if (body) { opt.headers['Content-Type'] = 'application/json'; opt.headers['X-CSRF-Token'] = CSRF; opt.body = JSON.stringify({ register: REG, ...body }); }
  const r = await fetch(`api.php?action=${action}&register=${REG}${extra}`, opt);
  let j = {};
  try { j = await r.json(); } catch { throw Object.assign(new Error('Réponse inattendue du serveur.'), { status: r.status }); }
  if (r.status === 401 && action !== 'login') { showLogin(); throw Object.assign(new Error(j.error || 'Connecte-toi.'), { status: 401 }); }
  if (!r.ok) throw Object.assign(new Error(j.error || 'Action impossible.'), { status: r.status });
  if (j.csrf) CSRF = j.csrf;
  return j;
}
async function loadState() {
  const j = await api('state');
  S = j.state; REV = j.revision; USER = j.user; ALLOWED = j.registers || ALLOWED;
  S.closures ??= []; S.checks ??= []; S.exchanges ??= [];
}
/** Une saisie = une modification de l'état, envoyée au serveur qui la vérifie et l'inscrit à l'historique. */
async function commit(mutate, { reason = '', restore = false } = {}) {
  if (busy) return false;
  busy = true;
  try {
    const next = restore ? mutate() : (() => { const n = clone(S); mutate(n); return n; })();
    next.audit.push({ at: new Date().toISOString(), action: restore ? 'Restauration' : 'Saisie', detail: '', reason });
    const j = await api('save', { request: uid(), revision: REV, state: next, restore });
    S = j.state; REV = j.revision; S.closures ??= [];
    render();
    return true;
  } catch (e) {
    toast(e.message, true);
    if (e.status === 409) { try { await loadState(); render(); } catch { } }
    return false;
  } finally { busy = false; }
}

/* ================= Connexion ================= */
function showLogin() { $('app').hidden = true; $('login').hidden = false; closeSheet(); }
$('loginForm').addEventListener('submit', async ev => {
  ev.preventDefault();
  const f = ev.target; $('loginError').textContent = '';
  try {
    const j = await api('login', { name: f.name.value.trim(), password: f.password.value });
    ALLOWED = j.registers; REG = ALLOWED.includes(REG) ? REG : ALLOWED[0];
    f.password.value = '';
    await start();
  } catch (e) { $('loginError').textContent = e.message; }
});
async function start() {
  try { const s = await api('session'); ALLOWED = s.registers; USER = s.user; }
  catch { showLogin(); return; }
  const saved = sessionStorage.getItem('cc-register');
  if (saved && ALLOWED.includes(saved)) REG = saved;
  if (!ALLOWED.includes(REG)) REG = ALLOWED[0];
  await loadState();
  $('login').hidden = true; $('app').hidden = false;
  render();
}

/* ================= Navigation ================= */
document.querySelectorAll('#tabs button').forEach(b => b.addEventListener('click', () => { VIEW = b.dataset.view; render(); window.scrollTo(0, 0); }));
async function switchRegister(r) {
  if (r === REG || busy) return;
  REG = r; try { sessionStorage.setItem('cc-register', r); } catch { }
  try { await loadState(); render(); } catch (e) { toast(e.message, true); }
}
function render() {
  if (!S) return;
  const seg = $('registerSeg');
  seg.innerHTML = ALLOWED.map(r => `<button class="${r === REG ? 'on' : ''}" data-r="${r}">${REGISTERS[r]}</button>`).join('');
  seg.querySelectorAll('button').forEach(b => b.onclick = () => switchRegister(b.dataset.r));
  document.querySelectorAll('#tabs button').forEach(b => b.classList.toggle('on', b.dataset.view === VIEW));
  for (const v of ['home', 'journal', 'months', 'settings']) $('view-' + v).hidden = v !== VIEW;
  ({ home: renderHome, journal: renderJournal, months: renderMonths, settings: renderSettings })[VIEW]();
}

/* ================= Accueil ================= */
function renderHome() {
  const t = today(), now = balanceAt(t), from = PERIOD + '-01', to = PERIOD === ym(t) ? t : monthEnd(PERIOD);
  const st = periodStats(from, to), chk = lastCheck(t), closed = closedMonths()[PERIOD];
  const pending = S.entries.filter(e => !e.deleted && e.category === 'bank' && ['prepared', 'deposited'].includes(e.bankStatus || 'deposited'));
  const recent = journalRows().filter(r => !r.deleted).slice(-6).reverse();
  const noStart = S.entries.length === 0 && S.audit.length <= 1;
  $('view-home').innerHTML = `
    <div class="view-head"><div><h1>${REGISTERS[REG]}</h1><div class="sub">${esc(USER.name)} · ${dateFr(t, { weekday: 'long', day: 'numeric', month: 'long' })}</div></div></div>
    ${noStart && isAdmin() ? `<div class="hero"><b>Bienvenue.</b> Commence par indiquer le point de départ de la caisse (date et espèces présentes) dans Réglages. <button class="link" data-go="settings">Configurer ›</button></div>` : ''}
    <div class="hero">
      <div class="lbl">Espèces en caisse (solde théorique)</div>
      <div class="amount">${eur(now)}</div>
      ${chk ? `<div class="sub">Dernier comptage le ${dateFr(chk.date)} : <b>${eur(chk.counted)}</b> compté · écart <b class="${chk.counted - chk.expected < 0 ? 'neg' : chk.counted - chk.expected > 0 ? 'pos' : ''}">${signed(chk.counted - chk.expected)}</b></div>` : '<div class="sub">Aucun comptage enregistré.</div>'}
    </div>
    ${canWrite() ? `<div class="actions no-print">
      <button class="action" data-act="in"><span class="ic in">+</span>Entrée d’espèces</button>
      <button class="action" data-act="out"><span class="ic out">−</span>Sortie d’espèces</button>
      <button class="action" data-act="bank"><span class="ic bank">🏦</span>Dépôt bancaire</button>
      <button class="action" data-act="ex"><span class="ic ex">⇄</span>Faire de la monnaie</button>
      <button class="action" data-act="count"><span class="ic count">✓</span>Compter ma caisse</button>
    </div>` : ''}
    <div class="group-title" style="display:flex;justify-content:space-between;align-items:center">
      <span>Période : ${monthFr(PERIOD)}${closed ? ' · clôturé' : ''}</span>
      <span class="no-print"><button class="link" data-period="-1">‹ Mois précédent</button>${PERIOD < ym(t) ? ` · <button class="link" data-period="1">Mois suivant ›</button>` : ''}</span>
    </div>
    <div class="stats">
      <div class="stat"><div class="k">Solde initial</div><div class="v">${eur(st.opening)}</div></div>
      <div class="stat"><div class="k">Entrées d’espèces</div><div class="v pos">${eur(st.ins)}</div></div>
      <div class="stat"><div class="k">Sorties d’espèces</div><div class="v neg">${eur(st.outs)}</div></div>
      <div class="stat"><div class="k">Dépôts bancaires</div><div class="v" style="color:var(--blue)">${eur(st.bank)}</div></div>
      <div class="stat wide"><div class="k">Solde théorique ${PERIOD === ym(t) ? 'actuel' : 'de fin de mois'}</div><div class="v">${eur(st.closing)}</div><div class="small mute">${eur(st.opening)} + ${eur(st.ins)} − ${eur(st.outs)} − ${eur(st.bank)}</div></div>
      <div class="stat wide"><div class="k">Comptage réel ${chk && ym(chk.date) === PERIOD ? 'du ' + dateFr(chk.date) : ''}</div>${(() => { const c = lastCheck(to); return c && ym(c.date) === PERIOD ? `<div class="v">${eur(c.counted)}</div><div class="small">Écart : <b class="${c.counted - c.expected < 0 ? 'neg' : c.counted - c.expected > 0 ? 'pos' : ''}">${signed(c.counted - c.expected)}</b> (théorique ${eur(c.expected)})</div>` : '<div class="v mute">—</div><div class="small mute">Pas de comptage sur cette période</div>'; })()}</div>
    </div>
    <div class="grid2">
      <div>
        <div class="group-title">Dernières opérations</div>
        <div class="group">${recent.length ? recent.map(rowLi).join('') : '<div class="empty">Aucune opération.</div>'}
          <button class="li chev" data-go="journal"><span class="main">Voir tout le journal</span></button></div>
      </div>
      <div>
        <div class="group-title">Dépôts en cours</div>
        <div class="group">${pending.length ? pending.map(e => `
          <button class="li" data-open="${esc(e.id)}"><span class="dot d-bank"></span><span class="main"><div class="t">${eur(e.cents)} · ${esc(e.bankName || 'Banque')}</div><div class="d">${dateFr(e.date)} · ${BANK[e.bankStatus || 'deposited']}${e.ref ? ' · ' + esc(e.ref) : ''}</div></span><span class="pill ${(e.bankStatus || 'deposited') === 'prepared' ? 'p-warn' : 'p-bank'}">${BANK[e.bankStatus || 'deposited']}</span></button>`).join('') : '<div class="empty">Aucun dépôt en attente de crédit.</div>'}</div>
        ${isAdmin() && ALLOWED.length > 1 ? `<div class="group-title">Entre établissements</div><div class="group"><button class="li chev" data-act="transfer"><span class="dot" style="background:var(--accent)"></span><span class="main"><div class="t">Transférer des espèces</div><div class="d">Sortie dans une caisse, entrée dans l’autre</div></span></button></div>` : ''}
      </div>
    </div>`;
  wire($('view-home'));
}
function rowLi(r) {
  const cls = r.type === 'ex' ? 'ex' : r.k;
  return `<button class="li" data-open="${esc(r.id)}" data-list="${r.list}"><span class="dot d-${cls}"></span><span class="main"><div class="t">${esc(r.label || r.cat)}</div><div class="d">${dateFr(r.date)}${r.time ? ' · ' + r.time : ''} · ${esc(r.cat)}</div></span><span class="num ${r.var > 0 ? 'pos' : r.var < 0 ? 'neg' : 'mute'}">${r.type === 'ex' ? eur(r.cents) : signed(r.var)}</span></button>`;
}
function wire(root) {
  root.querySelectorAll('[data-act]').forEach(b => b.onclick = () => openForm(b.dataset.act));
  root.querySelectorAll('[data-go]').forEach(b => b.onclick = () => { VIEW = b.dataset.go; render(); });
  root.querySelectorAll('[data-open]').forEach(b => b.onclick = () => openDetail(b.dataset.open, b.dataset.list || 'entries'));
  root.querySelectorAll('[data-period]').forEach(b => b.onclick = () => { PERIOD = +b.dataset.period < 0 ? prevMonth(PERIOD) : nextMonth(PERIOD); render(); });
}

/* ================= Journal ================= */
/** Toutes les opérations (entrées, sorties, dépôts, transferts, échanges) avec le solde après chacune. */
function journalRows() {
  const rows = [];
  S.entries.forEach((e, i) => rows.push({ list: 'entries', id: e.id, i, date: e.date, time: e.time || '', k: kindOf(e), type: e.category === 'bank' ? 'bank' : e.category === 'transfer' ? 'transfer' : e.kind, typeLabel: typeLabel(e), cat: catLabel(e), catKey: catKey(e), label: e.category === 'transfer' ? (e.transferNote || e.label) : e.label, ref: e.ref || e.bankName || '', cents: e.cents, var: effect(e), deleted: e.deleted, e }));
  S.exchanges.forEach((x, i) => rows.push({ list: 'exchanges', id: x.id, i: 100000 + i, date: x.date, time: localTime(x.at), k: 'ex', type: 'ex', typeLabel: 'Change de monnaie', cat: 'Change de monnaie', catKey: 'ex', label: x.note || describeUnits(x.given) + ' → ' + describeUnits(x.received), ref: '', cents: x.cents, var: 0, deleted: x.deleted, e: x }));
  rows.sort((a, b) => (a.date + (a.time || '')).localeCompare(b.date + (b.time || '')) || a.i - b.i);
  let bal = S.opening.cents;
  for (const r of rows) { bal += r.var; r.after = bal; }
  return rows;
}
function describeUnits(items) { return Object.entries(items || {}).filter(([, q]) => q > 0).map(([k, q]) => q + ' × ' + (UNIT[k]?.[2] || k).replace(/^(Billet|Pièce|Rouleau) de /, m => m.toLowerCase())).join(', '); }
function filteredRows() {
  let rows = journalRows();
  const f = JF;
  return rows.filter(r => {
    if (!f.showCancelled && r.deleted) return false;
    if (f.from && r.date < f.from) return false;
    if (f.to && r.date > f.to) return false;
    if (f.type && r.type !== f.type) return false;
    if (f.cat && r.catKey !== f.cat && !(f.cat.endsWith(':') && r.catKey.startsWith(f.cat))) return false;
    if (f.min) { try { if (r.cents < parseCents(f.min, { allowZero: true })) return false; } catch { } }
    if (f.max) { try { if (r.cents > parseCents(f.max, { allowZero: true })) return false; } catch { } }
    if (f.q) { const q = f.q.toLowerCase(); if (![r.label, r.cat, r.typeLabel, r.ref, r.id].join(' ').toLowerCase().includes(q)) return false; }
    return true;
  });
}
function catOptions() {
  return `<option value="">Toutes catégories</option>
    <optgroup label="Entrées">${Object.entries(INCOME).map(([k, v]) => `<option value="in:${k}">${v}</option>`).join('')}</optgroup>
    <optgroup label="Sorties">${Object.entries(EXPENSE).map(([k, v]) => `<option value="out:${k}">${v}</option>`).join('')}</optgroup>
    <optgroup label="Dépôts">${Object.entries(BANK).map(([k, v]) => `<option value="bank:${k}">Dépôt ${v.toLowerCase()}</option>`).join('')}</optgroup>
    <option value="transfer">Transferts</option><option value="ex">Change de monnaie</option>`;
}
function renderJournal() {
  const rows = filteredRows();
  const tot = rows.reduce((a, r) => (r.var > 0 ? a.in += r.var : r.k === 'bank' ? a.bank -= r.var : a.out -= r.var, a), { in: 0, out: 0, bank: 0 });
  const v = $('view-journal');
  v.innerHTML = `
    <div class="view-head"><div><h1>Journal</h1><div class="sub">${rows.length} opération(s) affichée(s) · ${REGISTERS[REG]}</div></div>
      <div class="toolbar no-print"><button class="btn-plain btn-sm" id="jCsv">Exporter Excel / CSV</button><button class="btn-plain btn-sm" id="jPrint">Imprimer / PDF</button></div></div>
    <div class="print-only"><h2>Journal de caisse · ${REGISTERS[REG]}</h2><p>${JF.from || 'début'} → ${JF.to || today()} · imprimé le ${dateFr(today())}</p></div>
    <div class="group no-print" style="margin-top:14px"><div class="filters">
      <input type="date" id="fFrom" value="${JF.from}" aria-label="Du">
      <input type="date" id="fTo" value="${JF.to}" aria-label="Au">
      <select id="fType" aria-label="Type"><option value="">Tous types</option><option value="in">Entrées</option><option value="out">Sorties</option><option value="bank">Dépôts bancaires</option><option value="transfer">Transferts</option><option value="ex">Change de monnaie</option></select>
      <select id="fCat" aria-label="Catégorie">${catOptions()}</select>
      <input id="fMin" inputmode="decimal" placeholder="Montant min." value="${esc(JF.min)}">
      <input id="fMax" inputmode="decimal" placeholder="Montant max." value="${esc(JF.max)}">
      <input id="fQ" class="search" type="search" placeholder="Rechercher (description, référence…)" value="${esc(JF.q)}">
      <label class="small" style="display:flex;align-items:center;gap:8px;grid-column:span 2"><input type="checkbox" id="fCancel" style="width:auto;min-height:0" ${JF.showCancelled ? 'checked' : ''}> Afficher les opérations annulées</label>
      <button class="btn-plain btn-sm" id="fMonth">Ce mois-ci</button><button class="btn-plain btn-sm" id="fReset">Tout effacer</button>
    </div></div>
    <div class="group" style="margin-top:14px"><div class="table-wrap"><table>
      <thead><tr><th>Date et heure</th><th>Type</th><th class="hide-sm">Catégorie</th><th>Description</th><th class="r">Montant</th><th class="r hide-sm">Variation</th><th class="r">Solde après</th></tr></thead>
      <tbody>${rows.length ? rows.slice().reverse().map(r => `
        <tr class="clickable t-${r.type === 'transfer' ? r.k : r.type}${r.deleted ? ' cancelled' : ''}" data-open="${esc(r.id)}" data-list="${r.list}">
          <td class="num">${dateFr(r.date, { day: '2-digit', month: '2-digit', year: 'numeric' })}${r.time ? ' ' + r.time : ''}</td>
          <td><span class="pill p-${r.type === 'transfer' ? r.k : r.type}">${r.typeLabel}</span></td>
          <td class="hide-sm">${esc(r.cat)}</td>
          <td>${esc(r.label)}${r.ref ? `<div class="small mute">${esc(r.ref)}</div>` : ''}${r.deleted ? '<div class="small">Annulée</div>' : ''}</td>
          <td class="r num">${eur(r.cents)}</td>
          <td class="r num hide-sm ${r.var > 0 ? 'pos' : r.var < 0 ? 'neg' : 'mute'}">${signed(r.var)}</td>
          <td class="r num">${eur(r.after)}</td></tr>`).join('') : '<tr><td colspan="7" class="empty">Aucune opération ne correspond aux filtres.</td></tr>'}</tbody>
      <tfoot><tr><td colspan="7" class="r small">Entrées <span class="pos">${eur(tot.in)}</span> · Sorties <span class="neg">${eur(tot.out)}</span> · Dépôts <span style="color:var(--blue)">${eur(tot.bank)}</span></td></tr></tfoot>
    </table></div></div>
    <p class="small mute" style="margin:10px 4px">Couleurs : <span class="pos">vert</span> entrée · <span class="neg">rouge</span> sortie · <span style="color:var(--blue)">bleu</span> dépôt bancaire · <span style="color:var(--gray)">gris</span> échange de monnaie. Les opérations annulées ne comptent pas dans les soldes.</p>`;
  $('fType').value = JF.type; $('fCat').value = JF.cat;
  const bind = (id, key, ev = 'input') => $(id).addEventListener(ev, e => { JF[key] = e.target.type === 'checkbox' ? e.target.checked : e.target.value; clearTimeout(bind.t); bind.t = setTimeout(() => { const pos = document.activeElement?.id; renderJournal(); if (pos && $(pos)) { $(pos).focus(); try { const l = $(pos).value.length; $(pos).setSelectionRange?.(l, l); } catch { } } }, ev === 'input' ? 250 : 0); });
  bind('fFrom', 'from', 'change'); bind('fTo', 'to', 'change'); bind('fType', 'type', 'change'); bind('fCat', 'cat', 'change');
  bind('fMin', 'min'); bind('fMax', 'max'); bind('fQ', 'q'); bind('fCancel', 'showCancelled', 'change');
  $('fMonth').onclick = () => { const m = ym(today()); JF.from = m + '-01'; JF.to = monthEnd(m); renderJournal(); };
  $('fReset').onclick = () => { JF = { from: '', to: '', type: '', cat: '', min: '', max: '', q: '', showCancelled: false }; renderJournal(); };
  $('jCsv').onclick = () => exportJournalCsv(rows);
  $('jPrint').onclick = () => window.print();
  wire(v);
}

/* ================= Exports ================= */
function csvCell(v) { v = String(v ?? ''); return /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }
function csvMoney(c) { return (c / 100).toFixed(2).replace('.', ','); }
function download(name, text, type = 'text/csv;charset=utf-8') {
  const blob = new Blob([type.startsWith('text/csv') ? '﻿' + text : text], { type });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.append(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
function fileStamp() { return (REG === 'camping' ? 'Camping' : 'Le76') + '-' + today(); }
function exportJournalCsv(rows) {
  const head = ['Date', 'Heure', 'Type', 'Catégorie', 'Description', 'Référence / justificatif', 'Montant (€)', 'Variation du solde (€)', 'Solde après (€)', 'Statut', 'Identifiant'];
  const lines = rows.map(r => [r.date, r.time, r.typeLabel, r.cat, r.label, r.ref, csvMoney(r.cents), csvMoney(r.var), csvMoney(r.after), r.deleted ? 'Annulée' : 'Active', r.id].map(csvCell).join(';'));
  download(`Journal-${fileStamp()}.csv`, [head.join(';'), ...lines].join('\r\n'));
}

/* ================= Mois : vues, comparaison, clôture ================= */
function renderMonths() {
  const t = today(), m = PERIOD, from = m + '-01', to = m === ym(t) ? t : monthEnd(m);
  const closed = closedMonths()[m];
  // découpage jour / semaine / mois
  const chunks = [];
  if (MONTH_VIEW === 'days') for (let d = from; d <= to; d = addDays(d, 1)) chunks.push([d, d, dateFr(d, { weekday: 'short', day: 'numeric', month: 'short' })]);
  else if (MONTH_VIEW === 'weeks') { let d = from; while (d <= to) { const wd = (new Date(d + 'T12:00:00').getDay() + 6) % 7; let e = addDays(d, 6 - wd); if (e > to) e = to; chunks.push([d, e, `Du ${dateFr(d, { day: 'numeric', month: 'short' })} au ${dateFr(e, { day: 'numeric', month: 'short' })}`]); d = addDays(e, 1); } }
  else chunks.push([from, to, monthFr(m)]);
  const rows = chunks.map(([a, b, l]) => ({ l, ...periodStats(a, b) })).filter(r => MONTH_VIEW !== 'days' || r.count);
  // comparaison des 12 derniers mois
  const comp = []; let cm = ym(t);
  for (let i = 0; i < 12; i++) { if (monthEnd(cm) < S.opening.date) break; const a = cm + '-01', b = cm === ym(t) ? t : monthEnd(cm); const c = S.checks.filter(x => !x.deleted && ym(x.date) === cm).sort((x, y) => (x.date + x.at).localeCompare(y.date + y.at)).pop(); comp.push({ m: cm, ...periodStats(a, b), chk: c, closed: closedMonths()[cm] }); cm = prevMonth(cm); }
  const maxIn = Math.max(1, ...comp.map(c => Math.max(c.ins, c.outs + c.bank)));
  const checks = S.checks.filter(c => ym(c.date) === m).sort((a, b) => (b.date + b.at).localeCompare(a.date + a.at));
  const exch = S.exchanges.filter(x => ym(x.date) === m && !x.deleted);
  const st = periodStats(from, to);
  const closable = m < ym(t) && monthEnd(m) >= S.opening.date;
  $('view-months').innerHTML = `
    <div class="view-head"><div><h1>${monthFr(m)[0].toUpperCase() + monthFr(m).slice(1)}</h1><div class="sub">${REGISTERS[REG]} · ${closed ? `<span class="pill p-bank">Clôturé</span> le ${dateFr(closed.at.slice(0, 10))} par ${esc(closed.by)}` : 'Mois ouvert'}</div></div>
      <div class="toolbar no-print"><button class="btn-plain btn-sm" data-period="-1">‹</button>${m < ym(t) ? '<button class="btn-plain btn-sm" data-period="1">›</button>' : ''}<button class="btn-plain btn-sm" id="mCsv">Exporter le bilan (CSV)</button><button class="btn-plain btn-sm" onclick="window.print()">Imprimer / PDF</button></div></div>
    <div class="print-only"><h2>Bilan mensuel des espèces · ${REGISTERS[REG]} · ${monthFr(m)}</h2></div>
    <div class="stats" style="margin-top:14px">
      <div class="stat"><div class="k">Solde d’ouverture</div><div class="v">${eur(st.opening)}</div></div>
      <div class="stat"><div class="k">Entrées</div><div class="v pos">${eur(st.ins)}</div></div>
      <div class="stat"><div class="k">Sorties</div><div class="v neg">${eur(st.outs)}</div></div>
      <div class="stat"><div class="k">Dépôts bancaires</div><div class="v" style="color:var(--blue)">${eur(st.bank)}</div></div>
      <div class="stat wide"><div class="k">${m === ym(t) ? 'Solde théorique actuel' : 'Solde de fin de mois (reporté au mois suivant)'}</div><div class="v">${eur(st.closing)}</div>${st.pending ? `<div class="small mute">${eur(st.pending)} de dépôts préparés pas encore sortis de la caisse</div>` : ''}</div>
      <div class="stat wide no-print"><div class="k">Clôture mensuelle</div>${closed ? `<div class="small" style="margin:6px 0">Clôturé avec un solde de <b>${eur(closed.cents)}</b>. Plus aucune saisie possible sur ce mois.</div>${isAdmin() ? '<button class="btn-soft btn-sm" id="mReopen">Rouvrir ce mois…</button>' : ''}` : closable ? `<div class="small" style="margin:6px 0">Bloque les saisies et corrections sur ${monthFr(m)}.</div>${isAdmin() ? '<button class="btn-soft btn-sm" id="mClose">Clôturer ' + monthFr(m) + '</button>' : '<div class="small mute">Réservé au responsable.</div>'}` : '<div class="small mute" style="margin-top:6px">Le mois en cours ne peut être clôturé qu’une fois terminé.</div>'}</div>
    </div>
    <div class="group-title" style="display:flex;justify-content:space-between;align-items:center"><span>Détail</span>
      <span class="seg no-print" style="text-transform:none" id="mSeg"><button data-v="days" class="${MONTH_VIEW === 'days' ? 'on' : ''}">Jours</button><button data-v="weeks" class="${MONTH_VIEW === 'weeks' ? 'on' : ''}">Semaines</button><button data-v="month" class="${MONTH_VIEW === 'month' ? 'on' : ''}">Mois</button></span></div>
    <div class="group"><div class="table-wrap"><table>
      <thead><tr><th>Période</th><th class="r">Ouverture</th><th class="r">Entrées</th><th class="r">Sorties</th><th class="r">Dépôts</th><th class="r">Solde</th></tr></thead>
      <tbody>${rows.length ? rows.map(r => `<tr><td>${esc(r.l)}</td><td class="r num">${eur(r.opening)}</td><td class="r num pos">${eur(r.ins)}</td><td class="r num neg">${eur(r.outs)}</td><td class="r num" style="color:var(--blue)">${eur(r.bank)}</td><td class="r num"><b>${eur(r.closing)}</b></td></tr>`).join('') : '<tr><td colspan="6" class="empty">Aucune opération sur ce mois.</td></tr>'}</tbody>
    </table></div></div>
    <div class="grid2">
      <div><div class="group-title">Comptages du mois</div><div class="group">${checks.length ? checks.map(c => `<button class="li" data-open="${esc(c.id)}" data-list="checks"><span class="dot" style="background:var(--orange)"></span><span class="main"><div class="t${c.deleted ? ' mute' : ''}">${dateFr(c.date)} · ${localTime(c.at)} · compté ${eur(c.counted)}${c.deleted ? ' (annulé)' : ''}</div><div class="d">Théorique ${eur(c.expected)}${c.note ? ' · ' + esc(c.note) : ''}</div></span><b class="num ${c.counted - c.expected < 0 ? 'neg' : c.counted - c.expected > 0 ? 'pos' : ''}">${signed(c.counted - c.expected)}</b></button>`).join('') : '<div class="empty">Aucun comptage ce mois-ci.</div>'}</div></div>
      <div><div class="group-title">Échanges de monnaie du mois</div><div class="group">${exch.length ? exch.map(x => `<button class="li" data-open="${esc(x.id)}" data-list="exchanges"><span class="dot d-ex"></span><span class="main"><div class="t">${dateFr(x.date)} · ${eur(x.cents)}</div><div class="d">${esc(describeUnits(x.given))} → ${esc(describeUnits(x.received))}</div></span></button>`).join('') : '<div class="empty">Aucun échange ce mois-ci.</div>'}</div></div>
    </div>
    <div class="group-title">Comparaison des derniers mois</div>
    <div class="group"><div class="table-wrap"><table>
      <thead><tr><th>Mois</th><th class="r">Ouverture</th><th class="r">Entrées</th><th class="r">Sorties</th><th class="r">Dépôts</th><th class="r">Fin de mois</th><th class="r hide-sm">Écart compté</th><th class="hide-sm" style="width:140px">Entrées / sorties</th></tr></thead>
      <tbody>${comp.map(c => `<tr class="clickable" data-month="${c.m}"><td>${monthFr(c.m)}${c.closed ? ' <span class="pill p-bank">clôturé</span>' : ''}</td><td class="r num">${eur(c.opening)}</td><td class="r num pos">${eur(c.ins)}</td><td class="r num neg">${eur(c.outs)}</td><td class="r num" style="color:var(--blue)">${eur(c.bank)}</td><td class="r num"><b>${eur(c.closing)}</b></td><td class="r num hide-sm ${c.chk ? (c.chk.counted - c.chk.expected < 0 ? 'neg' : c.chk.counted - c.chk.expected > 0 ? 'pos' : '') : 'mute'}">${c.chk ? signed(c.chk.counted - c.chk.expected) : '—'}</td><td class="hide-sm"><div class="bar" title="Entrées"><i style="width:${Math.round(c.ins / maxIn * 100)}%;background:var(--green)"></i></div><div class="bar" style="margin-top:3px" title="Sorties et dépôts"><i style="width:${Math.round((c.outs + c.bank) / maxIn * 100)}%;background:var(--red)"></i></div></td></tr>`).join('')}</tbody>
    </table></div></div>`;
  const v = $('view-months');
  v.querySelectorAll('#mSeg button').forEach(b => b.onclick = () => { MONTH_VIEW = b.dataset.v; renderMonths(); });
  v.querySelectorAll('[data-month]').forEach(tr => tr.onclick = () => { PERIOD = tr.dataset.month; renderMonths(); window.scrollTo(0, 0); });
  $('mCsv').onclick = () => exportMonthCsv(m, rows, st);
  if ($('mClose')) $('mClose').onclick = () => confirmClose(m, st.closing);
  if ($('mReopen')) $('mReopen').onclick = () => reopenMonth(m);
  wire(v);
}
function exportMonthCsv(m, rows, st) {
  const lines = [['Bilan mensuel des espèces', REGISTERS[REG], monthFr(m)], [], ['Solde d’ouverture', csvMoney(st.opening)], ['Entrées', csvMoney(st.ins)], ['Sorties', csvMoney(st.outs)], ['Dépôts bancaires', csvMoney(st.bank)], ['Solde de fin', csvMoney(st.closing)], [],
    ['Période', 'Ouverture', 'Entrées', 'Sorties', 'Dépôts', 'Solde'], ...rows.map(r => [r.l, csvMoney(r.opening), csvMoney(r.ins), csvMoney(r.outs), csvMoney(r.bank), csvMoney(r.closing)]), [],
    ['Opérations du mois'], ['Date', 'Heure', 'Type', 'Catégorie', 'Description', 'Référence', 'Variation', 'Solde après', 'Statut'],
    ...journalRows().filter(r => ym(r.date) === m).map(r => [r.date, r.time, r.typeLabel, r.cat, r.label, r.ref, csvMoney(r.var), csvMoney(r.after), r.deleted ? 'Annulée' : 'Active'])];
  download(`Bilan-${m}-${REG === 'camping' ? 'Camping' : 'Le76'}.csv`, lines.map(l => l.map(csvCell).join(';')).join('\r\n'));
}
function confirmClose(m, cents) {
  openSheet('Clôturer ' + monthFr(m), `
    <div class="recap"><div><span>Solde de fin de mois</span><b>${eur(cents)}</b></div></div>
    <p class="small mute">Après la clôture, plus aucune opération ne peut être ajoutée, corrigée ou annulée sur ${monthFr(m)}. Le solde de fin est reporté au mois suivant. Tu pourras rouvrir le mois avec un motif si besoin.</p>
    <button class="btn-main" id="okClose">Clôturer ${monthFr(m)}</button>`);
  $('okClose').onclick = async () => {
    try { const j = await api('close-month', { month: m }); S = j.state; REV = j.revision; closeSheet(); toast(monthFr(m) + ' est clôturé.'); render(); } catch (e) { toast(e.message, true); }
  };
}
function reopenMonth(m) {
  openSheet('Rouvrir ' + monthFr(m), `<label class="field"><span>Motif (obligatoire)</span><input id="rReason" maxlength="200" placeholder="Ex. facture oubliée"></label><button class="btn-main" id="okReopen">Rouvrir le mois</button>`);
  $('okReopen').onclick = async () => {
    try { const j = await api('reopen-month', { month: m, reason: $('rReason').value.trim() }); S = j.state; REV = j.revision; closeSheet(); toast(monthFr(m) + ' est rouvert.'); render(); } catch (e) { toast(e.message, true); }
  };
}

/* ================= Feuille de saisie ================= */
function openSheet(title, html) {
  $('sheetTitle').textContent = title; $('sheetBody').innerHTML = html;
  $('sheet').hidden = false; $('sheetBack').hidden = false;
  setTimeout(() => $('sheetBody').querySelector('input:not([type=date]):not([type=time]),select,textarea')?.focus({ preventScroll: true }), 60);
}
function closeSheet() { $('sheet').hidden = true; $('sheetBack').hidden = true; $('sheetBody').innerHTML = ''; }
$('sheetClose').onclick = closeSheet; $('sheetBack').onclick = closeSheet;
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('sheet').hidden) closeSheet(); });

function chips(name, options, value) {
  return `<div class="chips" data-chips="${name}">${Object.entries(options).map(([k, v]) => `<button type="button" class="chip${k === value ? ' on' : ''}" data-v="${k}">${v}</button>`).join('')}</div><input type="hidden" id="${name}" value="${value}">`;
}
function wireChips(root, onChange) {
  root.querySelectorAll('[data-chips]').forEach(box => box.querySelectorAll('.chip').forEach(c => c.onclick = () => {
    box.querySelectorAll('.chip').forEach(x => x.classList.toggle('on', x === c)); $(box.dataset.chips).value = c.dataset.v; onChange?.();
  }));
}
function dateTimeFields(date, time, { noTime = false } = {}) {
  return `<div class="fields2"><label class="field"><span>Date</span><input type="date" id="fDate" value="${date}" max="${today()}" required></label>${noTime ? '' : `<label class="field"><span>Heure</span><input type="time" id="fTime" value="${time}"></label>`}</div>`;
}
function guardDate(d) {
  if (!d || !/^\d{4}-\d{2}-\d{2}$/.test(d)) throw new Error('Choisis une date.');
  if (d < S.opening.date) throw new Error('La date précède le point de départ de la caisse (' + dateFr(S.opening.date) + ').');
  if (isClosed(d)) throw new Error('Le mois de ' + monthFr(ym(d)) + ' est clôturé.');
}

function openForm(kind, editId) {
  if (!canWrite()) return toast('Ton compte permet seulement la consultation.', true);
  const e = editId ? S.entries.find(x => x.id === editId) : null;
  if (kind === 'in' || kind === 'out') return entryForm(kind, e);
  if (kind === 'bank') return bankForm(e);
  if (kind === 'ex') return exchangeForm();
  if (kind === 'count') return countForm();
  if (kind === 'transfer') return transferForm();
}

function entryForm(kind, e) {
  const isIn = kind === 'in', cats = isIn ? INCOME : EXPENSE;
  const cat = e ? (isIn ? e.incomeCategory || 'sales' : (EXPENSE[e.expenseCategory] ? e.expenseCategory : 'other')) : (isIn ? 'sales' : 'supplier');
  openSheet(e ? 'Corriger l’opération' : isIn ? 'Entrée d’espèces' : 'Sortie d’espèces', `
    <label class="field"><span>Montant</span><input id="fAmount" class="money-in" inputmode="decimal" placeholder="0,00 €" value="${e ? centsToInput(e.cents) : ''}"></label>
    <div class="group-title" style="margin:6px 4px 8px">Catégorie</div>${chips('fCat', cats, cat)}
    ${dateTimeFields(e ? e.date : today(), e ? e.time || '' : nowTime())}
    <label class="field"><span>Description${isIn ? ' (facultatif)' : ''}</span><input id="fLabel" maxlength="200" value="${esc(e?.label || '')}" placeholder="${isIn ? 'Ex. recette du midi' : 'Ex. Metro, ampoules…'}"></label>
    <label class="field"><span>${isIn ? 'Référence ou justificatif (facultatif)' : 'Justificatif (facultatif)'}</span><input id="fRef" maxlength="200" value="${esc(e?.ref || '')}" placeholder="N° de ticket, facture…"></label>
    ${e ? '<label class="field"><span>Motif de la correction (obligatoire)</span><input id="fReason" maxlength="200"></label>' : ''}
    <div class="recap" id="fRecap"></div>
    <button class="btn-main" id="fSave">${e ? 'Enregistrer la correction' : isIn ? 'Enregistrer l’entrée' : 'Enregistrer la sortie'}</button>`);
  const recap = () => {
    let c = 0; try { c = parseCents($('fAmount').value); } catch { }
    const now = balanceAt(today()) - (e ? effect(e) : 0);
    $('fRecap').innerHTML = `<div><span>Solde actuel</span><b>${eur(now + (e ? effect(e) : 0))}</b></div><div><span>Après l’opération</span><b class="${now + (isIn ? c : -c) < 0 ? 'neg' : ''}">${eur(now + (isIn ? c : -c))}</b></div>`;
  };
  wireChips($('sheetBody')); $('fAmount').oninput = recap; recap();
  $('fSave').onclick = async () => {
    try {
      const cents = parseCents($('fAmount').value), date = $('fDate').value, time = $('fTime').value, label = $('fLabel').value.trim(), ref = $('fRef').value.trim();
      guardDate(date);
      if (!isIn && !label) throw new Error('Ajoute une description pour la sortie.');
      if (time && !/^\d{2}:\d{2}$/.test(time)) throw new Error('Heure invalide.');
      const reason = e ? $('fReason').value.trim() : '';
      if (e && !reason) throw new Error('Indique le motif de la correction.');
      const item = { ...(e ? clone(e) : { id: uid(), deleted: false }), date, kind: isIn ? 'in' : 'out', category: 'cash', cents, label };
      delete item.banknotes;
      if (time) item.time = time; else delete item.time;
      if (ref) item.ref = ref; else delete item.ref;
      if (isIn) { item.incomeCategory = $('fCat').value; delete item.expenseCategory; } else { item.expenseCategory = $('fCat').value; delete item.incomeCategory; }
      if (await commit(n => { if (e) n.entries[n.entries.findIndex(x => x.id === e.id)] = item; else n.entries.push(item); }, { reason })) {
        closeSheet(); toast(e ? 'Correction enregistrée.' : (isIn ? 'Entrée' : 'Sortie') + ' de ' + eur(cents) + ' enregistrée.');
      }
    } catch (err) { toast(err.message, true); }
  };
}

function bankForm(e) {
  const status = e ? e.bankStatus || 'deposited' : 'deposited';
  openSheet(e ? 'Corriger le dépôt' : 'Dépôt bancaire', `
    <label class="field"><span>Montant déposé</span><input id="fAmount" class="money-in" inputmode="decimal" placeholder="0,00 €" value="${e ? centsToInput(e.cents) : ''}"></label>
    <div class="group-title" style="margin:6px 4px 8px">Statut</div>${chips('fStatus', e ? BANK : { prepared: 'Préparé', deposited: 'Déposé', credited: 'Crédité' }, status)}
    <p class="small mute" id="fStatusHelp"></p>
    ${dateTimeFields(e ? e.date : today(), e ? e.time || '' : nowTime())}
    <div class="fields2"><label class="field"><span>Banque</span><input id="fBank" maxlength="100" value="${esc(e?.bankName || '')}" placeholder="Ex. Crédit Mutuel"></label>
    <label class="field"><span>Référence du dépôt</span><input id="fRef" maxlength="200" value="${esc(e?.ref || '')}" placeholder="N° de bordereau"></label></div>
    <label class="field"><span>Commentaire (facultatif)</span><input id="fLabel" maxlength="200" value="${esc(e?.label || '')}"></label>
    ${e ? '<label class="field"><span>Motif de la correction (obligatoire)</span><input id="fReason" maxlength="200"></label>' : ''}
    <div class="recap" id="fRecap"></div>
    <button class="btn-main" id="fSave">${e ? 'Enregistrer la correction' : 'Enregistrer le dépôt'}</button>`);
  const recap = () => {
    let c = 0; try { c = parseCents($('fAmount').value); } catch { }
    const s = $('fStatus').value, out = ['deposited', 'credited'].includes(s) ? c : 0, now = balanceAt(today()) - (e ? effect(e) : 0);
    $('fStatusHelp').textContent = { prepared: 'Préparé : les espèces sont encore dans la caisse, le solde ne change pas.', deposited: 'Déposé : les espèces ont quitté la caisse, le solde diminue.', credited: 'Crédité : la banque a crédité le compte. Pas de seconde sortie de caisse.', cancelled: 'Annulé : le dépôt n’a pas eu lieu, aucun effet sur la caisse.' }[s];
    $('fRecap').innerHTML = `<div><span>Solde de caisse</span><b>${eur(now + (e ? effect(e) : 0))}</b></div><div><span>Après ce dépôt</span><b class="${now - out < 0 ? 'neg' : ''}">${eur(now - out)}</b></div>`;
  };
  wireChips($('sheetBody'), recap); $('fAmount').oninput = recap; recap();
  $('fSave').onclick = async () => {
    try {
      const cents = parseCents($('fAmount').value), date = $('fDate').value; guardDate(date);
      const reason = e ? $('fReason').value.trim() : '';
      if (e && !reason) throw new Error('Indique le motif de la correction.');
      const item = { ...(e ? clone(e) : { id: uid(), deleted: false }), date, kind: 'out', category: 'bank', cents, bankStatus: $('fStatus').value, label: $('fLabel').value.trim() || 'Dépôt bancaire' };
      const bank = $('fBank').value.trim(), ref = $('fRef').value.trim(), time = $('fTime').value;
      if (time) item.time = time; else delete item.time;
      if (bank) item.bankName = bank; else delete item.bankName;
      if (ref) item.ref = ref; else delete item.ref;
      if (await commit(n => { if (e) n.entries[n.entries.findIndex(x => x.id === e.id)] = item; else n.entries.push(item); }, { reason })) { closeSheet(); toast('Dépôt de ' + eur(cents) + ' enregistré (' + BANK[item.bankStatus].toLowerCase() + ').'); }
    } catch (err) { toast(err.message, true); }
  };
}
async function setBankStatus(e, status) {
  if (await commit(n => { const x = n.entries.find(y => y.id === e.id); x.bankStatus = status; })) { closeSheet(); toast('Dépôt passé à « ' + BANK[status] + ' ».'); }
}

function denomGrid(prefix, keys, values = {}) {
  let html = '', group = '';
  for (const k of keys) {
    const u = UNIT[k];
    if (u[3] !== group) { group = u[3]; html += `<div class="denom-group">${group}</div>`; }
    html += `<div class="denom"><span>${u[2]}</span><input type="number" min="0" step="1" inputmode="numeric" data-g="${prefix}" data-k="${k}" value="${values[k] || ''}" placeholder="0"><span class="sum" id="${prefix}-${k}"></span></div>`;
  }
  return `<div class="denoms">${html}</div>`;
}
function readGrid(prefix) {
  const out = {}; let total = 0;
  document.querySelectorAll(`[data-g="${prefix}"]`).forEach(i => {
    const raw = i.value.trim(); if (!raw) return;
    if (!/^\d{1,6}$/.test(raw)) throw new Error('Nombre invalide pour : ' + UNIT[i.dataset.k][2]);
    const q = Number(raw); if (q > 0) { out[i.dataset.k] = q; total += q * UNIT[i.dataset.k][1]; }
  });
  return { items: out, total };
}

function exchangeForm() {
  const keys = UNITS.map(u => u[0]);
  openSheet('Faire de la monnaie', `
    <p class="small mute">Billets contre pièces ou rouleaux (ou l’inverse). Le solde de la caisse ne change pas : les deux totaux doivent être identiques.</p>
    ${dateTimeFields(today(), '', { noTime: true })}
    <div class="ex-cols">
      <div><h3>Je donne</h3>${denomGrid('give', keys)}</div>
      <div><h3>Je reçois</h3>${denomGrid('get', keys)}</div>
    </div>
    <label class="field" style="margin-top:14px"><span>Commentaire (facultatif)</span><input id="fLabel" maxlength="200" placeholder="Ex. banque, commerçant voisin"></label>
    <div class="sticky-foot"><div class="recap" id="fRecap"></div>
    <button class="btn-main" id="fSave" disabled>Enregistrer l’échange</button></div>`);
  const recap = () => {
    let g = 0, r = 0, err = '';
    try { g = readGrid('give').total; r = readGrid('get').total; } catch (x) { err = x.message; }
    const ok = !err && g > 0 && g === r;
    $('fRecap').innerHTML = `<div><span>Total remis</span><b>${eur(g)}</b></div><div><span>Total reçu</span><b>${eur(r)}</b></div><div><span>${ok ? 'Équilibré · solde inchangé' : err || (g || r ? 'Différence' : 'Saisis les deux côtés')}</span><b class="${ok ? 'pos' : 'neg'}">${ok ? '✓' : g || r ? signed(r - g) : ''}</b></div>`;
    $('fSave').disabled = !ok;
  };
  $('sheetBody').querySelectorAll('[data-g]').forEach(i => i.oninput = recap); recap();
  $('fSave').onclick = async () => {
    try {
      const date = $('fDate').value; guardDate(date);
      const give = readGrid('give'), get = readGrid('get');
      if (give.total <= 0 || give.total !== get.total) throw new Error('Le total remis doit être égal au total reçu.');
      const x = { id: uid(), date, at: new Date().toISOString(), cents: give.total, given: give.items, received: get.items, note: $('fLabel').value.trim(), deleted: false };
      if (await commit(n => n.exchanges.push(x))) { closeSheet(); toast('Échange de ' + eur(give.total) + ' enregistré. Solde inchangé.'); }
    } catch (err) { toast(err.message, true); }
  };
}

function countForm() {
  const keys = UNITS.map(u => u[0]);
  openSheet('Compter ma caisse', `
    ${dateTimeFields(today(), '', { noTime: true })}
    <p class="small mute">Indique le nombre de billets, de pièces et de rouleaux fermés. Ne compte pas un rouleau et ses pièces deux fois.</p>
    ${denomGrid('cnt', keys)}
    <label class="field" style="margin-top:14px"><span>Remarque (facultatif)</span><input id="fLabel" maxlength="200"></label>
    <div class="sticky-foot"><div class="recap" id="fRecap"></div>
    <button class="btn-main" id="fSave">Enregistrer le comptage</button></div>
    <p class="small mute" style="margin-top:10px">Le comptage ne corrige jamais le solde théorique. Pour régulariser un écart, saisis une entrée ou une sortie explicite, avec son motif.</p>`);
  const recap = () => {
    let total = 0, err = '';
    try { total = readGrid('cnt').total; } catch (x) { err = x.message; }
    document.querySelectorAll('[data-g="cnt"]').forEach(i => { const q = Number(i.value) || 0; $('cnt-' + i.dataset.k).textContent = q ? eur(q * UNIT[i.dataset.k][1]) : ''; });
    const d = $('fDate').value || today(), exp = balanceAt(d), diff = total - exp;
    $('fRecap').innerHTML = `<div><span>Solde théorique au ${dateFr(d)}</span><b>${eur(exp)}</b></div><div><span>Total compté</span><b>${err ? '—' : eur(total)}</b></div><div><span>Écart</span><b class="${diff < 0 ? 'neg' : diff > 0 ? 'pos' : ''}">${signed(diff)}</b></div>`;
  };
  $('sheetBody').querySelectorAll('[data-g]').forEach(i => i.oninput = recap); $('fDate').onchange = recap; recap();
  $('fSave').onclick = async () => {
    try {
      const date = $('fDate').value; guardDate(date);
      const { items, total } = readGrid('cnt');
      const banknotes = {}; const coins = {};
      for (const [k, v] of Object.entries(NOTE_KEYS)) banknotes[v] = items[k] || 0;
      for (const [k, q] of Object.entries(items)) if (!NOTE_KEYS[k]) coins[k] = q;
      const c = { id: uid(), date, at: new Date().toISOString(), expected: balanceAt(date), counted: total, banknotes, coins, otherCoinsCents: 0, note: $('fLabel').value.trim(), deleted: false };
      if (await commit(n => n.checks.push(c))) {
        closeSheet(); const diff = total - c.expected;
        toast(diff === 0 ? 'Comptage enregistré : la caisse est juste.' : 'Comptage enregistré. Écart de ' + signed(diff) + '.', diff !== 0);
      }
    } catch (err) { toast(err.message, true); }
  };
}

async function transferForm() {
  const other = ALLOWED.find(r => r !== REG);
  openSheet('Transférer des espèces', `
    <div class="fields2"><label class="field"><span>De</span><select id="tFrom">${ALLOWED.map(r => `<option value="${r}" ${r === REG ? 'selected' : ''}>${REGISTERS[r]}</option>`).join('')}</select></label>
    <label class="field"><span>Vers</span><select id="tTo">${ALLOWED.map(r => `<option value="${r}" ${r === other ? 'selected' : ''}>${REGISTERS[r]}</option>`).join('')}</select></label></div>
    <label class="field"><span>Montant</span><input id="fAmount" class="money-in" inputmode="decimal" placeholder="0,00 €"></label>
    ${dateTimeFields(today(), '', { noTime: true })}
    <label class="field"><span>Motif</span><input id="fLabel" maxlength="200" placeholder="Ex. fond de caisse du camping"></label>
    <div class="recap" id="fRecap">Chargement des soldes…</div>
    <button class="btn-main" id="fSave">Transférer</button>`);
  let ctx = null;
  const refresh = async () => { try { ctx = await api('transfer-context', null, '&date=' + ($('fDate').value || today())); $('fRecap').innerHTML = Object.entries(ctx.balances).map(([r, c]) => `<div><span>${REGISTERS[r]}</span><b>${eur(c)}</b></div>`).join(''); } catch (e) { $('fRecap').textContent = e.message; } };
  $('fDate').onchange = refresh; refresh();
  $('fSave').onclick = async () => {
    try {
      const cents = parseCents($('fAmount').value), date = $('fDate').value, src = $('tFrom').value, dst = $('tTo').value;
      if (src === dst) throw new Error('Choisis deux caisses différentes.');
      if (!ctx) throw new Error('Soldes non chargés.');
      await api('transfer', { request: uid(), source: src, destination: dst, date, cents, note: $('fLabel').value.trim(), revisions: ctx.revisions });
      await loadState(); closeSheet(); render(); toast('Transfert de ' + eur(cents) + ' enregistré dans les deux caisses.');
    } catch (err) { toast(err.message, true); await refresh(); }
  };
}

/* ================= Détail d'une opération ================= */
function openDetail(id, list) {
  const item = (S[list] || []).find(x => x.id === id);
  if (!item) return;
  const closed = isClosed(item.date), w = canWrite() && !item.deleted && !closed;
  let rows = '', actions = '';
  if (list === 'entries') {
    const e = item;
    rows = [['Type', typeLabel(e)], ['Catégorie', catLabel(e)], ['Date', dateFr(e.date) + (e.time ? ' à ' + e.time : '')], ['Montant', eur(e.cents)], ['Effet sur la caisse', signed(effect(e))],
      e.bankName ? ['Banque', e.bankName] : null, e.ref ? ['Référence', e.ref] : null, e.label ? ['Description', e.label] : null, e.transferNote ? ['Motif', e.transferNote] : null, ['Statut', e.deleted ? 'Annulée' : 'Active'], ['Identifiant', e.id]].filter(Boolean);
    if (w && e.category === 'bank' && BANK_NEXT[e.bankStatus || 'deposited']) actions += BANK_NEXT[e.bankStatus || 'deposited'].map(s => `<button class="btn-soft" data-status="${s}">Passer à « ${BANK[s]} »</button>`).join('');
    if (w && e.category !== 'transfer') actions += `<button class="btn-plain" id="dEdit">Corriger</button><button class="btn-danger" id="dCancel">Annuler l’opération</button>`;
    if (w && e.category === 'transfer' && isAdmin()) actions += `<button class="btn-danger" id="dCancelTr">Annuler le transfert (deux caisses)</button>`;
  } else if (list === 'exchanges') {
    rows = [['Date', dateFr(item.date) + ' à ' + localTime(item.at)], ['Montant échangé', eur(item.cents)], ['Remis', describeUnits(item.given)], ['Reçu', describeUnits(item.received)], ['Effet sur la caisse', 'Aucun (solde inchangé)'], item.note ? ['Commentaire', item.note] : null, ['Statut', item.deleted ? 'Annulé' : 'Actif']].filter(Boolean);
    if (w) actions = `<button class="btn-danger" id="dCancel">Annuler l’échange</button>`;
  } else {
    rows = [['Date', dateFr(item.date) + ' à ' + localTime(item.at)], ['Solde théorique', eur(item.expected)], ['Compté', eur(item.counted)], ['Écart', signed(item.counted - item.expected)], item.note ? ['Remarque', item.note] : null, ['Statut', item.deleted ? 'Annulé' : 'Actif']].filter(Boolean);
    if (item.banknotes) { const all = { ...Object.fromEntries(Object.entries(item.banknotes).map(([v, q]) => ['b' + v, q])), ...(item.coins || {}) }; rows.push(['Détail', describeUnits(all) + (item.otherCoinsCents ? ' + autres pièces ' + eur(item.otherCoinsCents) : '')]); }
    if (w) actions = `<button class="btn-danger" id="dCancel">Annuler ce comptage</button>`;
  }
  openSheet(list === 'entries' ? typeLabel(item) : list === 'exchanges' ? 'Change de monnaie' : 'Comptage', `
    <div class="group">${rows.map(([k, v]) => `<div class="row"><span class="mute">${k}</span><span class="val">${esc(v)}</span></div>`).join('')}</div>
    ${closed ? `<p class="small mute" style="margin-top:12px">Le mois de ${monthFr(ym(item.date))} est clôturé : cette opération ne peut plus être modifiée.</p>` : ''}
    <div style="display:grid;gap:8px;margin-top:16px">${actions}</div>`);
  const b = $('sheetBody');
  b.querySelectorAll('[data-status]').forEach(x => x.onclick = () => setBankStatus(item, x.dataset.status));
  if ($('dEdit')) $('dEdit').onclick = () => item.category === 'bank' ? bankForm(item) : entryForm(item.kind, item);
  if ($('dCancel')) $('dCancel').onclick = () => askReason('Annuler', async reason => commit(n => { n[list].find(x => x.id === id).deleted = true; }, { reason }));
  if ($('dCancelTr')) $('dCancelTr').onclick = () => askReason('Annuler le transfert', async reason => {
    try { const ctx = await api('transfer-context', null, '&date=' + today()); await api('cancel-transfer', { request: uid(), transferId: item.transferId, reason, revisions: ctx.revisions }); await loadState(); render(); return true; } catch (e) { toast(e.message, true); return false; }
  });
}
function askReason(title, run) {
  openSheet(title, `<p class="small mute">L’opération reste visible dans le journal (barrée) et dans l’historique. Elle ne compte plus dans les soldes.</p><label class="field"><span>Motif (obligatoire)</span><input id="aReason" maxlength="200"></label><button class="btn-main" id="aOk">${title}</button>`);
  $('aOk').onclick = async () => {
    const r = $('aReason').value.trim(); if (!r) return toast('Indique un motif.', true);
    if (await run(r)) { closeSheet(); toast('Annulation enregistrée.'); }
  };
}

/* ================= Réglages ================= */
function renderSettings() {
  const v = $('view-settings');
  v.innerHTML = `
    <div class="view-head"><div><h1>Réglages</h1><div class="sub">${esc(USER.name)} · ${USER.role === 'admin' ? 'Responsable' : USER.role === 'staff' ? 'Équipe' : 'Consultation'} · ComptaCaisse ${VERSION}</div></div></div>
    <div class="grid2"><div>
      <div class="group-title">Point de départ · ${REGISTERS[REG]}</div>
      <div class="group pad">
        <p class="small mute">Espèces présentes au début de la date choisie. Les opérations de cette date s’ajoutent ensuite.</p>
        <div class="fields2"><label class="field"><span>Date de départ</span><input type="date" id="oDate" value="${S.opening.date}" ${isAdmin() ? '' : 'disabled'}></label>
        <label class="field"><span>Solde initial</span><input id="oCents" inputmode="decimal" value="${centsToInput(S.opening.cents)}" ${isAdmin() ? '' : 'disabled'}></label></div>
        ${isAdmin() ? '<label class="field"><span>Motif du changement</span><input id="oReason" maxlength="200"></label><button class="btn-soft" id="oSave">Enregistrer le point de départ</button>' : '<p class="small mute">Modifiable par le responsable.</p>'}
      </div>
      <div class="group-title">Exports et sauvegardes</div>
      <div class="group">
        <button class="li chev" id="sAudit"><span class="main"><div class="t">Historique complet des modifications (CSV)</div><div class="d">Créations, corrections, annulations, clôtures, restaurations</div></span></button>
        <button class="li chev" id="sBackup"><span class="main"><div class="t">Télécharger une sauvegarde (JSON)</div><div class="d">Toutes les opérations de ${REGISTERS[REG]} et leur historique</div></span></button>
        ${isAdmin() ? `<label class="li chev" style="cursor:pointer"><span class="main"><div class="t">Restaurer une sauvegarde…</div><div class="d">Remplace les opérations actuelles (état actuel conservé en archive)</div></span><input type="file" id="sRestore" accept="application/json,.json" hidden></label>
        <button class="li chev" id="sArchives"><span class="main"><div class="t">Versions précédentes (serveur)</div><div class="d">Une sauvegarde automatique par jour et chaque modification</div></span></button>` : ''}
      </div>
      <div id="archives"></div>
    </div><div>
      <div class="group-title">Mon compte</div>
      <div class="group pad">
        <label class="field"><span>Mot de passe actuel</span><input type="password" id="pCur" autocomplete="current-password"></label>
        <label class="field"><span>Nouveau mot de passe (12 caractères minimum)</span><input type="password" id="pNew" autocomplete="new-password"></label>
        <button class="btn-soft" id="pSave">Changer mon mot de passe</button>
      </div>
      <div class="group" style="margin-top:12px"><button class="li" id="sLogout"><span class="main neg">Se déconnecter</span></button></div>
      ${isAdmin() ? '<div class="group-title">Équipe</div><div id="team"><div class="group"><div class="empty">Chargement…</div></div></div>' : ''}
      ${isAdmin() && ALLOWED.length > 1 ? '<div class="group-title">Transferts récents</div><div id="transfers"><div class="group"><div class="empty">Chargement…</div></div></div>' : ''}
    </div></div>`;
  if ($('oSave')) $('oSave').onclick = async () => {
    try {
      const date = $('oDate').value, cents = parseCents($('oCents').value, { allowZero: true }), reason = $('oReason').value.trim();
      if (!reason) throw new Error('Indique le motif du changement.');
      if (S.entries.some(e => e.date < date) || S.checks.some(e => e.date < date) || S.exchanges.some(e => e.date < date)) throw new Error('Des opérations existent avant cette date.');
      if (await commit(n => { n.opening = { date, cents }; }, { reason })) toast('Point de départ enregistré.');
    } catch (e) { toast(e.message, true); }
  };
  $('sAudit').onclick = () => download(`Historique-${fileStamp()}.csv`, [['Date et heure', 'Action', 'Détail', 'Motif'].join(';'), ...S.audit.map(a => [new Date(a.at).toLocaleString('fr-FR'), a.action, a.detail, a.reason || ''].map(csvCell).join(';'))].join('\r\n'));
  $('sBackup').onclick = () => download(`Sauvegarde-${fileStamp()}.json`, JSON.stringify(S, null, 2), 'application/json');
  if ($('sRestore')) $('sRestore').onchange = ev => restoreFile(ev.target.files[0]);
  if ($('sArchives')) $('sArchives').onclick = loadArchives;
  $('pSave').onclick = async () => {
    try { await api('password', { current: $('pCur').value, password: $('pNew').value }); $('pCur').value = $('pNew').value = ''; toast('Mot de passe changé.'); } catch (e) { toast(e.message, true); }
  };
  $('sLogout').onclick = async () => { try { await api('logout', {}); } catch { } S = null; showLogin(); };
  if (isAdmin()) { loadTeam(); if (ALLOWED.length > 1) loadTransfers(); }
}
async function restoreFile(file) {
  if (!file) return;
  try {
    const data = JSON.parse(await file.text());
    if (data?.version !== 1 || !Array.isArray(data.entries) || !data.opening) throw new Error('Ce fichier n’est pas une sauvegarde ComptaCaisse.');
    if ((data.registerId || 'restaurant') !== REG) throw new Error('Cette sauvegarde appartient à une autre caisse.');
    openSheet('Restaurer une sauvegarde', `<div class="recap"><div><span>Opérations dans le fichier</span><b>${data.entries.length}</b></div><div><span>Solde du fichier</span><b>${eur(data.opening.cents + data.entries.reduce((t, e) => t + effect(e), 0))}</b></div></div>
      <p class="small mute">Les opérations actuelles seront remplacées. L’état actuel reste conservé dans les versions précédentes du serveur.</p>
      <label class="field"><span>Motif (obligatoire)</span><input id="rsReason" maxlength="200"></label><button class="btn-danger" style="width:100%" id="rsOk">Restaurer</button>`);
    $('rsOk').onclick = async () => {
      const reason = $('rsReason').value.trim(); if (!reason) return toast('Indique un motif.', true);
      if (await commit(() => clone(data), { reason, restore: true })) { closeSheet(); toast('Sauvegarde restaurée.'); }
    };
  } catch (e) { toast(e.message, true); }
}
async function loadArchives() {
  try {
    const j = await api('archives');
    $('archives').innerHTML = `<div class="group-title">Versions précédentes</div><div class="group">${j.archives.length ? j.archives.slice(0, 40).map(a => `<button class="li" data-arch="${esc(a.id)}"><span class="main"><div class="t">Révision ${a.revision} · ${esc(a.actor)}</div><div class="d">${new Date(a.created_at.replace(' ', 'T') + 'Z').toLocaleString('fr-FR')}${String(a.id).startsWith('c') ? ' · modification' : ' · sauvegarde du jour'}</div></span><span class="link">Télécharger</span></button>`).join('') : '<div class="empty">Aucune version.</div>'}</div>`;
    $('archives').querySelectorAll('[data-arch]').forEach(b => b.onclick = async () => {
      try { const a = await api('archive', null, '&id=' + encodeURIComponent(b.dataset.arch)); download(`Version-${a.archive.revision}-${fileStamp()}.json`, a.archive.data, 'application/json'); } catch (e) { toast(e.message, true); }
    });
  } catch (e) { toast(e.message, true); }
}
async function loadTeam() {
  try {
    const j = await api('users');
    const roles = { admin: 'Responsable', staff: 'Équipe (saisie)', reader: 'Consultation' };
    $('team').innerHTML = `<div class="group">${j.users.map(u => `<div class="li"><span class="main"><div class="t">${esc(u.name)}${u.active ? '' : ' <span class="pill p-out">désactivé</span>'}</div><div class="d">${roles[u.role] || u.role} · ${u.registers.map(r => REGISTERS[r]).join(', ')}</div></span></div>`).join('')}</div>
      <div class="group pad" style="margin-top:12px">
        <h3>Créer ou modifier un compte</h3>
        <div class="fields2"><label class="field"><span>Identifiant</span><input id="uName" autocapitalize="none" placeholder="ex. vanessa"></label>
        <label class="field"><span>Action</span><select id="uMode"><option value="create">Créer</option><option value="reset">Nouveau mot de passe / réactiver</option><option value="access">Modifier les caisses</option><option value="disable">Désactiver</option></select></label></div>
        <div class="fields2"><label class="field"><span>Rôle</span><select id="uRole"><option value="staff">Équipe (saisie)</option><option value="reader">Consultation</option></select></label>
        <label class="field"><span>Mot de passe (12 car. min.)</span><input id="uPass" type="password" autocomplete="new-password"></label></div>
        <div class="chips">${Object.entries(REGISTERS).map(([k, v]) => `<label class="chip"><input type="checkbox" data-ureg="${k}" ${k === 'restaurant' ? 'checked' : ''} style="width:auto;min-height:0;margin-right:6px">${v}</label>`).join('')}</div>
        <button class="btn-soft" id="uSave">Valider</button>
      </div>`;
    $('uSave').onclick = async () => {
      try {
        const registers = [...document.querySelectorAll('[data-ureg]')].filter(c => c.checked).map(c => c.dataset.ureg);
        await api('users', { mode: $('uMode').value, name: $('uName').value.trim(), role: $('uRole').value, password: $('uPass').value, registers });
        toast('Compte mis à jour.'); loadTeam();
      } catch (e) { toast(e.message, true); }
    };
  } catch (e) { $('team').innerHTML = `<div class="group"><div class="empty">${esc(e.message)}</div></div>`; }
}
async function loadTransfers() {
  try {
    const j = await api('transfers');
    $('transfers').innerHTML = `<div class="group">${j.transfers.length ? j.transfers.slice(0, 15).map(t => `<div class="li"><span class="main"><div class="t">${REGISTERS[t.source]} → ${REGISTERS[t.destination]} · ${eur(+t.cents)}${+t.cancelled ? ' <span class="pill p-out">annulé</span>' : ''}</div><div class="d">${dateFr(t.movement_date)} · ${esc(t.actor_name)}${t.note ? ' · ' + esc(t.note) : ''}</div></span></div>`).join('') : '<div class="empty">Aucun transfert.</div>'}</div>`;
  } catch (e) { $('transfers').innerHTML = `<div class="group"><div class="empty">${esc(e.message)}</div></div>`; }
}

/* ================= Démarrage ================= */
start();
