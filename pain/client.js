'use strict';
/* Précommande du camping — page des campeurs (commande, bon avec QR code, modification). */

const T = {
  fr: {
    lang: 'Français', pickupWord: 'Retrait', order: 'Commander', pick: 'Jour de retrait', products: 'Votre commande', you: 'Vos coordonnées',
    name: 'Nom', pitch: 'N° d’emplacement', phone: 'Téléphone', phoneOpt: 'Téléphone (facultatif)', note: 'Remarque (facultatif)',
    total: 'Total', confirm: 'Valider la commande', update: 'Enregistrer les modifications', payOnSite: 'Paiement sur place au retrait.',
    until: 'Commande possible jusqu’au', at: 'à', pickup: 'Retrait le', from: 'de', to: 'à', closed: 'Les commandes en ligne sont fermées pour le moment.',
    noDay: 'Aucun jour n’est ouvert à la commande pour le moment.', empty: 'Ajoute au moins un produit.', ticket: 'Votre bon',
    showTicket: 'Présentez ce bon (ou son code) au comptoir.', saveTicket: 'Gardez cette page : ajoutez-la à l’écran d’accueil ou faites une capture d’écran.',
    print: 'Imprimer / PDF', edit: 'Modifier', cancel: 'Annuler la commande', cancelAsk: 'Annuler cette commande ?', cancelled: 'Commande annulée',
    confirmed: 'Confirmée', collected: 'Retirée', st_cancelled: 'Annulée', editUntil: 'Modifiable jusqu’au', noEdit: 'Cette commande ne peut plus être modifiée en ligne.',
    myOrders: 'Mes commandes', newOrder: 'Nouvelle commande', back: 'Retour', thanks: 'Merci ! Votre commande est enregistrée.', saved: 'Modifications enregistrées.',
    notFound: 'Bon introuvable.', network: 'Connexion impossible. Vérifiez le réseau et réessayez.', max: 'maximum', each: 'pièce', paid: 'Payée', unpaid: 'À payer sur place',
    qty: 'Qté', product: 'Produit', amount: 'Montant',
  },
  de: {
    lang: 'Deutsch', pickupWord: 'Abholung', order: 'Bestellen', pick: 'Abholtag', products: 'Ihre Bestellung', you: 'Ihre Angaben',
    name: 'Name', pitch: 'Stellplatz-Nr.', phone: 'Telefon', phoneOpt: 'Telefon (optional)', note: 'Bemerkung (optional)',
    total: 'Summe', confirm: 'Bestellung absenden', update: 'Änderungen speichern', payOnSite: 'Bezahlung vor Ort bei der Abholung.',
    until: 'Bestellbar bis', at: 'um', pickup: 'Abholung am', from: 'von', to: 'bis', closed: 'Online-Bestellungen sind derzeit geschlossen.',
    noDay: 'Derzeit ist kein Tag zur Bestellung geöffnet.', empty: 'Bitte mindestens ein Produkt wählen.', ticket: 'Ihr Abholschein',
    showTicket: 'Zeigen Sie diesen Schein (oder den Code) an der Theke.', saveTicket: 'Behalten Sie diese Seite: zum Home-Bildschirm hinzufügen oder Screenshot machen.',
    print: 'Drucken / PDF', edit: 'Ändern', cancel: 'Bestellung stornieren', cancelAsk: 'Diese Bestellung stornieren?', cancelled: 'Bestellung storniert',
    confirmed: 'Bestätigt', collected: 'Abgeholt', st_cancelled: 'Storniert', editUntil: 'Änderbar bis', noEdit: 'Diese Bestellung kann online nicht mehr geändert werden.',
    myOrders: 'Meine Bestellungen', newOrder: 'Neue Bestellung', back: 'Zurück', thanks: 'Danke! Ihre Bestellung ist eingegangen.', saved: 'Änderungen gespeichert.',
    notFound: 'Abholschein nicht gefunden.', network: 'Keine Verbindung. Bitte Netz prüfen und erneut versuchen.', max: 'max.', each: 'Stück', paid: 'Bezahlt', unpaid: 'Vor Ort zu bezahlen',
    qty: 'Anz.', product: 'Produkt', amount: 'Betrag',
  },
  en: {
    lang: 'English', pickupWord: 'Pick-up', order: 'Order', pick: 'Pick-up day', products: 'Your order', you: 'Your details',
    name: 'Name', pitch: 'Pitch number', phone: 'Phone', phoneOpt: 'Phone (optional)', note: 'Note (optional)',
    total: 'Total', confirm: 'Place order', update: 'Save changes', payOnSite: 'Pay on site when you collect.',
    until: 'Order until', at: 'at', pickup: 'Pick-up on', from: 'from', to: 'to', closed: 'Online ordering is closed for now.',
    noDay: 'No day is open for ordering at the moment.', empty: 'Add at least one product.', ticket: 'Your voucher',
    showTicket: 'Show this voucher (or its code) at the counter.', saveTicket: 'Keep this page: add it to your home screen or take a screenshot.',
    print: 'Print / PDF', edit: 'Change', cancel: 'Cancel order', cancelAsk: 'Cancel this order?', cancelled: 'Order cancelled',
    confirmed: 'Confirmed', collected: 'Collected', st_cancelled: 'Cancelled', editUntil: 'Can be changed until', noEdit: 'This order can no longer be changed online.',
    myOrders: 'My orders', newOrder: 'New order', back: 'Back', thanks: 'Thank you! Your order is confirmed.', saved: 'Changes saved.',
    notFound: 'Voucher not found.', network: 'No connection. Check the network and try again.', max: 'max', each: 'each', paid: 'Paid', unpaid: 'To pay on site',
    qty: 'Qty', product: 'Product', amount: 'Amount',
  },
  nl: {
    lang: 'Nederlands', pickupWord: 'Ophalen', order: 'Bestellen', pick: 'Ophaaldag', products: 'Uw bestelling', you: 'Uw gegevens',
    name: 'Naam', pitch: 'Plaatsnummer', phone: 'Telefoon', phoneOpt: 'Telefoon (optioneel)', note: 'Opmerking (optioneel)',
    total: 'Totaal', confirm: 'Bestelling plaatsen', update: 'Wijzigingen opslaan', payOnSite: 'Betalen ter plaatse bij het ophalen.',
    until: 'Bestellen kan tot', at: 'om', pickup: 'Ophalen op', from: 'van', to: 'tot', closed: 'Online bestellen is momenteel gesloten.',
    noDay: 'Er is momenteel geen dag open voor bestellingen.', empty: 'Kies minstens één product.', ticket: 'Uw bon',
    showTicket: 'Toon deze bon (of de code) aan de balie.', saveTicket: 'Bewaar deze pagina: zet hem op uw beginscherm of maak een screenshot.',
    print: 'Afdrukken / PDF', edit: 'Wijzigen', cancel: 'Bestelling annuleren', cancelAsk: 'Deze bestelling annuleren?', cancelled: 'Bestelling geannuleerd',
    confirmed: 'Bevestigd', collected: 'Opgehaald', st_cancelled: 'Geannuleerd', editUntil: 'Te wijzigen tot', noEdit: 'Deze bestelling kan online niet meer worden gewijzigd.',
    myOrders: 'Mijn bestellingen', newOrder: 'Nieuwe bestelling', back: 'Terug', thanks: 'Bedankt! Uw bestelling is geplaatst.', saved: 'Wijzigingen opgeslagen.',
    notFound: 'Bon niet gevonden.', network: 'Geen verbinding. Controleer het netwerk en probeer opnieuw.', max: 'max.', each: 'stuk', paid: 'Betaald', unpaid: 'Ter plaatse te betalen',
    qty: 'Aant.', product: 'Product', amount: 'Bedrag',
  },
};
const LOCALES = { fr: 'fr-FR', de: 'de-DE', en: 'en-GB', nl: 'nl-NL' };

