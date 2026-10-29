<?php
/**
 * CAMPING OS 76 (le76.fr/camping) : commande depuis le mobil-home, préparation par poste,
 * tournées de livraison en golfette, encaissement à la remise.
 *
 * Les données ne sont PAS dans ce dépôt : elles vivent dans « le76-camping », à côté de
 * public_html, hors d'atteinte du web et des mises à jour automatiques du site.
 * L'équipe se connecte avec les comptes de l'appli des réservations (le76.fr/equipe) :
 * un compte « responsable » peut tout faire, les autres travaillent comme poste ou livreur.
 *
 * Chaque action d'écriture tourne dans une transaction SQLite (BEGIN IMMEDIATE) : deux
 * responsables ne peuvent pas confier la même commande ni le même livreur en même temps.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

final class Fault extends RuntimeException
{
    public function __construct(string $message, public int $statut = 400)
    {
        parent::__construct($message);
    }
}

const C_STATIONS = ['pizza', 'cuisine', 'bar'];
const C_POSTES = ['pizza' => 'Poste pizza', 'cuisine' => 'Poste cuisine', 'bar' => 'Poste bar'];
const C_METHODS = ['cash' => 'espèces', 'card' => 'carte'];
const C_DEFAULT_STAFF = [
    'managers' => [['id' => 'greg', 'name' => 'Greg'], ['id' => 'vanessa', 'name' => 'Vanessa']],
    'drivers' => [['id' => 'livreur1', 'name' => 'Livreur 1'], ['id' => 'livreur2', 'name' => 'Livreur 2']],
];
const C_DEFAULT_MENU = [
    ['id' => 'margherita', 'name' => 'Pizza Margherita', 'description' => 'Tomate, mozzarella, basilic', 'price' => 1150, 'station' => 'pizza', 'active' => true],
    ['id' => 'reine', 'name' => 'Pizza Reine', 'description' => 'Jambon, champignons, mozzarella', 'price' => 1350, 'station' => 'pizza', 'active' => true],
    ['id' => 'burger', 'name' => 'Burger du camping', 'description' => 'Bœuf, cheddar, salade · avec frites', 'price' => 1450, 'station' => 'cuisine', 'active' => true],
    ['id' => 'frites', 'name' => 'Frites maison', 'description' => 'Une portion à partager', 'price' => 450, 'station' => 'cuisine', 'active' => true],
    ['id' => 'salade', 'name' => 'Salade estivale', 'description' => 'Crudités, feta, olives', 'price' => 1050, 'station' => 'cuisine', 'active' => true],
    ['id' => 'limonade', 'name' => 'Limonade artisanale', 'description' => '33 cl · sans alcool', 'price' => 350, 'station' => 'bar', 'active' => true],
    ['id' => 'eau', 'name' => 'Eau minérale', 'description' => '50 cl', 'price' => 200, 'station' => 'bar', 'active' => true],
];

/* ------------------------------------------------------------------ */
/* Stockage : documents JSON (même modèle que l'aperçu)                */
/* ------------------------------------------------------------------ */

function c_dossier(): string
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $env = getenv('CAMPING_DONNEES');
    $candidats = $env ? [$env] : [dirname(__DIR__, 2) . '/le76-camping', __DIR__ . '/donnees'];
    foreach ($candidats as $c) {
        if (!is_dir($c)) {
            @mkdir($c, 0750, true);
        }
        if (is_dir($c) && is_writable($c)) {
            if (str_starts_with($c, __DIR__) && !is_file("$c/.htaccess")) {
                file_put_contents("$c/.htaccess", "Require all denied\n");
            }
            return $d = $c;
        }
    }
    throw new RuntimeException('Impossible de créer le dossier des données.');
}

function c_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . c_dossier() . '/camping.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS docs (col TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL, PRIMARY KEY (col, id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS kv (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tentatives (cle TEXT NOT NULL, quand INTEGER NOT NULL)');
    return $pdo;
}

function c_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
}

function c_hex(): string
{
    return strtoupper(bin2hex(random_bytes(4)));
}

function c_split(string $p): array
{
    $x = explode('/', $p, 2);
    return [$x[0], $x[1] ?? ''];
}

function rd(string $p): ?array
{
    [$c, $i] = c_split($p);
    $st = c_db()->prepare('SELECT data FROM docs WHERE col = ? AND id = ?');
    $st->execute([$c, $i]);
    $v = $st->fetchColumn();
    return $v === false ? null : json_decode((string) $v, true);
}

