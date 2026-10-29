/* CAMPING OS 76 — logique métier (version aperçu, base partagée de l'artifact).
   Sans DOM : testable avec une fausse base (mockdb.js). */
function makeCore(db, env) {
  env = env || {};
  const now = env.now || (() => new Date().toISOString());
  const hex = () => Math.random().toString(16).slice(2, 10).toUpperCase().padEnd(8, '0');
  const sleep = env.sleep || (ms => new Promise(r => setTimeout(r, ms)));
  const TAB = env.tab || 'tab-' + hex();
  const LOCK_TTL = env.lockTtl || 2500, LOCK_TRIES = env.lockTries || 7, LOCK_WAIT = env.lockWait || 700;

  const STATIONS = ['pizza', 'cuisine', 'bar'];
  const POSTES = { pizza: 'Poste pizza', cuisine: 'Poste cuisine', bar: 'Poste bar' };
  const DEFAULT_STAFF = {
    managers: [{ id: 'alice', name: 'Responsable Alice' }, { id: 'hugo', name: 'Responsable Hugo' }],
    drivers: [{ id: 'lea', name: 'Livreuse Léa' }, { id: 'noa', name: 'Livreur Noa' }]
  };
  function accountsOf(staff) {
    const a = {};
    (staff.managers || []).forEach(m => { a[m.id] = [m.name, 'manager']; });
    for (const k in POSTES) a[k] = [POSTES[k], k];
    (staff.drivers || []).forEach(d => { a[d.id] = [d.name, 'driver']; });
    return a;
  }
  const DEFAULT_MENU = [
    { id: 'margherita', name: 'Pizza Margherita', description: 'Tomate, mozzarella, basilic', price: 1150, station: 'pizza', active: true },
    { id: 'reine', name: 'Pizza Reine', description: 'Jambon, champignons, mozzarella', price: 1350, station: 'pizza', active: true },
    { id: 'burger', name: 'Burger du camping', description: 'Bœuf, cheddar, salade · avec frites', price: 1450, station: 'cuisine', active: true },
    { id: 'frites', name: 'Frites maison', description: 'Une portion à partager', price: 450, station: 'cuisine', active: true },
    { id: 'salade', name: 'Salade estivale', description: 'Crudités, feta, olives', price: 1050, station: 'cuisine', active: true },
    { id: 'limonade', name: 'Limonade artisanale', description: '33 cl · sans alcool', price: 350, station: 'bar', active: true },
    { id: 'eau', name: 'Eau minérale', description: '50 cl', price: 200, station: 'bar', active: true }
  ];

  class Fault extends Error { constructor(m, status) { super(m); this.status = status || 400; } }
  const need = (role, ...roles) => { if (!roles.includes(role)) throw new Fault('Action non autorisée pour ce compte.', 403); };
  const D = p => db.doc(p);
  async function read(p) { const s = await D(p).get(); return s.exists ? s.data() : null; }
  const evKey = () => 'e' + Date.now().toString(36) + hex().slice(0, 5);
  const ev = (a, w) => ({ t: now(), a, w: w || '' });
  const eff = o => (o.status === 'preparing' && Object.values(o.stations || {}).every(Boolean)) ? 'ready' : o.status;

  async function withLock(name, fn) {
    for (let i = 0; i < LOCK_TRIES; i++) {
      const r = await D('locks/' + name).acquire({ holder: TAB, ttlMs: LOCK_TTL });
      if (r.acquired) return fn();
      await sleep(LOCK_WAIT + Math.random() * 300);
    }
    throw new Fault('Un autre responsable attribue en ce moment. Réessayez dans quelques secondes.', 409);
  }

  async function getStaff() { return (await read('config/staff')) || DEFAULT_STAFF; }
  async function seed() {
    if (!(await read('config/menu'))) await D('config/menu').set({ items: DEFAULT_MENU, confirmed: false });
    if (!(await read('config/settings'))) await D('config/settings').set({ open: true, spots: [] });
    if (!(await read('config/staff'))) await D('config/staff').set(DEFAULT_STAFF);
    for (const d of (await getStaff()).drivers) if (!(await read('drivers/' + d.id))) await D('drivers/' + d.id).set({ id: d.id, state: 'absent' });
  }
  const isDriver = async id => (await getStaff()).drivers.some(d => d.id === id);
  async function getMenu() { return (await read('config/menu')) || { items: DEFAULT_MENU, confirmed: false }; }
  async function getSettings() { return (await read('config/settings')) || { open: true, spots: [] }; }

  async function placeOrder(input, requestKey) {
    if (typeof requestKey !== 'string' || !/^[A-F0-9]{8}$/.test(requestKey)) throw new Fault('Référence de demande invalide.');
    if (await read('orders/' + requestKey)) return { id: requestKey, again: true };
    const settings = await getSettings();
    if (!settings.open) throw new Fault('Les commandes en ligne sont momentanément fermées. Merci de revenir un peu plus tard.', 409);
    let spot = String(input.spot || '').trim();
    const name = String(input.name || '').trim(), mode = input.mode;
    if (!/^[A-Za-z0-9 -]{1,16}$/.test(spot)) throw new Fault('Numéro d’emplacement obligatoire (16 caractères maximum).');
    const known = {}; for (const [s] of settings.spots || []) known[s.toUpperCase()] = s;
    if (Object.keys(known).length) {
      if (!known[spot.toUpperCase()]) throw new Fault('Cet emplacement n’existe pas. Vérifiez votre numéro.');
      spot = known[spot.toUpperCase()];
    }
    if (!name || name.length > 60 || !['delivery', 'pickup'].includes(mode)) throw new Fault('Nom et mode de remise obligatoires.');
    const menu = (await getMenu()).items.filter(m => m.active);
    const raw = input.items || {};
    if (typeof raw !== 'object' || !Object.keys(raw).length || Object.keys(raw).some(k => !menu.some(m => m.id === k))) throw new Fault('Panier invalide.');
    const items = [];
    for (const m of menu) {
      const q = raw[m.id] || 0;
      if (!Number.isInteger(q) || q < 0 || q > 20) throw new Fault('Quantité invalide (0 à 20).');
      if (q) items.push({ id: m.id, name: m.name, description: m.description, price: m.price, station: m.station, quantity: q });
    }
    if (!items.length || items.reduce((n, i) => n + i.quantity, 0) > 50) throw new Fault('Panier vide ou trop volumineux.');
    const stations = {}; items.forEach(i => { stations[i.station] = false; });
    const total = items.reduce((n, i) => n + i.price * i.quantity, 0);
    await D('orders/' + requestKey).set({
      id: requestKey, spot, name, mode, items, total, status: 'preparing', stations, trip: null, created: now(),
      events: { e0: ev('Commande reçue', 'client') }
    });
    return { id: requestKey };
  }

  async function validate(oid, station, who) {
    need(who.role, 'manager', 'pizza', 'cuisine', 'bar');
    const o = await read('orders/' + oid);
    if (!o || eff(o) !== 'preparing') throw new Fault('Commande déjà traitée ou inconnue.', 409);
    const st = o.stations; let patch = {}; let label;
    if (station === 'all') { need(who.role, 'manager'); for (const s in st) patch[s] = true; label = null; }
    else {
      if (!(station in st) || (who.role !== 'manager' && who.role !== station)) throw new Fault('Poste non autorisé.', 403);
      if (st[station]) throw new Fault('Poste déjà validé.', 409);
      patch[station] = true; label = 'Poste ' + station + ' prêt';
    }
    const done = Object.values({ ...st, ...patch }).every(Boolean);
    const events = {};
    if (label) events[evKey()] = ev(label, who.actor);
    if (done) events.ready = ev('Commande prête', who.actor);
    await D('orders/' + oid).update({ stations: patch, ...(done ? { status: 'ready' } : {}), events });
    // Validations simultanées : chacun a pu croire que les autres n'étaient pas finis. On relit après écriture ;
    // la clé « ready » est fixe, donc écrire deux fois ne crée pas de doublon.
    const after = await read('orders/' + oid);
    if (after && after.status === 'preparing' && eff(after) === 'ready' && !(after.events && after.events.ready))
      await D('orders/' + oid).update({ status: 'ready', events: { ready: ev('Commande prête', who.actor) } });
  }

  async function assign(ids, driverId, who) {
    need(who.role, 'manager');
    if (!Array.isArray(ids) || !ids.length || ids.length > 20 || new Set(ids).size !== ids.length) throw new Fault('Sélection invalide.');
    return withLock('dispatch', async () => {
      const d = await read('drivers/' + driverId);
      if (!d || d.state !== 'available') throw new Fault('Livreur indisponible ou non validé.', 409);
      const orders = [];
      for (const id of ids) {
        const o = await read('orders/' + id);
        if (!o || eff(o) !== 'ready' || o.trip || o.mode !== 'delivery') throw new Fault('Une commande a déjà été attribuée ou n’est pas prête. Actualisez.', 409);
        orders.push(o);
      }
      const tid = hex();
      await D('trips/' + tid).set({ id: tid, driver: driverId, state: 'assigned', manager: who.actor, created: now() });
      for (const o of orders) await D('orders/' + o.id).update({ status: 'assigned', trip: tid, events: { [evKey()]: ev('Tournée attribuée', who.actor) } });
      await D('drivers/' + driverId).update({ state: 'busy' });
      return { trip: tid };
    });
  }

  async function tripOrders(tid) { return (await db.collection('orders').where('trip', '==', tid).get()).docs.map(d => d.data()); }

  async function presence(who) {
    need(who.role, 'driver');
    if (!(await isDriver(who.actor))) throw new Fault('Compte livreur inconnu.', 403);
    const d = await read('drivers/' + who.actor);
    if (d && d.state !== 'absent') throw new Fault('Présence déjà déclarée.', 409);
    await D('drivers/' + who.actor).set({ id: who.actor, state: 'pending' });
  }
  async function approve(driver, who) {
    need(who.role, 'manager');
    const d = await read('drivers/' + driver);
    if (!d || d.state !== 'pending') throw new Fault('Aucune arrivée à valider.', 409);
    await D('drivers/' + driver).update({ state: 'available', since: now() });
  }
  async function markPresent(driver, who) {
    need(who.role, 'manager');
    if (!(await isDriver(driver))) throw new Fault('Livreur inconnu.', 409);
    const d = await read('drivers/' + driver);
    if (d && !['absent', 'pending'].includes(d.state)) throw new Fault('Ce livreur est déjà disponible ou en tournée.', 409);
    await D('drivers/' + driver).set({ id: driver, state: 'available', since: now() });
  }
  async function markAbsent(driver, who) {
    need(who.role, 'manager');
    const d = await read('drivers/' + driver);
    if (!d || d.state === 'busy') throw new Fault('Ce livreur est en tournée : attendez son retour.', 409);
    await D('drivers/' + driver).set({ id: driver, state: 'absent', since: null });
  }
  async function saveStaff(input, who) {
    need(who.role, 'manager');
    const old = await getStaff(), out = {}, seen = new Set();
    for (const [g, max, prefix] of [['managers', 10, 'm'], ['drivers', 30, 'd']]) {
      const raw = input && input[g];
      if (!Array.isArray(raw) || raw.length > max) throw new Fault('Liste de l’équipe invalide.');
      const known = new Set(old[g].map(x => x.id));
      out[g] = raw.map(x => {
        const name = String((x && x.name) || '').trim();
        if (!name || name.length > 40) throw new Fault('Chaque personne a besoin d’un nom (40 caractères maximum).');
        let id = x.id && known.has(x.id) && !seen.has(x.id) ? x.id : prefix + hex().slice(0, 6).toLowerCase();
        seen.add(id); return { id, name };
      });
    }
    if (!out.managers.length) throw new Fault('Gardez au moins un responsable.');
    const removed = old.drivers.filter(d => !out.drivers.some(x => x.id === d.id));
    for (const d of removed) { const s = await read('drivers/' + d.id); if (s && s.state === 'busy') throw new Fault(d.name + ' est en tournée : attendez son retour avant de le retirer.', 409); }
    await D('config/staff').set(out);
    for (const d of removed) await D('drivers/' + d.id).delete();
    for (const d of out.drivers) if (!(await read('drivers/' + d.id))) await D('drivers/' + d.id).set({ id: d.id, state: 'absent' });
  }
  const METHODS = { cash: 'espèces', card: 'carte' };
  const checkMethod = m => { if (!METHODS[m]) throw new Fault('Choisissez le mode de paiement : espèces ou carte.'); };
  // Le livreur de la tournée agit ; un responsable peut le faire en dépannage.
  async function tripFor(tid, who, msg) {
    need(who.role, 'driver', 'manager');
    const t = await read('trips/' + tid);
    if (!t || (who.role === 'driver' && t.driver !== who.actor)) throw new Fault(msg, 409);
    return t;
  }
  const by = (who, t) => (who.role === 'manager' ? ' (confirmé par un responsable)' : '');
  async function depart(tid, who) {
    const t = await tripFor(tid, who, 'Départ impossible.');
    if (t.state !== 'assigned') throw new Fault('Départ impossible.', 409);
    await D('trips/' + tid).update({ state: 'delivering', departed: now() });
    for (const o of await tripOrders(tid)) await D('orders/' + o.id).update({ status: 'delivering', events: { [evKey()]: ev('Livraison en cours' + by(who), who.actor) } });
  }
  async function deliver(oid, who, method) {
    need(who.role, 'driver', 'manager');
    checkMethod(method);
    const o = await read('orders/' + oid);
    const t = o && o.trip ? await read('trips/' + o.trip) : null;
    if (!o || !t || (who.role === 'driver' && t.driver !== who.actor) || !['assigned', 'delivering'].includes(o.status)) throw new Fault('Livraison impossible.', 409);
    if (t.state === 'assigned') await depart(t.id, who); // première remise = la tournée démarre toute seule
    await D('orders/' + oid).update({ status: 'delivered', paid: { method, amount: o.total, by: t.driver, at: now(), confirmedBy: who.actor },
      events: { [evKey()]: ev('Commande livrée · payée en ' + METHODS[method] + by(who), who.actor) } });
    return { closed: await closeIfDone(t.id) };
  }
  // Dernière remise de la tournée : la tournée se termine et le livreur redevient disponible tout de suite.
  // Relu après écriture : si deux remises arrivent en même temps, la dernière à écrire ferme la tournée.
  async function closeIfDone(tid) {
    const os = await tripOrders(tid);
    if (!os.length || os.some(o => o.status !== 'delivered')) return false;
    const t = await read('trips/' + tid);
    if (!t || t.state === 'done') return true;
    await D('trips/' + tid).update({ state: 'done', returned: now() });
    const d = await read('drivers/' + t.driver);
    if (d && d.state === 'busy') await D('drivers/' + t.driver).update({ state: 'available' });
    return true;
  }
  // Erreur de bouton : le livreur peut annuler sa remise pendant 2 minutes ; un responsable, tant que la tournée n'est pas terminée.
  async function undoDeliver(oid, who) {
    need(who.role, 'driver', 'manager');
    const o = await read('orders/' + oid);
    const t = o && o.trip ? await read('trips/' + o.trip) : null;
    if (!o || !t || o.status !== 'delivered' || (who.role === 'driver' && t.driver !== who.actor)) throw new Fault('Annulation impossible.', 409);
    if (who.role === 'driver' && (!o.paid || Date.parse(now()) - Date.parse(o.paid.at) > 120000)) throw new Fault('Trop tard pour annuler : demandez au responsable.', 409);
    if (t.state === 'done') {
      // Tournée fermée automatiquement : on la rouvre, seulement si le livreur n'est pas déjà reparti.
      const d = await read('drivers/' + t.driver);
      if (!d || d.state !== 'available') throw new Fault('Le livreur est déjà reparti : annulation impossible.', 409);
      await D('trips/' + t.id).update({ state: 'delivering', returned: null });
      await D('drivers/' + t.driver).update({ state: 'busy' });
    }
    await D('orders/' + oid).update({ status: 'delivering', paid: null, events: { [evKey()]: ev('Remise annulée' + by(who), who.actor) } });
  }
  async function returnTrip(tid, who) {
    const t = await tripFor(tid, who, 'Terminez les livraisons avant de confirmer le retour.');
    if (t.state !== 'delivering' || (await tripOrders(tid)).some(o => o.status !== 'delivered')) throw new Fault('Terminez les livraisons avant de confirmer le retour.', 409);
    await D('trips/' + tid).update({ state: 'done', returned: now() });
    await D('drivers/' + t.driver).update({ state: 'available' });
  }
  async function collect(oid, who, method) {
    need(who.role, 'manager');
    checkMethod(method);
    const o = await read('orders/' + oid);
    if (!o || o.mode !== 'pickup' || eff(o) !== 'ready') throw new Fault('Retrait impossible.', 409);
    await D('orders/' + oid).update({ status: 'collected', paid: { method, amount: o.total, by: 'restaurant', at: now(), confirmedBy: who.actor },
      events: { [evKey()]: ev('Commande retirée · payée en ' + METHODS[method], who.actor) } });
  }
  // Caisse : ce qu'une personne (livreur, ou « restaurant ») a encaissé depuis un instant donné.
  function cashUp(orders, holder, since) {
    const r = { count: 0, cash: 0, card: 0, total: 0 };
    for (const o of orders) {
      const p = o.paid;
      if (!p || p.by !== holder || (since && p.at < since)) continue;
      r.count++; r[p.method] += p.amount; r.total += p.amount;
    }
    return r;
  }

  async function saveMenu(raw, who) {
    need(who.role, 'manager');
    if (!Array.isArray(raw) || !raw.length || raw.length > 60) throw new Fault('La carte doit contenir entre 1 et 60 produits.');
    const known = new Set(((await getMenu()).items || []).map(i => i.id)), seen = new Set(), items = [];
    for (const it of raw) {
      if (!it || typeof it !== 'object') throw new Fault('Carte invalide.');
      const name = String(it.name || '').trim(), desc = String(it.description || '').trim(), price = it.price, st = it.station;
      if (!name || name.length > 60 || desc.length > 120) throw new Fault('Chaque produit a besoin d’un nom (60 caractères maximum) ; la description fait 120 caractères maximum.');
      if (!Number.isInteger(price) || price < 0 || price > 50000) throw new Fault('Prix invalide pour « ' + name + ' » (de 0 à 500 €).');
      if (!STATIONS.includes(st)) throw new Fault('Poste invalide pour « ' + name + ' ».');
      let pid = it.id;
      if (pid) { if (typeof pid !== 'string' || seen.has(pid)) throw new Fault('Carte invalide.'); if (!known.has(pid)) pid = null; }
      if (!pid) pid = 'p' + hex().slice(0, 6).toLowerCase();
      seen.add(pid); items.push({ id: pid, name, description: desc, price, station: st, active: it.active !== false });
    }
    if (!items.some(i => i.active)) throw new Fault('Au moins un produit doit rester proposé.');
    await D('config/menu').set({ items, confirmed: true });
  }
  async function saveSettings(input, who) {
    need(who.role, 'manager');
    if (typeof input.open !== 'boolean' || typeof input.spots !== 'string' || input.spots.length > 40000) throw new Fault('Réglages invalides.');
    const spots = [], seen = new Set();
    for (let line of input.spots.split(/\r?\n/)) {
      line = line.trim(); if (!line) continue;
      const i = line.indexOf(';'), sp = (i < 0 ? line : line.slice(0, i)).trim(), hint = i < 0 ? '' : line.slice(i + 1).trim();
      if (!/^[A-Za-z0-9 -]{1,16}$/.test(sp) || hint.length > 60) throw new Fault('Ligne invalide : « ' + line.slice(0, 40) + ' ». Format : numéro ; repère (facultatif).');
      if (seen.has(sp.toUpperCase())) throw new Fault('Emplacement en double : ' + sp + '.');
      seen.add(sp.toUpperCase()); spots.push([sp, hint]);
    }
    if (spots.length > 1500) throw new Fault('1500 emplacements maximum.');
    await D('config/settings').set({ open: input.open, spots });
  }
  async function setMap(id, who) { need(who.role, 'manager'); await D('config/map').set({ id: String(id), t: now() }); }

  async function listAll(col) { return (await db.collection(col).get()).docs.map(d => d.data()); }
  async function exportAll() {
    return JSON.stringify({
      app: 'CAMPING OS 76', exportedAt: now(),
      menu: await read('config/menu'), settings: await read('config/settings'), map: await read('config/map'), staff: await read('config/staff'),
      drivers: await listAll('drivers'), trips: await listAll('trips'), orders: await listAll('orders')
    }, null, 1);
  }
  async function resetTests(confirm, who) {
    need(who.role, 'manager');
    if (confirm !== 'EFFACER') throw new Fault('Tapez EFFACER pour confirmer.');
    for (const col of ['orders', 'trips']) for (const x of await listAll(col)) await D(col + '/' + x.id).delete();
    for (const d of (await getStaff()).drivers) await D('drivers/' + d.id).set({ id: d.id, state: 'absent', since: null });
  }

  return { Fault, METHODS, cashUp, undoDeliver, closeIfDone, STATIONS, POSTES, DEFAULT_STAFF, accountsOf, getStaff, markPresent, markAbsent, saveStaff, DEFAULT_MENU, eff, seed, getMenu, getSettings, placeOrder, validate, assign, presence, approve, depart, deliver, returnTrip, collect, saveMenu, saveSettings, setMap, exportAll, resetTests, hex };
}

