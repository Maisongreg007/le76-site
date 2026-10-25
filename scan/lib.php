<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');

/*
 * Les réglages (mot de passe, connexion Google) sont rangés À CÔTÉ de public_html,
 * comme pour l'outil hygiène : la mise à jour automatique du site ne peut pas les effacer
 * et ils ne sont jamais accessibles depuis le web.
 */
define('DATA_DIR', (function (): string {
    $candidats = [
        dirname(__DIR__, 2) . '/le76-scan',   // à côté de public_html
        __DIR__ . '/donnees',                  // repli (protégé par .htaccess)
    ];
    foreach ($candidats as $c) {
        if (!is_dir($c)) {
            @mkdir($c, 0750, true);
        }
        if (is_dir($c) && is_writable($c)) {
            if (str_starts_with($c, __DIR__) && !is_file("$c/.htaccess")) {
                file_put_contents("$c/.htaccess", "Require all denied\n");
            }
            return $c;
        }
    }
    throw new RuntimeException('Aucun dossier de stockage disponible.');
})());

/* Empreinte de la clé de la page de réglages (la clé elle-même n'est pas dans le code). */
const SETUP_KEY_HASH = '$2y$10$e.gG.sFCK/vrrjdSsa.KkeH61akpej517.afR7Dcnw.1PPGQxLgl2';

const ROOT_NAME = 'Le 76 - Documents';
const INBOX_NAME = 'À classer';

const CATEGORIES = [
    'factures'   => 'Factures fournisseurs',
    'livraisons' => 'Bons de livraison',
    'banque'     => 'Banque',
    'assurances' => 'Assurances',
    'impots'     => 'Impôts et URSSAF',
    'autres'     => 'Autres',
];

/* ---------- Configuration (data/config.json) ---------- */

function cfg(): array
{
    $f = DATA_DIR . '/config.json';
    if (!is_file($f)) {
        return [];
    }
    return json_decode((string) file_get_contents($f), true) ?: [];
}

/** Secret propre au serveur, créé une seule fois (sert à signer la connexion). */
function server_secret(): string
{
    $c = cfg();
    if (empty($c['secret'])) {
        $c['secret'] = bin2hex(random_bytes(24));
        cfg_save($c);
    }
    return $c['secret'];
}

function setup_key_ok(string $key): bool
{
    return $key !== '' && password_verify($key, SETUP_KEY_HASH);
}

function cfg_save(array $c): void
{
    $f = DATA_DIR . '/config.json';
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0600);
}

function cfg_set(array $values): void
{
    cfg_save(array_merge(cfg(), $values));
}

/* ---------- Connexion (un seul mot de passe pour tout le monde) ---------- */

function auth_token(): string
{
    $c = cfg();
    return hash_hmac('sha256', 'le76-scan-session', server_secret() . ($c['password_hash'] ?? ''));
}

function is_logged(): bool
{
    $c = cfg();
    return !empty($c['password_hash'])
        && isset($_COOKIE['scan_auth'])
        && hash_equals(auth_token(), (string) $_COOKIE['scan_auth']);
}