function wr(string $p, array $d): void
{
    [$c, $i] = c_split($p);
    c_db()->prepare('INSERT OR REPLACE INTO docs (col, id, data) VALUES (?, ?, ?)')
        ->execute([$c, $i, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    c_bump();
}

function c_merge(array &$t, array $s): void
{
    foreach ($s as $k => $v) {
        if (is_array($v) && $v !== [] && !array_is_list($v) && isset($t[$k]) && is_array($t[$k])) {
            c_merge($t[$k], $v);
        } else {
            $t[$k] = $v;
        }
    }
}

function upd(string $p, array $patch): void
{
    $d = rd($p);
    if ($d === null) {
        throw new Fault('Donnée introuvable. Actualisez.', 409);
    }
    c_merge($d, $patch);
    wr($p, $d);
}

function del(string $p): void
{
    [$c, $i] = c_split($p);
    c_db()->prepare('DELETE FROM docs WHERE col = ? AND id = ?')->execute([$c, $i]);
    c_bump();
}

function liste(string $col, string $where = '', array $params = []): array
{
    $st = c_db()->prepare('SELECT data FROM docs WHERE col = ?' . ($where ? " AND ($where)" : ''));
    $st->execute(array_merge([$col], $params));
    return array_map(fn($v) => json_decode((string) $v, true), $st->fetchAll(PDO::FETCH_COLUMN));
}

function c_bump(): void
{
    c_db()->exec("INSERT INTO kv (cle, valeur) VALUES ('rev', '1') ON CONFLICT(cle) DO UPDATE SET valeur = CAST(valeur AS INTEGER) + 1");
}

function c_rev(): int
{
    return (int) (c_db()->query("SELECT valeur FROM kv WHERE cle = 'rev'")->fetchColumn() ?: 0);
}

/* ------------------------------------------------------------------ */
/* Métier (même logique que l'aperçu testé)                            */
/* ------------------------------------------------------------------ */

function need(string $role, string ...$roles): void
{
    if (!in_array($role, $roles, true)) {
        throw new Fault('Action non autorisée pour ce compte.', 403);
    }
}

function ev(string $a, string $w = ''): array
{
    return ['t' => c_now(), 'a' => $a, 'w' => $w];
}

function ev_key(): string
{
    return 'e' . base_convert((string) (int) (microtime(true) * 1000), 10, 36) . strtolower(substr(c_hex(), 0, 5));
}

function eff(array $o): string
{
    $st = $o['stations'] ?? [];
    return ($o['status'] === 'preparing' && !in_array(false, array_values($st), true)) ? 'ready' : $o['status'];
}

function staff(): array
{
    return rd('config/staff') ?? C_DEFAULT_STAFF;
}

function comptes(?array $staff = null): array
{
    $staff ??= staff();
    $a = [];
    foreach ($staff['managers'] ?? [] as $m) {
        $a[$m['id']] = [$m['name'], 'manager'];
    }
    foreach (C_POSTES as $k => $v) {
        $a[$k] = [$v, $k];
    }
    foreach ($staff['drivers'] ?? [] as $d) {
        $a[$d['id']] = [$d['name'], 'driver'];
    }
    return $a;
}

function est_livreur(string $id): bool
{
    foreach (staff()['drivers'] ?? [] as $d) {
        if ($d['id'] === $id) {
            return true;
        }
    }
    return false;
}

function menu(): array
{
    return rd('config/menu') ?? ['items' => C_DEFAULT_MENU, 'confirmed' => false];
}

function reglages(): array
{
    return rd('config/settings') ?? ['open' => true, 'spots' => []];
}

function seed(): void
{
    if (!rd('config/menu')) {
        wr('config/menu', ['items' => C_DEFAULT_MENU, 'confirmed' => false]);
    }
    if (!rd('config/settings')) {
        wr('config/settings', ['open' => true, 'spots' => []]);
    }
    if (!rd('config/staff')) {
        wr('config/staff', C_DEFAULT_STAFF);
    }
    foreach (staff()['drivers'] as $d) {
        if (!rd('drivers/' . $d['id'])) {
            wr('drivers/' . $d['id'], ['id' => $d['id'], 'state' => 'absent', 'since' => null]);
        }
    }
}

function trip_orders(string $tid): array
{
    return liste('orders', "json_extract(data, '$.trip') = ?", [$tid]);
}

function place_order(array $in, string $key): array
{
    if (!preg_match('/^[A-F0-9]{8}$/D', $key)) {
        throw new Fault('Référence de demande invalide.');
    }
    if (rd('orders/' . $key)) {
        return ['id' => $key, 'again' => true];
    }
    $r = reglages();
    if (empty($r['open'])) {
        throw new Fault('Les commandes en ligne sont momentanément fermées. Merci de revenir un peu plus tard.', 409);
    }
    $spot = trim((string) ($in['spot'] ?? ''));
    $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($in['name'] ?? '')) ?? '');
    $mode = $in['mode'] ?? '';
    if (!preg_match('/^[A-Za-z0-9 -]{1,16}$/D', $spot)) {
        throw new Fault('Numéro d’emplacement obligatoire (16 caractères maximum).');
    }
    $connus = [];
    foreach ($r['spots'] ?? [] as $s) {
        $connus[mb_strtoupper($s[0])] = $s[0];
    }
    if ($connus) {
        if (!isset($connus[mb_strtoupper($spot)])) {
            throw new Fault('Cet emplacement n’existe pas. Vérifiez votre numéro.');
        }
        $spot = $connus[mb_strtoupper($spot)];
    }
    if ($name === '' || mb_strlen($name) > 60 || !in_array($mode, ['delivery', 'pickup'], true)) {
        throw new Fault('Nom et mode de remise obligatoires.');
    }
    $menu = array_values(array_filter(menu()['items'], fn($m) => !empty($m['active'])));
    $ids = array_column($menu, 'id');
    $raw = $in['items'] ?? null;
    if (!is_array($raw) || !$raw || array_is_list($raw)) {
        throw new Fault('Panier invalide.');
    }
    foreach (array_keys($raw) as $k) {
        if (!in_array((string) $k, $ids, true)) {
            throw new Fault('Panier invalide.');
        }
    }
    $items = [];
    $n = 0;
    foreach ($menu as $m) {
        $q = $raw[$m['id']] ?? 0;
        if (!is_int($q) || $q < 0 || $q > 20) {
            throw new Fault('Quantité invalide (0 à 20).');
        }
        if ($q) {
            $items[] = ['id' => $m['id'], 'name' => $m['name'], 'description' => $m['description'], 'price' => $m['price'], 'station' => $m['station'], 'quantity' => $q];
            $n += $q;
        }
    }
    if (!$items || $n > 50) {
        throw new Fault('Panier vide ou trop volumineux.');
    }
    $stations = [];
    $total = 0;
    foreach ($items as $i) {
        $stations[$i['station']] = false;
        $total += $i['price'] * $i['quantity'];
    }
    wr('orders/' . $key, [
        'id' => $key, 'spot' => $spot, 'name' => $name, 'mode' => $mode, 'items' => $items, 'total' => $total,
        'status' => 'preparing', 'stations' => $stations, 'trip' => null, 'created' => c_now(),
        'events' => ['e0' => ev('Commande reçue', 'client')], 'paid' => null,
    ]);
    return ['id' => $key];
}

