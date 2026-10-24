<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

const ETABS = ['le76' => 'Le 76', 'camping' => 'Camping'];
const POSTES = ['salle' => 'Salle', 'bar' => 'Bar', 'runner' => 'Runner', 'cuisine' => 'Cuisine', 'plonge' => 'Plonge'];
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
        $ouverts = 0;
        $nb = 0;
        foreach ($parEmp[$id] ?? [] as $p) {
            if ($etabFiltre !== null && $p['etab'] !== $etabFiltre) {
                continue;
            }
            if ($p['fin'] === null) {
                $ouverts++;
                continue;
            }
            $min[$p['etab']] += minutes_between($p['debut'], $p['fin']);
            $nb++;
        }
        $minFiltre = $etabFiltre ? $min[$etabFiltre] : array_sum($min);
        $totalMin = array_sum($min);

        $salaire = 0.0;
        $note = '';
        $concerne = $minFiltre > 0 || $ouverts > 0;

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
        $contratMin = ($e['type'] === 'fixe' && $e['heures_hebdo'] !== null)
            ? (int)round((float)$e['heures_hebdo'] * 52 / 12 * 60) : null;

        $rows[] = [
            'e' => $e,
            'minutes' => $minFiltre,
            'minutes_total' => $totalMin,
            'par_etab' => $min,
            'pointages' => $nb,
            'ouverts' => $ouverts,
            'cout' => $cout,
            'note' => $note,
            'contrat_min' => $contratMin,
        ];
    }
    return $rows;
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
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-title" content="Personnel">'
        . '<meta name="theme-color" content="#fbf8f3">'
        . '<link rel="manifest" href="' . h($manifest) . '">'
        . '<link rel="icon" href="assets/icon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="assets/icon.svg">'
        . '<link rel="stylesheet" href="assets/style.css?v=' . filemtime(__DIR__ . '/assets/style.css') . '">'
        . '<title>' . h($titre) . '</title></head><body class="' . h($bodyClass) . '">';
}

function admin_nav(string $actif): void
{
    $items = [
        'aujourdhui' => "Aujourd'hui",
        'planning' => 'Planning',
        'heures' => 'Heures',
        'equipe' => 'Équipe',
        'reglages' => 'Réglages',
    ];
    echo '<header class="top"><div class="brand">Personnel</div><nav>';
    foreach ($items as $k => $label) {
        echo '<a href="admin.php?p=' . $k . '"' . ($k === $actif ? ' class="on"' : '') . '>' . h($label) . '</a>';
    }
    echo '</nav></header><main class="wrap">';
}

function admin_end(): void
{
    // Bouton « Partager » : envoie le fichier via le menu de partage (iPad/iPhone : Mail, WhatsApp, AirDrop…), sinon le télécharge
    echo '</main><script>
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
