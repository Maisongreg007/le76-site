<?php
/**
 * Précommande de pain et viennoiseries du camping (le76.fr/pain).
 *
 * Les données (commandes, produits, réglages, code de l'équipe) ne sont PAS dans ce dépôt :
 * elles vivent dans « le76-pain », à côté de public_html, hors d'atteinte du web et des
 * mises à jour automatiques du site. Le code de l'équipe est choisi à la première ouverture
 * de l'espace équipe.
 *
 * Toutes les dates et heures métier sont calculées ici, en heure de Paris : le navigateur
 * ne décide jamais d'un prix, d'un total, d'une date possible ou d'un statut.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/epson.php';

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

const P_COOKIE = 'le76_pain';
const P_DUREE = 400 * 86400;
const P_LANGS = ['fr', 'de', 'en', 'nl'];
const P_STATUTS = ['pending', 'confirmed', 'collected', 'cancelled'];   // à payer → payée (à remettre) → retirée ; ou annulée
const P_PAIEMENTS = ['especes' => 'Espèces', 'carte' => 'Carte', 'en_ligne' => 'Carte en ligne'];

const P_REGLAGES_DEFAUT = [
    'title' => 'Pain & viennoiseries',
    'place' => 'Restaurant du camping',
    'message' => 'Retrait au restaurant du camping, de 8 h à 12 h. Paiement sur place (espèces ou carte).',
    'ordering_open' => true,
    'open_days' => [1, 2, 3, 4, 5, 6, 7],      // 1 = lundi … 7 = dimanche
    'pickup_from' => '08:00',
    'pickup_to' => '12:00',
    'cutoff_days' => 1,                         // 1 = la veille, 0 = le jour même
    'cutoff_time' => '20:00',
    'days_ahead' => 7,                          // commandes possibles jusqu'à N jours à l'avance
    'season_start' => '',                       // vide = pas de limite
    'season_end' => '',
    'closed_dates' => [],                       // fermetures exceptionnelles AAAA-MM-JJ
    'max_per_product' => 20,
    'printer_on' => false,                      // un bon s'imprime à chaque commande payée
    'printer_ip' => '',                         // imprimante Epson sur le réseau du camping (relais : page équipe ouverte)
];

const P_PRODUITS_DEFAUT = [
    ['name' => 'Baguette', 'names' => ['de' => 'Baguette', 'en' => 'Baguette', 'nl' => 'Stokbrood'], 'emoji' => '🥖', 'price' => 190, 'cost' => 90],
    ['name' => 'Croissant', 'names' => ['de' => 'Croissant', 'en' => 'Croissant', 'nl' => 'Croissant'], 'emoji' => '🥐', 'price' => 190, 'cost' => 90],
    ['name' => 'Pain au chocolat', 'names' => ['de' => 'Schokocroissant', 'en' => 'Chocolate croissant', 'nl' => 'Chocoladebroodje'], 'emoji' => '🍫', 'price' => 190, 'cost' => 90],
];

/* ------------------------------------------------------------------ */
/* Stockage                                                            */
/* ------------------------------------------------------------------ */

