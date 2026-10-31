<?php
/**
 * Le 76 OS (le76.fr/os) : tarifs modifiables, réception des commandes, page admin.
 *
 * Public : config (tarifs), commande (envoi d'une commande).
 * Admin (identifiant + mot de passe) : commandes, statut, suppression, tarifs, mot de passe.
 * Les données vivent dans un dossier « le76-os » à côté de public_html, hors d'atteinte du web
 * et des mises à jour du site (repli : api/donnees/os, protégé par .htaccess).
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const SESSION_NOM = 'le76_os';
const STATUTS = ['nouvelle', 'en_cours', 'traitee'];

function repondre(array $d, int $code = 200)
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function erreur(string $m, int $code = 400)
{
    repondre(['ok' => false, 'erreur' => $m], $code);
}

function dossier(): string
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $env = getenv('OS_DONNEES');
    $candidats = $env ? [$env] : [dirname(__DIR__, 2) . '/le76-os', __DIR__ . '/donnees/os'];
    foreach ($candidats as $c) {
        if (!is_dir($c)) {
            @mkdir($c, 0750, true);
        }
        if (is_dir($c) && is_writable($c)) {
            if (str_starts_with($c, __DIR__) && !is_file("$c/.htaccess")) {
                @file_put_contents("$c/.htaccess", "Require all denied\n");
            }
            return $d = $c;
        }
    }
    erreur("Le dossier de données n'est pas disponible.", 500);
}

/** Lit un fichier JSON (valeur par défaut s'il n'existe pas). */
function lire(string $nom, array $def = []): array
{
    $f = dossier() . "/$nom";
    if (!is_file($f)) {
        return $def;
    }
    $j = json_decode((string) file_get_contents($f), true);
    return is_array($j) ? $j : $def;
}
/** Modifie un fichier JSON de façon exclusive (verrou) : $fn reçoit les données et renvoie les nouvelles. */
function modifier(string $nom, array $def, callable $fn): array
{
    $f = dossier() . "/$nom";
    $h = fopen($f, 'c+');
    if (!$h || !flock($h, LOCK_EX)) {
        erreur("Enregistrement impossible.", 500);
    }
    $j = json_decode((string) stream_get_contents($h), true);
    $new = $fn(is_array($j) ? $j : $def);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0640);
    return $new;
}

function requete(): array
{
    $corps = file_get_contents('php://input', false, null, 0, 262144) ?: '';
    $j = json_decode($corps, true);
    return array_merge($_GET, is_array($j) ? $j : $_POST);
}
function ip_cle(): string
{
    return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '?') . '|le76os'), 0, 16);
}
function texte($v, int $max): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v) ?? '');
    return mb_substr($s, 0, $max);
}
function nombre($v, float $min, float $max): ?float
{
    if (!is_numeric($v)) {
        return null;
    }
    $n = (float) $v;
    return ($n >= $min && $n <= $max) ? round($n, 2) : null;
}

/* ------------------------------------------------------------------ */
/* Compte admin et session                                             */
/* ------------------------------------------------------------------ */

function compte(): array
{
    $c = lire('admin.json');
    if (empty($c['hash'])) {
        $c = require __DIR__ . '/lib/os-seed.php';
        modifier('admin.json', [], fn() => $c);
    }
    return $c;
}
function session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NOM);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    @session_start();
}
function connecte(): bool
{
    session();
    return !empty($_SESSION['os_admin']) && (time() - (int) ($_SESSION['os_t'] ?? 0)) < 43200;
}
function exiger_admin(array $q, bool $ecriture): void
{
    connecte() || erreur('Connecte-toi pour continuer.', 401);
    if ($ecriture) {
        $jeton = (string) ($_SERVER['HTTP_X_CSRF'] ?? '');
        hash_equals((string) ($_SESSION['os_csrf'] ?? ''), $jeton) || erreur('Session expirée : recharge la page.', 403);
    }
}

/** Limite les essais : 5 échecs par quart d'heure et par adresse. */
function essais_ok(): bool
{
    $t = lire('essais.json');
    $k = ip_cle();
    $e = $t[$k] ?? ['n' => 0, 't' => 0];
    if (time() - (int) $e['t'] > 900) {
        return true;
    }
    return (int) $e['n'] < 5;
}
function essai_noter(bool $ok): void
{
    $k = ip_cle();
    modifier('essais.json', [], function (array $t) use ($k, $ok) {
        $now = time();
        foreach ($t as $kk => $e) {
            if ($now - (int) ($e['t'] ?? 0) > 900) {
                unset($t[$kk]);
            }
        }
        if ($ok) {
            unset($t[$k]);
        } else {
            $e = $t[$k] ?? ['n' => 0, 't' => $now];
            $t[$k] = ['n' => (int) $e['n'] + 1, 't' => $now];
        }
        return $t;
    });
}

