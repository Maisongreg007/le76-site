<?php
/**
 * Registre hygiène du 76 (appli /hygiene/).
 *
 * Petit magasin de documents JSON (frigos, relevés, tâches, livraisons, étiquettes…)
 * + photos et documents envoyés depuis l'iPad, le tout protégé par un code d'accès.
 *
 * Les données ne sont PAS dans ce dépôt : elles vivent dans un dossier « le76-hygiene »
 * à côté de public_html, hors d'atteinte du web et des mises à jour du site.
 * Le code d'accès est choisi à la première ouverture de l'appli (il n'est écrit nulle part ici).
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

const H_COOKIE = 'le76_hygiene';
const H_DUREE = 400 * 86400;
const H_COLLECTIONS = ['equipment', 'tasks', 'readings', 'done', 'suppliers', 'fryers', 'config', 'receptions', 'labels', 'oils', 'cooling', 'documents', 'presets'];
const H_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

try {
    $action = (string) ($_GET['action'] ?? '');
    if ($action === 'fichier') {
        connecte() || erreur('Connecte-toi pour continuer.', 401);
        servir_fichier((string) ($_GET['id'] ?? ''));
    }
    if ($action === 'envoyer') {
        connecte() || erreur('Connecte-toi pour continuer.', 401);
        envoyer_fichier();
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        erreur('Méthode non autorisée.', 405);
    }
    $q = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($q)) {
        erreur('Requête illisible.');
    }
    $action = (string) ($q['action'] ?? '');

    switch ($action) {
        case 'etat':
            repondre(['ok' => true, 'installe' => code_hache() !== null, 'connecte' => connecte()]);
        case 'installer':
            installer((string) ($q['code'] ?? ''));
        case 'connexion':
            connexion((string) ($q['code'] ?? ''));
        case 'deconnexion':
            deconnexion();
    }

    connecte() || erreur('Connecte-toi pour continuer.', 401);

    switch ($action) {
        case 'sync':
            sync((int) ($q['depuis'] ?? 0));
        case 'set':
            ecrire((string) ($q['c'] ?? ''), (string) ($q['id'] ?? ''), $q['data'] ?? null, false);
        case 'update':
            ecrire((string) ($q['c'] ?? ''), (string) ($q['id'] ?? ''), $q['data'] ?? null, true);
        case 'delete':
            supprimer((string) ($q['c'] ?? ''), (string) ($q['id'] ?? ''));
        case 'supprimer_fichier':
            supprimer_fichier((string) ($q['id'] ?? ''));
        case 'code_changer':
            code_changer((string) ($q['ancien'] ?? ''), (string) ($q['nouveau'] ?? ''));
    }
    erreur('Action inconnue.');
} catch (Throwable $e) {
    error_log('[hygiene] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    erreur('Problème technique : ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Stockage                                                            */
/* ------------------------------------------------------------------ */