let L = pickLang(), CAT = null, CART = {}, DAY = '', EDIT = null, KEY = uid(), busy = false;
const $ = id => document.getElementById(id);
const t = k => T[L][k] ?? T.fr[k] ?? k;

function pickLang() {
  try { const s = localStorage.getItem('pain-lang'); if (T[s]) return s; } catch { }
  const n = (navigator.language || 'fr').slice(0, 2);
  return T[n] ? n : 'fr';
}
function uid() { return crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2, 12); }
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function eur(c) { return (c / 100).toLocaleString(LOCALES[L], { style: 'currency', currency: 'EUR' }); }
function day(d, opt = { weekday: 'long', day: 'numeric', month: 'long' }) { return new Date(d + 'T12:00:00').toLocaleDateString(LOCALES[L], opt); }
function hm(s) { return s.slice(11, 16); }
function pname(p) { return (p.names && p.names[L]) || p.name; }
function toast(msg, err = false) { const e = $('toast'); e.textContent = msg; e.className = 'show' + (err ? ' err' : ''); clearTimeout(toast.t); toast.t = setTimeout(() => e.className = '', err ? 5500 : 3000); }

async function api(action, body = {}) {
  let r;
  try { r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action, ...body }) }); }
  catch { throw new Error(t('network')); }
  let j = {}; try { j = await r.json(); } catch { throw new Error(t('network')); }
  if (!r.ok || !j.ok) throw Object.assign(new Error(j.error || t('network')), { status: r.status });
  return j;
}

