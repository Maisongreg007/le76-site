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
    } catch (e) { throw new base.Fault(t('network'), 503); }
    if (!r.ok || d.ok === false) throw new base.Fault(d.erreur || 'Action impossible.', r.status);
    return d;
  }
  let me = null, installe = true, canManage = false, rev = null, lastSig = '', lastMe = '', codeLivreurs = false;
  let loginMode = location.hash === '#livreur' ? 'driver' : 'staff';
  const codeSession = () => !!(me && me.driver); // livreur connecté par le code (et non par un compte du 76)
  const mut = (a, data) => call(a, Object.assign({ as: actor || '' }, data || {})).then(async r => { await poll(true); return r; });
  const core = Object.assign({}, base, {
    placeOrder: (input, key) => call('commander', { input, key, lang: L }).then(async r => { track = r.id; await poll(true); return r; }),
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
      me = d.me; installe = d.installe; canManage = !!(me && me.admin); codeLivreurs = !!d.codeLivreurs;
      if (me && me.driver && actor !== me.driver) { actor = me.driver; ssSet('campingActor', actor); }
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

  /* ---------- langues (pages des clients) ---------- */
  const T = {
    fr: {
      lang: 'Français', network: 'Connexion impossible. Vérifiez le réseau et réessayez.', navOrder: 'Commander', navTrack: 'Ma commande', navTeam: 'Équipe', badge: 'PAIEMENT À LA REMISE', foot1: 'Au rythme des vacances.', foot2: 'Livraison gratuite au camping · Retrait au restaurant',
      eyebrow: 'LE RESTAURANT VIENT À VOUS', hero: 'Vos vacances.<br>Notre cuisine.', heroP: 'Commandez depuis votre mobil-home. On vous livre gratuitement, ou on vous retrouve au restaurant.', heroTag: 'Livraison offerte · Emplacement obligatoire',
      closed: 'Les commandes en ligne sont momentanément fermées. Merci de revenir un peu plus tard.', menu: 'À la carte', demoMenu: 'Carte de démonstration', theMenu: 'La carte',
      pizza: 'Au four à pizza', cuisine: 'Côté cuisine', bar: 'Côté bar', add: '+ Ajouter', addA: 'Ajouter', cart: 'Votre panier', less: 'Retirer une unité', more: 'Ajouter une unité', total: 'Total', delivery: 'Livraison', free: 'gratuite', deliveryBtn: 'Livraison', pickupBtn: 'Retrait',
      spot: 'Emplacement / mobil-home *', spotEx: 'Ex. 127', name: 'Votre prénom *', nameEx: 'Ex. Camille', payNote: 'Paiement à la remise de la commande (espèces ou carte). Aucun paiement en ligne.', demoNote: ' Carte de démonstration : plats et prix d’exemple.',
      consent: 'Je confirme ma commande.', submit: 'Commander · paiement à la remise', empty: 'Un petit creux ?<br>Ajoutez votre premier plat.',
      yourOrder: 'Votre commande', codeHelp: 'Le code de suivi apparaît après votre commande (8 caractères, ex. 3FA9C1D2). Vous pouvez aussi le saisir ici.', code: 'Code de suivi', show: 'Afficher', notFound: 'Commande introuvable pour le code', otherCode: 'Saisir un autre code',
      sReceived: 'Commande reçue', sReceivedT: 'Le restaurant a bien reçu votre commande.', sKitchen: 'En cuisine', sKitchenT: 'Préparation en cours :', kPizza: 'pizza', kCuisine: 'cuisine', kBar: 'boissons',
      sReadyPick: 'Prête à retirer', sReadyPickT: 'Votre commande vous attend au comptoir du restaurant.', sCollected: 'Retirée', sReady: 'Prête', sReadyT: 'Votre commande est prête et attend son livreur.', sRoute: 'En route',
      brings: '{n} vous l’apporte en golfette, à l’emplacement {s}.', bringsAnon: 'Un livreur vous l’apporte en golfette, à l’emplacement {s}.', sDelivered: 'Livrée', enjoy: 'Bon appétit !', paidIn: 'payée en', cash: 'espèces', card: 'carte',
      hCollected: 'Commande retirée', hDelivered: 'Commande livrée', yourOrderUp: 'VOTRE COMMANDE', hello: 'Bonjour', paid: 'Payée', toPay: 'À régler à la remise', where: 'Où en est ma commande ?',
      live: 'Cette page se met à jour toute seule. Pas d’horaire estimé : on vous livre dès que c’est prêt.', copy: 'Copier mon lien de suivi', other: 'Autre suivi', recap: 'Le récapitulatif',
      atRestaurant: 'Retrait au restaurant', freeTo: 'Livraison gratuite à l’emplacement', payAt: 'paiement à la remise (espèces ou carte)', keep: 'Conservez votre lien : il permet de retrouver cette commande.', copied: 'Lien de suivi copié.', yourCode: 'Votre code :'
    },
    de: {
      lang: 'Deutsch', network: 'Keine Verbindung. Bitte Netz prüfen und erneut versuchen.', navOrder: 'Bestellen', navTrack: 'Meine Bestellung', navTeam: 'Team', badge: 'ZAHLUNG BEI ÜBERGABE', foot1: 'Im Rhythmus der Ferien.', foot2: 'Kostenlose Lieferung auf dem Campingplatz · Abholung im Restaurant',
      eyebrow: 'DAS RESTAURANT KOMMT ZU IHNEN', hero: 'Ihr Urlaub.<br>Unsere Küche.', heroP: 'Bestellen Sie von Ihrem Mobilheim aus. Wir liefern kostenlos, oder Sie holen im Restaurant ab.', heroTag: 'Kostenlose Lieferung · Stellplatz erforderlich',
      closed: 'Online-Bestellungen sind gerade geschlossen. Bitte versuchen Sie es etwas später.', menu: 'Speisekarte', demoMenu: 'Beispielkarte', theMenu: 'Die Karte',
      pizza: 'Aus dem Pizzaofen', cuisine: 'Aus der Küche', bar: 'Von der Bar', add: '+ Hinzufügen', addA: 'Hinzufügen', cart: 'Ihr Warenkorb', less: 'Eins weniger', more: 'Eins mehr', total: 'Summe', delivery: 'Lieferung', free: 'kostenlos', deliveryBtn: 'Lieferung', pickupBtn: 'Abholung',
      spot: 'Stellplatz / Mobilheim *', spotEx: 'z. B. 127', name: 'Ihr Vorname *', nameEx: 'z. B. Anna', payNote: 'Bezahlung bei Übergabe (bar oder Karte). Keine Online-Zahlung.', demoNote: ' Beispielkarte: Gerichte und Preise nur zur Ansicht.',
      consent: 'Ich bestätige meine Bestellung.', submit: 'Bestellen · Zahlung bei Übergabe', empty: 'Hunger?<br>Fügen Sie Ihr erstes Gericht hinzu.',
      yourOrder: 'Ihre Bestellung', codeHelp: 'Der Verfolgungscode erscheint nach der Bestellung (8 Zeichen, z. B. 3FA9C1D2). Sie können ihn auch hier eingeben.', code: 'Verfolgungscode', show: 'Anzeigen', notFound: 'Keine Bestellung gefunden für den Code', otherCode: 'Anderen Code eingeben',
      sReceived: 'Bestellung erhalten', sReceivedT: 'Das Restaurant hat Ihre Bestellung erhalten.', sKitchen: 'In der Küche', sKitchenT: 'Wird zubereitet:', kPizza: 'Pizza', kCuisine: 'Küche', kBar: 'Getränke',
      sReadyPick: 'Abholbereit', sReadyPickT: 'Ihre Bestellung wartet an der Theke des Restaurants.', sCollected: 'Abgeholt', sReady: 'Fertig', sReadyT: 'Ihre Bestellung ist fertig und wartet auf den Fahrer.', sRoute: 'Unterwegs',
      brings: '{n} bringt sie Ihnen mit dem Golfwagen zum Stellplatz {s}.', bringsAnon: 'Ein Fahrer bringt sie Ihnen mit dem Golfwagen zum Stellplatz {s}.', sDelivered: 'Geliefert', enjoy: 'Guten Appetit!', paidIn: 'bezahlt mit', cash: 'Bargeld', card: 'Karte',
      hCollected: 'Bestellung abgeholt', hDelivered: 'Bestellung geliefert', yourOrderUp: 'IHRE BESTELLUNG', hello: 'Hallo', paid: 'Bezahlt', toPay: 'Zahlung bei Übergabe', where: 'Wo ist meine Bestellung?',
      live: 'Diese Seite aktualisiert sich von selbst. Keine geschätzte Uhrzeit: Wir liefern, sobald alles fertig ist.', copy: 'Verfolgungslink kopieren', other: 'Andere Bestellung', recap: 'Übersicht',
      atRestaurant: 'Abholung im Restaurant', freeTo: 'Kostenlose Lieferung zum Stellplatz', payAt: 'Zahlung bei Übergabe (bar oder Karte)', keep: 'Bewahren Sie den Link auf: Damit finden Sie diese Bestellung wieder.', copied: 'Link kopiert.', yourCode: 'Ihr Code:'
    },
    en: {
      lang: 'English', network: 'No connection. Check your network and try again.', navOrder: 'Order', navTrack: 'My order', navTeam: 'Staff', badge: 'PAY ON DELIVERY', foot1: 'At holiday pace.', foot2: 'Free delivery on the campsite · Pick-up at the restaurant',
      eyebrow: 'THE RESTAURANT COMES TO YOU', hero: 'Your holiday.<br>Our kitchen.', heroP: 'Order from your mobile home. We deliver for free, or meet you at the restaurant.', heroTag: 'Free delivery · Pitch number required',
      closed: 'Online ordering is closed for the moment. Please come back a little later.', menu: 'Menu', demoMenu: 'Sample menu', theMenu: 'Our menu',
      pizza: 'From the pizza oven', cuisine: 'From the kitchen', bar: 'From the bar', add: '+ Add', addA: 'Add', cart: 'Your basket', less: 'One less', more: 'One more', total: 'Total', delivery: 'Delivery', free: 'free', deliveryBtn: 'Delivery', pickupBtn: 'Pick-up',
      spot: 'Pitch / mobile home *', spotEx: 'e.g. 127', name: 'Your first name *', nameEx: 'e.g. Emma', payNote: 'Pay when you receive your order (cash or card). No online payment.', demoNote: ' Sample menu: example dishes and prices.',
      consent: 'I confirm my order.', submit: 'Order · pay on delivery', empty: 'Feeling peckish?<br>Add your first dish.',
      yourOrder: 'Your order', codeHelp: 'Your tracking code appears after ordering (8 characters, e.g. 3FA9C1D2). You can also enter it here.', code: 'Tracking code', show: 'Show', notFound: 'No order found for code', otherCode: 'Enter another code',
      sReceived: 'Order received', sReceivedT: 'The restaurant has received your order.', sKitchen: 'In the kitchen', sKitchenT: 'Being prepared:', kPizza: 'pizza', kCuisine: 'kitchen', kBar: 'drinks',
      sReadyPick: 'Ready to collect', sReadyPickT: 'Your order is waiting at the restaurant counter.', sCollected: 'Collected', sReady: 'Ready', sReadyT: 'Your order is ready and waiting for its driver.', sRoute: 'On its way',
      brings: '{n} is bringing it by golf cart to pitch {s}.', bringsAnon: 'A driver is bringing it by golf cart to pitch {s}.', sDelivered: 'Delivered', enjoy: 'Enjoy your meal!', paidIn: 'paid by', cash: 'cash', card: 'card',
      hCollected: 'Order collected', hDelivered: 'Order delivered', yourOrderUp: 'YOUR ORDER', hello: 'Hello', paid: 'Paid', toPay: 'Pay on delivery', where: 'Where is my order?',
      live: 'This page updates by itself. No estimated time: we deliver as soon as it is ready.', copy: 'Copy my tracking link', other: 'Track another order', recap: 'Summary',
      atRestaurant: 'Pick-up at the restaurant', freeTo: 'Free delivery to pitch', payAt: 'pay on delivery (cash or card)', keep: 'Keep your link: it lets you find this order again.', copied: 'Tracking link copied.', yourCode: 'Your code:'
    },
    nl: {
      lang: 'Nederlands', network: 'Geen verbinding. Controleer het netwerk en probeer opnieuw.', navOrder: 'Bestellen', navTrack: 'Mijn bestelling', navTeam: 'Team', badge: 'BETALEN BIJ AFGIFTE', foot1: 'Op vakantieritme.', foot2: 'Gratis bezorging op de camping · Afhalen in het restaurant',
      eyebrow: 'HET RESTAURANT KOMT NAAR U', hero: 'Uw vakantie.<br>Onze keuken.', heroP: 'Bestel vanuit uw stacaravan. Wij bezorgen gratis, of u haalt af in het restaurant.', heroTag: 'Gratis bezorging · Plaatsnummer verplicht',
      closed: 'Online bestellen is even gesloten. Probeer het later opnieuw.', menu: 'Menukaart', demoMenu: 'Voorbeeldkaart', theMenu: 'De kaart',
      pizza: 'Uit de pizzaoven', cuisine: 'Uit de keuken', bar: 'Van de bar', add: '+ Toevoegen', addA: 'Toevoegen', cart: 'Uw mandje', less: 'Eén minder', more: 'Eén meer', total: 'Totaal', delivery: 'Bezorging', free: 'gratis', deliveryBtn: 'Bezorgen', pickupBtn: 'Afhalen',
      spot: 'Plaats / stacaravan *', spotEx: 'bijv. 127', name: 'Uw voornaam *', nameEx: 'bijv. Sanne', payNote: 'Betalen bij afgifte (contant of met kaart). Geen online betaling.', demoNote: ' Voorbeeldkaart: gerechten en prijzen als voorbeeld.',
      consent: 'Ik bevestig mijn bestelling.', submit: 'Bestellen · betalen bij afgifte', empty: 'Trek?<br>Voeg uw eerste gerecht toe.',
      yourOrder: 'Uw bestelling', codeHelp: 'De volgcode verschijnt na uw bestelling (8 tekens, bijv. 3FA9C1D2). U kunt hem ook hier invullen.', code: 'Volgcode', show: 'Tonen', notFound: 'Geen bestelling gevonden voor code', otherCode: 'Andere code invullen',
      sReceived: 'Bestelling ontvangen', sReceivedT: 'Het restaurant heeft uw bestelling ontvangen.', sKitchen: 'In de keuken', sKitchenT: 'Wordt bereid:', kPizza: 'pizza', kCuisine: 'keuken', kBar: 'drankjes',
      sReadyPick: 'Klaar om af te halen', sReadyPickT: 'Uw bestelling wacht aan de balie van het restaurant.', sCollected: 'Afgehaald', sReady: 'Klaar', sReadyT: 'Uw bestelling is klaar en wacht op de bezorger.', sRoute: 'Onderweg',
      brings: '{n} brengt hem met het golfkarretje naar plaats {s}.', bringsAnon: 'Een bezorger brengt hem met het golfkarretje naar plaats {s}.', sDelivered: 'Bezorgd', enjoy: 'Eet smakelijk!', paidIn: 'betaald met', cash: 'contant', card: 'kaart',
      hCollected: 'Bestelling afgehaald', hDelivered: 'Bestelling bezorgd', yourOrderUp: 'UW BESTELLING', hello: 'Hallo', paid: 'Betaald', toPay: 'Betalen bij afgifte', where: 'Waar is mijn bestelling?',
      live: 'Deze pagina werkt zichzelf bij. Geen geschatte tijd: we bezorgen zodra alles klaar is.', copy: 'Mijn volglink kopiëren', other: 'Andere bestelling', recap: 'Overzicht',
      atRestaurant: 'Afhalen in het restaurant', freeTo: 'Gratis bezorging naar plaats', payAt: 'betalen bij afgifte (contant of met kaart)', keep: 'Bewaar uw link: zo vindt u deze bestelling terug.', copied: 'Volglink gekopieerd.', yourCode: 'Uw code:'
    }
  };
  const LANGS = Object.keys(T);
  let L = (() => { const s = lsGet('camping-lang'); if (T[s]) return s; const n = (navigator.language || 'fr').slice(0, 2); return T[n] ? n : 'fr'; })();
  const t = (k, v) => { let s = (T[L] && T[L][k]) || T.fr[k] || k; if (v) for (const x in v) s = s.split('{' + x + '}').join(v[x]); return s; };
  const nm = m => (m.tr && m.tr[L] && m.tr[L].name) || m.name;
  const ds = m => (m.tr && m.tr[L] && m.tr[L].description) || m.description;
  const langSwitch = () => `<div class="langs" role="group" aria-label="Langue">${LANGS.map(l => `<button type="button" data-lang="${l}" class="${l === L ? 'on' : ''}" title="${T[l].lang}" aria-pressed="${l === L}">${l.toUpperCase()}</button>`).join('')}</div>`;
  function chrome() {
    document.documentElement.lang = L;
    const nb = document.querySelectorAll('nav button');
    if (nb.length === 3) { nb[0].textContent = t('navOrder'); nb[1].textContent = t('navTrack'); nb[2].textContent = t('navTeam'); }
    const bd = document.querySelector('header .demo'); if (bd) bd.textContent = t('badge');
    const ft = document.querySelector('footer'); if (ft) ft.innerHTML = `${esc(t('foot1'))} <span>${esc(t('foot2'))}</span>`;
  }

  /* ---------- client ---------- */
  const menuItems = () => S.menu.items.filter(m => m.active);
  function client() {
    const demo = !S.menu.confirmed, open = S.settings.open;
    app.innerHTML = `<section class="hero"><div><div class="row between"><span class="eyebrow">${t('eyebrow')}</span>${langSwitch()}</div><h1>${t('hero')}</h1><p>${t('heroP')}</p><span class="tag">${t('heroTag')}</span></div><div class="hero-art" aria-hidden="true">🍕</div></section>${open ? '' : `<div class="note">${t('closed')}</div>`}<div class="grid"><section><div class="row between"><h2>${t('menu')}</h2><span class="muted">${demo ? t('demoMenu') : t('theMenu')}</span></div><div class="menu">${menuItems().map(m => `<article class="card"><span class="tag">${esc(t(m.station))}</span><h3>${esc(nm(m))}</h3><p class="muted">${esc(ds(m))}</p><div class="row between bottom"><span class="price">${money(m.price)}</span><button data-add="${esc(m.id)}" ${open ? '' : 'disabled'} aria-label="${t('addA')} ${esc(nm(m))}">${t('add')}</button></div></article>`).join('')}</div></section><aside class="panel cart"><h2>${t('cart')}</h2><div id="cart"></div></aside></div>`;
    drawCart();
  }
  function drawCart() {
    const demo = !S.menu.confirmed, open = S.settings.open, spots = (S.settings.spots || []).map(x => x[0]);
    for (const k of Object.keys(cart)) if (!menuItems().some(m => m.id === k) || !cart[k]) delete cart[k];
    const items = menuItems().filter(m => cart[m.id]);
    $('#cart').innerHTML = items.length ? `${items.map(m => `<div class="cart-line"><div><strong>${esc(nm(m))}</strong><br><small>${money(m.price)} × ${cart[m.id]}</small></div><div><button data-minus="${esc(m.id)}" aria-label="${t('less')}">−</button> <button data-plus="${esc(m.id)}" aria-label="${t('more')}">+</button></div></div>`).join('')}<div class="row between total"><span>${t('total')}</span><span>${money(items.reduce((n, m) => n + m.price * cart[m.id], 0))}</span></div><small>${t('delivery')} : <strong>${t('free')}</strong></small><div class="choice"><button type="button" data-mode="delivery" class="${mode === 'delivery' ? 'active' : ''}">${t('deliveryBtn')}</button><button type="button" data-mode="pickup" class="${mode === 'pickup' ? 'active' : ''}">${t('pickupBtn')}</button></div><form id="checkout"><label for="f-spot">${t('spot')}</label><input id="f-spot" name="spot" required maxlength="16" pattern="[A-Za-z0-9 \\-]+" placeholder="${t('spotEx')}" list="spots" autocomplete="off" value="${esc(form.spot)}">${spots.length ? `<datalist id="spots">${spots.map(s => `<option value="${esc(s)}">`).join('')}</datalist>` : ''}<label for="f-name">${t('name')}</label><input id="f-name" name="name" required maxlength="60" placeholder="${t('nameEx')}" value="${esc(form.name)}"><div class="note">${t('payNote')}${demo ? t('demoNote') : ''}</div><label class="inl chk" for="f-consent"><input id="f-consent" type="checkbox" name="consent" required ${form.consent ? 'checked' : ''}>${t('consent')}</label><button class="primary full" style="margin-top:18px" ${open ? '' : 'disabled'}>${t('submit')}</button></form>` : `<div class="empty">${t('empty')}</div>`;
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
      app.innerHTML = `<div class="login panel"><div class="row between"><h2>${t('yourOrder')}</h2>${langSwitch()}</div><p>${t('codeHelp')}</p><form id="find"><label for="t-code">${t('code')}</label><input id="t-code" name="token" required maxlength="8" autocomplete="off"><button class="primary">${t('show')}</button></form></div>`;
      return;
    }
    const o = orderById(String(track).toUpperCase());
    if (!o) { app.innerHTML = `<div class="panel"><div class="row between"><p class="error">${t('notFound')} ${esc(track)}.</p>${langSwitch()}</div><button id="other">${t('otherCode')}</button></div>`; return; }
    const s = eff(o), pick = o.mode === 'pickup';
    const evs = events(o), at = re => { const e = evs.find(x => re.test(x.a)); return e ? e.t : null; };
    const trip = o.trip ? S.trips.find(x => x.id === o.trip) : null;
    const dname = trip ? ((S.staff.drivers || []).find(d => d.id === trip.driver) || {}).name : '';
    const kitchen = Object.entries(o.stations).map(([k, v]) => `${t({ pizza: 'kPizza', cuisine: 'kCuisine', bar: 'kBar' }[k] || k)} ${v ? '✓' : '…'}`).join(' · ');
    const paidTxt = o.paid ? ` · ${t('paidIn')} ${t(o.paid.method)}` : '';
    const steps = pick ? [
      [t('sReceived'), o.created, t('sReceivedT')],
      [t('sKitchen'), o.created, t('sKitchenT') + ' ' + kitchen + '.'],
      [t('sReadyPick'), at(/prête/i), t('sReadyPickT')],
      [t('sCollected'), o.paid && o.paid.at, t('enjoy') + paidTxt]
    ] : [
      [t('sReceived'), o.created, t('sReceivedT')],
      [t('sKitchen'), o.created, t('sKitchenT') + ' ' + kitchen + '.'],
      [t('sReady'), at(/prête/i), t('sReadyT')],
      [t('sRoute'), at(/Tournée attribuée/), dname ? t('brings', { n: esc(dname), s: esc(o.spot) }) : t('bringsAnon', { s: esc(o.spot) })],
      [t('sDelivered'), o.paid && o.paid.at, t('enjoy') + paidTxt]
    ];
    const cur = pick ? ({ preparing: 1, ready: 2, collected: 3 }[s] ?? 1) : ({ preparing: 1, ready: 2, assigned: 3, delivering: 3, delivered: 4 }[s] ?? 1);
    const last = cur === steps.length - 1;
    const head = last ? (pick ? t('hCollected') : t('hDelivered')) : steps[cur][0];
    app.innerHTML = `<div class="hero"><div><div class="row between"><span class="eyebrow">${t('yourOrderUp')} · ${esc(o.id)}</span>${langSwitch()}</div><h1>${esc(head)}</h1><p>${t('hello')} ${esc(o.name)}. ${steps[cur][2]}</p><span class="tag">${o.paid ? t('paid') : t('toPay')} · ${money(o.total)}</span></div><div class="hero-art" aria-hidden="true">${pick ? '🛍' : '🛺'}</div></div><div class="grid"><section class="panel"><h2>${t('where')}</h2><ol class="ctrack">${steps.map(([label, tm, txt], i) => `<li class="${i < cur || last ? 'done' : i === cur ? 'now' : ''}"><span class="dot">${i < cur || last ? '✓' : i + 1}</span><div><strong>${label}</strong>${tm && (i <= cur) ? `<small> · ${hhmm(tm)}</small>` : ''}${i === cur && !last ? `<p>${txt}</p>` : ''}</div></li>`).join('')}</ol><p class="muted">${t('live')}</p><button id="copy">${t('copy')}</button> <button id="other">${t('other')}</button></section><aside class="panel"><h2>${t('recap')}</h2>${o.items.map(i => `<p>${i.quantity} × ${esc(nm(i))} <strong>${money(i.price * i.quantity)}</strong></p>`).join('')}<hr><strong>${t('total')} ${money(o.total)}</strong><p class="muted">${pick ? t('atRestaurant') : t('freeTo') + ' ' + esc(o.spot)} · ${t('payAt')}</p><small>${t('keep')}</small></aside></div>`;
  }

  /* ---------- équipe ---------- */
  function login() {
    const opts = Object.entries(A).filter(([id, v]) => v[1] !== 'manager' || canManage);
    app.innerHTML = `<div class="login panel"><span class="eyebrow">ESPACE ÉQUIPE</span><h1 style="font-size:38px">Bonjour l’équipe.</h1><p class="muted">Choisissez votre compte.</p><form id="login"><label for="l-actor">Compte</label><select id="l-actor" name="actor">${opts.map(([id, v]) => `<option value="${id}">${esc(v[0])}${v[1] === 'driver' ? '' : v[1] === 'manager' ? '' : ' · téléphone'}</option>`).join('')}</select><button class="primary full" style="margin-top:14px">Entrer</button></form><div class="note">Connecté : <strong>${esc(me.nom)}</strong>${canManage ? ' · responsable' : ''}. <button type="button" id="deco" class="sm">Se déconnecter</button></div></div>`;
  }
  function staffLogin() {
    app.innerHTML = installe ? `<div class="login panel"><span class="eyebrow">ESPACE ÉQUIPE</span><h1 style="font-size:38px">Bonjour l’équipe.</h1><p class="muted">Connectez-vous avec votre compte du 76, le même que pour les réservations.</p><form id="staff-login"><label for="s-id">Identifiant</label><input id="s-id" name="identifiant" autocomplete="username" autocapitalize="none" required><label for="s-mdp">Mot de passe</label><input id="s-mdp" name="mdp" type="password" autocomplete="current-password" required><button class="primary full" style="margin-top:14px">Se connecter</button></form><p class="muted"><a href="/equipe/">Mot de passe oublié ou nouveau compte : appli des réservations</a></p><p><button type="button" id="to-driver" class="sm">Je suis livreur</button></p></div>` : '<div class="login panel"><span class="eyebrow">ESPACE ÉQUIPE</span><h2>Aucun compte pour l’instant</h2><p class="muted">Créez d’abord le compte responsable dans l’appli des réservations, <a href="/equipe/">le76.fr/equipe</a>, puis revenez ici.</p></div>';
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
    if (me && (me.driver || me.demo)) { try { await call('livreur_deconnexion'); } catch (e) { } me = null; actor = null; ssDel('campingActor'); loginMode = 'staff'; await poll(true); return rerender(true); }
    try { await fetch('/api/equipe.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'deconnexion' }) }); } catch (e) { }
    me = null; actor = null; ssDel('campingActor'); await poll(true); rerender(true);
  }
  const driverLink = () => location.origin + location.pathname.replace(/[^/]*$/, '') + 'livreur/';
  function driverLogin() {
    const ds = S.staff.drivers || [];
    app.innerHTML = `<div class="login panel"><span class="eyebrow">ESPACE LIVREUR</span><h1 style="font-size:38px">Bonjour !</h1>${codeLivreurs ? `<p class="muted">Choisissez votre prénom et tapez le code des livreurs donné par le responsable.</p><form id="driver-login"><label for="d-who">Prénom</label><select id="d-who" name="driver">${ds.map(d => `<option value="${esc(d.id)}">${esc(d.name)}</option>`).join('')}</select><label for="d-code">Code des livreurs</label><input id="d-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required><button class="primary full" style="margin-top:14px">Commencer</button></form>${me && me.demo ? '<p class="note">Démo : le code est 1234.</p>' : ''}` : '<p class="muted">Le responsable doit d’abord choisir le code des livreurs dans Mon camping.</p>'}<p><button type="button" id="to-staff" class="sm">Compte de l’équipe du 76</button></p></div>`;
  }
  async function driverConnect(f) {
    const b = f.querySelector('button'); b.disabled = true;
    try { await call('livreur_connexion', { driver: f.driver.value, code: f.code.value.trim() }); actor = f.driver.value; ssSet('campingActor', actor); await poll(true); rerender(true); }
    catch (e) { notice(friendly(e)); b.disabled = false; }
  }
  function team() {
    if (!me || (loginMode === 'driver' && me.demo && !me.driver)) return loginMode === 'driver' ? driverLogin() : staffLogin();
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
    app.innerHTML = `<div class="toolbar"><div><span class="eyebrow">MODULE LIVREUR</span><h1>${esc(A[actor][0])}</h1></div>${codeSession() ? '<button id="deco">Se déconnecter</button>' : '<button id="logout">Changer de compte</button>'}</div>
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
    $('#menu-editor').innerHTML = draftMenu.map((m, i) => `<div class="mrow"><input data-f="name" data-i="${i}" value="${esc(m.name)}" placeholder="Nom du produit" maxlength="60" aria-label="Nom du produit"><input data-f="description" data-i="${i}" value="${esc(m.description)}" placeholder="Description (facultatif)" maxlength="120" aria-label="Description"><div class="row"><input data-f="priceText" data-i="${i}" value="${esc(m.priceText != null ? m.priceText : (m.price / 100).toFixed(2).replace('.', ','))}" inputmode="decimal" placeholder="Prix €" aria-label="Prix en euros" style="width:96px"><select data-f="station" data-i="${i}" aria-label="Poste" style="width:auto">${Object.entries(STN).map(([k, v]) => `<option value="${k}" ${m.station === k ? 'selected' : ''}>${v}</option>`).join('')}</select><label class="inl"><input type="checkbox" data-f="active" data-i="${i}" ${m.active ? 'checked' : ''}>Proposé</label></div><div class="row"><button type="button" data-mv="${i}:-1" aria-label="Monter">↑</button><button type="button" data-mv="${i}:1" aria-label="Descendre">↓</button><button type="button" data-rm="${i}">Supprimer</button></div><details class="trad"><summary>Traductions (DE · EN · NL)</summary>${['de', 'en', 'nl'].map(l => `<div class="row"><b class="lg">${l.toUpperCase()}</b><input data-f="tr.${l}.name" data-i="${i}" value="${esc(((m.tr || {})[l] || {}).name || '')}" placeholder="Nom (${l.toUpperCase()})" maxlength="60" aria-label="Nom ${l.toUpperCase()}" style="flex:1;min-width:0;margin:0"><input data-f="tr.${l}.description" data-i="${i}" value="${esc(((m.tr || {})[l] || {}).description || '')}" placeholder="Description (${l.toUpperCase()})" maxlength="120" aria-label="Description ${l.toUpperCase()}" style="flex:2;min-width:0;margin:0"></div>`).join('')}<small>Laissé vide : le client voit le texte en français.</small></details></div>`).join('') || '<div class="empty">Aucun produit.</div>';
  }
  function staffEditor() {
    $('#staff-editor').innerHTML = [['managers', 'Responsables', 'un responsable'], ['drivers', 'Livreurs', 'un livreur']].map(([g, t, one]) => `<h3 style="margin-top:14px">${t}</h3>${draftStaff[g].map((x, i) => `<div class="row srow"><input data-g="${g}" data-i="${i}" value="${esc(x.name)}" maxlength="40" placeholder="Prénom" aria-label="Nom" style="flex:1;min-width:0;margin:0"><button type="button" data-srm="${g}:${i}">Retirer</button></div>`).join('')}<button type="button" data-sadd="${g}">+ Ajouter ${one}</button>`).join('');
  }
  function drawAdmin() {
    if (!draftMenu) draftMenu = S.menu.items.map(m => ({ ...m }));
    if (!draftStaff) draftStaff = { managers: (S.staff.managers || []).map(x => ({ ...x })), drivers: (S.staff.drivers || []).map(x => ({ ...x })) };
    const st = S.settings, spotsTxt = (st.spots || []).map(([s, h]) => s + (h ? ' ; ' + h : '')).join('\n');
    app.innerHTML = `<div class="toolbar"><div><span class="eyebrow">MON CAMPING</span><h1>Carte, emplacements, sauvegarde</h1></div>${segHtml()}</div><div class="staff-grid"><section><div class="panel"><h2>La carte</h2><p class="muted">${S.menu.confirmed ? 'Cette carte est celle que voient vos clients.' : 'Pour l’instant, les clients voient une carte de démonstration. Saisissez vos vrais plats et prix, puis enregistrez.'}</p><div id="menu-editor"></div><div class="row" style="margin-top:14px"><button id="add-item" type="button">+ Ajouter un produit</button><button class="primary" id="save-menu" type="button">Enregistrer la carte</button></div><small>Un produit décoché n’est plus proposé mais reste dans la liste. Chaque produit va sur le ticket de son poste (pizza, cuisine ou bar).</small></div><div class="panel" style="margin-top:16px"><h2>L’équipe</h2><p class="muted">Vos responsables et vos livreurs. Les postes pizza, cuisine et bar sont fixes.</p><div id="staff-editor"></div><button class="primary full" id="save-staff" type="button" style="margin-top:14px">Enregistrer l’équipe</button></div><div class="panel" style="margin-top:16px"><h2>Emplacements et commandes</h2><label class="inl chk" for="open"><input type="checkbox" id="open" ${st.open ? 'checked' : ''}>Accepter les commandes en ligne</label><label for="spots-txt">Liste des emplacements (facultatif)</label><textarea id="spots-txt" rows="8" placeholder="127 ; Allée des Pins&#10;128 ; Allée des Pins">${esc(spotsTxt)}</textarea><small>Une ligne par emplacement : le numéro, puis un repère facultatif après un point-virgule. Si la liste est remplie, seuls ces emplacements peuvent commander, et le repère apparaît sur les tickets et chez les livreurs. Laissée vide, tout numéro est accepté.</small><button class="primary full" id="save-settings" type="button" style="margin-top:14px">Enregistrer</button></div></section><aside><div class="panel"><h2>Connexion des livreurs</h2><p class="muted">Lien à donner aux livreurs : ils choisissent leur prénom et tapent le code. Pas besoin de compte.</p><p><strong>${esc(driverLink())}</strong></p><button type="button" id="copy-drv">Copier le lien</button><label for="drv-code">${codeLivreurs ? 'Nouveau code' : 'Code des livreurs'} (4 à 8 chiffres)</label><input id="drv-code" inputmode="numeric" autocomplete="off" maxlength="8"><button class="primary full" id="save-drv-code" type="button">${codeLivreurs ? 'Changer le code' : 'Enregistrer le code'}</button><small>${codeLivreurs ? 'Un code est en place. Le changer déconnecte tous les livreurs.' : 'Aucun code pour l’instant : les livreurs ne peuvent pas encore se connecter.'}</small></div><div class="panel" style="margin-top:16px"><h2>Plan du camping</h2><p class="muted">Ajoutez votre vrai plan pour les livreurs.</p>${assetsNs ? `<label for="map-upload">Image PNG ou JPEG</label><input id="map-upload" type="file" accept="image/png,image/jpeg">` : '<small>Le dépôt de fichier est réservé aux éditeurs de cette page.</small>'}${S.map && S.map.id ? `<img class="map" style="margin-top:12px" src="api.php?a=plan&amp;v=${esc(S.map.id)}" alt="Plan du camping enregistré">` : '<small>Aucun plan fourni pour le moment.</small>'}</div><div class="panel" style="margin-top:16px"><h2>Sauvegarde</h2><p class="muted">Enregistre un fichier avec toutes les données (carte, réglages, commandes, tournées).</p>${dl ? '<button class="primary full" id="backup" type="button">Enregistrer une sauvegarde</button>' : '<small>Le téléchargement n’est pas disponible dans cette vue.</small>'}</div><div class="panel" style="margin-top:16px"><h2>Remise à zéro des commandes</h2><p class="muted">Efface toutes les commandes et tournées, et remet les livreurs en « absent ». La carte, les emplacements et le plan sont conservés. Une copie de sécurité est faite automatiquement juste avant.</p><label for="confirm-reset">Tapez EFFACER pour confirmer</label><input id="confirm-reset" autocomplete="off"><button class="full" id="reset" type="button">Effacer les commandes</button></div><div class="panel" style="margin-top:16px"><h2>Accès</h2><p class="muted">Chacun se connecte avec son compte du 76 (appli des réservations). Les comptes responsables ont accès à tout ; les autres travaillent comme poste ou livreur. Pour ajouter quelqu’un : le76.fr/equipe, onglet Équipe.</p></div></aside></div>`;
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
    if (t.closest && t.closest('#menu-editor') && t.dataset.f) {
      const m = draftMenu[+t.dataset.i], path = t.dataset.f.split('.');
      if (path.length === 3) { m.tr = Object.assign({}, m.tr); m.tr[path[1]] = Object.assign({}, m.tr[path[1]], { [path[2]]: t.value }); }
      else m[t.dataset.f] = t.type === 'checkbox' ? t.checked : t.value;
    }
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
    else if (f.id === 'driver-login') driverConnect(f);
    else if (f.id === 'login') { actor = new FormData(f).get('actor'); ssSet('campingActor', actor); tab = 'orders'; rerender(true); }
  });
  app.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return; const d = b.dataset, id = b.id;
    if (d.lang) { L = d.lang; lsSet('camping-lang', L); chrome(); return rerender(true); }
    if (d.add) { cart[d.add] = Math.min(20, (cart[d.add] || 0) + 1); requestKey = newKey(); return drawCart(); }
    if (d.plus || d.minus) { const k = d.plus || d.minus; cart[k] = Math.max(0, Math.min(20, (cart[k] || 0) + (d.plus ? 1 : -1))); requestKey = newKey(); return drawCart(); }
    if (d.mode) { mode = d.mode; requestKey = newKey(); return drawCart(); }
    if (id === 'copy') { const link = location.origin + location.pathname + '?suivi=' + track; (navigator.clipboard ? navigator.clipboard.writeText(link) : Promise.reject()).then(() => notice(t('copied')), () => notice(t('yourCode') + ' ' + track)); return; }
    if (id === 'other') { track = null; lsDel('campingTrack'); return render(); }
    if (id === 'deco') return staffLogout();
    if (id === 'to-driver') { loginMode = 'driver'; return rerender(true); }
    if (id === 'to-staff') { loginMode = 'staff'; if (me && me.demo) { actor = null; ssDel('campingActor'); } return rerender(true); }
    if (id === 'copy-drv') { const l = driverLink(); (navigator.clipboard ? navigator.clipboard.writeText(l) : Promise.reject()).then(() => notice('Lien des livreurs copié.'), () => notice(l)); return; }
    if (id === 'save-drv-code') return act(() => mut('code_livreurs', { code: $('#drv-code').value.trim() }), 'Code des livreurs enregistré.');
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
      for (const m of draftMenu) { const p = parsePrice(m); if (Number.isNaN(p)) return notice('Prix invalide pour « ' + (m.name || 'produit sans nom') + ' ».'); items.push({ id: m.id, name: m.name, description: m.description, price: p, station: m.station, active: m.active, tr: m.tr || {} }); }
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
  if (location.hash === '#equipe' || location.hash === '#livreur') view = 'team';
  document.querySelectorAll('nav button').forEach(b => b.classList.toggle('active', b.dataset.view === view));
  chrome();
  render();
  poll(true);
})();