function set_auth_cookie(): void
{
    setcookie('scan_auth', auth_token(), [
        'expires'  => time() + 60 * 60 * 24 * 180,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function login_blocked(): bool
{
    $f = DATA_DIR . '/fails.json';
    $all = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $recent = array_filter($all[$ip] ?? [], fn($t) => $t > time() - 900);
    return count($recent) >= 8;
}

function login_fail(): void
{
    $f = DATA_DIR . '/fails.json';
    $all = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $all[$ip] = array_values(array_filter($all[$ip] ?? [], fn($t) => $t > time() - 900));
    $all[$ip][] = time();
    file_put_contents($f, json_encode($all), LOCK_EX);
}

function try_login(string $password): bool
{
    $c = cfg();
    if (empty($c['password_hash']) || login_blocked()) {
        return false;
    }
    if (password_verify($password, $c['password_hash'])) {
        set_auth_cookie();
        return true;
    }
    login_fail();
    usleep(600000);
    return false;
}

/* ---------- Appels HTTP / Google ---------- */

function http(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $r = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($r === false) {
        throw new RuntimeException('Connexion impossible : ' . $err);
    }
    $j = json_decode($r, true);
    if ($code >= 400) {
        $msg = is_array($j) ? ($j['error']['message'] ?? $j['error_description'] ?? $r) : $r;
        throw new RuntimeException('Google (' . $code . ') : ' . $msg, $code);
    }
    return is_array($j) ? $j : [];
}

function google_ready(): bool
{
    $c = cfg();
    return !empty($c['client_id']) && !empty($c['client_secret']) && !empty($c['refresh_token']);
}

function access_token(): string
{
    $f = DATA_DIR . '/token.json';
    if (is_file($f)) {
        $t = json_decode((string) file_get_contents($f), true);
        if (is_array($t) && ($t['expires'] ?? 0) > time() + 60) {
            return $t['token'];
        }
    }
    $c = cfg();
    if (!google_ready()) {
        throw new RuntimeException('Google Drive n\'est pas encore connecté.');
    }
    $r = http('POST', 'https://oauth2.googleapis.com/token', [], http_build_query([
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'refresh_token' => $c['refresh_token'],
        'grant_type'    => 'refresh_token',
    ]));
    file_put_contents($f, json_encode(['token' => $r['access_token'], 'expires' => time() + (int) $r['expires_in']]), LOCK_EX);
    @chmod($f, 0600);
    return $r['access_token'];
}

function drive(string $method, string $path, array $query = [], ?array $json = null): array
{
    $url = 'https://www.googleapis.com/drive/v3/' . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['Authorization: Bearer ' . access_token()];
    $body = null;
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = $json === [] ? '{}' : json_encode($json);
    }
    return http($method, $url, $headers, $body);
}

/* ---------- Dossiers Drive ---------- */

function folder_cache(): array
{
    $f = DATA_DIR . '/folders.json';
    return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
}

function folder_cache_save(array $c): void
{
    file_put_contents(DATA_DIR . '/folders.json', json_encode($c), LOCK_EX);
}

function folder_cache_clear(): void
{
    @unlink(DATA_DIR . '/folders.json');
}

function drive_folder(string $name, ?string $parent = null): string
{
    $cache = folder_cache();
    $key = ($parent ?? 'root') . '/' . $name;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $esc = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
    $q = "name = '$esc' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
    if ($parent) {
        $q .= " and '$parent' in parents";
    }
    $r = drive('GET', 'files', ['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]);
    if (!empty($r['files'][0]['id'])) {
        $id = $r['files'][0]['id'];
    } else {
        $meta = ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
        if ($parent) {
            $meta['parents'] = [$parent];
        }
        $id = drive('POST', 'files', ['fields' => 'id'], $meta)['id'];
    }
    $cache[$key] = $id;
    folder_cache_save($cache);
    return $id;
}

function folder_inbox(): string
{
    return drive_folder(INBOX_NAME, drive_folder(ROOT_NAME));
}

function folder_category(string $cat, string $year): string
{
    $root = drive_folder(ROOT_NAME);
    return drive_folder($year, drive_folder(CATEGORIES[$cat], $root));
}

/* ---------- Envoi / liste / déplacement ---------- */

function drive_upload(string $data, string $name, string $mime, string $folderId): array
{
    $b = bin2hex(random_bytes(8));
    $meta = json_encode(['name' => $name, 'parents' => [$folderId]], JSON_UNESCAPED_UNICODE);
    $body = "--$b\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
          . "--$b\r\nContent-Type: $mime\r\n\r\n$data\r\n--$b--";
    return http(
        'POST',
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink',
        ['Authorization: Bearer ' . access_token(), 'Content-Type: multipart/related; boundary=' . $b],
        $body
    );
}

/** Envoie un fichier ; si un dossier mis en cache a été supprimé dans Drive, on recommence à neuf. */
function drive_upload_to(string $data, string $name, string $mime, callable $folderFn): array
{
    try {
        return drive_upload($data, $name, $mime, $folderFn());
    } catch (RuntimeException $e) {
        if ($e->getCode() === 404) {
            folder_cache_clear();
            return drive_upload($data, $name, $mime, $folderFn());
        }
        throw $e;
    }
}

function inbox_list(): array
{
    $id = folder_inbox();
    $r = drive('GET', 'files', [
        'q'        => "'$id' in parents and trashed = false",
        'fields'   => 'files(id,name,createdTime,webViewLink)',
        'orderBy'  => 'createdTime desc',
        'pageSize' => 100,
    ]);
    return $r['files'] ?? [];
}

function drive_move(string $fileId, string $cat, ?string $year = null): void
{
    $f = drive('GET', 'files/' . rawurlencode($fileId), ['fields' => 'parents,createdTime']);
    $year = $year ?: substr($f['createdTime'] ?? date('Y'), 0, 4);
    $target = folder_category($cat, $year);
    drive('PATCH', 'files/' . rawurlencode($fileId) . '?' . http_build_query([
        'addParents'    => $target,
        'removeParents' => implode(',', $f['parents'] ?? []),
        'fields'        => 'id',
    ]), [], []);
}

function clean_label(string $s): string
{
    $s = preg_replace('/[^\p{L}\p{N}\- ]/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, 60);
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