function dossier(): string
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $env = getenv('HYGIENE_DONNEES');
    $candidats = $env ? [$env] : [
        dirname(__DIR__, 2) . '/le76-hygiene',   // à côté de public_html
        __DIR__ . '/donnees-hygiene',            // repli : api/donnees-hygiene (protégé)
    ];
    foreach ($candidats as $c) {
        if (!is_dir($c)) {
            @mkdir($c, 0750, true);
        }
        if (is_dir($c) && is_writable($c)) {
            if (str_starts_with($c, __DIR__) && !is_file("$c/.htaccess")) {
                file_put_contents("$c/.htaccess", "Require all denied\n");
            }
            if (!is_dir("$c/fichiers")) {
                @mkdir("$c/fichiers", 0750, true);
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
    $pdo = new PDO('sqlite:' . dossier() . '/hygiene.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS docs (
        c TEXT NOT NULL,
        id TEXT NOT NULL,
        data TEXT,
        rev INTEGER NOT NULL,
        modifie_le TEXT NOT NULL,
        PRIMARY KEY (c, id)
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_docs_rev ON docs(rev)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reglages (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (jeton TEXT PRIMARY KEY, expire INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tentatives (cle TEXT NOT NULL, quand INTEGER NOT NULL)');
    return $pdo;
}

/* ------------------------------------------------------------------ */
/* Code d'accès                                                        */
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
    return preg_match('/^\d{4}$/', $code) === 1;
}

function connecte(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $j = (string) ($_COOKIE[H_COOKIE] ?? '');
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
    db()->prepare('INSERT INTO sessions (jeton, expire) VALUES (?, ?)')->execute([hash('sha256', $j), time() + H_DUREE]);
    setcookie(H_COOKIE, $j, [
        'expires' => time() + H_DUREE,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

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

function installer(string $code): never
{
    if (!code_valide($code)) {
        erreur('Le code doit faire 4 chiffres.');
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    if (code_hache() !== null) {
        $pdo->exec('ROLLBACK');
        erreur('Le code est déjà choisi : entre-le pour ouvrir le registre.', 409);
    }
    $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES ('code', ?)")->execute([password_hash($code, PASSWORD_DEFAULT)]);
    // Premier lancement : on reprend les frigos, tâches, articles et fournisseurs déjà préparés
    $depart = __DIR__ . '/lib/hygiene-depart.json';
    if ((int) $pdo->query('SELECT COUNT(*) FROM docs')->fetchColumn() === 0 && is_file($depart)) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO docs (c, id, data, rev, modifie_le) VALUES (?, ?, ?, ?, ?)');
        $rev = 0;
        foreach (json_decode((string) file_get_contents($depart), true) ?: [] as $d) {
            if (in_array($d['c'] ?? '', H_COLLECTIONS, true) && is_array($d['data'] ?? null)) {
                $st->execute([$d['c'], (string) $d['id'], json_encode($d['data'], JSON_UNESCAPED_UNICODE), ++$rev, date('Y-m-d H:i:s')]);
            }
        }
    }
    $pdo->exec('COMMIT');
    ouvrir_session();
    repondre(['ok' => true]);
}

function connexion(string $code): never
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
    if (!limiter('cnx:' . $ip, 8, 900) || !limiter('cnx:tous', 60, 900)) {
        erreur('Trop d’essais. Réessaie dans 15 minutes.', 429);
    }
    $h = code_hache();
    if ($h === null) {
        erreur('Le registre n’est pas encore installé.', 409);
    }
    if (!password_verify($code, $h)) {
        erreur('Code incorrect.', 401);
    }
    ouvrir_session();
    repondre(['ok' => true]);
}

function deconnexion(): never
{
    $j = (string) ($_COOKIE[H_COOKIE] ?? '');
    if ($j !== '') {
        db()->prepare('DELETE FROM sessions WHERE jeton = ?')->execute([hash('sha256', $j)]);
    }
    setcookie(H_COOKIE, '', ['expires' => 1, 'path' => '/']);
    repondre(['ok' => true]);
}

function code_changer(string $ancien, string $nouveau): never
{
    if (!password_verify($ancien, (string) code_hache())) {
        erreur('Ancien code incorrect.');
    }
    if (!code_valide($nouveau)) {
        erreur('Le nouveau code doit faire 4 chiffres.');
    }
    db()->prepare("UPDATE reglages SET valeur = ? WHERE cle = 'code'")->execute([password_hash($nouveau, PASSWORD_DEFAULT)]);
    // Les autres appareils devront entrer le nouveau code ; celui-ci reste connecté.
    db()->exec('DELETE FROM sessions');
    ouvrir_session();
    repondre(['ok' => true]);
}

/* ------------------------------------------------------------------ */
/* Documents                                                           */
/* ------------------------------------------------------------------ */

function verifier_cle(string $c, string $id): void
{
    if (!in_array($c, H_COLLECTIONS, true)) {
        erreur('Collection inconnue.');
    }
    if (!preg_match('/^[A-Za-z0-9_\-.~:@+]{1,120}$/', $id)) {
        erreur('Identifiant invalide.');
    }
}

function rev_suivante(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COALESCE(MAX(rev), 0) + 1 FROM docs')->fetchColumn();
}

/** Fusion façon « update » : les objets se fusionnent, le reste (listes compris) remplace. */
function fusion(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        if (is_array($v) && !array_is_list($v) && isset($a[$k]) && is_array($a[$k]) && !array_is_list($a[$k])) {
            $a[$k] = fusion($a[$k], $v);
        } else {
            $a[$k] = $v;
        }
    }
    return $a;
}

function ecrire(string $c, string $id, $data, bool $fusionner): never
{
    verifier_cle($c, $id);
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        erreur('Données invalides.');
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    if ($fusionner) {
        $st = $pdo->prepare('SELECT data FROM docs WHERE c = ? AND id = ?');
        $st->execute([$c, $id]);
        $avant = $st->fetchColumn();
        $avant = is_string($avant) ? json_decode($avant, true) : null;
        if (!is_array($avant)) {
            $pdo->exec('ROLLBACK');
            erreur('Document introuvable.', 404);
        }
        $data = fusion($avant, $data);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 262144) {
        $pdo->exec('ROLLBACK');
        erreur('Document trop gros.');
    }
    $rev = rev_suivante($pdo);
    $pdo->prepare('INSERT INTO docs (c, id, data, rev, modifie_le) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(c, id) DO UPDATE SET data = excluded.data, rev = excluded.rev, modifie_le = excluded.modifie_le')
        ->execute([$c, $id, $json, $rev, date('Y-m-d H:i:s')]);
    $pdo->exec('COMMIT');
    repondre(['ok' => true, 'rev' => $rev]);
}

function supprimer(string $c, string $id): never
{
    verifier_cle($c, $id);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $rev = rev_suivante($pdo);
    // On garde une trace « supprimé » pour que les autres appareils le sachent
    $pdo->prepare('UPDATE docs SET data = NULL, rev = ?, modifie_le = ? WHERE c = ? AND id = ?')
        ->execute([$rev, date('Y-m-d H:i:s'), $c, $id]);
    $pdo->exec('COMMIT');
    repondre(['ok' => true, 'rev' => $rev]);
}

/** Tout ce qui a changé depuis la révision donnée (0 = tout). */
function sync(int $depuis): never
{
    $st = db()->prepare($depuis > 0
        ? 'SELECT c, id, data, rev FROM docs WHERE rev > ? ORDER BY rev'
        : 'SELECT c, id, data, rev FROM docs WHERE rev > ? AND data IS NOT NULL ORDER BY rev');
    $st->execute([$depuis]);
    $docs = [];
    $max = $depuis;
    foreach ($st as $l) {
        $docs[] = ['c' => $l['c'], 'id' => $l['id'], 'data' => $l['data'] === null ? null : json_decode($l['data'], true)];
        $max = max($max, (int) $l['rev']);
    }
    if ($depuis === 0) {
        $max = max($max, (int) db()->query('SELECT COALESCE(MAX(rev), 0) FROM docs')->fetchColumn());
    }
    repondre(['ok' => true, 'rev' => $max, 'docs' => $docs]);
}

/* ------------------------------------------------------------------ */
/* Fichiers (photos de BL, documents)                                  */
/* ------------------------------------------------------------------ */

function chemin_fichier(string $id): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        return null;
    }
    foreach (H_TYPES as $ext) {
        $f = dossier() . "/fichiers/$id.$ext";
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

function envoyer_fichier(): never
{
    $f = $_FILES['fichier'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $code = $f['error'] ?? 0;
        erreur(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Fichier trop lourd.' : 'Le fichier n’est pas arrivé.', 400);
    }
    if ($f['size'] > 20 * 1024 * 1024) {
        erreur('Fichier trop lourd (20 Mo maximum).', 413);
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!isset(H_TYPES[$type])) {
        erreur('Format non accepté : utilise un PDF, un JPEG ou un PNG.', 415);
    }
    $id = bin2hex(random_bytes(16));
    $dest = dossier() . "/fichiers/$id." . H_TYPES[$type];
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        erreur('Impossible d’enregistrer le fichier.', 500);
    }
    repondre(['ok' => true, 'id' => $id, 'contentType' => $type, 'sizeBytes' => filesize($dest)]);
}

function servir_fichier(string $id): never
{
    $f = chemin_fichier($id) ?? erreur('Fichier introuvable.', 404);
    $type = array_search(pathinfo($f, PATHINFO_EXTENSION), H_TYPES, true) ?: 'application/octet-stream';
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    if ($type === 'application/pdf') {
        header('Content-Disposition: inline; filename="document.pdf"');
    }
    readfile($f);
    exit;
}

function supprimer_fichier(string $id): never
{
    $f = chemin_fichier($id);
    if ($f) {
        @unlink($f);
    }
    repondre(['ok' => true, 'deleted' => (bool) $f]);
}

/* ------------------------------------------------------------------ */

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