function validate(string $oid, string $station, array $who): void
{
    need($who['role'], 'manager', 'pizza', 'cuisine', 'bar');
    $o = rd('orders/' . $oid);
    if (!$o || eff($o) !== 'preparing') {
        throw new Fault('Commande déjà traitée ou inconnue.', 409);
    }
    $st = $o['stations'];
    $patch = [];
    $label = null;
    if ($station === 'all') {
        need($who['role'], 'manager');
        foreach ($st as $k => $_) {
            $patch[$k] = true;
        }
    } else {
        if (!array_key_exists($station, $st) || ($who['role'] !== 'manager' && $who['role'] !== $station)) {
            throw new Fault('Poste non autorisé.', 403);
        }
        if ($st[$station]) {
            throw new Fault('Poste déjà validé.', 409);
        }
        $patch[$station] = true;
        $label = 'Poste ' . $station . ' prêt';
    }
    $done = !in_array(false, array_values(array_merge($st, $patch)), true);
    $events = [];
    if ($label) {
        $events[ev_key()] = ev($label, $who['actor']);
    }
    if ($done) {
        $events['ready'] = ev('Commande prête', $who['actor']);
    }
    upd('orders/' . $oid, ['stations' => $patch, 'events' => $events] + ($done ? ['status' => 'ready'] : []));
}

