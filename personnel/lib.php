<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

const ETABS = ['le76' => 'Le 76', 'camping' => 'Camping'];
const POSTES = ['salle' => 'Salle', 'bar' => 'Bar', 'runner' => 'Runner', 'cuisine' => 'Cuisine', 'plonge' => 'Plonge'];
/** Ce qui s'est passé sur un service (choisi dans le planning). */
const MOTIFS = [
    'malade' => 'Malade',
    'conge' => 'Congé / absence justifiée',
    'absent' => 'Absent·e sans prévenir',
    'retard_ok' => 'Retard justifié',
];
const JOURS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const JOURS_LONG = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const OUBLI_HEURES = 16; // un pointage ouvert depuis plus longtemps = départ oublié
define('DATA_DIR', __DIR__ . '/data');

/* ---------- Utilitaires ---------- */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function eur(float $x): string
{
    return str_replace(' ', "\u{00A0}", number_format($x, 2, ',', ' ')) . "\u{00A0}€";
}

function fmt_min(int $m): string
{
    $m = max(0, $m);
    return intdiv($m, 60) . 'h' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT);
}

function date_fr(string $d): string
{
    $t = strtotime($d);
    return JOURS_LONG[(int)date('N', $t) - 1] . ' ' . (int)date('j', $t) . ' ' . MOIS[(int)date('n', $t) - 1];
}

