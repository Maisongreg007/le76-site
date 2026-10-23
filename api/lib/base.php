<?php
/**
 * Réservations du 76 : socle commun (base de données, réglages, disponibilités).
 *
 * Les données (réservations, comptes, clés) ne sont PAS dans ce dépôt :
 * elles vivent dans un dossier « le76-reservations » à côté de public_html,
 * hors d'atteinte du web et des mises à jour du site.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

const SERVICES = ['midi', 'soir'];
const STATUTS = ['attente', 'confirmee', 'refusee', 'annulee'];
const STATUTS_ACTIFS = ['attente', 'confirmee'];

/* ------------------------------------------------------------------ */
/* Dossier de données et base                                          */
/* ------------------------------------------------------------------ */

function dossier_donnees(): string
{
    static $dossier = null;
    if ($dossier !== null) {
        return $dossier;
    }
    $env = getenv('RESA_DONNEES');
    $candidats = $env ? [$env] : [
        dirname(__DIR__, 3) . '/le76-reservations', // à côté de public_html
        dirname(__DIR__) . '/donnees',              // repli : api/donnees (protégé)
    ];
    foreach ($candidats as $c) {
        if (!is_dir($c)) {
            @mkdir($c, 0750, true);
        }
        if (is_dir($c) && is_writable($c)) {
            if (str_starts_with($c, dirname(__DIR__)) && !is_file("$c/.htaccess")) {
                file_put_contents("$c/.htaccess", "Require all denied\n");
            }
            return $dossier = $c;
        }
    }
    throw new RuntimeException("Impossible de créer le dossier des données.");
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . dossier_donnees() . '/reservations.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    creer_tables($pdo);
    return $pdo;
}