function assign(array $ids, string $driver, array $who): array
{
    need($who['role'], 'manager');
    if (!$ids || count($ids) > 20 || count(array_unique($ids)) !== count($ids)) {
        throw new Fault('Sélection invalide.');
    }
    $d = rd('drivers/' . $driver);
    if (!$d || $d['state'] !== 'available') {
        throw new Fault('Livreur indisponible ou non validé.', 409);
    }
    $orders = [];
    foreach ($ids as $id) {
        $o = rd('orders/' . (string) $id);
        if (!$o || eff($o) !== 'ready' || $o['trip'] || $o['mode'] !== 'delivery') {
            throw new Fault('Une commande a déjà été attribuée ou n’est pas prête. Actualisez.', 409);
        }
        $orders[] = $o;
    }
    $tid = c_hex();
    wr('trips/' . $tid, ['id' => $tid, 'driver' => $driver, 'state' => 'assigned', 'manager' => $who['actor'], 'created' => c_now()]);
    foreach ($orders as $o) {
        upd('orders/' . $o['id'], ['status' => 'assigned', 'trip' => $tid, 'events' => [ev_key() => ev('Tournée attribuée', $who['actor'])]]);
    }
    upd('drivers/' . $driver, ['state' => 'busy']);
    return ['trip' => $tid];
}

function presence(array $who): void
{
    need($who['role'], 'driver');
    if (!est_livreur($who['actor'])) {
        throw new Fault('Compte livreur inconnu.', 403);
    }
    $d = rd('drivers/' . $who['actor']);
    if ($d && $d['state'] !== 'absent') {
        throw new Fault('Présence déjà déclarée.', 409);
    }
    wr('drivers/' . $who['actor'], ['id' => $who['actor'], 'state' => 'pending', 'since' => null]);
}

function approve(string $driver, array $who): void
{
    need($who['role'], 'manager');
    $d = rd('drivers/' . $driver);
    if (!$d || $d['state'] !== 'pending') {
        throw new Fault('Aucune arrivée à valider.', 409);
    }
    upd('drivers/' . $driver, ['state' => 'available', 'since' => c_now()]);
}

function mark_present(string $driver, array $who): void
{
    need($who['role'], 'manager');
    if (!est_livreur($driver)) {
        throw new Fault('Livreur inconnu.', 409);
    }
    $d = rd('drivers/' . $driver);
    if ($d && !in_array($d['state'], ['absent', 'pending'], true)) {
        throw new Fault('Ce livreur est déjà disponible ou en tournée.', 409);
    }
    wr('drivers/' . $driver, ['id' => $driver, 'state' => 'available', 'since' => c_now()]);
}

function mark_absent(string $driver, array $who): void
{
    need($who['role'], 'manager');
    $d = rd('drivers/' . $driver);
    if (!$d || $d['state'] === 'busy') {
        throw new Fault('Ce livreur est en tournée : attendez son retour.', 409);
    }
    wr('drivers/' . $driver, ['id' => $driver, 'state' => 'absent', 'since' => null]);
}

function save_staff(array $in, array $who): void
{
    need($who['role'], 'manager');
    $old = staff();
    $out = [];
    $seen = [];
    foreach ([['managers', 10, 'm'], ['drivers', 30, 'd']] as [$g, $max, $prefix]) {
        $raw = $in[$g] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > $max) {
            throw new Fault('Liste de l’équipe invalide.');
        }
        $known = array_column($old[$g] ?? [], 'id');
        $out[$g] = [];
        foreach ($raw as $x) {
            $name = trim((string) (is_array($x) ? ($x['name'] ?? '') : ''));
            if ($name === '' || mb_strlen($name) > 40) {
                throw new Fault('Chaque personne a besoin d’un nom (40 caractères maximum).');
            }
            $id = is_array($x) ? (string) ($x['id'] ?? '') : '';
            if ($id === '' || !in_array($id, $known, true) || isset($seen[$id])) {
                $id = $prefix . strtolower(substr(c_hex(), 0, 6));
            }
            $seen[$id] = true;
            $out[$g][] = ['id' => $id, 'name' => $name];
        }
    }
    if (!$out['managers']) {
        throw new Fault('Gardez au moins un responsable.');
    }
    $nouveaux = array_column($out['drivers'], 'id');
    $retires = array_filter($old['drivers'] ?? [], fn($d) => !in_array($d['id'], $nouveaux, true));
    foreach ($retires as $d) {
        $s = rd('drivers/' . $d['id']);
        if ($s && $s['state'] === 'busy') {
            throw new Fault($d['name'] . ' est en tournée : attendez son retour avant de le retirer.', 409);
        }
    }
    wr('config/staff', $out);
    foreach ($retires as $d) {
        del('drivers/' . $d['id']);
    }
    foreach ($out['drivers'] as $d) {
        if (!rd('drivers/' . $d['id'])) {
            wr('drivers/' . $d['id'], ['id' => $d['id'], 'state' => 'absent', 'since' => null]);
        }
    }
}

