'use strict';
/* Précommande du camping — page des campeurs (commande, bon avec QR code, modification). */

const T = {
  fr: {
    lang: 'Français', pickupWord: 'Retrait', opensOn: 'Les précommandes ouvriront le', seasonOver: 'La saison des précommandes est terminée. À l’année prochaine !', preview: 'Aperçu équipe : la boutique est fermée aux campeurs (hors saison ou en pause). Vous pouvez tester ; vos commandes sont réelles, pensez à les annuler.', payCard: 'Payer par carte maintenant', payCardNote: 'Paiement sécurisé sur la page Stripe. Vous pouvez aussi payer au comptoir.', checking: 'Vérification du paiement…', paidOk: 'Paiement reçu : votre commande est validée.', notYet: 'Paiement pas encore confirmé. Si vous avez payé, actualisez dans un instant.', orCounter: 'ou au comptoir', required: 'Prénom, nom, n° d’emplacement et téléphone sont obligatoires.', firstName: 'Prénom', lastName: 'Nom', saveImg: 'Enregistrer le bon (image)', saveHelp: 'L’image s’enregistre dans vos photos ou téléchargements. Vous pouvez aussi la montrer hors connexion.', find: 'Vous avez perdu votre bon ?', findBtn: 'Retrouver mon bon', findHelp: 'Indiquez le téléphone et l’emplacement donnés à la commande.', none: 'Aucune commande trouvée avec ce téléphone et cet emplacement.', phoneHelp: 'Pour vous joindre et retrouver votre bon si besoin.', order: 'Commander', pick: 'Jour de retrait', products: 'Votre commande', you: 'Vos coordonnées',
    name: 'Nom', pitch: 'N° d’emplacement', phone: 'Téléphone', phoneOpt: 'Téléphone (facultatif)', note: 'Remarque (facultatif)',
    total: 'Total', confirm: 'Valider la commande', update: 'Enregistrer les modifications', payOnSite: 'Validée après paiement.',
    until: 'Commande possible jusqu’au', at: 'à', pickup: 'Retrait le', from: 'de', to: 'à', closed: 'Les commandes en ligne sont fermées pour le moment.',
    noDay: 'Aucun jour n’est ouvert à la commande pour le moment.', empty: 'Ajoute au moins un produit.', ticket: 'Votre bon',
    showTicket: 'Présentez ce bon (ou son code) au comptoir.', saveTicket: 'Gardez cette page : ajoutez-la à l’écran d’accueil ou faites une capture d’écran.',
    print: 'Imprimer / PDF', edit: 'Modifier', cancel: 'Annuler la commande', cancelAsk: 'Annuler cette commande ?', cancelled: 'Commande annulée',
    confirmed: 'Payée · validée', pending: 'En attente de paiement', pendingNote: 'Votre commande sera validée après paiement. Passez au comptoir (espèces ou carte) avant le', pendingNote2: 'Sans paiement, elle est annulée automatiquement.', paidNote: 'Payée : il ne reste plus qu’à la retirer.', collected: 'Retirée', st_cancelled: 'Annulée', editUntil: 'Modifiable jusqu’au', noEdit: 'Cette commande ne peut plus être modifiée en ligne.',
    myOrders: 'Mes commandes', newOrder: 'Nouvelle commande', back: 'Retour', thanks: 'Merci ! Votre commande est enregistrée.', saved: 'Modifications enregistrées.',
    notFound: 'Bon introuvable.', network: 'Connexion impossible. Vérifiez le réseau et réessayez.', max: 'maximum', each: 'pièce', paid: 'Payée', unpaid: 'À payer sur place',
    qty: 'Qté', product: 'Produit', amount: 'Montant',
  },
  de: {
    lang: 'Deutsch', pickupWord: 'Abholung', opensOn: 'Vorbestellungen öffnen am', seasonOver: 'Die Vorbestell-Saison ist beendet. Bis nächstes Jahr!', preview: 'Vorschau für das Team.', payCard: 'Jetzt mit Karte bezahlen', payCardNote: 'Sichere Zahlung auf der Stripe-Seite. Sie können auch an der Theke bezahlen.', checking: 'Zahlung wird geprüft…', paidOk: 'Zahlung erhalten: Ihre Bestellung ist bestätigt.', notYet: 'Zahlung noch nicht bestätigt. Wenn Sie bezahlt haben, laden Sie die Seite gleich neu.', orCounter: 'oder an der Theke', required: 'Vorname, Nachname, Stellplatz und Telefon sind Pflichtfelder.', firstName: 'Vorname', lastName: 'Nachname', saveImg: 'Abholschein speichern (Bild)', saveHelp: 'Das Bild wird in Ihren Fotos oder Downloads gespeichert und funktioniert auch offline.', find: 'Abholschein verloren?', findBtn: 'Abholschein finden', findHelp: 'Telefon und Stellplatz wie bei der Bestellung angeben.', none: 'Keine Bestellung mit diesem Telefon und Stellplatz gefunden.', phoneHelp: 'Um Sie zu erreichen und Ihren Schein wiederzufinden.', order: 'Bestellen', pick: 'Abholtag', products: 'Ihre Bestellung', you: 'Ihre Angaben',
    name: 'Name', pitch: 'Stellplatz-Nr.', phone: 'Telefon', phoneOpt: 'Telefon (optional)', note: 'Bemerkung (optional)',
    total: 'Summe', confirm: 'Bestellung absenden', update: 'Änderungen speichern', payOnSite: 'Gültig nach Bezahlung.',
    until: 'Bestellbar bis', at: 'um', pickup: 'Abholung am', from: 'von', to: 'bis', closed: 'Online-Bestellungen sind derzeit geschlossen.',
    noDay: 'Derzeit ist kein Tag zur Bestellung geöffnet.', empty: 'Bitte mindestens ein Produkt wählen.', ticket: 'Ihr Abholschein',
    showTicket: 'Zeigen Sie diesen Schein (oder den Code) an der Theke.', saveTicket: 'Behalten Sie diese Seite: zum Home-Bildschirm hinzufügen oder Screenshot machen.',
    print: 'Drucken / PDF', edit: 'Ändern', cancel: 'Bestellung stornieren', cancelAsk: 'Diese Bestellung stornieren?', cancelled: 'Bestellung storniert',
    confirmed: 'Bezahlt · bestätigt', pending: 'Wartet auf Zahlung', pendingNote: 'Ihre Bestellung wird nach der Bezahlung bestätigt. Bitte an der Theke bezahlen (bar oder Karte) bis', pendingNote2: 'Ohne Bezahlung wird sie automatisch storniert.', paidNote: 'Bezahlt: Sie müssen sie nur noch abholen.', collected: 'Abgeholt', st_cancelled: 'Storniert', editUntil: 'Änderbar bis', noEdit: 'Diese Bestellung kann online nicht mehr geändert werden.',
    myOrders: 'Meine Bestellungen', newOrder: 'Neue Bestellung', back: 'Zurück', thanks: 'Danke! Ihre Bestellung ist eingegangen.', saved: 'Änderungen gespeichert.',
    notFound: 'Abholschein nicht gefunden.', network: 'Keine Verbindung. Bitte Netz prüfen und erneut versuchen.', max: 'max.', each: 'Stück', paid: 'Bezahlt', unpaid: 'Vor Ort zu bezahlen',
    qty: 'Anz.', product: 'Produkt', amount: 'Betrag',
  },
  en: {
    lang: 'English', pickupWord: 'Pick-up', opensOn: 'Pre-orders open on', seasonOver: 'The pre-order season is over. See you next year!', preview: 'Team preview.', payCard: 'Pay by card now', payCardNote: 'Secure payment on the Stripe page. You can also pay at the counter.', checking: 'Checking payment…', paidOk: 'Payment received: your order is confirmed.', notYet: 'Payment not confirmed yet. If you paid, refresh in a moment.', orCounter: 'or at the counter', required: 'First name, last name, pitch number and phone are required.', firstName: 'First name', lastName: 'Last name', saveImg: 'Save voucher (image)', saveHelp: 'The image is saved to your photos or downloads and also works offline.', find: 'Lost your voucher?', findBtn: 'Find my voucher', findHelp: 'Enter the phone number and pitch used for the order.', none: 'No order found with this phone number and pitch.', phoneHelp: 'So we can reach you and find your voucher if needed.', order: 'Order', pick: 'Pick-up day', products: 'Your order', you: 'Your details',
    name: 'Name', pitch: 'Pitch number', phone: 'Phone', phoneOpt: 'Phone (optional)', note: 'Note (optional)',
    total: 'Total', confirm: 'Place order', update: 'Save changes', payOnSite: 'Confirmed once paid.',
    until: 'Order until', at: 'at', pickup: 'Pick-up on', from: 'from', to: 'to', closed: 'Online ordering is closed for now.',
    noDay: 'No day is open for ordering at the moment.', empty: 'Add at least one product.', ticket: 'Your voucher',
    showTicket: 'Show this voucher (or its code) at the counter.', saveTicket: 'Keep this page: add it to your home screen or take a screenshot.',
    print: 'Print / PDF', edit: 'Change', cancel: 'Cancel order', cancelAsk: 'Cancel this order?', cancelled: 'Order cancelled',
    confirmed: 'Paid · confirmed', pending: 'Awaiting payment', pendingNote: 'Your order is confirmed once paid. Please pay at the counter (cash or card) before', pendingNote2: 'Unpaid orders are cancelled automatically.', paidNote: 'Paid: you only need to collect it.', collected: 'Collected', st_cancelled: 'Cancelled', editUntil: 'Can be changed until', noEdit: 'This order can no longer be changed online.',
    myOrders: 'My orders', newOrder: 'New order', back: 'Back', thanks: 'Thank you! Your order is confirmed.', saved: 'Changes saved.',
    notFound: 'Voucher not found.', network: 'No connection. Check the network and try again.', max: 'max', each: 'each', paid: 'Paid', unpaid: 'To pay on site',
    qty: 'Qty', product: 'Product', amount: 'Amount',
  },
  nl: {
    lang: 'Nederlands', pickupWord: 'Ophalen', opensOn: 'Voorbestellingen openen op', seasonOver: 'Het voorbestelseizoen is voorbij. Tot volgend jaar!', preview: 'Voorbeeld voor het team.', payCard: 'Nu betalen met kaart', payCardNote: 'Veilig betalen op de Stripe-pagina. U kunt ook aan de balie betalen.', checking: 'Betaling wordt gecontroleerd…', paidOk: 'Betaling ontvangen: uw bestelling is bevestigd.', notYet: 'Betaling nog niet bevestigd. Hebt u betaald, vernieuw dan zo meteen.', orCounter: 'of aan de balie', required: 'Voornaam, achternaam, plaatsnummer en telefoon zijn verplicht.', firstName: 'Voornaam', lastName: 'Achternaam', saveImg: 'Bon opslaan (afbeelding)', saveHelp: 'De afbeelding wordt in uw foto’s of downloads bewaard en werkt ook offline.', find: 'Bon kwijt?', findBtn: 'Mijn bon zoeken', findHelp: 'Vul het telefoonnummer en de plaats van de bestelling in.', none: 'Geen bestelling gevonden met dit telefoonnummer en deze plaats.', phoneHelp: 'Om u te bereiken en uw bon terug te vinden.', order: 'Bestellen', pick: 'Ophaaldag', products: 'Uw bestelling', you: 'Uw gegevens',
    name: 'Naam', pitch: 'Plaatsnummer', phone: 'Telefoon', phoneOpt: 'Telefoon (optioneel)', note: 'Opmerking (optioneel)',
    total: 'Totaal', confirm: 'Bestelling plaatsen', update: 'Wijzigingen opslaan', payOnSite: 'Bevestigd na betaling.',
    until: 'Bestellen kan tot', at: 'om', pickup: 'Ophalen op', from: 'van', to: 'tot', closed: 'Online bestellen is momenteel gesloten.',
    noDay: 'Er is momenteel geen dag open voor bestellingen.', empty: 'Kies minstens één product.', ticket: 'Uw bon',
    showTicket: 'Toon deze bon (of de code) aan de balie.', saveTicket: 'Bewaar deze pagina: zet hem op uw beginscherm of maak een screenshot.',
    print: 'Afdrukken / PDF', edit: 'Wijzigen', cancel: 'Bestelling annuleren', cancelAsk: 'Deze bestelling annuleren?', cancelled: 'Bestelling geannuleerd',
    confirmed: 'Betaald · bevestigd', pending: 'Wacht op betaling', pendingNote: 'Uw bestelling wordt bevestigd na betaling. Betaal aan de balie (contant of kaart) vóór', pendingNote2: 'Zonder betaling wordt ze automatisch geannuleerd.', paidNote: 'Betaald: u hoeft ze alleen nog op te halen.', collected: 'Opgehaald', st_cancelled: 'Geannuleerd', editUntil: 'Te wijzigen tot', noEdit: 'Deze bestelling kan online niet meer worden gewijzigd.',
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
    ${!s.ordering_open && !CAT.preview ? `<div class="notice warn">${t('closed')}</div>` : !dates.length ? `<div class="notice warn">${s.season_start && CAT.today < s.season_start ? `${t('opensOn')} ${day(s.season_start, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}.` : s.season_end && CAT.today > s.season_end ? t('seasonOver') : t('noDay')}</div>` : `
    <div class="group-title">${t('pick')}</div>
    <div class="days">${dates.map(d => { const x = new Date(d.date + 'T12:00:00'); return `<button class="day ${d.date === DAY ? 'on' : ''}" data-day="${d.date}"><div class="w">${x.toLocaleDateString(LOCALES[L], { weekday: 'short' })}</div><div class="n">${x.getDate()}</div><div class="m">${x.toLocaleDateString(LOCALES[L], { month: 'short' })}</div></button>`; }).join('')}</div>
    ${sel ? `<p class="small mute" style="margin:4px 6px 0">${t('until')} ${day(sel.limit.slice(0, 10), { weekday: 'long', day: 'numeric', month: 'long' })} ${t('at')} ${hm(sel.limit)}</p>` : ''}
    <div class="group-title">${t('products')}</div>
    <div class="group">${CAT.products.map(p => { const q = CART[p.id] || 0; return `
      <div class="li"><span class="emoji">${esc(p.emoji || '🍞')}</span><span class="main"><div class="t">${esc(pname(p))}</div><div class="d">${eur(p.price)}</div></span>
      <span class="stepper ${q ? 'has' : ''}"><button data-minus="${p.id}" aria-label="−" ${q ? '' : 'disabled'}>−</button><b>${q}</b><button data-plus="${p.id}" aria-label="+" ${q >= s.max_per_product ? 'disabled' : ''}>+</button></span></div>`; }).join('')}</div>
    <div class="group-title">${t('you')}</div>
    <div class="group">
      <label class="row"><span>${t('firstName')}</span><input id="fFirst" maxlength="40" autocomplete="given-name" value="${esc(EDIT?.firstName ?? stored('first'))}"></label>
      <label class="row"><span>${t('lastName')}</span><input id="fName" maxlength="60" autocomplete="family-name" value="${esc(EDIT?.lastName ?? stored('name'))}"></label>
      <label class="row"><span>${t('pitch')}</span><input id="fPitch" maxlength="30" value="${esc(EDIT?.pitch ?? stored('pitch'))}"></label>
      <label class="row"><span>${t('phone')}</span><input id="fPhone" type="tel" maxlength="30" autocomplete="tel" placeholder="+33 6 …" value="${esc(EDIT?.phone ?? stored('phone'))}"></label>
      <label class="row"><span>${t('note')}</span><input id="fNote" maxlength="200" value="${esc(EDIT?.note ?? '')}"></label>
    </div>
    <p class="small mute" style="margin:6px 6px 0">${t('phoneHelp')}</p>
    <div class="basket">
      <div class="total"><span>${t('total')} <span class="small mute">· ${t('payOnSite')}${s.card_online ? ' 💳' : ''}</span></span><b class="num">${eur(totalCents)}</b></div>
      <button class="btn-main" id="fGo" ${totalCents ? '' : 'disabled'}>${EDIT ? t('update') : t('confirm')}</button>
      ${EDIT ? `<button class="btn-plain" style="width:100%;margin-top:8px" id="fBack">${t('back')}</button>` : ''}
      <p class="error" id="fErr"></p>
    </div>`}
    ${!EDIT ? `<div class="group-title">${t('find')}</div><div class="group pad"><p class="small mute">${t('findHelp')}</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><input id="rPhone" type="tel" placeholder="${t('phone')}" autocomplete="tel"><input id="rPitch" placeholder="${t('pitch')}"></div>
      <button class="btn-soft" style="width:100%;margin-top:8px" id="rGo">${t('findBtn')}</button><div id="rOut"></div></div>` : ''}
    ${!EDIT && mine.length ? `<div class="group-title">${t('myOrders')}</div><div class="group">${mine.map(o => `<a class="li" style="color:inherit;text-decoration:none" href="?c=${encodeURIComponent(o.token)}"><span class="main"><div class="t">${esc(o.code)}</div><div class="d">${day(o.date)}</div></span><span class="mute">›</span></a>`).join('')}</div>` : ''}`;
  wireLang(renderOrder);
  document.querySelectorAll('[data-day]').forEach(b => b.onclick = () => { keepFields(); DAY = b.dataset.day; renderOrder(); });
  document.querySelectorAll('[data-plus]').forEach(b => b.onclick = () => { keepFields(); CART[b.dataset.plus] = Math.min(s.max_per_product, (CART[b.dataset.plus] || 0) + 1); renderOrder(); });
  document.querySelectorAll('[data-minus]').forEach(b => b.onclick = () => { keepFields(); CART[b.dataset.minus] = Math.max(0, (CART[b.dataset.minus] || 0) - 1); renderOrder(); });
  if ($('fGo')) $('fGo').onclick = submitOrder;
  if ($('rGo')) $('rGo').onclick = async () => {
    try {
      const j = await api('retrouver', { phone: $('rPhone').value, pitch: $('rPitch').value });
      $('rOut').innerHTML = j.orders.length ? `<div class="group" style="margin-top:10px">${j.orders.map(o => `<a class="li" style="color:inherit;text-decoration:none" href="?c=${encodeURIComponent(o.token)}"><span class="main"><div class="t">${esc(o.code)} · ${eur(o.total)}</div><div class="d">${day(o.date)}</div></span><span class="mute">›</span></a>`).join('')}</div>` : `<p class="error">${t('none')}</p>`;
    } catch (e) { $('rOut').innerHTML = `<p class="error">${esc(e.message)}</p>`; }
  };
  if ($('fBack')) $('fBack').onclick = () => showTicket(EDIT.token);
}
let FIELDS = {};
function stored(k) { if (FIELDS[k] !== undefined) return FIELDS[k]; try { return JSON.parse(localStorage.getItem('pain-me') || '{}')[k] || ''; } catch { return ''; } }
function keepFields() { if ($('fName')) { FIELDS = { first: $('fFirst').value, name: $('fName').value, pitch: $('fPitch').value, phone: $('fPhone').value }; if (EDIT) Object.assign(EDIT, { firstName: FIELDS.first, lastName: FIELDS.name, pitch: FIELDS.pitch, phone: FIELDS.phone, note: $('fNote').value }); } }
async function submitOrder() {
  if (busy) return;
  const err = $('fErr'); err.textContent = '';
  const items = Object.entries(CART).filter(([, q]) => q > 0).map(([id, qty]) => ({ id: +id, qty }));
  if (!items.length) { err.textContent = t('empty'); return; }
  for (const id of ['fFirst', 'fName', 'fPitch', 'fPhone']) if (!$(id).value.trim()) { err.textContent = t('required'); $(id).focus(); return; }
  const body = { date: DAY, firstName: $('fFirst').value.trim(), lastName: $('fName').value.trim(), pitch: $('fPitch').value.trim(), phone: $('fPhone').value.trim(), note: $('fNote').value.trim(), items, lang: L };
  busy = true; $('fGo').disabled = true;
  try {
    const j = EDIT ? await api('modifier', { token: EDIT.token, ...body }) : await api('commander', { key: KEY, ...body });
    try { localStorage.setItem('pain-me', JSON.stringify({ first: body.firstName, name: body.lastName, pitch: body.pitch, phone: body.phone })); } catch { }
    remember(j.order);
    toast(EDIT ? t('saved') : t('thanks'));
    if (!EDIT) { KEY = uid(); CART = {}; }
    history.pushState(null, '', '?c=' + encodeURIComponent(j.order.token));
    CARD = !!CAT?.settings?.card_online;
    renderTicket(j.order, j.staff);
  } catch (e) { err.textContent = e.message; if (e.status === 409) { try { CAT = await api('catalogue'); } catch { } } }
  finally { busy = false; if ($('fGo')) $('fGo').disabled = false; }
}

/* ================= Bon ================= */
let CARD = false;
async function showTicket(token) {
  const back = new URLSearchParams(location.search).get('paye') === '1';
  try {
    let j = await api('bon', { token }); CARD = !!j.cardOnline;
    if (back && j.order.status === 'pending') {
      toast(t('checking'));
      for (let i = 0; i < 6 && j.order.status === 'pending'; i++) { await new Promise(r => setTimeout(r, 2000)); j = await api('bon', { token }); }
      toast(j.order.status === 'pending' ? t('notYet') : t('paidOk'), j.order.status === 'pending');
    } else if (back && j.order.paid) toast(t('paidOk'));
    if (back) history.replaceState(null, '', '?c=' + encodeURIComponent(token));
    renderTicket(j.order, j.staff);
  }
  catch (e) { $('app').innerHTML = `<div class="top"><div class="hero-ic">🥐</div>${langSwitch()}</div><div class="notice warn">${esc(e.status === 404 ? t('notFound') : e.message)}</div><p style="margin-top:14px"><a class="link" href="./">${t('newOrder')}</a></p>`; wireLang(() => showTicket(token)); }
}
function renderTicket(o, staff) {
  remember(o);
  const url = location.origin + location.pathname + '?c=' + encodeURIComponent(o.token);
  let qr = ''; try { qr = qrSvg(url); } catch { }
  const stLabel = { pending: t('pending'), confirmed: t('confirmed'), collected: t('collected'), cancelled: t('st_cancelled') }[o.status];
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
      ${o.note ? `<p class="small mute" style="margin-top:12px">${esc(o.note)}</p>` : ''}
    </div>
    ${o.status === 'pending' && CARD && o.editable ? `<button class="btn-main" style="margin-top:14px" id="tPay">💳 ${t('payCard')} · ${eur(o.total)}</button><p class="small mute" style="margin:8px 6px 0">${t('payCardNote')}</p>` : ''}
    ${o.status === 'pending' ? `<div class="notice warn"><b>${t('pending')}.</b> ${t('pendingNote')} ${day(o.limit.slice(0, 10))} ${t('at')} ${hm(o.limit)}. ${t('pendingNote2')}</div>` : o.status === 'confirmed' ? `<div class="notice">${t('paidNote')}</div>` : ''}
    ${o.status === 'confirmed' ? `<p class="small mute" style="margin:12px 6px">${t('showTicket')} ${t('saveTicket')}</p>` : ''}
    <div class="row-btns no-print" style="margin-top:10px">
      ${o.editable ? `<button class="btn-soft" id="tEdit">${t('edit')}</button><button class="btn-danger" id="tCancel">${t('cancel')}</button>` : ''}
      ${o.status !== 'cancelled' ? `<button class="btn-main" style="width:auto" id="tSave">${t('saveImg')}</button>` : ''}
      <button class="btn-plain" onclick="window.print()">${t('print')}</button>
      <a class="btn-plain" style="display:inline-flex;align-items:center;text-decoration:none;color:inherit" href="./">${t('newOrder')}</a>
    </div>
    ${o.status === 'confirmed' ? `<p class="small mute" style="margin:12px 6px">${o.editable ? `${t('editUntil')} ${day(o.limit.slice(0, 10))} ${t('at')} ${hm(o.limit)}.` : t('noEdit')}</p>` : ''}`;
  wireLang(() => renderTicket(o, staff));
  if ($('tEdit')) $('tEdit').onclick = () => showOrder(o);
  if ($('tSave')) $('tSave').onclick = () => saveTicketImage(o, url);
  if ($('tPay')) $('tPay').onclick = async () => {
    $('tPay').disabled = true;
    try { const j = await api('payer_carte', { token: o.token }); location.href = j.url; }
    catch (e) { toast(e.message, true); $('tPay').disabled = false; }
  };
  if ($('tCancel')) $('tCancel').onclick = async () => {
    if (!confirm(t('cancelAsk'))) return;
    try { const j = await api('annuler', { token: o.token }); toast(t('cancelled')); renderTicket(j.order, staff); } catch (e) { toast(e.message, true); }
  };
  if (staff) wireStaff(o);
}

/** Dessine le bon (QR, code, jour, détail) dans une image PNG à garder sur le téléphone. */
async function saveTicketImage(o, url) {
  try {
    const W = 1080, pad = 70, c = document.createElement('canvas'), g = c.getContext('2d');
    const lines = o.items.map(i => [`${i.qty} × ${i.name}`, eur(i.qty * i.price)]);
    c.width = W; c.height = 1340 + lines.length * 64;
    g.fillStyle = '#ffffff'; g.fillRect(0, 0, W, c.height);
    g.fillStyle = '#b5651d'; g.fillRect(0, 0, W, 16);
    const font = (w, s) => `${w} ${s}px -apple-system, "Helvetica Neue", Arial, sans-serif`;
    g.textAlign = 'center'; g.fillStyle = '#1d1d1f';
    g.font = font(700, 52); g.fillText(CAT?.settings?.title || document.title, W / 2, 110);
    g.font = font(400, 34); g.fillStyle = '#6e6e73'; g.fillText(CAT?.settings?.place || '', W / 2, 160);
    const img = new Image();
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(qrSvg(url));
    await img.decode();
    g.imageSmoothingEnabled = false; g.drawImage(img, (W - 640) / 2, 200, 640, 640);
    g.fillStyle = '#1d1d1f'; g.font = `700 88px ui-monospace, Menlo, monospace`; g.fillText(o.code, W / 2, 950);
    g.font = font(600, 44); g.fillText(`${t('pickup')} ${day(o.date)}`, W / 2, 1030);
    g.font = font(400, 34); g.fillStyle = '#6e6e73'; g.fillText(`${o.name} · ${t('pitch')} ${o.pitch}`, W / 2, 1085);
    let y = 1170; g.textAlign = 'left'; g.fillStyle = '#1d1d1f'; g.font = font(400, 40);
    for (const [a, b] of lines) { g.fillText(a, pad, y); g.textAlign = 'right'; g.fillText(b, W - pad, y); g.textAlign = 'left'; y += 64; }
    g.strokeStyle = '#d2d2d7'; g.lineWidth = 2; g.beginPath(); g.moveTo(pad, y - 34); g.lineTo(W - pad, y - 34); g.stroke();
    g.font = font(700, 44); g.fillText(t('total'), pad, y + 24); g.textAlign = 'right'; g.fillText(eur(o.total), W - pad, y + 24);
    g.textAlign = 'center'; g.font = font(400, 30); g.fillStyle = '#6e6e73'; g.fillText(t('showTicket'), W / 2, y + 110);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    const file = new File([blob], `bon-${o.code}.png`, { type: 'image/png' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      try { await navigator.share({ files: [file], title: o.code }); return; } catch (e) { if (e.name === 'AbortError') return; }
    }
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = file.name; document.body.append(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    toast(t('saveHelp'));
  } catch (e) { toast(t('print') + ' ?', true); }
}

/* L'équipe, connectée sur son téléphone, scanne le QR : le bon s'ouvre avec les boutons de retrait. */
function staffBar(o) {
  const today = new Date().toLocaleDateString('sv-SE'), btn = 'style="background:rgba(255,255,255,.2);color:inherit"';
  const when = o.date === today ? 'aujourd’hui' : 'le ' + new Date(o.date + 'T12:00').toLocaleDateString('fr-FR');
  const etat = { pending: 'à payer · retrait ' + when, confirmed: 'payée · à remettre ' + when, collected: 'déjà retirée', cancelled: 'annulée' }[o.status];
  return `<div class="staffbar no-print"><span class="grow">Équipe · ${etat}</span>
    ${o.status === 'pending' ? `<button class="btn-ok btn-sm" data-pay="especes">Espèces encaissées</button><button class="btn-ok btn-sm" data-pay="carte">Carte encaissée</button>` : ''}
    ${o.status === 'confirmed' ? `<button class="btn-ok btn-sm" id="sCollect">✓ Remise au client</button>` : ''}
    ${o.status === 'collected' ? `<button class="btn-plain btn-sm" ${btn} id="sUndo">Annuler le retrait</button>` : ''}
    <a class="btn-plain btn-sm" ${btn.replace('"', '"display:inline-flex;align-items:center;text-decoration:none;')} href="equipe/">Espace équipe</a></div>`;
}
function wireStaff(o) {
  const run = async (action, extra = {}, msg) => {
    try { await api(action, { id: o.id, ...extra }); toast(msg); showTicket(o.token); }
    catch (e) {
      if (e.status === 409 && action === 'retirer' && /autre jour|était prévue/.test(e.message) && confirm(e.message + '\n\nRemettre quand même ?')) return run(action, { ...extra, force: true }, msg);
      toast(e.message, true);
    }
  };
  document.querySelectorAll('[data-pay]').forEach(b => b.onclick = () => run('encaisser', { method: b.dataset.pay }, 'Paiement encaissé : commande validée.'));
  if ($('sCollect')) $('sCollect').onclick = () => run('retirer', {}, 'Remise enregistrée.');
  if ($('sUndo')) $('sUndo').onclick = () => run('annuler_retrait', {}, 'Retrait annulé.');
}

/* ================= Démarrage ================= */
document.documentElement.lang = L;
function route() {
  const token = new URLSearchParams(location.search).get('c');
  if (token) showTicket(token); else showOrder();
}
window.addEventListener('popstate', route);
route();