/* CAMPING OS 76 — interface (le76.fr/camping). Les règles métier sont appliquées par le serveur (api.php). */
(async function () {
  'use strict';
  const $ = s => document.querySelector(s), app = $('#app');
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = n => (n / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
  const hhmm = iso => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  const lsGet = k => { try { return localStorage.getItem(k) } catch (e) { return null } };
  const lsSet = (k, v) => { try { localStorage.setItem(k, v) } catch (e) { } };
  const lsDel = k => { try { localStorage.removeItem(k) } catch (e) { } };
  const ssGet = k => { try { return sessionStorage.getItem(k) } catch (e) { return null } };
  const ssSet = (k, v) => { try { sessionStorage.setItem(k, v) } catch (e) { } };
  const ssDel = k => { try { sessionStorage.removeItem(k) } catch (e) { } };
  const labels = { preparing: 'En préparation', ready: 'Prête', assigned: 'Tournée attribuée', delivering: 'En livraison', delivered: 'Livrée', collected: 'Retirée', absent: 'Absent', pending: 'Arrivée à valider', available: 'Disponible', busy: 'En tournée', returning: 'Retour attendu', done: 'Terminée' };
  const newKey = () => Array.from(crypto.getRandomValues(new Uint8Array(4)), n => n.toString(16).padStart(2, '0')).join('').toUpperCase();
  const API = 'api.php';
  const base = makeCore(null, {});
  async function call(a, data) {
    let r, d;
    try {
      r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ a }, data || {})) });
      d = await r.json();
    } catch (e) { throw new base.Fault('Connexion impossible. Vérifiez le réseau et réessayez.', 503); }
    if (!r.ok || d.ok === false) throw new base.Fault(d.erreur || 'Action impossible.', r.status);
    return d;
  }
  let me = null, installe = true, canManage = false, rev = null, lastSig = '', lastMe = '';
  const mut = (a, data) => call(a, Object.assign({ as: actor || '' }, data || {})).then(async r => { await poll(true); return r; });
  const core = Object.assign({}, base, {
    placeOrder: (input, key) => call('commander', { input, key }).then(async r => { track = r.id; await poll(true); return r; }),
    validate: (id, station) => mut('valider', { id, station }),
    assign: (ids, driver) => mut('confier', { ids, driver }),
    presence: () => mut('presence'),
    approve: driver => mut('approuver', { driver }),
    markPresent: driver => mut('present', { driver }),
    markAbsent: driver => mut('absent', { driver }),
    saveStaff: staff => mut('equipe', { staff }),
    depart: trip => mut('depart', { trip }),
    deliver: (id, _w, method) => mut('remise', { id, method }),
    undoDeliver: id => mut('annuler', { id }),
    returnTrip: trip => mut('retour', { trip }),
    collect: (id, _w, method) => mut('retrait', { id, method }),
    saveMenu: items => mut('carte', { items }),
    saveSettings: settings => mut('reglages', { settings }),
    resetTests: confirm => mut('effacer', { confirm }),
    setMap: () => poll(true),
    exportAll: async () => '',
    seed: async () => { }
  });
  const dl = { save: async () => { location.href = API + '?a=export'; return { status: 'saved' }; } };
  const assetsNs = {
    upload: async f => {
      const fd = new FormData(); fd.append('a', 'plan'); fd.append('as', actor || ''); fd.append('plan', f);
      let d; try { const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }); d = await r.json(); } catch (e) { throw new base.Fault('Envoi impossible.', 503); }
      if (!d.ok) throw new base.Fault(d.erreur || 'Envoi impossible.');
      return { id: d.id };
    }
  };
  let A = core.accountsOf(core.DEFAULT_STAFF);

  const S = { staff: core.DEFAULT_STAFF, menu: { items: core.DEFAULT_MENU, confirmed: false }, settings: { open: true, spots: [] }, map: null, orders: [], drivers: [], trips: [], got: new Set() };
  let view = 'client', tab = 'orders', actor = ssGet('campingActor'), mode = 'delivery', cart = {}, selected = new Set(), draftMenu = null, draftStaff = null;
  let form = { spot: '', name: '', consent: false }, requestKey = newKey(), track = lsGet('campingTrack'), dirty = false;
  const checkActor = () => { if (actor && (!A[actor] || (A[actor][1] === 'manager' && !canManage))) { actor = null; ssDel('campingActor'); } };
  const role = () => (actor ? A[actor][1] : null), who = () => ({ actor, role: role() });
  const eff = core.eff;

  /* ---------- messages ---------- */
  function notice(s) { const n = $('#notice'); n.textContent = s; n.style.display = 'block'; clearTimeout(window.__toast); window.__toast = setTimeout(() => { n.style.display = 'none'; }, 5000); }
  function friendly(e) {
    if (e instanceof core.Fault) return e.message;
    const c = e && e.code;
    if (c === 'invalid_argument') return 'Action refusée : votre accès ne permet pas de modifier ces données (il faut au moins le niveau Contributeur sur cette page).';
    if (c === 'quota_exceeded') return 'La base partagée est pleine. Utilisez « Remise à zéro des commandes » dans Mon camping.';
    if (c === 'revoked') return 'Votre accès a été retiré. Rechargez la page.';
    if (c === 'resource_exhausted') return 'Trop d’actions à la fois. Patientez un instant.';
    return (e && e.message) || 'Action impossible.';
  }
  async function act(fn, msg) { try { const r = await fn(); if (r !== false) notice(r && r.closed ? (role() === 'driver' ? 'Tournée terminée : vous êtes disponible.' : 'Tournée terminée : livreur disponible.') : msg || 'C’est enregistré.'); } catch (e) { notice(friendly(e)); } rerender(true); }

  /* ---------- données : le serveur est interrogé toutes les 3 secondes ---------- */
  let polling = false;
  async function poll(force) {
    if (polling && !force) return;
    polling = true;
    try {
      const d = await call('etat', { rev: force ? null : rev, track: track || '' });
      const meSig = JSON.stringify(d.me), meChanged = meSig !== lastMe; lastMe = meSig;
      me = d.me; installe = d.installe; canManage = !!(me && me.admin);
      let changed = false;
      if (!d.same) {
        rev = d.rev;
        const sig = JSON.stringify([d.orders, d.menu, d.settings, d.trips, d.drivers, d.staff, d.map]);
        if (sig !== lastSig) {
          lastSig = sig; changed = true;
          S.staff = d.staff || core.DEFAULT_STAFF; A = core.accountsOf(S.staff);
          S.menu = d.menu; S.settings = d.settings; S.map = d.map;
          S.orders = (d.orders || []).slice().sort((a, b) => (a.created < b.created ? 1 : -1));
          S.drivers = d.drivers || []; S.trips = d.trips || [];
          ['staff', 'menu', 'settings', 'orders', 'drivers', 'trips'].forEach(k => S.got.add(k));
        }
      }
      if (changed || meChanged) { checkActor(); polling = false; rerender(); return; }
    } catch (e) { if (!S.got.size) app.innerHTML = `<div class="panel empty">${esc(friendly(e))}</div>`; }
    polling = false;
  }
  setInterval(() => { if (!document.hidden) poll(); }, 3000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(true); });

  /* ---------- dérivés ---------- */
  const loaded = () => ['staff', 'menu', 'settings', 'orders', 'drivers', 'trips'].every(k => S.got.has(k));
  const orderById = id => S.orders.find(o => o.id === id);
  const tripOrders = t => S.orders.filter(o => o.trip === t.id);
  const tripEff = t => (t.state === 'delivering' && tripOrders(t).length && tripOrders(t).every(o => o.status === 'delivered') ? 'returning' : t.state);
  const driverList = () => (S.staff.drivers || []).map(x => { const doc = S.drivers.find(d => d.id === x.id) || { state: 'absent' }; return { id: x.id, name: x.name, state: doc.state, since: doc.since || null }; });
  const cashLine = c => (c.count ? `${c.count} encaissement${c.count > 1 ? 's' : ''} · ${money(c.total)} (espèces ${money(c.cash)} · carte ${money(c.card)})` : 'aucun encaissement');
  const todayStart = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d.toISOString(); };
  const activeTrip = id => S.trips.filter(t => t.driver === id && t.state !== 'done').sort((a, b) => (a.created < b.created ? 1 : -1))[0];
  function tripHelp(d) {
    const t = activeTrip(d.id); if (!t) return '';
    const e = tripEff(t), os = tripOrders(t), n = os.filter(o => o.status === 'delivered').length;
    return `<br><small>Tournée ${esc(t.id)} · ${e === 'assigned' ? 'pas encore partie' : e === 'returning' ? 'retour attendu' : `remises ${n}/${os.length}`}</small>${e === 'assigned' ? `<br><button data-depart="${esc(t.id)}">Dépannage : démarrer pour ${esc(d.name)}</button>` : e === 'returning' ? `<br><button data-ret="${esc(t.id)}">Dépannage : confirmer son retour</button>` : ''}`;
  }
  const hints = () => { const h = {}; for (const [s, t] of S.settings.spots || []) h[s.toUpperCase()] = t; return h; };
  const hintOf = o => hints()[String(o.spot).toUpperCase()] || '';
  const finished = o => ['delivered', 'collected'].includes(eff(o));
  const events = o => Object.values(o.events || {}).sort((a, b) => (a.t < b.t ? -1 : 1));

  /* ---------- navigation / rendu ---------- */
  function nav(v) { view = v; document.querySelectorAll('nav button').forEach(b => b.classList.toggle('active', b.dataset.view === v)); rerender(true); }
  $('nav').onclick = e => { if (e.target.dataset.view) nav(e.target.dataset.view); };
  const editing = () => { const a = document.activeElement; return a && app.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && a.type !== 'checkbox'; };
  function rerender(force) {
    if (!force) {
      if (view === 'team' && tab === 'camping' && role() === 'manager') return;
      if (editing()) { dirty = true; return; }
    }
    dirty = false; render();
  }
  document.addEventListener('focusout', () => { if (dirty) setTimeout(() => { if (dirty && !editing()) rerender(true); }, 300); });
  function render() {
    if (!loaded()) { app.innerHTML = '<div class="panel empty">Connexion à la base partagée…</div>'; return; }
    if (view === 'client') client(); else if (view === 'track') tracking(); else team();
  }

  /* ---------- client ---------- */
  const menuItems = () => S.menu.items.filter(m => m.active);
  function client() {
    const demo = !S.menu.confirmed, open = S.settings.open;
    app.innerHTML = `<section class="hero"><div><span class="eyebrow">LE RESTAURANT VIENT À VOUS</span><h1>Vos vacances.<br>Notre cuisine.</h1><p>Commandez depuis votre mobil-home. On vous livre gratuitement, ou on vous retrouve au restaurant.</p><span class="tag">Livraison offerte · Emplacement obligatoire</span></div><div class="hero-art" aria-hidden="true">🍕</div></section>${open ? '' : '<div class="note">Les commandes en ligne sont momentanément fermées. Merci de revenir un peu plus tard.</div>'}<div class="grid"><section><div class="row between"><h2>À la carte</h2><span class="muted">${demo ? 'Carte de démonstration' : 'La carte'}</span></div><div class="menu">${menuItems().map(m => `<article class="card"><span class="tag">${esc(m.station === 'pizza' ? 'Au four à pizza' : m.station === 'bar' ? 'Côté bar' : 'Côté cuisine')}</span><h3>${esc(m.name)}</h3><p class="muted">${esc(m.description)}</p><div class="row between bottom"><span class="price">${money(m.price)}</span><button data-add="${esc(m.id)}" ${open ? '' : 'disabled'} aria-label="Ajouter ${esc(m.name)}">+ Ajouter</button></div></article>`).join('')}</div></section><aside class="panel cart"><h2>Votre panier</h2><div id="cart"></div></aside></div>`;
    drawCart();
  }
  function drawCart() {
    const demo = !S.menu.confirmed, open = S.settings.open, spots = (S.settings.spots || []).map(x => x[0]);
    for (const k of Object.keys(cart)) if (!menuItems().some(m => m.id === k) || !cart[k]) delete cart[k];
    const items = menuItems().filter(m => cart[m.id]);
    $('#cart').innerHTML = items.length ? `${items.map(m => `<div class="cart-line"><div><strong>${esc(m.name)}</strong><br><small>${money(m.price)} × ${cart[m.id]}</small></div><div><button data-minus="${esc(m.id)}" aria-label="Retirer une unité">−</button> <button data-plus="${esc(m.id)}" aria-label="Ajouter une unité">+</button></div></div>`).join('')}<div class="row between total"><span>Total</span><span>${money(items.reduce((n, m) => n + m.price * cart[m.id], 0))}</span></div><small>Livraison : <strong>gratuite</strong></small><div class="choice"><button type="button" data-mode="delivery" class="${mode === 'delivery' ? 'active' : ''}">Livraison</button><button type="button" data-mode="pickup" class="${mode === 'pickup' ? 'active' : ''}">Retrait</button></div><form id="checkout"><label for="f-spot">Emplacement / mobil-home *</label><input id="f-spot" name="spot" required maxlength="16" pattern="[A-Za-z0-9 \\-]+" placeholder="Ex. 127" list="spots" autocomplete="off" value="${esc(form.spot)}">${spots.length ? `<datalist id="spots">${spots.map(s => `<option value="${esc(s)}">`).join('')}</datalist>` : ''}<label for="f-name">Votre prénom *</label><input id="f-name" name="name" required maxlength="60" placeholder="Ex. Camille" value="${esc(form.name)}"><div class="note">Paiement à la remise de la commande (espèces ou carte). Aucun paiement en ligne.${demo ? ' Carte de démonstration : plats et prix d’exemple.' : ''}</div><label class="inl chk" for="f-consent"><input id="f-consent" type="checkbox" name="consent" required ${form.consent ? 'checked' : ''}>Je confirme ma commande.</label><button class="primary full" style="margin-top:18px" ${open ? '' : 'disabled'}>Commander · paiement à la remise</button></form>` : '<div class="empty">Un petit creux ?<br>Ajoutez votre premier plat.</div>';
  }
  async function submitOrder(formEl) {
    const btn = formEl.querySelector('button.primary'); btn.disabled = true;
    try {
      const d = await core.placeOrder({ spot: form.spot, name: form.name, mode, items: cart }, requestKey);
      track = d.id; lsSet('campingTrack', track); cart = {}; form.consent = false; requestKey = newKey(); nav('track');
    } catch (e) { notice(friendly(e)); btn.disabled = false; }
  }

  /* ---------- suivi ---------- */
  function tracking() {
    if (!track) {
      app.innerHTML = '<div class="login panel"><h2>Votre commande</h2><p>Le code de suivi apparaît après votre commande (8 caractères, ex. 3FA9C1D2). Vous pouvez aussi le saisir ici.</p><form id="find"><label for="t-code">Code de suivi</label><input id="t-code" name="token" required maxlength="8" autocomplete="off"><button class="primary">Afficher</button></form></div>';
      return;
    }
    const o = orderById(String(track).toUpperCase());
    if (!o) { app.innerHTML = `<div class="panel"><p class="error">Commande introuvable pour le code ${esc(track)}.</p><button id="other">Saisir un autre code</button></div>`; return; }
    const s = eff(o), pick = o.mode === 'pickup';
    const evs = events(o), at = re => { const e = evs.find(x => re.test(x.a)); return e ? e.t : null; };
    const trip = o.trip ? S.trips.find(t => t.id === o.trip) : null;
    const dname = trip ? ((S.staff.drivers || []).find(d => d.id === trip.driver) || {}).name : '';
    const kitchen = Object.entries(o.stations).map(([k, v]) => `${{ pizza: 'pizza', cuisine: 'cuisine', bar: 'boissons' }[k] || k} ${v ? '✓' : '…'}`).join(' · ');
    const paidTxt = o.paid ? ` · payée en ${core.METHODS[o.paid.method]}` : '';
    const steps = pick ? [
      ['Commande reçue', o.created, 'Le restaurant a bien reçu votre commande.'],
      ['En cuisine', o.created, 'Préparation en cours : ' + kitchen + '.'],
      ['Prête à retirer', at(/prête/i), 'Votre commande vous attend au comptoir du restaurant.'],
      ['Retirée', o.paid && o.paid.at, 'Bon appétit !' + paidTxt]
    ] : [
      ['Commande reçue', o.created, 'Le restaurant a bien reçu votre commande.'],
      ['En cuisine', o.created, 'Préparation en cours : ' + kitchen + '.'],
      ['Prête', at(/prête/i), 'Votre commande est prête et attend son livreur.'],
      ['En route', at(/Tournée attribuée/), (dname ? esc(dname) + ' vous l’apporte' : 'Un livreur vous l’apporte') + ' en golfette, à l’emplacement ' + esc(o.spot) + '.'],
      ['Livrée', o.paid && o.paid.at, 'Bon appétit !' + paidTxt]
    ];
    const cur = pick ? ({ preparing: 1, ready: 2, collected: 3 }[s] ?? 1) : ({ preparing: 1, ready: 2, assigned: 3, delivering: 3, delivered: 4 }[s] ?? 1);
    const last = cur === steps.length - 1;
    const head = last ? (pick ? 'Commande retirée' : 'Commande livrée') : steps[cur][0];
    app.innerHTML = `<div class="hero"><div><span class="eyebrow">VOTRE COMMANDE · ${esc(o.id)}</span><h1>${esc(head)}</h1><p>Bonjour ${esc(o.name)}. ${steps[cur][2]}</p><span class="tag">${o.paid ? 'Payée' : 'À régler à la remise'} · ${money(o.total)}</span></div><div class="hero-art" aria-hidden="true">${pick ? '🛍' : '🛺'}</div></div><div class="grid"><section class="panel"><h2>Où en est ma commande ?</h2><ol class="ctrack">${steps.map(([label, t, txt], i) => `<li class="${i < cur || last ? 'done' : i === cur ? 'now' : ''}"><span class="dot">${i < cur || last ? '✓' : i + 1}</span><div><strong>${label}</strong>${t && (i <= cur) ? `<small> · ${hhmm(t)}</small>` : ''}${i === cur && !last ? `<p>${txt}</p>` : ''}</div></li>`).join('')}</ol><p class="muted">Cette page se met à jour toute seule. Pas d’horaire estimé : on vous livre dès que c’est prêt.</p><button id="copy">Copier mon lien de suivi</button> <button id="other">Autre suivi</button></section><aside class="panel"><h2>Le récapitulatif</h2>${o.items.map(i => `<p>${i.quantity} × ${esc(i.name)} <strong>${money(i.price * i.quantity)}</strong></p>`).join('')}<hr><strong>Total ${money(o.total)}</strong><p class="muted">${pick ? 'Retrait au restaurant' : 'Livraison gratuite à l’emplacement ' + esc(o.spot)} · paiement à la remise (espèces ou carte)</p><small>Conservez votre code : il permet de retrouver cette commande.</small></aside></div>`;
  }

  /* ---------- équipe ---------- */
  function login() {
    const opts = Object.entries(A).filter(([id, v]) => v[1] !== 'manager' || canManage);
    app.innerHTML = `<div class="login panel"><span class="eyebrow">ESPACE ÉQUIPE</span><h1 style="font-size:38px">Bonjour l’équipe.</h1><p class="muted">Choisissez votre compte.</p><form id="login"><label for="l-actor">Compte</label><select id="l-actor" name="actor">${opts.map(([id, v]) => `<option value="${id}">${esc(v[0])}${v[1] === 'driver' ? '' : v[1] === 'manager' ? '' : ' · téléphone'}</option>`).join('')}</select><button class="primary full" style="margin-top:14px">Entrer</button></form><div class="note">Connecté : <strong>${esc(me.nom)}</strong>${canManage ? ' · responsable' : ''}. <button type="button" id="deco" class="sm">Se déconnecter</button></div></div>`;
  }
  function staffLogin() {
    app.innerHTML = installe ? `<div class="login panel"><span class="eyebrow">ESPACE ÉQUIPE</span><h1 style="font-size:38px">Bonjour l’équipe.</h1><p class="muted">Connectez-vous avec votre compte du 76, le même que pour les réservations.</p><form id="staff-login"><label for="s-id">Identifiant</label><input id="s-id" name="identifiant" autocomplete="username" autocapitalize="none" required><label for="s-mdp">Mot de passe</label><input id="s-mdp" name="mdp" type="password" autocomplete="current-password" required><button class="primary full" style="margin-top:14px">Se connecter</button></form><p class="muted"><a href="/equipe/">Mot de passe oublié ou nouveau compte : appli des réservations</a></p></div>` : '<div class="login panel"><span class="eyebrow">ESPACE ÉQUIPE</span><h2>Aucun compte pour l’instant</h2><p class="muted">Créez d’abord le compte responsable dans l’appli des réservations, <a href="/equipe/">le76.fr/equipe</a>, puis revenez ici.</p></div>';
  }
  async function staffConnect(f) {
    const b = f.querySelector('button'); b.disabled = true;
    try {
      const r = await fetch('/api/equipe.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'connexion', identifiant: f.identifiant.value.trim(), mdp: f.mdp.value }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || d.ok === false) throw new Error(d.erreur || 'Connexion impossible.');
      await poll(true); rerender(true);
    } catch (e) { notice(e.message); b.disabled = false; }
  }
  async function staffLogout() {
    try { await fetch('/api/equipe.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'deconnexion' }) }); } catch (e) { }
    me = null; actor = null; ssDel('campingActor'); await poll(true); rerender(true);
  }
  function team() {
    if (!me) return staffLogin();
    if (!actor) return login();
    const manager = role() === 'manager', driver = role() === 'driver';
    if (manager && tab === 'camping') return drawAdmin();
    if (manager && tab === 'drivers') return drawDispatch();
    if (driver) return drawDriver();
    let os = S.orders;
    if (driver) { const mine = S.trips.filter(t => t.driver === actor).map(t => t.id); os = os.filter(o => mine.includes(o.trip)); }
    if (['pizza', 'cuisine', 'bar'].includes(role())) os = os.filter(o => role() in o.stations && !finished(o));
    const open = os.filter(o => !finished(o)), done = os.filter(finished), drivers = driverList();
    selected = new Set([...selected].filter(id => open.some(o => o.id === id && eff(o) === 'ready' && !o.trip)));
    app.innerHTML = `<div class="toolbar"><div><span class="eyebrow">${driver ? 'CAMPING DELIVERY' : 'SERVICE & ORGANISATION'}</span><h1>${esc(A[actor][0])}</h1></div><span class="refresh">En direct · ${new Date().toLocaleTimeString('fr-FR')}</span>${manager ? segHtml() : ''}<button id="logout">Changer de compte</button></div>${manager ? `<div class="stats"><div class="stat"><strong>${open.filter(o => eff(o) === 'preparing').length}</strong>En préparation</div><div class="stat"><strong>${open.filter(o => eff(o) === 'ready').length}</strong>Prêtes à partir</div><div class="stat"><strong>${drivers.filter(d => d.state === 'available').length}</strong>Livreurs disponibles</div></div>` : ''}${driver ? driverPanel(drivers) : ''}<div class="staff-grid"><section><h2>${driver ? 'Ma tournée' : manager ? 'Les commandes' : 'Validation mobile · ' + esc(role())}</h2><p class="muted">${driver ? 'Vérifiez l’emplacement avant chaque remise.' : manager ? 'Tickets papier en cuisine. Validation sur téléphone.' : 'Validez votre poste lorsque les produits sont prêts. Aucun écran cuisine dédié requis.'}</p><div class="orders">${open.map(o => orderCard(o, manager)).join('') || '<div class="panel empty">Tout est à jour. Aucune commande en attente.</div>'}</div>${manager ? `<details style="margin-top:24px"><summary>Commandes terminées (${done.length}) · ${cashLine(core.cashUp(done.map(o => ({ ...o, paid: o.paid && { ...o.paid, by: '*' } })), '*', todayStart()))} aujourd’hui</summary><div class="orders">${done.map(o => orderCard(o, true)).join('')}</div></details>` : ''}</section><aside>${manager ? managerPanel(drivers) : ''}${driver ? mapPanel(os) : ''}${!manager && !driver ? '<div class="panel"><h3>Le bon réflexe</h3><p>Affichez le ticket de votre poste puis validez la préparation sur ce téléphone.</p><small>La commande devient prête lorsque tous ses postes sont validés.</small></div>' : ''}</aside></div>`;
  }
  function orderCard(o, manager) {
    const s = eff(o), h = hintOf(o);
    return `<article class="card order ${selected.has(o.id) ? 'selected' : ''}"><div class="head"><div><span class="eyebrow">${o.mode === 'delivery' ? 'LIVRAISON GRATUITE' : 'RETRAIT'}</span><h3>Emplacement ${esc(o.spot)}${h ? ' · ' + esc(h) : ''}</h3><small>${esc(o.name)} · #${esc(o.id)} · ${hhmm(o.created)}</small></div><span class="status ${s}">${esc(labels[s])}</span></div><ul>${o.items.map(i => `<li>${i.quantity} × ${esc(i.name)}</li>`).join('')}</ul><p>${Object.entries(o.stations).map(([k, v]) => `<span class="tag">${esc(k)} ${v ? '✓' : 'en préparation'}</span>`).join(' ')}</p><div class="row between"><small>${o.paid ? `${money(o.paid.amount)} · payée en ${core.METHODS[o.paid.method]}` : money(o.total) + ' · à encaisser à la remise'}</small><button data-print="${esc(o.id)}">Voir les tickets</button></div>${s === 'preparing' ? `<div>${Object.entries(o.stations).filter(([k, v]) => !v && (manager || k === role())).map(([k]) => `<button data-ready="${esc(o.id)}" data-station="${k}">Valider ${k}</button>`).join('')}${manager ? `<button class="primary" data-ready="${esc(o.id)}" data-station="all">Tout est prêt</button>` : ''}</div>` : ''}${manager && s === 'ready' && o.mode === 'delivery' && !o.trip ? `<label class="inl chk"><input type="checkbox" data-select="${esc(o.id)}" ${selected.has(o.id) ? 'checked' : ''}>Ajouter à la prochaine tournée</label>` : ''}${manager && s === 'ready' && o.mode === 'pickup' ? `<div class="pay"><button class="primary" data-collect="${esc(o.id)}" data-method="cash">Retirée · Espèces</button><button class="primary" data-collect="${esc(o.id)}" data-method="card">Retirée · Carte</button></div>` : ''}${manager && ['assigned', 'delivering'].includes(s) ? `<p class="muted dep">Dépannage, si le livreur ne peut pas confirmer :</p><div class="pay"><button data-deliver="${esc(o.id)}" data-method="cash">Remise · Espèces</button><button data-deliver="${esc(o.id)}" data-method="card">Remise · Carte</button></div>` : ''}</article>`;
  }
  function managerPanel(drivers) {
    return `<div class="panel"><h2>Prochaine tournée</h2><p><strong>${selected.size}</strong> commande(s) sélectionnée(s)</p><label for="assign-driver">Livreur validé</label><select id="assign-driver"><option value="">Choisir un livreur</option>${drivers.filter(d => d.state === 'available').map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('')}</select>${drivers.some(d => d.state === 'available') ? '' : '<p class="note">Aucun livreur disponible : touchez « Marquer présent » sous « Les livreurs ».</p>'}<button class="primary full" id="assign" ${selected.size ? '' : 'disabled'}>Attribuer la tournée</button><small>Attribution manuelle. Une commande et un livreur ne peuvent appartenir qu’à une tournée active.</small></div><div class="panel" style="margin-top:16px"><h2>Les livreurs</h2>${drivers.length ? '' : '<p class="muted">Aucun livreur. Ajoutez-les dans Mon camping › L’équipe.</p>'}${drivers.map(d => `<div class="driver row between"><div><strong>${esc(d.name)}</strong><br><small>${esc(labels[d.state])}${d.since ? ' depuis ' + hhmm(d.since) : ''}</small>${d.since ? `<br><small>Service : ${cashLine(core.cashUp(S.orders, d.id, d.since))}</small>` : ''}${tripHelp(d)}</div>${d.state === 'pending' ? `<button data-approve="${esc(d.id)}">Valider l’arrivée</button>` : d.state === 'absent' ? `<button data-present="${esc(d.id)}">Marquer présent</button>` : d.state === 'available' ? `<button data-absent="${esc(d.id)}">Fin de service</button>` : ''}</div>`).join('')}</div>`;
  }
  function driverPanel(drivers) {
    const me = drivers.find(d => d.id === actor) || { state: 'absent' };
    return `<div class="panel" style="margin-bottom:24px"><div class="row between"><div><h3>Ma présence · ${esc(labels[me.state])}</h3><span class="muted">${me.state === 'pending' ? 'Un responsable doit valider votre arrivée.' : me.state === 'available' ? 'Vous pouvez recevoir une tournée.' : me.state === 'busy' ? 'Terminez votre tournée puis confirmez votre retour.' : 'Signalez votre arrivée au restaurant.'}</span></div>${me.state === 'absent' ? '<button class="primary" id="presence">Je suis arrivé(e)</button>' : ''}</div>${S.trips.filter(t => t.driver === actor && t.state !== 'done').map(t => { const e = tripEff(t); return `<div class="row" style="margin-top:16px"><strong>Tournée ${esc(t.id)}</strong>${e === 'assigned' ? `<button class="primary" data-depart="${esc(t.id)}">Démarrer la tournée</button>` : e === 'returning' ? `<button class="primary" data-ret="${esc(t.id)}">Je suis de retour au restaurant</button>` : '<span class="tag">Livraisons en cours</span>'}</div>`; }).join('')}</div>`;
  }
  /* ---------- cycle d'un livreur (partagé : module livreur + onglet Livreurs) ---------- */
  const STEPS = ['Arrivée', 'Validation', 'Disponible', 'En tournée'];
  const sortStops = os => os.slice().sort((a, b) => String(a.spot).localeCompare(String(b.spot), 'fr', { numeric: true }));
  function driverInfo(d) {
    const t = activeTrip(d.id), te = t ? tripEff(t) : null, stops = t ? sortStops(tripOrders(t)) : [];
    const n = stops.filter(o => o.status === 'delivered').length;
    const stage = d.state === 'absent' ? 0 : d.state === 'pending' ? 1 : !t ? 2 : 3;
    return { t, te, stops, n, stage, due: stops.filter(o => o.status !== 'delivered').reduce((x, o) => x + o.total, 0), cash: core.cashUp(S.orders, d.id, d.since) };
  }
  const cycleHtml = (stage, mini, prog) => `<ol class="cycle${mini ? ' mini' : ''}">${STEPS.map((x, i) => `<li class="${i < stage ? 'done' : i === stage ? 'now' : ''}"><span>${i < stage ? '✓' : i + 1}</span>${x}${i === 3 && i === stage && prog ? ' ' + prog : ''}</li>`).join('')}</ol>`;
  const bar = (n, N) => `<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="${N}" aria-valuenow="${n}"><i style="width:${N ? Math.round(100 * n / N) : 0}%"></i></div>`;
  const recentlyMine = o => o.paid && o.paid.confirmedBy === actor && Date.now() - Date.parse(o.paid.at) < 120000;

  /* ---------- module livreur ---------- */
  let focusStop = null;
  function drawDriver() {
    const me = driverList().find(d => d.id === actor) || { id: actor, state: 'absent', since: null };
    const I = driverInfo(me), { t, stops, n, stage } = I, N = stops.length;
    const todo = stops.filter(o => o.status !== 'delivered');
    if (!stops.some(o => o.id === focusStop && o.status !== 'delivered')) focusStop = todo[0] ? todo[0].id : null;
    const focus = stops.find(o => o.id === focusStop);
    const title = I.te === 'returning' ? 'Toutes les commandes sont remises.' : ['Bonjour ! Signalez votre arrivée.', 'Arrivée signalée. Un responsable va valider.', 'Disponible. Une tournée arrive bientôt.', n === 0 ? `${N} arrêt${N > 1 ? 's' : ''} à livrer` : `Arrêt ${n + 1} sur ${N}`][stage];
    const action = stage === 0 ? '<button class="primary big" id="presence">Je suis arrivé(e)</button>' : I.te === 'returning' ? `<button class="primary big" data-ret="${esc(t.id)}">Je suis de retour au restaurant</button>` : '';
    const undo = S.orders.filter(o => o.status === 'delivered' && recentlyMine(o) && S.trips.some(x => x.id === o.trip && x.driver === actor)).sort((a, b) => (a.paid.at < b.paid.at ? 1 : -1))[0];
    const doneTrips = S.trips.filter(x => x.driver === actor && x.state === 'done' && me.since && (x.returned || x.created) >= me.since).sort((a, b) => (a.created < b.created ? 1 : -1));
    app.innerHTML = `<div class="toolbar"><div><span class="eyebrow">MODULE LIVREUR</span><h1>${esc(A[actor][0])}</h1></div><button id="logout">Changer de compte</button></div>
<section class="hero drv"><div class="drv-main"><span class="eyebrow">MON CYCLE · ${esc(labels[me.state])}${me.since ? ' depuis ' + hhmm(me.since) : ''}</span><h1>${title}</h1>${N ? `${bar(n, N)}<p class="muted">${n}/${N} remis · reste à encaisser <strong>${money(I.due)}</strong></p>` : ''}${cycleHtml(stage, false, N ? n + '/' + N : '')}${action}</div><div class="hero-art" aria-hidden="true">🛺</div></section>
${N ? `<nav class="chips" aria-label="Arrêts de la tournée">${stops.map((o, i) => `<button class="chip ${o.status === 'delivered' ? 'ok' : ''} ${o.id === focusStop ? 'on' : ''}" data-focus="${esc(o.id)}"><b>${o.status === 'delivered' ? '✓ ' : ''}${esc(o.spot)}</b><small>${esc(hintOf(o) || o.name)}</small></button>`).join('')}</nav>` : ''}
${focus ? focusCard(focus, stops.indexOf(focus), N) : N ? `<div class="panel done-card"><h2>Toutes les commandes sont remises</h2><p class="muted">Rentrez au restaurant puis touchez le bouton ci-dessus.</p></div>` : '<div class="panel empty">Aucune tournée en cours. Les commandes que le responsable vous confie apparaîtront ici.</div>'}
${undo ? `<p class="undo">Remise de l’emplacement ${esc(undo.spot)} enregistrée en ${core.METHODS[undo.paid.method]}. <button data-undo="${esc(undo.id)}">Annuler cette remise</button></p>` : ''}
<div class="staff-grid" style="margin-top:24px"><section><div class="panel"><h2>Ma caisse du service</h2>${me.since ? `<p class="muted">Depuis ${hhmm(me.since)}</p><div class="cash"><div><small>Espèces</small><strong>${money(I.cash.cash)}</strong></div><div><small>Carte</small><strong>${money(I.cash.card)}</strong></div><div><small>Total</small><strong>${money(I.cash.total)}</strong></div></div><p class="note">Espèces à remettre au responsable en fin de service : <strong>${money(I.cash.cash)}</strong></p>${doneTrips.length ? `<h3>Tournées terminées</h3>${doneTrips.map(x => `<p class="row between"><span>Tournée ${esc(x.id)} · ${S.orders.filter(o => o.trip === x.id).length} cmd</span><small>${x.returned ? 'retour ' + hhmm(x.returned) : ''}</small></p>`).join('')}` : ''}` : '<p class="muted">Votre service commence quand votre arrivée est validée.</p>'}</div></section><aside>${mapPanel(stops)}</aside></div>`;
  }
  function focusCard(o, i, N) {
    const h = hintOf(o);
    return `<article class="panel focus"><span class="eyebrow">ARRÊT ${i + 1} SUR ${N}</span><div class="spotline"><span class="bigspot">${esc(o.spot)}</span><div><strong>${esc(h || 'Emplacement ' + o.spot)}</strong><br><span class="muted">${esc(o.name)} · #${esc(o.id)}</span></div></div><ul class="items">${o.items.map(x => `<li><b>${x.quantity}×</b> ${esc(x.name)}</li>`).join('')}</ul><div class="amount"><small>À encaisser</small><strong>${money(o.total)}</strong></div><p class="muted">Remettez la commande, encaissez, puis touchez le mode de paiement.</p><div class="pay big2"><button class="primary" data-deliver="${esc(o.id)}" data-method="cash">Espèces</button><button class="primary" data-deliver="${esc(o.id)}" data-method="card">Carte</button></div></article>`;
  }

  /* ---------- onglet Livreurs (responsable) ---------- */
  function segHtml() { return `<div class="seg" role="tablist">${[['orders', 'Commandes'], ['drivers', 'Livreurs'], ['camping', 'Mon camping']].map(([k, v]) => `<button role="tab" aria-selected="${tab === k}" class="${tab === k ? 'active' : ''}" data-tab="${k}">${v}</button>`).join('')}</div>`; }
  function drawDispatch() {
    const drivers = driverList(), infos = drivers.map(d => ({ d, I: driverInfo(d) }));
    const ready = S.orders.filter(o => eff(o) === 'ready' && o.mode === 'delivery' && !o.trip).sort((a, b) => (a.created < b.created ? -1 : 1));
    selected = new Set([...selected].filter(id => ready.some(o => o.id === id)));
    const cashAll = infos.reduce((x, { I }) => ({ cash: x.cash + I.cash.cash, card: x.card + I.cash.card }), { cash: 0, card: 0 });
    app.innerHTML = `<div class="toolbar"><div><span class="eyebrow">SERVICE & ORGANISATION</span><h1>Les livreurs</h1></div>${segHtml()}<button id="logout">Changer de compte</button></div>
<div class="stats"><div class="stat"><strong>${drivers.filter(d => d.state !== 'absent' && d.state !== 'pending').length}</strong>En service</div><div class="stat"><strong>${infos.filter(x => x.I.t).length}</strong>En tournée</div><div class="stat"><strong>${money(cashAll.cash)}</strong>Espèces chez les livreurs</div></div>
<section class="panel"><div class="row between"><h2>Prêtes à partir</h2><small>${selected.size ? selected.size + ' sélectionnée' + (selected.size > 1 ? 's' : '') : 'Touchez les commandes à confier'}</small></div>${ready.length ? `<div class="chips wrap">${ready.map(o => `<button class="chip ${selected.has(o.id) ? 'on' : ''}" data-pick="${esc(o.id)}" aria-pressed="${selected.has(o.id)}"><b>${selected.has(o.id) ? '✓ ' : ''}${esc(o.spot)}</b><small>${esc(o.name)} · ${money(o.total)}</small></button>`).join('')}</div>` : '<p class="muted">Aucune commande prête en livraison.</p>'}</section>
<div class="dcards">${infos.map(({ d, I }) => driverCard(d, I)).join('') || '<div class="panel empty">Aucun livreur. Ajoutez-les dans Mon camping › L’équipe.</div>'}</div>`;
  }
  function driverCard(d, I) {
    const { t, te, stops, n, stage } = I;
    const actions = d.state === 'absent' ? `<button class="primary" data-present="${esc(d.id)}">Marquer présent</button>` : d.state === 'pending' ? `<button class="primary" data-approve="${esc(d.id)}">Valider l’arrivée</button>` : d.state === 'available' ? `<button class="primary" data-give="${esc(d.id)}" ${selected.size ? '' : 'disabled'}>Confier ${selected.size || ''} commande${selected.size > 1 ? 's' : ''}</button><button data-absent="${esc(d.id)}">Fin de service</button>` : '';
    return `<article class="panel dcard"><div class="row between"><div><h3>${esc(d.name)}</h3><small>${esc(labels[d.state])}${d.since ? ' depuis ' + hhmm(d.since) : ''}</small></div><span class="status ${stage === 2 ? 'ready' : ''}">${(stage === 3 ? (n ? 'En livraison' : 'Tournée confiée') : ['Absent', 'À valider', 'Disponible'][stage])}</span></div>${cycleHtml(stage, true, stops.length ? n + '/' + stops.length : '')}${t ? `<p class="row between"><strong>Tournée ${esc(t.id)}</strong><small>${n}/${stops.length} remis</small></p>${bar(n, stops.length)}<ul class="stoplist">${stops.map(o => `<li class="${o.status === 'delivered' ? 'ok' : ''}"><span><b>${esc(o.spot)}</b> ${esc(o.name)}<br><small>${o.paid ? '✓ ' + hhmm(o.paid.at) + ' · ' + core.METHODS[o.paid.method] + ' · ' + money(o.paid.amount) : 'À encaisser ' + money(o.total)}</small></span>${o.status === 'delivered' ? `<button class="sm" data-undo="${esc(o.id)}" title="Dépannage">Annuler</button>` : `<span class="sm2"><button class="sm" data-deliver="${esc(o.id)}" data-method="cash">Esp.</button><button class="sm" data-deliver="${esc(o.id)}" data-method="card">Carte</button></span>`}</li>`).join('')}</ul><small class="muted blk">Les boutons de la liste servent en dépannage, si le livreur ne peut pas confirmer.</small>${te === 'returning' ? `<button class="full" style="margin-top:10px" data-ret="${esc(t.id)}">Dépannage : confirmer son retour</button>` : ''}` : ''}<p class="cashline">${d.state === 'absent' ? 'Hors service' : 'Service : ' + cashLine(I.cash)}</p><div class="row">${actions}</div></article>`;
  }
  function mapPanel(os) {
    const spots = os.filter(o => !finished(o)).map(o => esc(o.spot) + (hintOf(o) ? ' (' + esc(hintOf(o)) + ')' : '')).join(', ') || 'aucun';
    return `<div class="panel"><h2>Se repérer</h2>${S.map && S.map.id ? `<a href="api.php?a=plan&amp;v=${esc(S.map.id)}" target="_blank" rel="noopener"><img class="map" src="api.php?a=plan&amp;v=${esc(S.map.id)}" alt="Plan du camping fourni par le responsable"></a>` : '<div class="empty">🗺<br>Le responsable doit ajouter le plan du camping.</div>'}<p class="muted">Emplacements de votre tournée : <strong>${spots}</strong></p><small>Plan consultable. Positionnement et itinéraires automatiques à intégrer à partir du vrai plan.</small></div>`;
  }

  /* ---------- tickets (l'impression papier n'existe pas dans un aperçu) ---------- */
  function ticketText(o, st) {
    const h = hintOf(o), L = [];
    L.push('CAMPING OS 76 · ' + st.toUpperCase(), 'À ENCAISSER : ' + money(o.total), 'Commande #' + o.id, new Date(o.created).toLocaleString('fr-FR'), '', 'EMPLACEMENT ' + o.spot + (h ? ' (' + h + ')' : ''), o.name + ' · ' + (o.mode === 'delivery' ? 'LIVRAISON GRATUITE' : 'RETRAIT RESTAURANT'), '--------------------------------');
    o.items.filter(i => i.station === st).forEach(i => L.push(i.quantity + ' × ' + i.name));
    L.push('--------------------------------', 'Validation mobile du poste : ' + st);
    return L.join('\n');
  }
  function showTicket(id) {
    const o = orderById(id); if (!o) return;
    closeTicket();
    const m = document.createElement('div'); m.className = 'modal'; m.id = 'ticket-modal';
    m.innerHTML = `<div class="sheet" role="dialog" aria-modal="true" aria-label="Tickets de la commande ${esc(o.id)}"><div class="row between"><h2>Tickets · #${esc(o.id)}</h2><button data-close>Fermer</button></div><p class="muted">Un ticket par poste concerné. Les tickets s’affichent à l’écran : montrez-les au poste ou copiez-les. L’impression papier se fait avec la version installée sur le Mac.</p>${Object.keys(o.stations).map(st => `<div><pre class="ticket">${esc(ticketText(o, st))}</pre><button data-copy="${st}">Copier le ticket ${esc(st)}</button></div>`).join('')}</div>`;
    m.onclick = e => {
      if (e.target === m || e.target.dataset.close !== undefined) return closeTicket();
      const st = e.target.dataset.copy; if (!st) return;
      const text = ticketText(o, st);
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => notice('Ticket copié.'), () => { const pre = [...m.querySelectorAll('pre')][Object.keys(o.stations).indexOf(st)]; const r = document.createRange(); r.selectNodeContents(pre); const sel = getSelection(); sel.removeAllRanges(); sel.addRange(r); notice('Sélectionné : copiez avec Ctrl+C.'); });
    };
    document.body.append(m);
  }
  const closeTicket = () => { const m = $('#ticket-modal'); if (m) m.remove(); };
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTicket(); });

  /* ---------- Mon camping ---------- */
  const STN = { pizza: 'Pizza', cuisine: 'Cuisine', bar: 'Bar' };
  function menuEditor() {
    $('#menu-editor').innerHTML = draftMenu.map((m, i) => `<div class="mrow"><input data-f="name" data-i="${i}" value="${esc(m.name)}" placeholder="Nom du produit" maxlength="60" aria-label="Nom du produit"><input data-f="description" data-i="${i}" value="${esc(m.description)}" placeholder="Description (facultatif)" maxlength="120" aria-label="Description"><div class="row"><input data-f="priceText" data-i="${i}" value="${esc(m.priceText != null ? m.priceText : (m.price / 100).toFixed(2).replace('.', ','))}" inputmode="decimal" placeholder="Prix €" aria-label="Prix en euros" style="width:96px"><select data-f="station" data-i="${i}" aria-label="Poste" style="width:auto">${Object.entries(STN).map(([k, v]) => `<option value="${k}" ${m.station === k ? 'selected' : ''}>${v}</option>`).join('')}</select><label class="inl"><input type="checkbox" data-f="active" data-i="${i}" ${m.active ? 'checked' : ''}>Proposé</label></div><div class="row"><button type="button" data-mv="${i}:-1" aria-label="Monter">↑</button><button type="button" data-mv="${i}:1" aria-label="Descendre">↓</button><button type="button" data-rm="${i}">Supprimer</button></div></div>`).join('') || '<div class="empty">Aucun produit.</div>';
  }
  function staffEditor() {
    $('#staff-editor').innerHTML = [['managers', 'Responsables', 'un responsable'], ['drivers', 'Livreurs', 'un livreur']].map(([g, t, one]) => `<h3 style="margin-top:14px">${t}</h3>${draftStaff[g].map((x, i) => `<div class="row srow"><input data-g="${g}" data-i="${i}" value="${esc(x.name)}" maxlength="40" placeholder="Prénom" aria-label="Nom" style="flex:1;min-width:0;margin:0"><button type="button" data-srm="${g}:${i}">Retirer</button></div>`).join('')}<button type="button" data-sadd="${g}">+ Ajouter ${one}</button>`).join('');
  }
  function drawAdmin() {
    if (!draftMenu) draftMenu = S.menu.items.map(m => ({ ...m }));
    if (!draftStaff) draftStaff = { managers: (S.staff.managers || []).map(x => ({ ...x })), drivers: (S.staff.drivers || []).map(x => ({ ...x })) };
    const st = S.settings, spotsTxt = (st.spots || []).map(([s, h]) => s + (h ? ' ; ' + h : '')).join('\n');
    app.innerHTML = `<div class="toolbar"><div><span class="eyebrow">MON CAMPING</span><h1>Carte, emplacements, sauvegarde</h1></div>${segHtml()}</div><div class="staff-grid"><section><div class="panel"><h2>La carte</h2><p class="muted">${S.menu.confirmed ? 'Cette carte est celle que voient vos clients.' : 'Pour l’instant, les clients voient une carte de démonstration. Saisissez vos vrais plats et prix, puis enregistrez.'}</p><div id="menu-editor"></div><div class="row" style="margin-top:14px"><button id="add-item" type="button">+ Ajouter un produit</button><button class="primary" id="save-menu" type="button">Enregistrer la carte</button></div><small>Un produit décoché n’est plus proposé mais reste dans la liste. Chaque produit va sur le ticket de son poste (pizza, cuisine ou bar).</small></div><div class="panel" style="margin-top:16px"><h2>L’équipe</h2><p class="muted">Vos responsables et vos livreurs. Les postes pizza, cuisine et bar sont fixes.</p><div id="staff-editor"></div><button class="primary full" id="save-staff" type="button" style="margin-top:14px">Enregistrer l’équipe</button></div><div class="panel" style="margin-top:16px"><h2>Emplacements et commandes</h2><label class="inl chk" for="open"><input type="checkbox" id="open" ${st.open ? 'checked' : ''}>Accepter les commandes en ligne</label><label for="spots-txt">Liste des emplacements (facultatif)</label><textarea id="spots-txt" rows="8" placeholder="127 ; Allée des Pins&#10;128 ; Allée des Pins">${esc(spotsTxt)}</textarea><small>Une ligne par emplacement : le numéro, puis un repère facultatif après un point-virgule. Si la liste est remplie, seuls ces emplacements peuvent commander, et le repère apparaît sur les tickets et chez les livreurs. Laissée vide, tout numéro est accepté.</small><button class="primary full" id="save-settings" type="button" style="margin-top:14px">Enregistrer</button></div></section><aside><div class="panel"><h2>Plan du camping</h2><p class="muted">Ajoutez votre vrai plan pour les livreurs.</p>${assetsNs ? `<label for="map-upload">Image PNG ou JPEG</label><input id="map-upload" type="file" accept="image/png,image/jpeg">` : '<small>Le dépôt de fichier est réservé aux éditeurs de cette page.</small>'}${S.map && S.map.id ? `<img class="map" style="margin-top:12px" src="api.php?a=plan&amp;v=${esc(S.map.id)}" alt="Plan du camping enregistré">` : '<small>Aucun plan fourni pour le moment.</small>'}</div><div class="panel" style="margin-top:16px"><h2>Sauvegarde</h2><p class="muted">Enregistre un fichier avec toutes les données (carte, réglages, commandes, tournées).</p>${dl ? '<button class="primary full" id="backup" type="button">Enregistrer une sauvegarde</button>' : '<small>Le téléchargement n’est pas disponible dans cette vue.</small>'}</div><div class="panel" style="margin-top:16px"><h2>Remise à zéro des commandes</h2><p class="muted">Efface toutes les commandes et tournées, et remet les livreurs en « absent ». La carte, les emplacements et le plan sont conservés. Une copie de sécurité est faite automatiquement juste avant.</p><label for="confirm-reset">Tapez EFFACER pour confirmer</label><input id="confirm-reset" autocomplete="off"><button class="full" id="reset" type="button">Effacer les commandes</button></div><div class="panel" style="margin-top:16px"><h2>Accès</h2><p class="muted">Chacun se connecte avec son compte du 76 (appli des réservations). Les comptes responsables ont accès à tout ; les autres travaillent comme poste ou livreur. Pour ajouter quelqu’un : le76.fr/equipe, onglet Équipe.</p></div></aside></div>`;
    menuEditor(); staffEditor();
  }
  function parsePrice(m) {
    const raw = String(m.priceText != null ? m.priceText : m.price / 100).replace(',', '.').replace(/[€\s]/g, '');
    const p = Math.round(parseFloat(raw) * 100); return Number.isFinite(p) && /^\d+(\.\d{0,2})?$/.test(raw) ? p : NaN;
  }

  /* ---------- événements ---------- */
  app.addEventListener('input', e => {
    const t = e.target;
    if (t.form && t.form.id === 'checkout') { form[t.name] = t.type === 'checkbox' ? t.checked : t.value; return; }
    if (t.dataset.g) { draftStaff[t.dataset.g][+t.dataset.i].name = t.value; return; }
    if (t.closest && t.closest('#menu-editor') && t.dataset.f) draftMenu[+t.dataset.i][t.dataset.f] = t.type === 'checkbox' ? t.checked : t.value;
  });
  app.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.select !== undefined) { t.checked ? selected.add(t.dataset.select) : selected.delete(t.dataset.select); rerender(true); }
    if (t.id === 'map-upload' && t.files[0]) act(async () => {
      const f = t.files[0]; if (f.size > 8 * 1024 * 1024 || !['image/png', 'image/jpeg'].includes(f.type)) throw new core.Fault('Image PNG ou JPEG, 8 Mo maximum.');
      const r = await assetsNs.upload(f); await core.setMap(r.id, who());
    }, 'Plan enregistré.');
  });
  app.addEventListener('submit', e => {
    e.preventDefault(); const f = e.target;
    if (f.id === 'checkout') submitOrder(f);
    else if (f.id === 'find') { track = new FormData(f).get('token').trim().toUpperCase(); lsSet('campingTrack', track); render(); }
    else if (f.id === 'staff-login') staffConnect(f);
    else if (f.id === 'login') { actor = new FormData(f).get('actor'); ssSet('campingActor', actor); tab = 'orders'; rerender(true); }
  });
  app.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return; const d = b.dataset, id = b.id;
    if (d.add) { cart[d.add] = Math.min(20, (cart[d.add] || 0) + 1); requestKey = newKey(); return drawCart(); }
    if (d.plus || d.minus) { const k = d.plus || d.minus; cart[k] = Math.max(0, Math.min(20, (cart[k] || 0) + (d.plus ? 1 : -1))); requestKey = newKey(); return drawCart(); }
    if (d.mode) { mode = d.mode; requestKey = newKey(); return drawCart(); }
    if (id === 'copy') { const link = location.origin + location.pathname + '?suivi=' + track; (navigator.clipboard ? navigator.clipboard.writeText(link) : Promise.reject()).then(() => notice('Lien de suivi copié.'), () => notice('Votre code : ' + track)); return; }
    if (id === 'other') { track = null; lsDel('campingTrack'); return render(); }
    if (id === 'deco') return staffLogout();
    if (id === 'logout') { actor = null; ssDel('campingActor'); tab = 'orders'; draftMenu = null; selected.clear(); return rerender(true); }
    if (d.tab) { tab = d.tab; draftMenu = null; draftStaff = null; return rerender(true); }
    if (d.focus) { focusStop = d.focus; return rerender(true); }
    if (d.pick) { selected.has(d.pick) ? selected.delete(d.pick) : selected.add(d.pick); return rerender(true); }
    if (d.give) { if (!selected.size) return notice('Touchez d’abord les commandes à confier.'); b.disabled = true; return act(async () => { await core.assign([...selected], d.give, who()); selected.clear(); }, 'Tournée confiée.'); }
    if (d.undo) return act(() => core.undoDeliver(d.undo, who()), 'Remise annulée.');
    if (id === 'tab-camping') { tab = 'camping'; draftMenu = null; draftStaff = null; return rerender(true); }
    if (id === 'back') { tab = 'orders'; draftMenu = null; draftStaff = null; return rerender(true); }
    if (d.ready) return act(() => core.validate(d.ready, d.station, who()));
    if (d.collect) return act(() => core.collect(d.collect, who(), d.method), 'Retrait enregistré · ' + core.METHODS[d.method] + '.');
    if (d.deliver) { b.disabled = true; return act(() => core.deliver(d.deliver, who(), d.method), 'Remise enregistrée · ' + core.METHODS[d.method] + '.'); }
    if (d.approve) return act(() => core.approve(d.approve, who()));
    if (d.present) return act(() => core.markPresent(d.present, who()), 'Livreur disponible.');
    if (d.absent) return act(() => core.markAbsent(d.absent, who()), 'Fin de service enregistrée.');
    if (d.depart) return act(() => core.depart(d.depart, who()), 'Tournée démarrée.');
    if (d.ret) return act(() => core.returnTrip(d.ret, who()), 'Retour enregistré : disponible.');
    if (d.print) return showTicket(d.print);
    if (id === 'presence') return act(() => core.presence(who()));
    if (id === 'assign') {
      const driver = $('#assign-driver').value;
      if (!driver || !selected.size) return notice('Sélectionnez des commandes et un livreur disponible.');
      b.disabled = true;
      return act(async () => { await core.assign([...selected], driver, who()); selected.clear(); }, 'Tournée attribuée.');
    }
    // Mon camping
    if (d.rm !== undefined) { draftMenu.splice(+d.rm, 1); return menuEditor(); }
    if (d.mv) { const [i, k] = d.mv.split(':').map(Number), j = i + k; if (j < 0 || j >= draftMenu.length) return; [draftMenu[i], draftMenu[j]] = [draftMenu[j], draftMenu[i]]; return menuEditor(); }
    if (id === 'add-item') { draftMenu.push({ id: '', name: '', description: '', price: 0, priceText: '', station: 'cuisine', active: true }); menuEditor(); const ins = document.querySelectorAll('#menu-editor [data-f=name]'); ins[ins.length - 1].focus(); return; }
    if (id === 'save-menu') {
      const items = [];
      for (const m of draftMenu) { const p = parsePrice(m); if (Number.isNaN(p)) return notice('Prix invalide pour « ' + (m.name || 'produit sans nom') + ' ».'); items.push({ id: m.id, name: m.name, description: m.description, price: p, station: m.station, active: m.active }); }
      return act(async () => { await core.saveMenu(items, who()); draftMenu = null; }, 'Carte enregistrée.');
    }
    if (d.srm) { const [g, i] = d.srm.split(':'); draftStaff[g].splice(+i, 1); return staffEditor(); }
    if (d.sadd) { draftStaff[d.sadd].push({ id: '', name: '' }); staffEditor(); const ins = document.querySelectorAll(`#staff-editor [data-g=${d.sadd}]`); ins[ins.length - 1].focus(); return; }
    if (id === 'save-staff') return act(async () => { await core.saveStaff(draftStaff, who()); draftStaff = null; }, 'Équipe enregistrée.');
    if (id === 'save-settings') return act(() => core.saveSettings({ open: $('#open').checked, spots: $('#spots-txt').value }, who()), 'Réglages enregistrés.');
    if (id === 'backup') return act(async () => {
      try { await dl.save({ filename: 'camping-76-sauvegarde-' + new Date().toISOString().slice(0, 10) + '.json', data: await core.exportAll() }); }
      catch (err) { if (err && err.code === 'declined') return false; throw err; }
    }, 'Sauvegarde enregistrée.');
    if (id === 'reset') return act(async () => { await core.resetTests($('#confirm-reset').value.trim(), who()); selected.clear(); }, 'Commandes effacées.');
  });

  const qs = new URLSearchParams(location.search);
  if (/^[A-Fa-f0-9]{8}$/.test(qs.get('suivi') || '')) { track = qs.get('suivi').toUpperCase(); lsSet('campingTrack', track); view = 'track'; }
  if (location.hash === '#equipe') view = 'team';
  document.querySelectorAll('nav button').forEach(b => b.classList.toggle('active', b.dataset.view === view));
  render();
  poll(true);
})();