/* Mémoire locale des bons (pratique, pas indispensable : le lien du bon suffit) */
function myOrders() { try { return JSON.parse(localStorage.getItem('pain-orders') || '[]'); } catch { return []; } }
function remember(o) {
  try {
    const list = myOrders().filter(x => x.token !== o.token);
    list.unshift({ token: o.token, code: o.code, date: o.date });
    localStorage.setItem('pain-orders', JSON.stringify(list.slice(0, 20)));
  } catch { }
}

function langSwitch() {
  return `<div class="langs no-print">${Object.keys(T).map(l => `<button data-lang="${l}" class="${l === L ? 'on' : ''}" title="${T[l].lang}">${l.toUpperCase()}</button>`).join('')}</div>`;
}
function wireLang(rerender) {
  document.querySelectorAll('[data-lang]').forEach(b => b.onclick = () => {
    L = b.dataset.lang; try { localStorage.setItem('pain-lang', L); } catch { }
    document.documentElement.lang = L; rerender();
  });
}

/* ================= Commande ================= */
async function showOrder(edit = null) {
  EDIT = edit;
  try { CAT = await api('catalogue'); } catch (e) { $('app').innerHTML = `<div class="notice warn">${esc(e.message)}</div>`; return; }
  if (edit) {
    CART = Object.fromEntries(edit.items.map(i => [i.id, i.qty]));
    DAY = edit.date;
  } else if (!CAT.dates.some(d => d.date === DAY)) DAY = CAT.dates[0]?.date || '';
  renderOrder();
}
function renderOrder() {
  const s = CAT.settings, dates = CAT.dates.slice();
  if (EDIT && !dates.some(d => d.date === EDIT.date)) dates.unshift({ date: EDIT.date, limit: EDIT.limit });
  const mine = myOrders();
  const totalCents = CAT.products.reduce((a, p) => a + (CART[p.id] || 0) * p.price, 0);
  const sel = dates.find(d => d.date === DAY);
  document.title = s.title;
  $('app').innerHTML = `
    <div class="top"><div class="hero-ic">🥐</div>${langSwitch()}</div>
    <h1>${esc(EDIT ? t('edit') + ' · ' + EDIT.code : s.title)}</h1>
    <div class="sub">${esc(s.place)} · ${t('pickupWord')} ${s.pickup_from}–${s.pickup_to}</div>
    ${s.message && !EDIT ? `<div class="notice">${esc(s.message)}</div>` : ''}
    ${!s.ordering_open ? `<div class="notice warn">${t('closed')}</div>` : !dates.length ? `<div class="notice warn">${t('noDay')}</div>` : `
    <div class="group-title">${t('pick')}</div>
    <div class="days">${dates.map(d => { const x = new Date(d.date + 'T12:00:00'); return `<button class="day ${d.date === DAY ? 'on' : ''}" data-day="${d.date}"><div class="w">${x.toLocaleDateString(LOCALES[L], { weekday: 'short' })}</div><div class="n">${x.getDate()}</div><div class="m">${x.toLocaleDateString(LOCALES[L], { month: 'short' })}</div></button>`; }).join('')}</div>
    ${sel ? `<p class="small mute" style="margin:4px 6px 0">${t('until')} ${day(sel.limit.slice(0, 10), { weekday: 'long', day: 'numeric', month: 'long' })} ${t('at')} ${hm(sel.limit)}</p>` : ''}
    <div class="group-title">${t('products')}</div>
    <div class="group">${CAT.products.map(p => { const q = CART[p.id] || 0; return `
      <div class="li"><span class="emoji">${esc(p.emoji || '🍞')}</span><span class="main"><div class="t">${esc(pname(p))}</div><div class="d">${eur(p.price)}</div></span>
      <span class="stepper ${q ? 'has' : ''}"><button data-minus="${p.id}" aria-label="−" ${q ? '' : 'disabled'}>−</button><b>${q}</b><button data-plus="${p.id}" aria-label="+" ${q >= s.max_per_product ? 'disabled' : ''}>+</button></span></div>`; }).join('')}</div>
    <div class="group-title">${t('you')}</div>
    <div class="group">
      <label class="row"><span>${t('name')}</span><input id="fName" maxlength="60" autocomplete="family-name" value="${esc(EDIT?.name ?? stored('name'))}"></label>
      <label class="row"><span>${t('pitch')}</span><input id="fPitch" maxlength="30" value="${esc(EDIT?.pitch ?? stored('pitch'))}"></label>
      <label class="row"><span>${s.phone_required ? t('phone') : t('phoneOpt')}</span><input id="fPhone" type="tel" maxlength="30" autocomplete="tel" value="${esc(EDIT?.phone ?? stored('phone'))}"></label>
      <label class="row"><span>${t('note')}</span><input id="fNote" maxlength="200" value="${esc(EDIT?.note ?? '')}"></label>
    </div>
    <div class="basket">
      <div class="total"><span>${t('total')} <span class="small mute">· ${t('payOnSite')}</span></span><b class="num">${eur(totalCents)}</b></div>
      <button class="btn-main" id="fGo" ${totalCents ? '' : 'disabled'}>${EDIT ? t('update') : t('confirm')}</button>
      ${EDIT ? `<button class="btn-plain" style="width:100%;margin-top:8px" id="fBack">${t('back')}</button>` : ''}
      <p class="error" id="fErr"></p>
    </div>`}
    ${!EDIT && mine.length ? `<div class="group-title">${t('myOrders')}</div><div class="group">${mine.map(o => `<a class="li" style="color:inherit;text-decoration:none" href="?c=${encodeURIComponent(o.token)}"><span class="main"><div class="t">${esc(o.code)}</div><div class="d">${day(o.date)}</div></span><span class="mute">›</span></a>`).join('')}</div>` : ''}`;
  wireLang(renderOrder);
  document.querySelectorAll('[data-day]').forEach(b => b.onclick = () => { keepFields(); DAY = b.dataset.day; renderOrder(); });
  document.querySelectorAll('[data-plus]').forEach(b => b.onclick = () => { keepFields(); CART[b.dataset.plus] = Math.min(s.max_per_product, (CART[b.dataset.plus] || 0) + 1); renderOrder(); });
  document.querySelectorAll('[data-minus]').forEach(b => b.onclick = () => { keepFields(); CART[b.dataset.minus] = Math.max(0, (CART[b.dataset.minus] || 0) - 1); renderOrder(); });
  if ($('fGo')) $('fGo').onclick = submitOrder;
  if ($('fBack')) $('fBack').onclick = () => showTicket(EDIT.token);
}
let FIELDS = {};
function stored(k) { if (FIELDS[k] !== undefined) return FIELDS[k]; try { return JSON.parse(localStorage.getItem('pain-me') || '{}')[k] || ''; } catch { return ''; } }
function keepFields() { if ($('fName')) { FIELDS = { name: $('fName').value, pitch: $('fPitch').value, phone: $('fPhone').value }; if (EDIT) Object.assign(EDIT, { name: FIELDS.name, pitch: FIELDS.pitch, phone: FIELDS.phone, note: $('fNote').value }); } }
async function submitOrder() {
  if (busy) return;
  const err = $('fErr'); err.textContent = '';
  const items = Object.entries(CART).filter(([, q]) => q > 0).map(([id, qty]) => ({ id: +id, qty }));
  if (!items.length) { err.textContent = t('empty'); return; }
  const body = { date: DAY, name: $('fName').value.trim(), pitch: $('fPitch').value.trim(), phone: $('fPhone').value.trim(), note: $('fNote').value.trim(), items, lang: L };
  busy = true; $('fGo').disabled = true;
  try {
    const j = EDIT ? await api('modifier', { token: EDIT.token, ...body }) : await api('commander', { key: KEY, ...body });
    try { localStorage.setItem('pain-me', JSON.stringify({ name: body.name, pitch: body.pitch, phone: body.phone })); } catch { }
    remember(j.order);
    toast(EDIT ? t('saved') : t('thanks'));
    if (!EDIT) { KEY = uid(); CART = {}; }
    history.pushState(null, '', '?c=' + encodeURIComponent(j.order.token));
    renderTicket(j.order, j.staff);
  } catch (e) { err.textContent = e.message; if (e.status === 409) { try { CAT = await api('catalogue'); } catch { } } }
  finally { busy = false; if ($('fGo')) $('fGo').disabled = false; }
}