function trip_for(string $tid, array $who, string $msg): array
{
    need($who['role'], 'driver', 'manager');
    $t = rd('trips/' . $tid);
    if (!$t || ($who['role'] === 'driver' && $t['driver'] !== $who['actor'])) {
        throw new Fault($msg, 409);
    }
    return $t;
}

function par(array $who): string
{
    return $who['role'] === 'manager' ? ' (confirmé par un responsable)' : '';
}

function depart(string $tid, array $who): void
{
    $t = trip_for($tid, $who, 'Départ impossible.');
    if ($t['state'] !== 'assigned') {
        throw new Fault('Départ impossible.', 409);
    }
    upd('trips/' . $tid, ['state' => 'delivering', 'departed' => c_now()]);
    foreach (trip_orders($tid) as $o) {
        upd('orders/' . $o['id'], ['status' => 'delivering', 'events' => [ev_key() => ev('Livraison en cours' . par($who), $who['actor'])]]);
    }
}

function check_method(mixed $m): string
{
    if (!is_string($m) || !isset(C_METHODS[$m])) {
        throw new Fault('Choisissez le mode de paiement : espèces ou carte.');
    }
    return $m;
}

function deliver(string $oid, array $who, mixed $method): array
{
    need($who['role'], 'driver', 'manager');
    $method = check_method($method);
    $o = rd('orders/' . $oid);
    $t = $o && $o['trip'] ? rd('trips/' . $o['trip']) : null;
    if (!$o || !$t || ($who['role'] === 'driver' && $t['driver'] !== $who['actor']) || !in_array($o['status'], ['assigned', 'delivering'], true)) {
        throw new Fault('Livraison impossible.', 409);
    }
    if ($t['state'] === 'assigned') {
        depart($t['id'], $who); // première remise = la tournée démarre toute seule
    }
    upd('orders/' . $oid, [
        'status' => 'delivered',
        'paid' => ['method' => $method, 'amount' => $o['total'], 'by' => $t['driver'], 'at' => c_now(), 'confirmedBy' => $who['actor']],
        'events' => [ev_key() => ev('Commande livrée · payée en ' . C_METHODS[$method] . par($who), $who['actor'])],
    ]);
    return ['closed' => close_if_done($t['id'])];
}

/** Dernière remise : la tournée se termine et le livreur redevient disponible tout de suite. */
function close_if_done(string $tid): bool
{
    $os = trip_orders($tid);
    if (!$os) {
        return false;
    }
    foreach ($os as $o) {
        if ($o['status'] !== 'delivered') {
            return false;
        }
    }
    $t = rd('trips/' . $tid);
    if (!$t || $t['state'] === 'done') {
        return true;
    }
    upd('trips/' . $tid, ['state' => 'done', 'returned' => c_now()]);
    $d = rd('drivers/' . $t['driver']);
    if ($d && $d['state'] === 'busy') {
        upd('drivers/' . $t['driver'], ['state' => 'available']);
    }
    return true;
}