/* ------------------------------------------------------------------ */
/* Tarifs                                                              */
/* ------------------------------------------------------------------ */

function tarifs_nettoyer(array $in): array
{
    $out = ['mods' => [], 'packs' => [], 'host' => [], 'domain' => null, 'install' => null, 'promoEnd' => null];
    foreach ((array) ($in['mods'] ?? []) as $id => $v) {
        if (!preg_match('/^[a-z]{2,12}$/', (string) $id) || !is_array($v)) {
            continue;
        }
        $m = nombre($v['m'] ?? null, 0, 100000);
        $l = nombre($v['l'] ?? null, 0, 1000000);
        if ($m !== null && $l !== null) {
            $out['mods'][$id] = ['m' => $m, 'l' => $l];
        }
    }
    foreach ((array) ($in['packs'] ?? []) as $id => $v) {
        if (!preg_match('/^[a-z]{2,12}$/', (string) $id) || !is_array($v)) {
            continue;
        }
        $m = nombre($v['m'] ?? null, 0, 100000);
        $l = nombre($v['l'] ?? null, 0, 1000000);
        if ($m !== null && $l !== null) {
            $out['packs'][$id] = ['m' => $m, 'l' => $l];
        }
    }
    foreach (['m', 'a', 's', 'l'] as $k) {
        $n = nombre($in['host'][$k] ?? null, 0, 100000);
        if ($n !== null) {
            $out['host'][$k] = $n;
        }
    }
    $out['domain'] = nombre($in['domain'] ?? null, 0, 10000);
    $out['install'] = nombre($in['install'] ?? null, 0, 100000);
    $d = (string) ($in['promoEnd'] ?? '');
    $out['promoEnd'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : null;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Actions                                                             */
/* ------------------------------------------------------------------ */

try {
    $q = requete();
    $action = (string) ($q['action'] ?? '');
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    switch ($action) {
        case 'config':
            repondre(['ok' => true, 'tarifs' => lire('tarifs.json')]);

        case 'commande':
            $post || erreur('Méthode non autorisée.', 405);
            if (texte($q['website'] ?? '', 50) !== '') {
                repondre(['ok' => true]); // piège à robots
            }
            $nom = texte($q['nom'] ?? '', 120);
            $email = texte($q['email'] ?? '', 160);
            $tel = texte($q['tel'] ?? '', 40);
            $nom !== '' || erreur('Indiquez votre nom.');
            ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) || ($email === '' && $tel !== '') || erreur('Indiquez une adresse e-mail valide ou un téléphone.');
            $lignes = [];
            foreach (array_slice((array) ($q['lines'] ?? []), 0, 40) as $l) {
                if (is_array($l)) {
                    $lignes[] = ['name' => texte($l['name'] ?? '', 120), 'price' => (float) ($l['price'] ?? 0), 'unit' => texte($l['unit'] ?? '', 20)];
                }
            }
            $lignes || erreur('Le panier est vide.');
            $cle = ip_cle();
            $recent = array_filter(lire('commandes.json'), fn($c) => ($c['ip'] ?? '') === $cle && time() - (int) ($c['ts'] ?? 0) < 3600);
            count($recent) < 5 || erreur('Trop de commandes envoyées. Réessayez plus tard.', 429);
            $cmd = [
                'id' => date('Ymd-His') . '-' . bin2hex(random_bytes(2)),
                'ts' => time(),
                'date' => date('Y-m-d H:i'),
                'statut' => 'nouvelle',
                'ip' => $cle,
                'client' => ['nom' => $nom, 'etab' => texte($q['etab'] ?? '', 160), 'email' => $email, 'tel' => $tel, 'message' => texte($q['message'] ?? '', 1500)],
                'plan' => texte($q['planLabel'] ?? '', 40),
                'lignes' => $lignes,
                'host' => (float) ($q['host'] ?? 0),
                'dom' => (float) ($q['dom'] ?? 0),
                'install' => (float) ($q['install'] ?? 0),
                'today' => (float) ($q['today'] ?? 0),
                'ttc' => (float) ($q['ttc'] ?? 0),
                'then' => texte($q['thenText'] ?? '', 200),
                'recap' => texte($q['recap'] ?? '', 4000),
            ];
            modifier('commandes.json', [], function (array $l) use ($cmd) {
                array_unshift($l, $cmd);
                return array_slice($l, 0, 2000);
            });
            repondre(['ok' => true, 'id' => $cmd['id']]);

        case 'moi':
            repondre(['ok' => true, 'connecte' => connecte(), 'csrf' => connecte() ? (string) $_SESSION['os_csrf'] : '', 'id' => connecte() ? (string) compte()['id'] : '']);

        case 'login':
            $post || erreur('Méthode non autorisée.', 405);
            essais_ok() || erreur('Trop d\'essais. Réessayez dans un quart d\'heure.', 429);
            $c = compte();
            $okId = hash_equals(mb_strtolower((string) $c['id']), mb_strtolower(texte($q['id'] ?? '', 60)));
            $okMdp = password_verify((string) ($q['mdp'] ?? ''), (string) $c['hash']);
            if (!($okId && $okMdp)) {
                essai_noter(false);
                usleep(500000);
                erreur('Identifiant ou mot de passe incorrect.', 401);
            }
            essai_noter(true);
            session();
            session_regenerate_id(true);
            $_SESSION['os_admin'] = true;
            $_SESSION['os_t'] = time();
            $_SESSION['os_csrf'] = bin2hex(random_bytes(16));
            repondre(['ok' => true, 'csrf' => $_SESSION['os_csrf'], 'id' => (string) $c['id']]);

        case 'logout':
            session();
            $_SESSION = [];
            @session_destroy();
            repondre(['ok' => true]);

        case 'commandes':
            exiger_admin($q, false);
            repondre(['ok' => true, 'commandes' => array_map(function ($c) { unset($c['ip']); return $c; }, lire('commandes.json'))]);

        case 'commande_statut':
            $post || erreur('Méthode non autorisée.', 405);
            exiger_admin($q, true);
            in_array($q['statut'] ?? '', STATUTS, true) || erreur('Statut inconnu.');
            $id = (string) ($q['id'] ?? '');
            modifier('commandes.json', [], function (array $l) use ($id, $q) {
                foreach ($l as &$c) {
                    if (($c['id'] ?? '') === $id) {
                        $c['statut'] = $q['statut'];
                    }
                }
                return $l;
            });
            repondre(['ok' => true]);

        case 'commande_supprimer':
            $post || erreur('Méthode non autorisée.', 405);
            exiger_admin($q, true);
            $id = (string) ($q['id'] ?? '');
            modifier('commandes.json', [], fn(array $l) => array_values(array_filter($l, fn($c) => ($c['id'] ?? '') !== $id)));
            repondre(['ok' => true]);

        case 'tarifs_lire':
            exiger_admin($q, false);
            repondre(['ok' => true, 'tarifs' => lire('tarifs.json')]);

        case 'tarifs_ecrire':
            $post || erreur('Méthode non autorisée.', 405);
            exiger_admin($q, true);
            $t = tarifs_nettoyer(is_array($q['tarifs'] ?? null) ? $q['tarifs'] : []);
            modifier('tarifs.json', [], fn() => $t);
            repondre(['ok' => true, 'tarifs' => $t]);

        case 'tarifs_reinitialiser':
            $post || erreur('Méthode non autorisée.', 405);
            exiger_admin($q, true);
            modifier('tarifs.json', [], fn() => []);
            repondre(['ok' => true]);

        case 'mdp_changer':
            $post || erreur('Méthode non autorisée.', 405);
            exiger_admin($q, true);
            $c = compte();
            password_verify((string) ($q['actuel'] ?? ''), (string) $c['hash']) || erreur('Le mot de passe actuel est incorrect.', 403);
            $nouveau = (string) ($q['nouveau'] ?? '');
            mb_strlen($nouveau) >= 10 || erreur('Le nouveau mot de passe doit faire au moins 10 caractères.');
            $id = texte($q['id'] ?? $c['id'], 60) ?: (string) $c['id'];
            modifier('admin.json', [], fn() => ['id' => $id, 'hash' => password_hash($nouveau, PASSWORD_BCRYPT, ['cost' => 11])]);
            repondre(['ok' => true]);

        default:
            erreur('Action inconnue.', 404);
    }
} catch (Throwable $e) {
    error_log('os.php: ' . $e->getMessage());
    erreur('Erreur du serveur.', 500);
}