/* ================= Bon ================= */
async function showTicket(token) {
  try { const j = await api('bon', { token }); renderTicket(j.order, j.staff); }
  catch (e) { $('app').innerHTML = `<div class="top"><div class="hero-ic">🥐</div>${langSwitch()}</div><div class="notice warn">${esc(e.status === 404 ? t('notFound') : e.message)}</div><p style="margin-top:14px"><a class="link" href="./">${t('newOrder')}</a></p>`; wireLang(() => showTicket(token)); }
}
function renderTicket(o, staff) {
  remember(o);
  const url = location.origin + location.pathname + '?c=' + encodeURIComponent(o.token);
  let qr = ''; try { qr = qrSvg(url); } catch { }
  const stLabel = { confirmed: t('confirmed'), collected: t('collected'), cancelled: t('st_cancelled') }[o.status];
  $('app').innerHTML = `
    ${staff ? staffBar(o) : ''}
    <div class="top"><div><h1 style="font-size:26px">${t('ticket')}</h1></div>${langSwitch()}</div>
    <div class="ticket">
      <div class="qr">${qr}</div>
      <div class="code">${esc(o.code)}</div>
      <div class="status st-${o.status}">${stLabel}</div>
      <div class="when">${t('pickup')} ${day(o.date)}</div>
      <div class="mute small">${esc(o.name)} · ${t('pitch')} ${esc(o.pitch)}</div>
      <table>
        ${o.items.map(i => `<tr><td>${esc(i.emoji)} ${esc(i.name)}</td><td class="r num">${i.qty} × ${eur(i.price)}</td><td class="r num">${eur(i.qty * i.price)}</td></tr>`).join('')}
        <tr><td><b>${t('total')}</b></td><td></td><td class="r num"><b>${eur(o.total)}</b></td></tr>
      </table>
      <p class="small mute" style="margin-top:12px">${o.paid ? t('paid') : t('unpaid')}${o.note ? ' · ' + esc(o.note) : ''}</p>
    </div>
    ${o.status === 'confirmed' ? `<p class="small mute" style="margin:12px 6px">${t('showTicket')} ${t('saveTicket')}</p>` : ''}
    <div class="row-btns no-print" style="margin-top:10px">
      ${o.editable ? `<button class="btn-soft" id="tEdit">${t('edit')}</button><button class="btn-danger" id="tCancel">${t('cancel')}</button>` : ''}
      <button class="btn-plain" onclick="window.print()">${t('print')}</button>
      <a class="btn-plain" style="display:inline-flex;align-items:center;text-decoration:none;color:inherit" href="./">${t('newOrder')}</a>
    </div>
    ${o.status === 'confirmed' ? `<p class="small mute" style="margin:12px 6px">${o.editable ? `${t('editUntil')} ${day(o.limit.slice(0, 10))} ${t('at')} ${hm(o.limit)}.` : t('noEdit')}</p>` : ''}`;
  wireLang(() => renderTicket(o, staff));
  if ($('tEdit')) $('tEdit').onclick = () => showOrder(o);
  if ($('tCancel')) $('tCancel').onclick = async () => {
    if (!confirm(t('cancelAsk'))) return;
    try { const j = await api('annuler', { token: o.token }); toast(t('cancelled')); renderTicket(j.order, staff); } catch (e) { toast(e.message, true); }
  };
  if (staff) wireStaff(o);
}