function undo_deliver(string $oid, array $who): void
{
    need($who['role'], 'driver', 'manager');
    $o = rd('orders/' . $oid);
    $t = $o && $o['trip'] ? rd('trips/' . $o['trip']) : null;
    if (!$o || !$t || $o['status'] !== 'delivered' || ($who['role'] === 'driver' && $t['driver'] !== $who['actor'])) {
        throw new Fault('Annulation impossible.', 409);
    }
    if ($who['role'] === 'driver' && (empty($o['paid']) || time() - strtotime($o['paid']['at']) > 120)) {
        throw new Fault('Trop tard pour annuler : demandez au responsable.', 409);
    }
    if ($t['state'] === 'done') {
        $d = rd('drivers/' . $t['driver']);
        if (!$d || $d['state'] !== 'available') {
            throw new Fault('Le livreur est déjà reparti : annulation impossible.', 409);
        }
        upd('trips/' . $t['id'], ['state' => 'delivering', 'returned' => null]);
        upd('drivers/' . $t['driver'], ['state' => 'busy']);
    }
    upd('orders/' . $oid, ['status' => 'delivering', 'paid' => null, 'events' => [ev_key() => ev('Remise annulée' . par($who), $who['actor'])]]);
}

function return_trip(string $tid, array $who): void
{
    $t = trip_for($tid, $who, 'Terminez les livraisons avant de confirmer le retour.');
    if ($t['state'] !== 'delivering' || !close_if_done($tid)) {
        throw new Fault('Terminez les livraisons avant de confirmer le retour.', 409);
    }
}

function collect(string $oid, array $who, mixed $method): void
{
    need($who['role'], 'manager');
    $method = check_method($method);
    $o = rd('orders/' . $oid);
    if (!$o || $o['mode'] !== 'pickup' || eff($o) !== 'ready') {
        throw new Fault('Retrait impossible.', 409);
    }
    upd('orders/' . $oid, [
        'status' => 'collected',
        'paid' => ['method' => $method, 'amount' => $o['total'], 'by' => 'restaurant', 'at' => c_now(), 'confirmedBy' => $who['actor']],
        'events' => [ev_key() => ev('Commande retirée · payée en ' . C_METHODS[$method], $who['actor'])],
    ]);
}

function save_menu(mixed $raw, array $who): void
{
    need($who['role'], 'manager');
    if (!is_array($raw) || !array_is_list($raw) || !$raw || count($raw) > 60) {
        throw new Fault('La carte doit contenir entre 1 et 60 produits.');
    }
    $known = array_column(menu()['items'], 'id');
    $seen = [];
    $items = [];
    foreach ($raw as $it) {
        if (!is_array($it)) {
            throw new Fault('Carte invalide.');
        }
        $name = trim((string) ($it['name'] ?? ''));
        $desc = trim((string) ($it['description'] ?? ''));
        $price = $it['price'] ?? null;
        $st = $it['station'] ?? null;
        if ($name === '' || mb_strlen($name) > 60 || mb_strlen($desc) > 120) {
            throw new Fault('Chaque produit a besoin d’un nom (60 caractères maximum) ; la description fait 120 caractères maximum.');
        }
        if (!is_int($price) || $price < 0 || $price > 50000) {
            throw new Fault('Prix invalide pour « ' . $name . ' » (de 0 à 500 €).');
        }
        if (!in_array($st, C_STATIONS, true)) {
            throw new Fault('Poste invalide pour « ' . $name . ' ».');
        }
        $pid = $it['id'] ?? '';
        if ($pid) {
            if (!is_string($pid) || isset($seen[$pid])) {
                throw new Fault('Carte invalide.');
            }
            if (!in_array($pid, $known, true)) {
                $pid = '';
            }
        }
        if (!$pid) {
            $pid = 'p' . strtolower(substr(c_hex(), 0, 6));
        }
        $seen[$pid] = true;
        $items[] = ['id' => $pid, 'name' => $name, 'description' => $desc, 'price' => $price, 'station' => $st, 'active' => ($it['active'] ?? true) !== false];
    }
    if (!array_filter($items, fn($i) => $i['active'])) {
        throw new Fault('Au moins un produit doit rester proposé.');
    }
    wr('config/menu', ['items' => $items, 'confirmed' => true]);
}