function creer_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        service TEXT NOT NULL,
        heure TEXT NOT NULL,
        couverts INTEGER NOT NULL,
        nom TEXT NOT NULL,
        telephone TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        remarque TEXT NOT NULL DEFAULT '',
        note_interne TEXT NOT NULL DEFAULT '',
        statut TEXT NOT NULL DEFAULT 'attente',
        source TEXT NOT NULL DEFAULT 'site',
        arrive INTEGER NOT NULL DEFAULT 0,
        jeton TEXT NOT NULL,
        cree_le TEXT NOT NULL,
        cree_par TEXT NOT NULL DEFAULT '',
        modifie_le TEXT,
        traite_par TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_resa_date ON reservations(date, service)");
    $cols = array_column($pdo->query("PRAGMA table_info(reservations)")->fetchAll(), 'name');
    if (!in_array('rappel_envoye', $cols, true)) {
        $pdo->exec("ALTER TABLE reservations ADD COLUMN rappel_envoye INTEGER NOT NULL DEFAULT 0");
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_resa_jeton ON reservations(jeton)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nom TEXT NOT NULL,
        identifiant TEXT NOT NULL UNIQUE COLLATE NOCASE,
        mdp TEXT NOT NULL,
        admin INTEGER NOT NULL DEFAULT 0,
        actif INTEGER NOT NULL DEFAULT 1,
        cree_le TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        jeton TEXT PRIMARY KEY,
        utilisateur_id INTEGER NOT NULL,
        expire INTEGER NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reglages (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL)");
    // Exceptions jour par jour (fermeture, capacité, résa en ligne fermée…)
    $pdo->exec("CREATE TABLE IF NOT EXISTS jours (
        date TEXT NOT NULL,
        service TEXT NOT NULL,
        ouvert INTEGER,
        ferme_en_ligne INTEGER NOT NULL DEFAULT 0,
        capacite INTEGER,
        menu_impose INTEGER,
        note TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (date, service)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push (
        endpoint TEXT PRIMARY KEY,
        utilisateur_id INTEGER NOT NULL,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        cree_le TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tentatives (cle TEXT NOT NULL, quand INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reinit_mdp (jeton TEXT PRIMARY KEY, utilisateur_id INTEGER NOT NULL, expire INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS journal_mails (id INTEGER PRIMARY KEY AUTOINCREMENT, quand TEXT NOT NULL, a TEXT NOT NULL, sujet TEXT NOT NULL, ok INTEGER NOT NULL)");
}

function maintenant(): string
{
    return date('Y-m-d H:i:s');
}

/* ------------------------------------------------------------------ */
/* Réglages                                                            */
/* ------------------------------------------------------------------ */

function reglages_defaut(): array
{
    return [
        'resa_en_ligne' => true,
        'capacite' => ['midi' => 140, 'soir' => 140],
        // Saisons qui reviennent chaque année : [{nom, debut 'MM-JJ', fin 'MM-JJ', midi, soir}]
        'saisons' => [],
        // 1 = lundi … 7 = dimanche
        'semaine' => [
            '1' => ['midi' => false, 'soir' => false, 'midi_fin' => '13:30', 'soir_fin' => '20:30'],
            '2' => ['midi' => true, 'soir' => false, 'midi_fin' => '13:30', 'soir_fin' => '20:30'],
            '3' => ['midi' => true, 'soir' => false, 'midi_fin' => '13:30', 'soir_fin' => '20:30'],
            '4' => ['midi' => true, 'soir' => true, 'midi_fin' => '13:30', 'soir_fin' => '20:30'],
            '5' => ['midi' => true, 'soir' => true, 'midi_fin' => '13:30', 'soir_fin' => '20:30'],
            '6' => ['midi' => false, 'soir' => true, 'midi_fin' => '13:30', 'soir_fin' => '21:00'],
            '7' => ['midi' => true, 'soir' => true, 'midi_fin' => '13:30', 'soir_fin' => '20:30'],
        ],
        'midi_debut' => '11:30',
        'soir_debut' => '18:00',
        'pas' => 15,
        // 'heures' : jusqu'à N heures avant · 'veille' : jusqu'à la veille au soir
        'delai_mode' => 'heures',
        'delai_heures' => 2,
        'max_en_ligne' => 12,
        // Plus de demande en ligne pour le service du jour une fois qu'il a commencé (11h30 / 18h)
        'fermer_pendant_service' => true,
        // E-mail de rappel la veille, envoyé à partir de cette heure-là
        'rappel_veille' => true,
        'rappel_heure' => '10:00',
        'max_par_creneau' => 0,
        'jours_avance' => 365,
        'alertes_email' => [],
        'expediteur' => 'reservation@le76.fr',
        'nom' => 'Le 76',
        'telephone' => '03 88 86 31 87',
        'adresse' => '76 rue Principale, 67930 Beinheim',
        'site' => 'https://le76.fr',
        'mot_client' => '',
    ];
}

function reglages(bool $recharger = false): array
{
    static $r = null;
    if ($r !== null && !$recharger) {
        return $r;
    }
    $r = reglages_defaut();
    foreach (db()->query('SELECT cle, valeur FROM reglages') as $ligne) {
        if (array_key_exists($ligne['cle'], $r)) {
            $v = json_decode($ligne['valeur'], true);
            if ($ligne['cle'] === 'semaine' && is_array($v)) {
                foreach ($r['semaine'] as $j => $def) {
                    $r['semaine'][$j] = array_replace($def, is_array($v[$j] ?? null) ? $v[$j] : []);
                }
            } elseif ($ligne['cle'] === 'capacite' && is_array($v)) {
                $r['capacite'] = array_replace($r['capacite'], $v);
            } else {
                $r[$ligne['cle']] = $v;
            }
        }
    }
    return $r;
}

function reglages_enregistrer(array $nouveaux): array
{
    $def = reglages_defaut();
    $propres = nettoyer_reglages($nouveaux, reglages());
    $st = db()->prepare('INSERT INTO reglages (cle, valeur) VALUES (?, ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur');
    foreach ($propres as $cle => $valeur) {
        if (array_key_exists($cle, $def)) {
            $st->execute([$cle, json_encode($valeur, JSON_UNESCAPED_UNICODE)]);
        }
    }
    return reglages(true);
}

function heure_valide($h): bool
{
    return is_string($h) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) === 1;
}

function nettoyer_reglages(array $n, array $actuel): array
{
    $o = [];
    if (array_key_exists('resa_en_ligne', $n)) {
        $o['resa_en_ligne'] = (bool) $n['resa_en_ligne'];
    }
    if (array_key_exists('rappel_veille', $n)) {
        $o['rappel_veille'] = (bool) $n['rappel_veille'];
    }
    if (heure_valide($n['rappel_heure'] ?? null)) {
        $o['rappel_heure'] = $n['rappel_heure'];
    }
    if (array_key_exists('fermer_pendant_service', $n)) {
        $o['fermer_pendant_service'] = (bool) $n['fermer_pendant_service'];
    }
    if (isset($n['capacite']) && is_array($n['capacite'])) {
        foreach (SERVICES as $s) {
            $o['capacite'][$s] = max(0, min(2000, (int) ($n['capacite'][$s] ?? $actuel['capacite'][$s])));
        }
    }
    if (isset($n['saisons']) && is_array($n['saisons'])) {
        $o['saisons'] = [];
        foreach ($n['saisons'] as $s) {
            if (!is_array($s) || !preg_match('/^\d\d-\d\d$/', (string) ($s['debut'] ?? '')) || !preg_match('/^\d\d-\d\d$/', (string) ($s['fin'] ?? ''))) {
                continue;
            }
            $o['saisons'][] = [
                'nom' => mb_substr(trim((string) ($s['nom'] ?? 'Saison')), 0, 40),
                'debut' => $s['debut'],
                'fin' => $s['fin'],
                'midi' => max(0, min(2000, (int) ($s['midi'] ?? 0))),
                'soir' => max(0, min(2000, (int) ($s['soir'] ?? 0))),
            ];
        }
    }
    if (isset($n['semaine']) && is_array($n['semaine'])) {
        foreach ($actuel['semaine'] as $j => $def) {
            $v = is_array($n['semaine'][$j] ?? null) ? $n['semaine'][$j] : [];
            $o['semaine'][$j] = [
                'midi' => (bool) ($v['midi'] ?? $def['midi']),
                'soir' => (bool) ($v['soir'] ?? $def['soir']),
                'midi_fin' => heure_valide($v['midi_fin'] ?? null) ? $v['midi_fin'] : $def['midi_fin'],
                'soir_fin' => heure_valide($v['soir_fin'] ?? null) ? $v['soir_fin'] : $def['soir_fin'],
            ];
        }
    }
    foreach (['midi_debut', 'soir_debut'] as $c) {
        if (heure_valide($n[$c] ?? null)) {
            $o[$c] = $n[$c];
        }
    }
    if (isset($n['pas']) && in_array((int) $n['pas'], [10, 15, 20, 30, 60], true)) {
        $o['pas'] = (int) $n['pas'];
    }
    if (isset($n['delai_mode']) && in_array($n['delai_mode'], ['heures', 'veille'], true)) {
        $o['delai_mode'] = $n['delai_mode'];
    }
    foreach (['delai_heures' => [0, 72], 'max_en_ligne' => [1, 200], 'max_par_creneau' => [0, 500], 'jours_avance' => [1, 400]] as $c => [$min, $max]) {
        if (isset($n[$c]) && is_numeric($n[$c])) {
            $o[$c] = max($min, min($max, (int) $n[$c]));
        }
    }
    if (isset($n['alertes_email'])) {
        $liste = is_array($n['alertes_email']) ? $n['alertes_email'] : preg_split('/[\s,;]+/', (string) $n['alertes_email']);
        $o['alertes_email'] = array_values(array_unique(array_filter(array_map('trim', $liste), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }
    if (isset($n['expediteur']) && filter_var(trim((string) $n['expediteur']), FILTER_VALIDATE_EMAIL)) {
        $o['expediteur'] = trim((string) $n['expediteur']);
    }
    foreach (['nom' => 60, 'telephone' => 30, 'adresse' => 120, 'site' => 120, 'mot_client' => 600] as $c => $lg) {
        if (isset($n[$c]) && is_string($n[$c])) {
            $o[$c] = mb_substr(trim($n[$c]), 0, $lg);
        }
    }
    return $o;
}

/* ------------------------------------------------------------------ */
/* Soirées (lues dans contenu/soirees.json, le fichier du site)        */
/* ------------------------------------------------------------------ */

function agenda(): array
{
    static $a = null;
    if ($a !== null) {
        return $a;
    }
    $f = getenv('RESA_SOIREES') ?: dirname(__DIR__, 2) . '/contenu/soirees.json';
    $json = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $a = [];
    foreach (($json['agenda'] ?? []) as $e) {
        $d = (string) ($e['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            continue;
        }
        $fin = (string) ($e['date_fin'] ?? '');
        $titre = trim((string) ($e['titre'] ?? ''));
        $a[] = [
            'date' => $d,
            'date_fin' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin) ? $fin : $d,
            'heure' => trim((string) ($e['heure'] ?? '')),
            'titre' => $titre,
            'details' => trim((string) ($e['details'] ?? '')),
            'fermeture' => (bool) preg_match('/\bferm[ée]/iu', $titre),
        ];
    }
    return $a;
}

function evenement_du(string $date): ?array
{
    foreach (agenda() as $e) {
        if ($date >= $e['date'] && $date <= $e['date_fin']) {
            return $e;
        }
    }
    return null;
}

/** Une soirée avec un prix et pas « à la carte » = menu imposé (modifiable jour par jour). */
function menu_impose_auto(?array $soiree): bool
{
    if (!$soiree || $soiree['fermeture']) {
        return false;
    }
    $d = mb_strtolower($soiree['details']);
    return str_contains($d, '€') && !str_contains($d, 'à la carte') && !str_contains($d, 'a la carte');
}

/* ------------------------------------------------------------------ */
/* Disponibilités                                                      */
/* ------------------------------------------------------------------ */

function date_valide($d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $j] = array_map('intval', explode('-', $d));
    return checkdate($m, $j, $y);
}

function service_de(string $heure): string
{
    return $heure < '16:00' ? 'midi' : 'soir';
}

function capacite_saison(string $date, string $service, array $r): int
{
    $md = substr($date, 5);
    foreach ($r['saisons'] as $s) {
        $dans = $s['debut'] <= $s['fin']
            ? ($md >= $s['debut'] && $md <= $s['fin'])
            : ($md >= $s['debut'] || $md <= $s['fin']); // saison à cheval sur le 1er janvier
        if ($dans && (int) $s[$service] > 0) {
            return (int) $s[$service];
        }
    }
    return (int) $r['capacite'][$service];
}

function exceptions_jours(string $debut, string $fin): array
{
    $st = db()->prepare('SELECT * FROM jours WHERE date BETWEEN ? AND ?');
    $st->execute([$debut, $fin]);
    $o = [];
    foreach ($st as $l) {
        $o[$l['date'] . '|' . $l['service']] = $l;
    }
    return $o;
}

/** Couverts déjà pris (en attente + confirmés), par date|service et par date|service|heure. */
function occupation(string $debut, string $fin, ?int $sauf_id = null): array
{
    $st = db()->prepare("SELECT date, service, heure, SUM(couverts) AS n FROM reservations
        WHERE date BETWEEN ? AND ? AND statut IN ('attente','confirmee') AND id != ?
        GROUP BY date, service, heure");
    $st->execute([$debut, $fin, $sauf_id ?? 0]);
    $o = ['service' => [], 'creneau' => []];
    foreach ($st as $l) {
        $k = $l['date'] . '|' . $l['service'];
        $o['service'][$k] = ($o['service'][$k] ?? 0) + (int) $l['n'];
        $o['creneau'][$k . '|' . $l['heure']] = (int) $l['n'];
    }
    return $o;
}

function creneaux(string $debut, string $fin, int $pas): array
{
    $o = [];
    $t = strtotime("2000-01-01 $debut");
    $f = strtotime("2000-01-01 $fin");
    while ($t <= $f && count($o) < 200) {
        $o[] = date('H:i', $t);
        $t += $pas * 60;
    }
    return $o;
}

/**
 * État d'un service : ouvert ? réservable en ligne ? capacité, places prises, soirée…
 * $exc et $occ peuvent être passés pour éviter des requêtes (calendrier).
 */
function etat_service(string $date, string $service, ?array $exc = null, ?array $occ = null): array
{
    $r = reglages();
    $jour = $r['semaine'][date('N', strtotime($date))];
    $exc ??= exceptions_jours($date, $date);
    $occ ??= occupation($date, $date);
    $x = $exc["$date|$service"] ?? null;

    $evt = evenement_du($date);
    $soiree = ($service === 'soir' && $evt && !$evt['fermeture']) ? $evt : null;
    $fermeture = $evt && $evt['fermeture'];

    $ouvert = (bool) $jour[$service];
    $raison = $ouvert ? '' : 'Fermé ce ' . ($service === 'midi' ? 'midi' : 'soir');
    if ($soiree) {
        $ouvert = true; // une soirée au programme = restaurant ouvert ce soir-là
        $raison = '';
    }
    if ($fermeture) {
        $ouvert = false;
        $raison = $evt['titre'];
    }
    if ($x && $x['ouvert'] !== null) {
        $ouvert = (bool) $x['ouvert'];
        $raison = $ouvert ? '' : 'Fermé exceptionnellement';
    }

    $capacite = ($x && $x['capacite'] !== null) ? (int) $x['capacite'] : capacite_saison($date, $service, $r);
    $occupe = $occ['service']["$date|$service"] ?? 0;
    $ferme_en_ligne = $x ? (bool) $x['ferme_en_ligne'] : false;
    $menu = ($x && $x['menu_impose'] !== null) ? (bool) $x['menu_impose'] : menu_impose_auto($soiree);

    return [
        'date' => $date,
        'service' => $service,
        'ouvert' => $ouvert,
        'ouverture_forcee' => ($x && $x['ouvert'] !== null) ? (int) $x['ouvert'] : null,
        'raison' => $raison,
        'en_ligne' => $ouvert && $r['resa_en_ligne'] && !$ferme_en_ligne,
        'ferme_en_ligne' => $ferme_en_ligne,
        'capacite' => $capacite,
        'capacite_auto' => !($x && $x['capacite'] !== null),
        'occupe' => $occupe,
        'restant' => max(0, $capacite - $occupe),
        'soiree' => $soiree ? ['titre' => $soiree['titre'], 'details' => $soiree['details'], 'heure' => $soiree['heure']] : null,
        'menu_impose' => $menu,
        'menu_impose_auto' => !($x && $x['menu_impose'] !== null),
        'note' => $x['note'] ?? '',
        'debut' => $r[$service . '_debut'],
        'fin' => $jour[$service . '_fin'],
    ];
}

/** Le délai avant le créneau est-il respecté pour une demande en ligne ? */
function delai_ok(string $date, string $heure, ?int $now = null): bool
{
    $r = reglages();
    $now ??= time();
    if ($r['delai_mode'] === 'veille') {
        return $date > date('Y-m-d', $now);
    }
    return strtotime("$date $heure") - $now >= $r['delai_heures'] * 3600;
}

/** Créneaux proposés en ligne avec le nombre maximum de personnes réservables sur chacun. */
function creneaux_en_ligne(array $etat, array $occ): array
{
    $r = reglages();
    $o = [];
    if (!$etat['en_ligne']) {
        return $o;
    }
    $max_service = min($etat['restant'], (int) $r['max_en_ligne']);
    // Service déjà commencé aujourd'hui : plus de demande en ligne pour ce service
    if ($r['fermer_pendant_service'] && $etat['date'] === date('Y-m-d') && date('H:i') >= $etat['debut']) {
        $max_service = 0;
    }
    foreach (creneaux($etat['debut'], $etat['fin'], (int) $r['pas']) as $h) {
        $max = delai_ok($etat['date'], $h) ? $max_service : 0;
        if ($max > 0 && $r['max_par_creneau'] > 0) {
            $pris = $occ['creneau'][$etat['date'] . '|' . $etat['service'] . '|' . $h] ?? 0;
            $max = min($max, max(0, (int) $r['max_par_creneau'] - $pris));
        }
        $o[] = ['h' => $h, 'max' => $max];
    }
    return $o;
}

/* ------------------------------------------------------------------ */
/* Divers                                                              */
/* ------------------------------------------------------------------ */

function jeton(int $octets = 16): string
{
    return bin2hex(random_bytes($octets));
}

function ip_client(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
}

/** Limite simple : $max essais par $fenetre secondes pour une clé. */
function limiter(string $cle, int $max, int $fenetre): bool
{
    $pdo = db();
    $pdo->prepare('DELETE FROM tentatives WHERE quand < ?')->execute([time() - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM tentatives WHERE cle = ? AND quand > ?');
    $st->execute([$cle, time() - $fenetre]);
    if ((int) $st->fetchColumn() >= $max) {
        return false;
    }
    $pdo->prepare('INSERT INTO tentatives (cle, quand) VALUES (?, ?)')->execute([$cle, time()]);
    return true;
}

function nettoyer_texte($v, int $max): string
{
    $v = is_scalar($v) ? (string) $v : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function telephone_valide(string $t): bool
{
    return preg_match('/^\+?[0-9 .\-\/()]{8,25}$/', $t) === 1 && strlen(preg_replace('/\D/', '', $t)) >= 9;
}

/** Mobile français (06/07), allemand (015/016/017) ou tout numéro international avec indicatif. */
function mobile_valide(string $t): bool
{
    $d = preg_replace('/[\s.\-\/()]/', '', $t) ?? '';
    return preg_match('/^(?:\+33|0033|0)[67]\d{8}$/', $d) === 1
        || preg_match('/^(?:\+49|0049|0)1[5-7]\d{8,9}$/', $d) === 1
        || preg_match('/^\+(?!33|49)\d{8,15}$/', $d) === 1;
}

function date_fr(string $date, bool $avec_annee = false): string
{
    static $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    static $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($date);
    $j = (int) date('j', $t);
    return $jours[(int) date('N', $t)] . ' ' . ($j === 1 ? '1er' : $j) . ' ' . $mois[(int) date('n', $t)] . ($avec_annee ? ' ' . date('Y', $t) : '');
}

function heure_fr(string $h): string
{
    return str_replace(':', 'h', $h);
}

function repondre($donnees, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

function erreur(string $message, int $code = 400): never
{
    repondre(['ok' => false, 'erreur' => $message], $code);
}

function lire_requete(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return $_GET;
    }
    $brut = file_get_contents('php://input') ?: '';
    $d = json_decode($brut, true);
    if (!is_array($d)) {
        erreur('Requête illisible.');
    }
    return $d;
}

function resa_publique(array $r): array
{
    return [
        'date' => $r['date'],
        'date_texte' => date_fr($r['date'], true),
        'heure' => $r['heure'],
        'couverts' => (int) $r['couverts'],
        'nom' => $r['nom'],
        'statut' => $r['statut'],
    ];
}