/* L'équipe, connectée sur son téléphone, scanne le QR : le bon s'ouvre avec les boutons de retrait. */
function staffBar(o) {
  const today = new Date().toLocaleDateString('sv-SE');
  return `<div class="staffbar no-print"><span class="grow">Équipe · ${o.status === 'confirmed' ? (o.date === today ? 'à retirer aujourd’hui' : 'prévue le ' + new Date(o.date + 'T12:00').toLocaleDateString('fr-FR')) : o.status === 'collected' ? 'déjà retirée' : 'annulée'}</span>
    ${o.status === 'confirmed' ? `<button class="btn-ok btn-sm" id="sCollect">✓ Retirée et payée</button><button class="btn-plain btn-sm" style="background:rgba(255,255,255,.2);color:inherit" id="sCollectUnpaid">Retirée, non payée</button>` : ''}
    ${o.status === 'collected' ? `<button class="btn-plain btn-sm" style="background:rgba(255,255,255,.2);color:inherit" id="sUndo">Annuler le retrait</button>` : ''}
    <a class="btn-plain btn-sm" style="background:rgba(255,255,255,.2);color:inherit;display:inline-flex;align-items:center;text-decoration:none" href="equipe/">Espace équipe</a></div>`;
}
function wireStaff(o) {
  const run = async (action, extra = {}) => {
    try { await api(action, { id: o.id, ...extra }); toast(action === 'retirer' ? 'Retrait enregistré.' : 'Retrait annulé.'); showTicket(o.token); }
    catch (e) {
      if (e.status === 409 && action === 'retirer' && /autre jour|était prévue/.test(e.message) && confirm(e.message + '\n\nValider quand même le retrait ?')) return run(action, { ...extra, force: true });
      toast(e.message, true);
    }
  };
  if ($('sCollect')) $('sCollect').onclick = () => run('retirer', { paid: true });
  if ($('sCollectUnpaid')) $('sCollectUnpaid').onclick = () => run('retirer', { paid: false });
  if ($('sUndo')) $('sUndo').onclick = () => run('annuler_retrait');
}

/* ================= Démarrage ================= */
document.documentElement.lang = L;
function route() {
  const token = new URLSearchParams(location.search).get('c');
  if (token) showTicket(token); else showOrder();
}
window.addEventListener('popstate', route);
route();