function mois_fr(string $ym): string
{
    $t = strtotime($ym . '-01');
    return MOIS[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}

function monday_of(string $date): string
{
    return date('Y-m-d', strtotime('monday this week', strtotime($date)));
}

function minutes_between(string $a, string $b): int
{
    return max(0, (int)round((strtotime($b) - strtotime($a)) / 60));
}

/** Durée d'un créneau planifié "HH:MM" -> "HH:MM" (passe minuit si fin <= début). */
/** Minutes couvertes par des intervalles [début, fin] (timestamps), sans compter deux fois les chevauchements. */
function minutes_union(array $iv): int
{
    usort($iv, fn($x, $y) => $x[0] <=> $y[0]);
    $tot = 0;
    $cur = null;
    foreach ($iv as [$a, $b]) {
        if ($b <= $a) continue;
        if ($cur === null || $a > $cur[1]) {
            if ($cur) $tot += $cur[1] - $cur[0];
            $cur = [$a, $b];
        } else {
            $cur[1] = max($cur[1], $b);
        }
    }
    if ($cur) $tot += $cur[1] - $cur[0];
    return (int)round($tot / 60);
}

/** Chevauchements entre pointages d'une même personne : liste de [pointage A, pointage B]. */
function chevauchements(string $du, string $au): array
{
    $st = db()->prepare('SELECT p.*, e.prenom, e.nom FROM pointages p JOIN employes e ON e.id = p.employe_id WHERE p.debut >= ? AND p.debut < ? ORDER BY p.employe_id, p.debut');
    $st->execute([$du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00']);
    $out = [];
    $prev = null;
    foreach ($st->fetchAll() as $p) {
        if ($prev && (int)$prev['employe_id'] === (int)$p['employe_id']) {
            $finPrev = $prev['fin'] ?? date('Y-m-d H:i:s');
            if ($p['debut'] < $finPrev) {
                $out[] = [$prev, $p];
            }
        }
        if (!$prev || (int)$prev['employe_id'] !== (int)$p['employe_id'] || ($p['fin'] ?? '9999') > ($prev['fin'] ?? '9999')) {
            $prev = $p;
        }
    }
    return $out;
}

function shift_minutes(string $debut, string $fin): int
{
    [$h1, $m1] = array_map('intval', explode(':', $debut));
    [$h2, $m2] = array_map('intval', explode(':', $fin));
    $d = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
    return $d <= 0 ? $d + 1440 : $d;
}

function valid_etab(?string $e): bool
{
    return $e !== null && isset(ETABS[$e]);
}

function valid_date(?string $d): bool
{
    return $d !== null && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

function valid_time(?string $t): bool
{
    return $t !== null && (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function base_path(): string
{
    $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $d . '/';
}

function base_url(): string
{
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/* ---------- Base de données ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    $files = glob(DATA_DIR . '/p_*.sqlite') ?: [];
    $file = $files[0] ?? DATA_DIR . '/p_' . bin2hex(random_bytes(8)) . '.sqlite';
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function db_file(): string
{
    db();
    return (glob(DATA_DIR . '/p_*.sqlite') ?: [''])[0];
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS employes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        prenom TEXT NOT NULL,
        nom TEXT NOT NULL DEFAULT '',
        type TEXT NOT NULL CHECK (type IN ('fixe','extra')),
        etabs TEXT NOT NULL DEFAULT 'le76',
        pin TEXT NOT NULL UNIQUE,
        taux REAL NOT NULL DEFAULT 12,
        salaire REAL NOT NULL DEFAULT 1800,
        heures_hebdo REAL,
        entree TEXT,
        sortie TEXT,
        token TEXT NOT NULL UNIQUE,
        actif INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS pointages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employe_id INTEGER NOT NULL,
        etab TEXT NOT NULL,
        debut TEXT NOT NULL,
        fin TEXT,
        manuel INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_pointages_debut ON pointages(debut);
    CREATE INDEX IF NOT EXISTS idx_pointages_emp ON pointages(employe_id);
    CREATE TABLE IF NOT EXISTS planning (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employe_id INTEGER NOT NULL,
        etab TEXT NOT NULL,
        jour TEXT NOT NULL,
        debut TEXT NOT NULL,
        fin TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_planning_jour ON planning(jour);
    CREATE TABLE IF NOT EXISTS tentatives (kind TEXT NOT NULL, ip TEXT NOT NULL, at INTEGER NOT NULL);
    ");
    $cols = array_column($pdo->query('PRAGMA table_info(employes)')->fetchAll(), 'name');
    if (!in_array('postes', $cols, true)) {
        $pdo->exec("ALTER TABLE employes ADD COLUMN postes TEXT NOT NULL DEFAULT ''");
    }
    $cols = array_column($pdo->query('PRAGMA table_info(planning)')->fetchAll(), 'name');
    if (!in_array('motif', $cols, true)) {
        $pdo->exec("ALTER TABLE planning ADD COLUMN motif TEXT");
        $pdo->exec("ALTER TABLE planning ADD COLUMN note TEXT");
    }
    if (!in_array('preset', $cols, true)) {
        $pdo->exec("ALTER TABLE planning ADD COLUMN preset TEXT");
        $linkPresets = true;
    }
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS indispos (id INTEGER PRIMARY KEY AUTOINCREMENT, employe_id INTEGER NOT NULL, jour TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, UNIQUE(employe_id, jour));
    CREATE TABLE IF NOT EXISTS modeles (id INTEGER PRIMARY KEY AUTOINCREMENT, nom TEXT NOT NULL, etab TEXT NOT NULL, data TEXT NOT NULL, created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS clotures (ym TEXT PRIMARY KEY, at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS alertes_envoyees (cle TEXT PRIMARY KEY, at INTEGER NOT NULL);
    ");
    if (!empty($linkPresets)) {
        // Relie les horaires existants aux horaires rapides qui ont les mêmes heures
        $raw = $pdo->query("SELECT v FROM settings WHERE k = 'presets'")->fetchColumn();
        foreach (preg_split('/\R/', $raw === false ? "Midi;10:30;15:00\nSoir;17:30;23:00\nJournée;10:30;23:00" : (string)$raw) as $line) {
            $p = array_map('trim', explode(';', $line));
            if (count($p) === 3) {
                $pdo->prepare('UPDATE planning SET preset = ? WHERE preset IS NULL AND debut = ? AND fin = ?')->execute([$p[0], $p[1], $p[2]]);
            }
        }
    }
}

function setting(string $k, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

function secret(): string
{
    static $s = null;
    if ($s) {
        return $s;
    }
    $s = setting('secret');
    if (!$s) {
        $s = bin2hex(random_bytes(32));
        set_setting('secret', $s);
    }
    return $s;
}

function share_token(bool $renew = false): string
{
    $t = setting('share_token');
    if ($renew || !$t) {
        $t = bin2hex(random_bytes(12));
        set_setting('share_token', $t);
    }
    return $t;
}

/** Nom de l'horaire rapide qui correspond exactement à ces heures (ou null). */
function preset_nom(string $d, string $f): ?string
{
    foreach (presets() as [$n, $a, $b]) {
        if ($a === $d && $b === $f) return $n;
    }
    return null;
}

function presets(): array
{
    $raw = setting('presets');
    if ($raw === null) {
        $raw = "Midi;10:30;15:00\nSoir;17:30;23:00\nJournée;10:30;23:00";
    }
    $out = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $p = array_map('trim', explode(';', $line));
        if (count($p) === 3 && $p[0] !== '' && valid_time($p[1]) && valid_time($p[2])) {
            $out[] = $p;
        }
    }
    return $out;
}

/* ---------- Sécurité : connexion admin, pointeuse, CSRF ---------- */

function sign(string $d): string
{
    return hash_hmac('sha256', $d, secret());
}

function set_cookie_secure(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => base_path(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function admin_login(): void
{
    $exp = time() + 86400 * 30;
    $val = $exp . '.' . sign('admin|' . $exp);
    set_cookie_secure('adm', $val, $exp);
    $_COOKIE['adm'] = $val;
}

function admin_logout(): void
{
    set_cookie_secure('adm', '', time() - 3600);
    unset($_COOKIE['adm']);
}

function is_admin(): bool
{
    $c = (string)($_COOKIE['adm'] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $c, $m)) {
        return false;
    }
    return (int)$m[1] > time() && hash_equals(sign('admin|' . $m[1]), $m[2]);
}

function csrf_token(): string
{
    return substr(sign('csrf|' . (string)($_COOKIE['adm'] ?? '')), 0, 32);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expirée. Recharge la page et réessaie.');
    }
}

function kiosk_set(string $etab): void
{
    $exp = time() + 86400 * 390;
    set_cookie_secure('kiosk', $etab . '.' . sign('kiosk|' . $etab), $exp);
}

function kiosk_clear(): void
{
    set_cookie_secure('kiosk', '', time() - 3600);
}

function kiosk_etab(): ?string
{
    $c = (string)($_COOKIE['kiosk'] ?? '');
    if (!preg_match('/^([a-z0-9]+)\.([a-f0-9]{64})$/', $c, $m)) {
        return null;
    }
    return valid_etab($m[1]) && hash_equals(sign('kiosk|' . $m[1]), $m[2]) ? $m[1] : null;
}

function too_many(string $kind, int $max, int $windowSec): bool
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
    db()->prepare('DELETE FROM tentatives WHERE at < ?')->execute([time() - 86400]);
    $st = db()->prepare('SELECT COUNT(*) FROM tentatives WHERE kind = ? AND ip = ? AND at > ?');
    $st->execute([$kind, $ip, time() - $windowSec]);
    return (int)$st->fetchColumn() >= $max;
}

function note_fail(string $kind): void
{
    db()->prepare('INSERT INTO tentatives (kind, ip, at) VALUES (?, ?, ?)')->execute([$kind, (string)($_SERVER['REMOTE_ADDR'] ?? '?'), time()]);
}

/* ---------- Employés ---------- */

function employes(bool $actifSeulement = true, ?string $etab = null): array
{
    $sql = "SELECT * FROM employes" . ($actifSeulement ? " WHERE actif = 1" : "")
        . " ORDER BY CASE type WHEN 'fixe' THEN 0 ELSE 1 END, prenom COLLATE NOCASE";
    $rows = db()->query($sql)->fetchAll();
    if ($etab !== null) {
        $rows = array_values(array_filter($rows, fn($e) => in_array($etab, emp_etabs($e), true)));
    }
    return $rows;
}

function emp_etabs(array $e): array
{
    return array_values(array_filter(explode(',', (string)$e['etabs']), 'valid_etab'));
}

function emp_nom(array $e): string
{
    return trim($e['prenom'] . ' ' . $e['nom']);
}

/** Prénom + initiale du nom (ex. « Vanessa M. »), pour la pointeuse. */
function emp_court(array $e): string
{
    $n = trim((string)$e['nom']);
    return trim($e['prenom'] . ($n !== '' ? ' ' . mb_strtoupper(mb_substr($n, 0, 1)) . '.' : ''));
}

function emp_postes(array $e): array
{
    return array_values(array_filter(explode(',', (string)($e['postes'] ?? '')), fn($p) => isset(POSTES[$p])));
}

/** Petites étiquettes des postes (Salle, Bar…). */
function postes_html(array $e): string
{
    $out = '';
    foreach (emp_postes($e) as $p) {
        $out .= '<span class="pill poste">' . h(POSTES[$p]) . '</span> ';
    }
    return trim($out);
}

/** Ordre de tri par poste : salle, bar, runner, cuisine, plonge, puis sans poste. */
function poste_rang(array $e): int
{
    $ps = emp_postes($e);
    $keys = array_keys(POSTES);
    return $ps ? (int)array_search($ps[0], $keys, true) : count($keys);
}

function emp_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM employes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function pin_libre(): string
{
    for ($i = 0; $i < 200; $i++) {
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $st = db()->prepare('SELECT 1 FROM employes WHERE pin = ?');
        $st->execute([$pin]);
        if (!$st->fetchColumn()) {
            return $pin;
        }
    }
    throw new RuntimeException('Plus de code libre');
}

/* ---------- Heures & coûts ---------- */

/**
 * Rapport mensuel par employé.
 * Extras : minutes pointées × taux. Fixes : salaire mensuel (prorata si entrée/sortie dans le mois).
 */
function rapport_mois(string $ym, ?string $etabFiltre): array
{
    $debutMois = $ym . '-01';
    $finMois = date('Y-m-t', strtotime($debutMois));
    $jours = (int)date('t', strtotime($debutMois));
    $a = $debutMois . ' 00:00:00';
    $b = date('Y-m-d H:i:s', strtotime($a . ' +1 month'));

    $st = db()->prepare('SELECT * FROM pointages WHERE debut >= ? AND debut < ? ORDER BY debut');
    $st->execute([$a, $b]);
    $parEmp = [];
    foreach ($st->fetchAll() as $p) {
        $parEmp[(int)$p['employe_id']][] = $p;
    }

    $rows = [];
    foreach (employes(false) as $e) {
        $id = (int)$e['id'];
        $min = ['le76' => 0, 'camping' => 0];
        $iv = ['le76' => [], 'camping' => []];
        $ouverts = 0;
        $enService = 0;
        $nb = 0;
        $seuilOubli = date('Y-m-d H:i:s', time() - OUBLI_HEURES * 3600);
        foreach ($parEmp[$id] ?? [] as $p) {
            if ($etabFiltre !== null && $p['etab'] !== $etabFiltre) {
                continue;
            }
            if ($p['fin'] === null) {
                if ($p['debut'] >= $seuilOubli) $enService++; else $ouverts++;
                continue;
            }
            $iv[$p['etab']][] = [strtotime($p['debut']), strtotime($p['fin'])];
            $nb++;
        }
        // Les heures qui se chevauchent (pointage en double) ne sont comptées qu'une fois
        foreach ($iv as $k => $l) {
            $min[$k] = minutes_union($l);
        }
        $minFiltre = $etabFiltre ? $min[$etabFiltre] : array_sum($min);
        $totalMin = array_sum($min);

        $salaire = 0.0;
        $note = '';
        $concerne = $minFiltre > 0 || $ouverts > 0 || $enService > 0;

        if ($e['type'] === 'fixe') {
            $entree = $e['entree'] ?: substr((string)$e['created_at'], 0, 7) . '-01'; // sans date : mois complet dès le mois de création
            $sortie = $e['sortie'] ?: null;
            $present = $entree <= $finMois && ($sortie === null || $sortie >= $debutMois);
            $etabsEmp = emp_etabs($e);
            $dansFiltre = $etabFiltre === null || in_array($etabFiltre, $etabsEmp, true);
            if ($present && $dansFiltre) {
                $d1 = max($entree, $debutMois);
                $d2 = $sortie !== null ? min($sortie, $finMois) : $finMois;
                $joursPresent = (int)round((strtotime($d2) - strtotime($d1)) / 86400) + 1;
                $fraction = min(1.0, $joursPresent / $jours);
                $salaire = (float)$e['salaire'] * $fraction;
                if ($fraction < 1.0) {
                    $note = "prorata $joursPresent/$jours j";
                }
                // Fixe sur les deux établissements : coût réparti selon les heures pointées
                if ($etabFiltre !== null && count($etabsEmp) > 1) {
                    $part = $totalMin > 0 ? $min[$etabFiltre] / $totalMin : 1 / count($etabsEmp);
                    $salaire *= $part;
                    $note = trim($note . ' répartition ' . round($part * 100) . ' %');
                }
                $concerne = true;
            }
        }

        if (!$concerne) {
            continue;
        }

        $cout = $e['type'] === 'extra' ? round($minFiltre / 60 * (float)$e['taux'], 2) : round($salaire, 2);
        // Contrat ramené à la période réellement suivie (du 1er pointage du mois ou de l'entrée, jusqu'à aujourd'hui)
        $contratMin = null;
        if ($e['type'] === 'fixe' && $e['heures_hebdo'] !== null && !empty($parEmp[$id])) {
            $d1 = max($debutMois, substr($parEmp[$id][0]['debut'], 0, 10), (string)($e['entree'] ?: $debutMois));
            $d2 = min($finMois, date('Y-m-d'), (string)($e['sortie'] ?: $finMois));
            $jrs = max(0, (int)round((strtotime($d2) - strtotime($d1)) / 86400) + 1);
            $contratMin = (int)round((float)$e['heures_hebdo'] / 7 * $jrs * 60);
        }

        $rows[] = [
            'e' => $e,
            'minutes' => $minFiltre,
            'minutes_total' => $totalMin,
            'par_etab' => $min,
            'pointages' => $nb,
            'ouverts' => $ouverts,
            'en_service' => $enService,
            'cout' => $cout,
            'note' => $note,
            'contrat_min' => $contratMin,
        ];
    }
    return $rows;
}

/* ---------- Prévu (planning) / réel (pointages) ---------- */

function tolerance(): int
{
    return max(0, min(120, (int)setting('tolerance', '15')));
}

/** Début/fin d'un créneau planifié en timestamps (gère le passage de minuit). */
function shift_bounds(array $s): array
{
    $a = strtotime($s['jour'] . ' ' . $s['debut']);
    $b = strtotime($s['jour'] . ' ' . $s['fin']);
    if ($b <= $a) {
        $b += 86400;
    }
    return [$a, $b];
}

/**
 * Compare le planning et les pointages entre deux dates (incluses).
 * Retour : ['shifts' => [id => résultat], 'np' => [pointages non prévus]]
 * Statuts : avenir, attendu (pas encore arrivé, en retard), absent, encours, ok, plus (a fait plus), moins (a fait moins).
 */
function comparer(string $du, string $au, ?string $etab = null, ?int $empId = null): array
{
    $now = time();
    $tol = tolerance() * 60;

    $sql = 'SELECT * FROM planning WHERE jour BETWEEN ? AND ?';
    $par = [$du, $au];
    if ($etab) { $sql .= ' AND etab = ?'; $par[] = $etab; }
    if ($empId) { $sql .= ' AND employe_id = ?'; $par[] = $empId; }
    $st = db()->prepare($sql . ' ORDER BY jour, debut');
    $st->execute($par);
    $shifts = $st->fetchAll();

    $sql = 'SELECT * FROM pointages WHERE debut >= ? AND debut < ?';
    $par = [date('Y-m-d H:i:s', strtotime($du . ' 00:00:00') - 4 * 3600), date('Y-m-d H:i:s', strtotime($au . ' 00:00:00') + 86400 + 6 * 3600)];
    if ($etab) { $sql .= ' AND etab = ?'; $par[] = $etab; }
    if ($empId) { $sql .= ' AND employe_id = ?'; $par[] = $empId; }
    $st = db()->prepare($sql . ' ORDER BY debut');
    $st->execute($par);
    $punches = $st->fetchAll();

    // Rattache chaque pointage au créneau du même employé/lieu qui le recouvre le plus (ou le plus proche à moins de 3 h)
    $parShift = [];
    $np = [];
    foreach ($punches as $p) {
        $pa = strtotime($p['debut']);
        $pb = $p['fin'] !== null ? strtotime($p['fin']) : max($pa, $now);
        $best = null;
        $bestScore = -INF;
        foreach ($shifts as $s) {
            if ((int)$s['employe_id'] !== (int)$p['employe_id'] || $s['etab'] !== $p['etab']) {
                continue;
            }
            [$sa, $sb] = shift_bounds($s);
            $overlap = min($pb, $sb) - max($pa, $sa);
            $score = $overlap > 0 ? $overlap : -min(abs($pa - $sb), abs($sa - $pb), abs($pa - $sa));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $s;
            }
        }
        if ($best !== null && $bestScore > -3 * 3600) {
            $parShift[(int)$best['id']][] = $p;
        } elseif (substr($p['debut'], 0, 10) >= $du && substr($p['debut'], 0, 10) <= $au) {
            $np[] = $p;
        }
    }

    $out = [];
    foreach ($shifts as $s) {
        [$sa, $sb] = shift_bounds($s);
        $prevu = (int)round(($sb - $sa) / 60);
        $ps = $parShift[(int)$s['id']] ?? [];
        $r = ['shift' => $s, 'prevu' => $prevu, 'reel' => 0, 'ecart' => 0, 'debut_reel' => null, 'fin_reel' => null,
              'retard' => 0, 'tot' => 0, 'ouvert' => false, 'statut' => 'avenir', 'sa' => $sa, 'sb' => $sb,
              'punches' => $ps, 'motif' => $s['motif'] ?? null];
        if ($ps) {
            $first = min(array_map(fn($p) => strtotime($p['debut']), $ps));
            $last = 0;
            $ivs = [];
            foreach ($ps as $p) {
                if ($p['fin'] === null) {
                    $r['ouvert'] = true;
                    $ivs[] = [strtotime($p['debut']), max($now, strtotime($p['debut']))];
                } else {
                    $ivs[] = [strtotime($p['debut']), strtotime($p['fin'])];
                    $last = max($last, strtotime($p['fin']));
                }
            }
            $r['reel'] = minutes_union($ivs);
            $r['debut_reel'] = $first;
            $r['fin_reel'] = $r['ouvert'] ? null : $last;
            $r['retard'] = (int)round(($first - $sa) / 60);              // + = arrivé en retard
            $r['tot'] = $r['ouvert'] ? 0 : (int)round(($sb - $last) / 60); // + = parti plus tôt
            $r['ecart'] = $r['reel'] - $prevu;
            if ($r['ouvert']) {
                $r['statut'] = 'encours';
            } elseif ($r['ecart'] > tolerance()) {
                $r['statut'] = 'plus';
            } elseif ($r['ecart'] < -tolerance()) {
                $r['statut'] = 'moins';
            } else {
                $r['statut'] = 'ok';
            }
        } elseif ($now > $sb) {
            $r['statut'] = 'absent';
            $r['ecart'] = -$prevu;
        } elseif ($now > $sa + $tol) {
            $r['statut'] = 'attendu';
        }
        // Motif saisi à la main
        $motif = $s['motif'] ?? null;
        if (in_array($motif, ['malade', 'conge'], true) && !$ps) {
            $r['statut'] = 'excuse';
            $r['prevu'] = 0;
            $r['ecart'] = 0;
        } elseif ($motif === 'absent' && !$ps) {
            $r['statut'] = 'absent';
        } elseif ($motif === 'retard_ok' && in_array($r['statut'], ['moins', 'attendu'], true)) {
            $r['statut'] = $r['statut'] === 'attendu' ? 'avenir' : 'ok';
        }
        $out[(int)$s['id']] = $r;
    }
    return ['shifts' => $out, 'np' => $np];
}

/* ---------- Clôture du mois ---------- */

function mois_cloture(string $ym): ?string
{
    $st = db()->prepare('SELECT at FROM clotures WHERE ym = ?');
    $st->execute([$ym]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/** Résumé du mois au format CSV (Excel), pour l'export et l'envoi au comptable. */
function csv_resume(string $ym, ?string $etabF = null): string
{
    $f = fopen('php://temp', 'w+');
    fwrite($f, "\xEF\xBB\xBF");
    $num = static fn(float $x) => number_format($x, 2, ',', '');
    $put = static function (array $row) use ($f): void {
        $row = array_map(static fn($c) => is_string($c) && preg_match('/^[=+\-@]/', $c) ? "'" . $c : $c, $row);
        fputcsv($f, $row, ';', '"', '\\');
    };
    $put(['Nom', 'Poste', 'Contrat', 'Restaurant(s)', 'Heures', 'Heures (décimal)', 'Taux horaire net', 'À payer (net)', 'Remarque']);
    $total = 0.0;
    foreach (rapport_mois($ym, $etabF) as $r) {
        $e = $r['e'];
        $total += $r['cout'];
        $put([
            emp_nom($e),
            implode(' + ', array_map(fn($p) => POSTES[$p], emp_postes($e))),
            $e['type'] === 'fixe' ? 'Fixe' : 'Extra',
            implode(' + ', array_map(fn($k) => ETABS[$k], emp_etabs($e))),
            fmt_min($r['minutes']),
            $num($r['minutes'] / 60),
            $e['type'] === 'extra' ? $num((float)$e['taux']) : '',
            $num($r['cout']),
            trim($r['note'] . ($r['ouverts'] ? ' ' . $r['ouverts'] . ' pointage(s) sans départ' : '')),
        ]);
    }
    $put(['TOTAL', '', '', '', '', '', '', $num($total), '']);
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    return (string)$csv;
}

/* ---------- Indisponibilités ---------- */

/** [employe_id][jour] => note */
function indispos_entre(string $du, string $au): array
{
    $st = db()->prepare('SELECT * FROM indispos WHERE jour BETWEEN ? AND ?');
    $st->execute([$du, $au]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['employe_id']][$r['jour']] = (string)$r['note'];
    }
    return $out;
}

/* ---------- Notifications (téléphone via ntfy + e-mail) ---------- */

function notif_topic(): string
{
    $t = setting('ntfy_topic');
    if (!$t) {
        $t = 'le76-' . bin2hex(random_bytes(10));
        set_setting('ntfy_topic', $t);
    }
    return $t;
}

/** Envoie une notification. Retourne true si au moins un canal a fonctionné. */
function envoyer_notif(string $titre, string $message, string $priorite = 'default'): bool
{
    $ok = false;
    if (setting('notif_ntfy', '0') === '1') {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: text/plain; charset=utf-8\r\nTitle: =?UTF-8?B?" . base64_encode($titre) . "?=\r\nPriority: " . $priorite . "\r\nTags: clock\r\n",
            'content' => $message,
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $r = @file_get_contents('https://ntfy.sh/' . rawurlencode(notif_topic()), false, $ctx);
        $ok = $r !== false || $ok;
    }
    $mail = trim((string)setting('notif_email', ''));
    if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $ok = envoyer_mail($mail, '[Personnel] ' . $titre, $message) || $ok;
    }
    return $ok;
}

function expediteur(): string
{
    $from = trim((string)setting('mail_from', ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = 'reservation@' . preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'le76.fr'));
    }
    return $from;
}

/** E-mail simple, avec pièce jointe facultative [nom, contenu, type]. */
function envoyer_mail(string $to, string $sujet, string $texte, ?array $pj = null): bool
{
    $from = expediteur();
    $b = 'b' . bin2hex(random_bytes(8));
    $headers = 'From: Personnel Le 76 <' . $from . ">\r\nReply-To: " . $from . "\r\nMIME-Version: 1.0\r\n";
    if ($pj) {
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $b . '"';
        $body = "--$b\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texte))
            . "--$b\r\nContent-Type: " . $pj[2] . '; name="' . $pj[0] . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $pj[0] . "\"\r\n\r\n"
            . chunk_split(base64_encode($pj[1])) . "--$b--";
    } else {
        $headers .= "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64";
        $body = chunk_split(base64_encode($texte));
    }
    return @mail($to, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $body, $headers, '-f' . $from);
}

/**
 * Vérifie les retards / dépassements du jour et prévient (une seule fois par cas).
 * Appelée régulièrement par la pointeuse (iPad allumé) et par la page Aujourd'hui.
 */
function verifier_alertes(): void
{
    if (setting('notif_ntfy', '0') !== '1' && trim((string)setting('notif_email', '')) === '') {
        return;
    }
    $last = (int)setting('alertes_last', '0');
    if (time() - $last < 60) {
        return;
    }
    set_setting('alertes_last', (string)time());
    $retardMin = max(5, (int)setting('notif_retard', '15'));
    $depasseMin = max(5, (int)setting('notif_depasse', '30'));
    $today = date('Y-m-d');
    $cmp = comparer(date('Y-m-d', strtotime('-1 day')), $today);
    $now = time();
    $deja = db()->prepare('SELECT 1 FROM alertes_envoyees WHERE cle = ?');
    $note = db()->prepare('INSERT OR IGNORE INTO alertes_envoyees (cle, at) VALUES (?, ?)');
    db()->prepare('DELETE FROM alertes_envoyees WHERE at < ?')->execute([$now - 7 * 86400]);
    foreach ($cmp['shifts'] as $r) {
        $s = $r['shift'];
        $e = emp_get((int)$s['employe_id']);
        if (!$e || !(int)$e['actif']) {
            continue;
        }
        $lieu = ETABS[$s['etab']];
        $cle = null;
        $msg = null;
        if ($r['statut'] === 'attendu' && $now > $r['sa'] + $retardMin * 60 && $now < $r['sb']) {
            $cle = 'retard-' . $s['id'];
            $msg = [emp_nom($e) . ' pas encore arrivé·e', 'Prévu à ' . $s['debut'] . ' (' . $lieu . '). Retard : ' . fmt_min((int)round(($now - $r['sa']) / 60)) . '.', 'high'];
        } elseif ($r['statut'] === 'encours' && $now > $r['sb'] + $depasseMin * 60 && $now - $r['sb'] < 6 * 3600) {
            $cle = 'depasse-' . $s['id'];
            $msg = [emp_nom($e) . ' dépasse son horaire', 'Prévu jusqu\'à ' . $s['fin'] . ' (' . $lieu . '), toujours pointé·e. Dépassement : ' . fmt_min((int)round(($now - $r['sb']) / 60)) . '.', 'default'];
        }
        if ($cle) {
            $deja->execute([$cle]);
            if (!$deja->fetchColumn()) {
                $note->execute([$cle, $now]);
                envoyer_notif($msg[0], $msg[1], $msg[2]);
            }
        }
    }
}

/** « +0h40 » / « −1h05 » */
function fmt_ecart(int $m): string
{
    return ($m >= 0 ? '+' : '−') . fmt_min(abs($m));
}

/** Pointages ouverts depuis plus de OUBLI_HEURES (départ oublié). */
function pointages_oublies(): array
{
    $seuil = date('Y-m-d H:i:s', time() - OUBLI_HEURES * 3600);
    $st = db()->prepare('SELECT p.*, e.prenom, e.nom FROM pointages p JOIN employes e ON e.id = p.employe_id WHERE p.fin IS NULL AND p.debut < ? ORDER BY p.debut');
    $st->execute([$seuil]);
    return $st->fetchAll();
}

/** Pointage en cours (ouvert depuis moins de OUBLI_HEURES). */
function pointage_en_cours(int $empId): ?array
{
    $seuil = date('Y-m-d H:i:s', time() - OUBLI_HEURES * 3600);
    $st = db()->prepare('SELECT * FROM pointages WHERE employe_id = ? AND fin IS NULL AND debut >= ? ORDER BY debut DESC LIMIT 1');
    $st->execute([$empId, $seuil]);
    return $st->fetch() ?: null;
}

/* ---------- Mise en page ---------- */

function page_head(string $titre, string $manifest = 'manifest-admin.json', string $bodyClass = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    $theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : '';
    echo '<!doctype html><html lang="fr"' . ($theme && $bodyClass !== 'kiosk' ? ' data-theme="' . $theme . '"' : '') . '><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-title" content="Personnel">'
        . '<meta name="theme-color" content="#f2f2f7" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)"><meta name="color-scheme" content="light dark">'
        . '<link rel="manifest" href="' . h($manifest) . '">'
        . '<link rel="icon" href="assets/icon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="assets/icon.svg">'
        . '<link rel="stylesheet" href="assets/style.css?v=' . filemtime(__DIR__ . '/assets/style.css') . '">'
        . '<title>' . h($titre) . '</title></head><body class="' . h($bodyClass) . '">';
}

function admin_nav(string $actif): void
{
    // Icônes style SF Symbols (traits simples)
    $ic = [
        'aujourdhui' => '<path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/><circle cx="12" cy="12" r="4"/>',
        'planning' => '<rect x="3" y="4.5" width="18" height="16" rx="3"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
        'heures' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'equipe' => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3 19.5c.6-3.3 3-5 6-5s5.4 1.7 6 5"/><circle cx="17" cy="9.5" r="2.5"/><path d="M16.5 14.6c2.4.1 4 1.5 4.5 4"/>',
        'reglages' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    ];
    $items = [
        'aujourdhui' => "Aujourd'hui",
        'planning' => 'Planning',
        'heures' => 'Heures',
        'equipe' => 'Équipe',
        'reglages' => 'Réglages',
    ];
    echo '<header class="top"><div class="brand"><span class="brand-ico"></span>Personnel</div><nav class="tabbar">';
    foreach ($items as $k => $label) {
        echo '<a href="admin.php?p=' . $k . '"' . ($k === $actif ? ' class="on" aria-current="page"' : '') . '>'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ic[$k] . '</svg>'
            . '<span>' . h($label) . '</span></a>';
    }
    echo '</nav></header><main class="wrap">';
}

function admin_end(): void
{
    // Bouton « Partager » : envoie le fichier via le menu de partage (iPad/iPhone : Mail, WhatsApp, AirDrop…), sinon le télécharge
    echo '</main><script>
// Confirmation sans fenêtre : 1er appui = « Sûr ? », 2e appui = on valide
document.addEventListener("click", function (ev) {
  const b = ev.target.closest("[data-confirm]"); if (!b) return;
  if (b.dataset.armed === "1") return;
  ev.preventDefault(); ev.stopImmediatePropagation();
  b.dataset.label = b.dataset.label || b.textContent;
  b.textContent = b.dataset.confirm; b.dataset.armed = "1"; b.classList.add("armed");
  clearTimeout(b._t);
  b._t = setTimeout(function () { b.textContent = b.dataset.label; b.dataset.armed = ""; b.classList.remove("armed"); }, 4000);
}, true);
document.addEventListener("click", async function (ev) {
  const b = ev.target.closest("[data-share]"); if (!b) return;
  const old = b.textContent; b.disabled = true; b.textContent = "…";
  try {
    const r = await fetch(b.dataset.share, {cache: "no-store"});
    const blob = await r.blob();
    const file = new File([blob], b.dataset.file, {type: "text/csv"});
    if (navigator.canShare && navigator.canShare({files: [file]})) {
      await navigator.share({files: [file], title: b.dataset.file});
    } else {
      const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = b.dataset.file;
      document.body.appendChild(a); a.click(); a.remove();
    }
  } catch (e) { /* partage annulé */ }
  b.disabled = false; b.textContent = old;
});
</script></body></html>';
}

function flash_show(): void
{
    if (!empty($_GET['ok'])) {
        echo '<div class="flash ok">' . h((string)$_GET['ok']) . '</div>';
    }
    if (!empty($_GET['err'])) {
        echo '<div class="flash err">' . h((string)$_GET['err']) . '</div>';
    }
}

function back(string $url, string $ok = '', string $err = ''): never
{
    $sep = str_contains($url, '?') ? '&' : '?';
    if ($err !== '') {
        $url .= $sep . 'err=' . rawurlencode($err);
    } elseif ($ok !== '') {
        $url .= $sep . 'ok=' . rawurlencode($ok);
    }
    redirect($url);
}