function save_settings(mixed $in, array $who): void
{
    need($who['role'], 'manager');
    if (!is_array($in) || !is_bool($in['open'] ?? null) || !is_string($in['spots'] ?? null) || strlen($in['spots']) > 40000) {
        throw new Fault('Réglages invalides.');
    }
    $spots = [];
    $seen = [];
    foreach (preg_split('/\r?\n/', $in['spots']) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $i = mb_strpos($line, ';');
        $sp = trim($i === false ? $line : mb_substr($line, 0, $i));
        $hint = $i === false ? '' : trim(mb_substr($line, $i + 1));
        if (!preg_match('/^[A-Za-z0-9 -]{1,16}$/D', $sp) || mb_strlen($hint) > 60) {
            throw new Fault('Ligne invalide : « ' . mb_substr($line, 0, 40) . ' ». Format : numéro ; repère (facultatif).');
        }
        if (isset($seen[mb_strtoupper($sp)])) {
            throw new Fault('Emplacement en double : ' . $sp . '.');
        }
        $seen[mb_strtoupper($sp)] = true;
        $spots[] = [$sp, $hint];
    }
    if (count($spots) > 1500) {
        throw new Fault('1500 emplacements maximum.');
    }
    wr('config/settings', ['open' => $in['open'], 'spots' => $spots]);
}

function reset_tests(mixed $confirm, array $who): void
{
    need($who['role'], 'manager');
    if ($confirm !== 'EFFACER') {
        throw new Fault('Tapez EFFACER pour confirmer.');
    }
    c_db()->exec("DELETE FROM docs WHERE col IN ('orders', 'trips')");
    foreach (staff()['drivers'] as $d) {
        wr('drivers/' . $d['id'], ['id' => $d['id'], 'state' => 'absent', 'since' => null]);
    }
}

/** Copie de la base dans le dossier des données (30 dernières gardées). */
function c_sauvegarde(string $tag): void
{
    $dir = c_dossier() . '/sauvegardes';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $f = $dir . '/camping-' . date('Ymd-His') . '-' . $tag . '.sqlite';
    c_db()->exec('VACUUM INTO ' . c_db()->quote($f));
    $tous = glob($dir . '/camping-*.sqlite') ?: [];
    sort($tous);
    foreach (array_slice($tous, 0, max(0, count($tous) - 30)) as $vieux) {
        @unlink($vieux);
    }
}

function export_all(): string
{
    return json_encode([
        'app' => 'CAMPING OS 76', 'exportedAt' => c_now(),
        'menu' => rd('config/menu'), 'settings' => rd('config/settings'), 'staff' => rd('config/staff'), 'map' => rd('config/map'),
        'drivers' => liste('drivers'), 'trips' => liste('trips'), 'orders' => liste('orders'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/* ------------------------------------------------------------------ */
/* Connexion : comptes de l'appli des réservations (le76.fr/equipe)    */
/* ------------------------------------------------------------------ */

function resa_base(): ?string
{
    $env = getenv('RESA_DONNEES');
    $web = dirname(__DIR__);
    foreach ($env ? [$env . '/reservations.sqlite'] : [dirname($web) . '/le76-reservations/reservations.sqlite', $web . '/api/donnees/reservations.sqlite'] as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

/** Le compte de l'équipe connecté (même session que le76.fr/equipe et /admin), ou null. */
function equipe_utilisateur(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    $j = (string) ($_COOKIE['le76_equipe'] ?? '');
    $f = resa_base();
    if (!$f || !preg_match('/^[a-f0-9]{64}$/', $j)) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $st = $pdo->prepare('SELECT u.id, u.nom, u.admin FROM sessions s JOIN utilisateurs u ON u.id = s.utilisateur_id WHERE s.jeton = ? AND s.expire > ? AND u.actif = 1');
        $st->execute([hash('sha256', $j), time()]);
        $r = $st->fetch();
        $u = $r ? ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'admin' => (bool) $r['admin']] : null;
    } catch (Throwable $e) {
        error_log('[camping] session : ' . $e->getMessage());
    }
    return $u;
}

function equipe_installee(): bool
{
    $f = resa_base();
    if (!$f) {
        return false;
    }
    try {
        $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return (int) $pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/* ------------------------------------------------------------------ */
/* Divers                                                              */
/* ------------------------------------------------------------------ */

function limiter(string $cle, int $max, int $fenetre): bool
{
    $pdo = c_db();
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM tentatives WHERE quand < ?')->execute([time() - 86400]);
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM tentatives WHERE cle = ? AND quand > ?');
    $st->execute([$cle, time() - $fenetre]);
    if ((int) $st->fetchColumn() >= $max) {
        return false;
    }
    $pdo->prepare('INSERT INTO tentatives (cle, quand) VALUES (?, ?)')->execute([$cle, time()]);
    return true;
}

function repondre(array $d, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