function dossier(): string
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $env = getenv(defined('PAIN_DEMO') ? 'PAIN_DEMO_DONNEES' : 'PAIN_DONNEES');
    $candidats = $env ? [$env] : (defined('PAIN_DEMO')
        ? [dirname(__DIR__, 2) . '/le76-pain-demo', __DIR__ . '/donnees-demo']
        : [dirname(__DIR__, 2) . '/le76-pain', __DIR__ . '/donnees']);
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

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . dossier() . '/pain.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reglages (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (jeton TEXT PRIMARY KEY, expire INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tentatives (cle TEXT NOT NULL, quand INTEGER NOT NULL)');
    // Liens (QR) à usage unique pour connecter un autre appareil sans retaper le code
    $pdo->exec('CREATE TABLE IF NOT EXISTS liens (jeton TEXT PRIMARY KEY, expire INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS produits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nom TEXT NOT NULL, noms TEXT NOT NULL DEFAULT \'{}\', emoji TEXT NOT NULL DEFAULT \'\',
        prix INTEGER NOT NULL, cout INTEGER NOT NULL DEFAULT 0,
        actif INTEGER NOT NULL DEFAULT 1, ordre INTEGER NOT NULL DEFAULT 0,
        limite_jour INTEGER, cree_le TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS commandes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        jeton TEXT NOT NULL UNIQUE, code TEXT NOT NULL UNIQUE, cle TEXT UNIQUE,
        nom TEXT NOT NULL, emplacement TEXT NOT NULL, telephone TEXT NOT NULL DEFAULT \'\', remarque TEXT NOT NULL DEFAULT \'\',
        langue TEXT NOT NULL DEFAULT \'fr\', jour TEXT NOT NULL, statut TEXT NOT NULL, paye INTEGER NOT NULL DEFAULT 0,
        total INTEGER NOT NULL, cout INTEGER NOT NULL, origine TEXT NOT NULL DEFAULT \'client\',
        cree_le TEXT NOT NULL, modifie_le TEXT NOT NULL, retire_le TEXT, annule_le TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commandes_jour ON commandes(jour, statut)');
    $cols = array_column($pdo->query('PRAGMA table_info(commandes)')->fetchAll(), 'name');
    if (!in_array('prenom', $cols, true)) {
        $pdo->exec("ALTER TABLE commandes ADD COLUMN prenom TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('paiement', $cols, true)) {
        $pdo->exec("ALTER TABLE commandes ADD COLUMN paiement TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('checkout_id', $cols, true)) {
        $pdo->exec('ALTER TABLE commandes ADD COLUMN checkout_id TEXT');
        $pdo->exec('ALTER TABLE commandes ADD COLUMN checkout_montant INTEGER');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS lignes (
        commande_id INTEGER NOT NULL REFERENCES commandes(id) ON DELETE CASCADE,
        produit_id INTEGER NOT NULL, nom TEXT NOT NULL, emoji TEXT NOT NULL DEFAULT \'\',
        prix INTEGER NOT NULL, cout INTEGER NOT NULL, quantite INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lignes ON lignes(commande_id)');
    // Tickets à imprimer : l'imprimante vient les chercher (attente → envoye → imprime, ou echec)
    $pdo->exec('CREATE TABLE IF NOT EXISTS impressions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, job TEXT NOT NULL UNIQUE, commande_id INTEGER,
        lignes TEXT NOT NULL, statut TEXT NOT NULL DEFAULT \'attente\', essais INTEGER NOT NULL DEFAULT 0,
        cree_le TEXT NOT NULL, envoye_le TEXT, fini_le TEXT, erreur TEXT NOT NULL DEFAULT \'\')');
    $pdo->exec('CREATE TABLE IF NOT EXISTS journal (
        id INTEGER PRIMARY KEY AUTOINCREMENT, quand TEXT NOT NULL, qui TEXT NOT NULL,
        action TEXT NOT NULL, commande_id INTEGER, detail TEXT NOT NULL DEFAULT \'\')');
    if ((int) $pdo->query('SELECT COUNT(*) FROM produits')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO produits (nom, noms, emoji, prix, cout, actif, ordre, cree_le) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
        foreach (P_PRODUITS_DEFAUT as $i => $p) {
            $st->execute([$p['name'], json_encode($p['names'], JSON_UNESCAPED_UNICODE), $p['emoji'], $p['price'], $p['cost'], $i + 1, maintenant()]);
        }
    }
    return $pdo;
}

function maintenant(): string
{
    return date('Y-m-d H:i:s');
}

function journaliser(string $qui, string $action, ?int $commande, string $detail = ''): void
{
    db()->prepare('INSERT INTO journal (quand, qui, action, commande_id, detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([maintenant(), $qui, $action, $commande, mb_substr($detail, 0, 1000)]);
}

/* ------------------------------------------------------------------ */
/* Réglages                                                            */
/* ------------------------------------------------------------------ */

function reglages(): array
{
    $st = db()->prepare("SELECT valeur FROM reglages WHERE cle = 'reglages'");
    $st->execute();
    $v = $st->fetchColumn();
    $r = $v === false ? [] : (json_decode((string) $v, true) ?: []);
    return array_merge(P_REGLAGES_DEFAUT, array_intersect_key($r, P_REGLAGES_DEFAUT));
}

function heure_valide(mixed $h): bool
{
    return is_string($h) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $h) === 1;
}

function date_valide(mixed $d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d)) {
        return false;
    }
    $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

function texte(mixed $v, int $max, bool $requis = false, string $nom = 'Ce champ'): string
{
    $s = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $v) ?? '');
    if ($requis && $s === '') {
        erreur($nom . ' est obligatoire.');
    }
    if (mb_strlen($s) > $max) {
        erreur($nom . ' est trop long (' . $max . ' caractères maximum).');
    }
    return $s;
}

function valider_reglages(array $in): array
{
    $r = reglages();
    $out = $r;
    $out['title'] = texte($in['title'] ?? $r['title'], 60, true, 'Le titre');
    $out['place'] = texte($in['place'] ?? $r['place'], 80, false, 'Le lieu');
    $out['message'] = texte($in['message'] ?? $r['message'], 400, false, 'Le message');
    $out['ordering_open'] = (bool) ($in['ordering_open'] ?? $r['ordering_open']);
    $jours = $in['open_days'] ?? $r['open_days'];
    if (!is_array($jours)) {
        erreur('Jours d’ouverture invalides.');
    }
    $jours = array_values(array_unique(array_map('intval', $jours)));
    sort($jours);
    foreach ($jours as $j) {
        if ($j < 1 || $j > 7) {
            erreur('Jours d’ouverture invalides.');
        }
    }
    $out['open_days'] = $jours;
    foreach (['pickup_from', 'pickup_to', 'cutoff_time'] as $k) {
        $h = $in[$k] ?? $r[$k];
        if (!heure_valide($h)) {
            erreur('Heure invalide.');
        }
        $out[$k] = $h;
    }
    if ($out['pickup_from'] >= $out['pickup_to']) {
        erreur('L’heure de fin du retrait doit être après l’heure de début.');
    }
    $cd = $in['cutoff_days'] ?? $r['cutoff_days'];
    if (!is_numeric($cd) || (int) $cd < 0 || (int) $cd > 7) {
        erreur('Délai de commande invalide.');
    }
    $out['cutoff_days'] = (int) $cd;
    if ($out['cutoff_days'] === 0 && $out['cutoff_time'] > $out['pickup_to']) {
        erreur('Le jour même, l’heure limite de commande doit être avant la fin du retrait.');
    }
    $da = $in['days_ahead'] ?? $r['days_ahead'];
    if (!is_numeric($da) || (int) $da < 1 || (int) $da > 60) {
        erreur('Nombre de jours à l’avance invalide (1 à 60).');
    }
    $out['days_ahead'] = (int) $da;
    foreach (['season_start', 'season_end'] as $k) {
        $d = (string) ($in[$k] ?? $r[$k]);
        if ($d !== '' && !date_valide($d)) {
            erreur('Date de saison invalide.');
        }
        $out[$k] = $d;
    }
    if ($out['season_start'] !== '' && $out['season_end'] !== '' && $out['season_start'] > $out['season_end']) {
        erreur('La fin de saison doit être après le début.');
    }
    $fermes = $in['closed_dates'] ?? $r['closed_dates'];
    if (!is_array($fermes) || count($fermes) > 400) {
        erreur('Fermetures invalides.');
    }
    foreach ($fermes as $d) {
        if (!date_valide($d)) {
            erreur('Date de fermeture invalide.');
        }
    }
    $fermes = array_values(array_unique($fermes));
    sort($fermes);
    $out['closed_dates'] = $fermes;
    $mx = $in['max_per_product'] ?? $r['max_per_product'];
    if (!is_numeric($mx) || (int) $mx < 1 || (int) $mx > 200) {
        erreur('Quantité maximale invalide (1 à 200).');
    }
    $out['max_per_product'] = (int) $mx;
    $out['printer_on'] = (bool) ($in['printer_on'] ?? $r['printer_on']);
    $ip = trim((string) ($in['printer_ip'] ?? $r['printer_ip']));
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        erreur('Adresse de l’imprimante invalide (ex. 192.168.192.54).');
    }
    $out['printer_ip'] = $ip;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Dates possibles                                                     */
/* ------------------------------------------------------------------ */

/** Heure limite pour commander un retrait le jour $jour. */
function limite_commande(string $jour, ?array $r = null): DateTimeImmutable
{
    $r ??= reglages();
    return (new DateTimeImmutable($jour . ' ' . $r['cutoff_time']))->modify('-' . $r['cutoff_days'] . ' day');
}

/**
 * Aperçu équipe : connectée à l'espace équipe sur le même appareil, l'équipe peut utiliser la boutique
 * hors saison ou commandes en pause (pour tester). Les jours, fermetures et heures limites restent appliqués.
 */
function apercu_equipe(?bool $actif = null): bool
{
    static $a = false;
    if ($actif !== null) {
        $a = $actif;
    }
    return $a;
}

/** Jour ouvert (saison, jour de la semaine, fermetures), sans tenir compte de l'heure limite. */
function jour_ouvert(string $jour, ?array $r = null): bool
{
    $r ??= reglages();
    if (!apercu_equipe() && $r['season_start'] !== '' && $jour < $r['season_start']) {
        return false;
    }
    if (!apercu_equipe() && $r['season_end'] !== '' && $jour > $r['season_end']) {
        return false;
    }
    if (in_array($jour, $r['closed_dates'], true)) {
        return false;
    }
    return in_array((int) (new DateTimeImmutable($jour))->format('N'), $r['open_days'], true);
}

/** Le client peut-il encore commander (ou modifier) pour ce jour ? */
function jour_commandable(string $jour, ?array $r = null): bool
{
    $r ??= reglages();
    if ((!$r['ordering_open'] && !apercu_equipe()) || !date_valide($jour) || !jour_ouvert($jour, $r)) {
        return false;
    }
    $max = (new DateTimeImmutable('today'))->modify('+' . $r['days_ahead'] . ' day')->format('Y-m-d');
    if ($jour > $max) {
        return false;
    }
    return new DateTimeImmutable() < limite_commande($jour, $r);
}

function jours_commandables(): array
{
    $r = reglages();
    $out = [];
    for ($i = 0; $i <= $r['days_ahead']; $i++) {
        $j = (new DateTimeImmutable('today'))->modify("+$i day")->format('Y-m-d');
        if (jour_commandable($j, $r)) {
            $out[] = ['date' => $j, 'limit' => limite_commande($j, $r)->format('Y-m-d H:i')];
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Produits                                                            */
/* ------------------------------------------------------------------ */

function produits(bool $actifsSeulement): array
{
    $rows = db()->query('SELECT * FROM produits' . ($actifsSeulement ? ' WHERE actif = 1' : '') . ' ORDER BY ordre, id')->fetchAll();
    return array_map(fn($p) => [
        'id' => (int) $p['id'], 'name' => $p['nom'], 'names' => json_decode($p['noms'], true) ?: [], 'emoji' => $p['emoji'],
        'price' => (int) $p['prix'], 'cost' => (int) $p['cout'], 'active' => (bool) $p['actif'], 'sort' => (int) $p['ordre'],
        'dailyLimit' => $p['limite_jour'] === null ? null : (int) $p['limite_jour'],
    ], $rows);
}

/** Quantités déjà réservées pour un jour (hors commande $sauf). */
function reserve_du_jour(string $jour, int $sauf = 0): array
{
    $st = db()->prepare("SELECT l.produit_id, SUM(l.quantite) q FROM lignes l JOIN commandes c ON c.id = l.commande_id
        WHERE c.jour = ? AND c.statut != 'cancelled' AND c.id != ? GROUP BY l.produit_id");
    $st->execute([$jour, $sauf]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['produit_id']] = (int) $r['q'];
    }
    return $out;
}

/**
 * Transforme [{id, qty}] en lignes au prix du catalogue (le prix envoyé par le navigateur est ignoré).
 * Vérifie quantités, produits actifs et limites du jour.
 */
function lignes_depuis(mixed $items, string $jour, int $sauf = 0, bool $equipe = false): array
{
    if (!is_array($items) || count($items) > 50) {
        erreur('Panier invalide.');
    }
    $r = reglages();
    $catalogue = [];
    foreach (produits(false) as $p) {
        $catalogue[$p['id']] = $p;
    }
    $reserve = reserve_du_jour($jour, $sauf);
    $lignes = [];
    foreach ($items as $it) {
        $id = (int) ($it['id'] ?? 0);
        $q = $it['qty'] ?? null;
        if (!is_int($q) && !(is_string($q) && ctype_digit($q))) {
            erreur('Quantité invalide.');
        }
        $q = (int) $q;
        if ($q === 0) {
            continue;
        }
        $p = $catalogue[$id] ?? null;
        if (!$p || (!$p['active'] && !$equipe)) {
            erreur('Un produit n’est plus disponible. Recharge la page.', 409);
        }
        if ($q < 0 || $q > $r['max_per_product']) {
            erreur('Quantité maximale : ' . $r['max_per_product'] . ' par produit.');
        }
        if (isset($lignes[$id])) {
            erreur('Panier invalide.');
        }
        if ($p['dailyLimit'] !== null && ($reserve[$id] ?? 0) + $q > $p['dailyLimit']) {
            $reste = max(0, $p['dailyLimit'] - ($reserve[$id] ?? 0));
            erreur('Plus assez de « ' . $p['name'] . ' » pour ce jour : ' . $reste . ' disponible(s).', 409);
        }
        $lignes[$id] = ['produit_id' => $id, 'nom' => $p['name'], 'emoji' => $p['emoji'], 'prix' => $p['price'], 'cout' => $p['cost'], 'quantite' => $q];
    }
    if (!$lignes) {
        erreur('Le panier est vide.');
    }
    return array_values($lignes);
}

/* ------------------------------------------------------------------ */
/* Commandes                                                           */
/* ------------------------------------------------------------------ */

function jeton(int $octets = 12): string
{
    return rtrim(strtr(base64_encode(random_bytes($octets)), '+/', '-_'), '=');
}

function nouveau_code(): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($essai = 0; $essai < 20; $essai++) {
        $c = '';
        for ($i = 0; $i < 6; $i++) {
            $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        $c = substr($c, 0, 3) . '-' . substr($c, 3);
        $st = db()->prepare('SELECT 1 FROM commandes WHERE code = ?');
        $st->execute([$c]);
        if (!$st->fetchColumn()) {
            return $c;
        }
    }
    throw new RuntimeException('Impossible de créer un code de commande.');
}

function commande_par(string $champ, string|int $valeur): ?array
{
    if (!in_array($champ, ['id', 'jeton', 'code', 'cle', 'checkout_id'], true)) {
        throw new InvalidArgumentException('champ');
    }
    $st = db()->prepare("SELECT * FROM commandes WHERE $champ = ?");
    $st->execute([$valeur]);
    $c = $st->fetch();
    return $c ?: null;
}

function lignes_de(int $id): array
{
    $st = db()->prepare('SELECT produit_id, nom, emoji, prix, cout, quantite FROM lignes WHERE commande_id = ? ORDER BY rowid');
    $st->execute([$id]);
    return array_map(fn($l) => ['id' => (int) $l['produit_id'], 'name' => $l['nom'], 'emoji' => $l['emoji'], 'price' => (int) $l['prix'], 'cost' => (int) $l['cout'], 'qty' => (int) $l['quantite']], $st->fetchAll());
}

/** Vue d'une commande. Le client ne voit ni le coût ni le journal. */
function vue_commande(array $c, bool $equipe): array
{
    $v = [
        'token' => $c['jeton'], 'code' => $c['code'], 'name' => trim(($c['prenom'] ?? '') . ' ' . $c['nom']), 'firstName' => $c['prenom'] ?? '', 'lastName' => $c['nom'],
        'pitch' => $c['emplacement'], 'phone' => $c['telephone'], 'example' => ($c['origine'] ?? '') === 'exemple',
        'note' => $c['remarque'], 'lang' => $c['langue'], 'date' => $c['jour'], 'status' => $c['statut'], 'paid' => (bool) $c['paye'],
        'total' => (int) $c['total'], 'items' => lignes_de((int) $c['id']), 'createdAt' => $c['cree_le'],
        'collectedAt' => $c['retire_le'], 'cancelledAt' => $c['annule_le'],
        'paymentMethod' => $c['paiement'] ?? '',
        'editable' => $c['statut'] === 'pending' && jour_commandable($c['jour']),
        'limit' => limite_commande($c['jour'])->format('Y-m-d H:i'),
    ];
    if ($equipe) {
        $v['id'] = (int) $c['id'];
        $v['cost'] = (int) $c['cout'];
        $v['origin'] = $c['origine'];
    } else {
        foreach ($v['items'] as &$l) {
            unset($l['cost']);
        }
    }
    return $v;
}

/* ------------------------------------------------------------------ */
/* Imprimante des bons (Epson, Server Direct Print)                    */
/* ------------------------------------------------------------------ */

/** Clé secrète de l'adresse que l'imprimante interroge, et dernier contact. */
function imprimante_conf(): array
{
    $st = db()->prepare("SELECT valeur FROM reglages WHERE cle = 'imprimante'");
    $st->execute();
    $v = $st->fetchColumn();
    $c = $v === false ? [] : (json_decode((string) $v, true) ?: []);
    if (empty($c['cle'])) {
        $c = ['cle' => bin2hex(random_bytes(16)), 'vu_le' => null, 'nom' => ''];
        imprimante_enregistrer($c);
    }
    return $c + ['vu_le' => null, 'nom' => ''];
}

function imprimante_enregistrer(array $c): void
{
    db()->prepare("INSERT INTO reglages (cle, valeur) VALUES ('imprimante', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
        ->execute([json_encode($c, JSON_UNESCAPED_UNICODE)]);
}

/** Met un ticket dans la file ; l'imprimante le récupère à son prochain passage (quelques secondes). */
function imprimante_ajouter(array $lignes, ?int $commande): void
{
    db()->prepare('INSERT INTO impressions (job, commande_id, lignes, cree_le) VALUES (?, ?, ?, ?)')
        ->execute(['P' . bin2hex(random_bytes(6)), $commande, json_encode($lignes, JSON_UNESCAPED_UNICODE), maintenant()]);
}

const IMP_MAX_ESSAIS = 3;

/**
 * Donne les tickets à imprimer (au plus $max) et les marque « envoyés ».
 * À appeler dans une transaction. Un ticket envoyé sans réponse depuis 2 minutes est redonné ;
 * rien de plus vieux que 2 jours (une imprimante rallumée ne ressort pas tout l'historique).
 */
function imprimante_donner(int $max): array
{
    $st = db()->prepare("SELECT * FROM impressions WHERE cree_le >= ? AND essais < ?
        AND (statut = 'attente' OR (statut = 'envoye' AND envoye_le < ?)) ORDER BY id LIMIT " . max(1, min(10, $max)));
    $st->execute([date('Y-m-d H:i:s', time() - 2 * 86400), IMP_MAX_ESSAIS, date('Y-m-d H:i:s', time() - 120)]);
    $maj = db()->prepare("UPDATE impressions SET statut = 'envoye', envoye_le = ?, essais = essais + 1 WHERE id = ?");
    $out = [];
    foreach ($st->fetchAll() as $t) {
        $lignes = json_decode((string) $t['lignes'], true);
        if (is_array($lignes)) {
            $maj->execute([maintenant(), $t['id']]);
            $out[] = ['job' => $t['job'], 'lignes' => $lignes];
        }
    }
    return $out;
}

/** Résultat d'impression : imprimé, ou à retenter (3 fois au plus : plus de papier, capot ouvert…). */
function imprimante_noter(string $job, bool $ok, string $code): void
{
    if ($ok) {
        db()->prepare("UPDATE impressions SET statut = 'imprime', fini_le = ?, erreur = '' WHERE job = ?")->execute([maintenant(), $job]);
        return;
    }
    db()->prepare("UPDATE impressions SET statut = CASE WHEN essais < ? THEN 'attente' ELSE 'echec' END, fini_le = ?, erreur = ? WHERE job = ? AND statut = 'envoye'")
        ->execute([IMP_MAX_ESSAIS, maintenant(), mb_substr($code, 0, 80), $job]);
}

/** L'imprimante a répondu : on note l'heure (affichée « Imprimante reliée »). */
function imprimante_vue(string $nom): void
{
    $c = imprimante_conf();
    $c['vu_le'] = maintenant();
    $c['nom'] = mb_substr($nom, 0, 40);
    imprimante_enregistrer($c);
}

/** État pour l'écran des réglages. */
function imprimante_etat(string $urlBase): array
{
    $c = imprimante_conf();
    $n = db()->query("SELECT COUNT(*) FROM impressions WHERE statut IN ('attente', 'envoye') AND cree_le >= datetime('now', 'localtime', '-2 days')")->fetchColumn();
    $dernier = db()->query("SELECT i.statut, i.fini_le, i.erreur, c.code FROM impressions i LEFT JOIN commandes c ON c.id = i.commande_id WHERE i.statut IN ('imprime', 'echec') ORDER BY i.id DESC LIMIT 1")->fetch() ?: null;
    return [
        'on' => (bool) reglages()['printer_on'], 'ip' => reglages()['printer_ip'],
        'url' => $urlBase . 'imprimante.php?k=' . $c['cle'],
        'seen' => $c['vu_le'], 'name' => $c['nom'], 'now' => maintenant(),
        'waiting' => (int) $n, 'last' => $dernier,
    ];
}

/**
 * Bon de production d'une commande fraîchement payée, mis en file pour l'imprimante.
 * N'échoue jamais bruyamment : un souci d'impression ne doit jamais empêcher d'encaisser un client.
 */
function imprimer_bon(array $c): void
{
    try {
        if (!reglages()['printer_on']) {
            return;
        }
        $r = reglages();
        $trait = ['text' => str_repeat('-', 48)];
        $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        $j = new DateTimeImmutable($c['jour']);
        $quand = $c['jour'] === date('Y-m-d') ? 'AUJOURD\'HUI' : ($c['jour'] === date('Y-m-d', strtotime('+1 day')) ? 'DEMAIN' : strtoupper($jours[(int) $j->format('N')]));
        $lignes = [
            ['text' => $r['title'], 'align' => 'center', 'bold' => true],
            ['text' => 'Retrait ' . $quand . ' ' . $j->format('d/m'), 'align' => 'center', 'bold' => true, 'width' => 2, 'height' => 2],
            ['text' => 'de ' . str_replace(':', 'h', $r['pickup_from']) . ' a ' . str_replace(':', 'h', $r['pickup_to']), 'align' => 'center'],
            $trait,
            ['text' => 'Empl. ' . $c['emplacement'], 'bold' => true, 'width' => 2, 'height' => 2],
            ['text' => trim(($c['prenom'] ?? '') . ' ' . $c['nom']) . ($c['telephone'] !== '' ? '  -  ' . $c['telephone'] : '')],
            $trait,
        ];
        foreach (lignes_de((int) $c['id']) as $l) {
            $lignes[] = ['text' => $l['qty'] . ' x ' . $l['name'], 'bold' => true, 'width' => 2, 'height' => 2];
        }
        $rem = trim((string) ($c['remarque'] ?? ''));
        if ($rem !== '') {
            $lignes[] = $trait;
            $lignes[] = ['text' => 'Remarque : ' . $rem, 'bold' => true];
        }
        $lignes[] = $trait;
        $methode = P_PAIEMENTS[$c['paiement'] ?? ''] ?? '';
        $lignes[] = ['text' => 'Total ' . eur((int) $c['total']) . ($methode !== '' ? ' - paye (' . mb_strtolower($methode) . ')' : ''), 'bold' => true];
        $lignes[] = ['text' => 'Commande ' . $c['code'] . ' - ' . date('d/m H:i')];
        $lignes[] = ['feed' => 3];
        $lignes[] = ['cut' => true];
        imprimante_ajouter($lignes, (int) $c['id']);
    } catch (Throwable $e) {
        error_log('pain: impression du bon #' . ($c['id'] ?? '?') . ' échouée : ' . $e->getMessage());
    }
}

function eur(int $c): string
{
    return number_format($c / 100, 2, ',', ' ') . ' €';
}

function enregistrer_lignes(int $id, array $lignes): array
{
    db()->prepare('DELETE FROM lignes WHERE commande_id = ?')->execute([$id]);
    $st = db()->prepare('INSERT INTO lignes (commande_id, produit_id, nom, emoji, prix, cout, quantite) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $total = 0;
    $cout = 0;
    foreach ($lignes as $l) {
        $st->execute([$id, $l['produit_id'], $l['nom'], $l['emoji'], $l['prix'], $l['cout'], $l['quantite']]);
        $total += $l['prix'] * $l['quantite'];
        $cout += $l['cout'] * $l['quantite'];
    }
    return [$total, $cout];
}

function resume_lignes(array $lignes): string
{
    return implode(', ', array_map(fn($l) => ($l['quantite'] ?? $l['qty']) . ' × ' . ($l['nom'] ?? $l['name']), $lignes));
}

/** Champs saisis par le client ou l'équipe. */
/** Chiffres seuls d'un numéro, sans indicatif ni 0 de tête (0612… et +33612… donnent 612…). */
function chiffres_tel(string $t): string
{
    $d = preg_replace('/\D/', '', $t) ?? '';
    $d = preg_replace('/^00/', '', $d) ?? $d;
    foreach (['33', '49', '31', '32', '41', '44', '352', '43', '39', '34'] as $ind) {
        if (str_starts_with($d, $ind) && strlen($d) >= strlen($ind) + 9) {
            $d = substr($d, strlen($ind));
            break;
        }
    }
    return ltrim($d, '0');
}

function champs_client(array $q, bool $equipe = false): array
{
    $r = reglages();
    // Prénom + nom obligatoires (anciennes versions : un seul champ « name » accepté comme nom)
    $prenom = texte($q['firstName'] ?? '', 40, true, 'Le prénom');
    $nom = texte($q['lastName'] ?? ($q['name'] ?? ''), 60, true, 'Le nom');
    $empl = texte($q['pitch'] ?? '', 30, true, 'Le numéro d’emplacement');
    // Téléphone obligatoire pour les campeurs (pour les joindre et retrouver leur bon) ; facultatif pour l'équipe
    $tel = texte($q['phone'] ?? '', 30, !$equipe, 'Le numéro de téléphone');
    if ($tel !== '' && (!preg_match('/^[0-9 +().\/\-]{6,30}$/', $tel) || strlen(chiffres_tel($tel)) < 8)) {
        erreur('Numéro de téléphone invalide.');
    }
    $rem = texte($q['note'] ?? '', 200, false, 'La remarque');
    $langue = in_array($q['lang'] ?? 'fr', P_LANGS, true) ? ($q['lang'] ?? 'fr') : 'fr';
    return [$nom, $empl, $tel, $rem, $langue, $prenom];
}

/**
 * Une commande de campeur n'est validée qu'une fois payée. Passé l'heure limite sans paiement,
 * elle est annulée automatiquement (et n'entre pas dans la préparation).
 */
function expirer_impayees(): void
{
    static $fait = false;
    if ($fait) {
        return;
    }
    $fait = true;
    $r = reglages();
    $st = db()->prepare("SELECT * FROM commandes WHERE statut = 'pending' AND jour <= ?");
    $st->execute([date('Y-m-d', strtotime('+' . ($r['cutoff_days'] + 1) . ' day'))]);
    foreach ($st->fetchAll() as $c) {
        if (new DateTimeImmutable() >= limite_commande($c['jour'], $r)) {
            if (!empty($c['checkout_id']) && function_exists('verifier_carte') && verifier_carte($c)) {
                continue;
            }
            $u = db()->prepare("UPDATE commandes SET statut = 'cancelled', annule_le = ?, modifie_le = ? WHERE id = ? AND statut = 'pending'");
            $u->execute([maintenant(), maintenant(), $c['id']]);
            if ($u->rowCount() === 1) {
                journaliser('système', 'Annulée : non payée avant l’heure limite', (int) $c['id']);
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Équipe : code d'accès                                               */
/* ------------------------------------------------------------------ */

function code_hache(): ?string
{
    $st = db()->prepare("SELECT valeur FROM reglages WHERE cle = 'code'");
    $st->execute();
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function code_valide(string $code): bool
{
    return preg_match('/^\d{4,8}$/', $code) === 1;
}

function connecte(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (defined('PAIN_DEMO')) {
        // Démo : l'espace équipe est ouvert sans code (données fictives), la boutique reste « campeur ».
        $ref = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        return $ok = str_contains($ref, '/pain/equipe/');
    }
    $j = (string) ($_COOKIE[P_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $j)) {
        return $ok = false;
    }
    $st = db()->prepare('SELECT 1 FROM sessions WHERE jeton = ? AND expire > ?');
    $st->execute([hash('sha256', $j), time()]);
    return $ok = (bool) $st->fetchColumn();
}

function ouvrir_session(): void
{
    $j = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM sessions WHERE expire < ?')->execute([time()]);
    db()->prepare('INSERT INTO sessions (jeton, expire) VALUES (?, ?)')->execute([hash('sha256', $j), time() + P_DUREE]);
    setcookie(P_COOKIE, $j, [
        'expires' => time() + P_DUREE, 'path' => '/pain/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function fermer_session(): void
{
    $j = (string) ($_COOKIE[P_COOKIE] ?? '');
    if ($j !== '') {
        db()->prepare('DELETE FROM sessions WHERE jeton = ?')->execute([hash('sha256', $j)]);
    }
    setcookie(P_COOKIE, '', ['expires' => time() - 3600, 'path' => '/pain/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

function limiter(string $cle, int $max, int $fenetre): bool
{
    $pdo = db();
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

function ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
}

/* ------------------------------------------------------------------ */
/* Réponses                                                            */
/* ------------------------------------------------------------------ */

function repondre(array $donnees, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erreur(string $message, int $code = 400): never
{
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    repondre(['ok' => false, 'error' => $message], $code);
}
