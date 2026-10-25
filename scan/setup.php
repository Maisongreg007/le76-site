<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/* Page de réglage : réservée à celui qui connaît la clé (donnée une seule fois). */
$c = cfg();
$given = (string) ($_GET['key'] ?? $_COOKIE['scan_setup'] ?? '');
if (!setup_key_ok($given)) {
    http_response_code(403);
    exit('Accès refusé.');
}
setcookie('scan_setup', $given, ['expires' => time() + 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);

$self = 'https://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
$msg = '';
$err = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = $_POST['do'] ?? '';
        if ($do === 'password') {
            $pw = (string) ($_POST['password'] ?? '');
            if (mb_strlen($pw) < 8) {
                throw new RuntimeException('Le mot de passe doit faire au moins 8 caractères.');
            }
            cfg_set(['password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
            $msg = 'Mot de passe enregistré.';
        } elseif ($do === 'google') {
            cfg_set([
                'client_id'     => trim((string) $_POST['client_id']),
                'client_secret' => trim((string) $_POST['client_secret']),
            ]);
            $msg = 'Clés Google enregistrées. Tu peux maintenant connecter le Drive.';
        } elseif ($do === 'mail') {
            cfg_set([
                'imap_host' => trim((string) $_POST['imap_host']),
                'imap_user' => trim((string) $_POST['imap_user']),
                'imap_pass' => (string) $_POST['imap_pass'] !== '' ? (string) $_POST['imap_pass'] : ($c['imap_pass'] ?? ''),
                'mail_allow' => trim((string) $_POST['mail_allow']),
            ]);
            $msg = 'Réglages de la boîte mail enregistrés.';
        }
        $c = cfg();
    }

    if (isset($_GET['connect'])) {
        $state = bin2hex(random_bytes(8));
        setcookie('scan_state', $state, ['expires' => time() + 600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $c['client_id'] ?? '',
            'redirect_uri'  => $self,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.file',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]));
        exit;
    }

    if (isset($_GET['code'])) {
        if (!hash_equals((string) ($_COOKIE['scan_state'] ?? 'x'), (string) ($_GET['state'] ?? ''))) {
            throw new RuntimeException('Connexion annulée (contrôle de sécurité). Recommence.');
        }
        $r = http('POST', 'https://oauth2.googleapis.com/token', [], http_build_query([
            'code'          => $_GET['code'],
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'redirect_uri'  => $self,
            'grant_type'    => 'authorization_code',
        ]));
        if (empty($r['refresh_token'])) {
            throw new RuntimeException('Google n\'a pas donné d\'accès durable. Recommence en cliquant « Connecter ».');
        }
        @unlink(DATA_DIR . '/token.json');
        folder_cache_clear();
        cfg_set(['refresh_token' => $r['refresh_token']]);
        foreach (CATEGORIES as $k => $_) {
            folder_category($k, date('Y'));
        }
        folder_inbox();
        $c = cfg();
        $msg = 'Google Drive est connecté. Les dossiers ont été créés.';
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$ok = fn(bool $b) => $b ? '✓' : '○';
$h = fn($s) => htmlspecialchars((string) $s);
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Réglages du scan</title>
<style>
body{font:16px/1.45 -apple-system,system-ui,sans-serif;max-width:640px;margin:0 auto;padding:16px;background:#f6f4f1;color:#1d1d1f}
section{background:#fff;border:1px solid #e4e0da;border-radius:12px;padding:16px;margin-bottom:14px}
h1{font-size:22px}h2{font-size:17px;margin:0 0 8px}
input{font:inherit;width:100%;padding:10px;margin:4px 0 10px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box}
button,a.b{font:inherit;background:#7a1f2b;color:#fff;border:0;border-radius:10px;padding:11px 16px;text-decoration:none;display:inline-block;cursor:pointer}
code{background:#eee;padding:2px 5px;border-radius:4px;word-break:break-all}
.m{background:#e6f4ea;padding:10px;border-radius:8px}.e{background:#fde8e6;padding:10px;border-radius:8px}
small{color:#666}
</style></head><body>
<h1>Réglages du scan</h1>
<?php if ($msg): ?><p class="m"><?= $h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="e"><?= $h($err) ?></p><?php endif; ?>

<section>
<h2><?= $ok(!empty($c['password_hash'])) ?> 1. Mot de passe commun</h2>
<form method="post"><input type="hidden" name="do" value="password">
<input type="password" name="password" placeholder="Choisis un mot de passe (8 caractères minimum)" autocomplete="new-password" required>
<button>Enregistrer</button></form>
<small>C'est celui que toi, Vanessa et Ludo taperez sur la page de scan.</small>
</section>

<section>
<h2><?= $ok(!empty($c['client_id'])) ?> 2. Clés Google</h2>
<p><small>Adresse à déclarer dans Google comme « URI de redirection autorisé » :<br><code><?= $h($self) ?></code></small></p>
<form method="post"><input type="hidden" name="do" value="google">
<input name="client_id" placeholder="ID client" value="<?= $h($c['client_id'] ?? '') ?>" required autocomplete="off">
<input name="client_secret" placeholder="Code secret du client" value="<?= $h($c['client_secret'] ?? '') ?>" required autocomplete="off">
<button>Enregistrer</button></form>
</section>

<section>
<h2><?= $ok(!empty($c['refresh_token'])) ?> 3. Connexion au Drive</h2>
<?php if (!empty($c['client_id'])): ?>
  <p>Connecte-toi avec <b>le76.documents@gmail.com</b> (pas ton compte perso).</p>
  <a class="b" href="?connect=1"><?= !empty($c['refresh_token']) ? 'Reconnecter' : 'Connecter Google Drive' ?></a>
<?php else: ?><p><small>Remplis d'abord l'étape 2.</small></p><?php endif; ?>
</section>

<section>
<h2><?= $ok(!empty($c['imap_user'])) ?> 4. Scans de l'imprimante HP (par mail) <small>(plus tard)</small></h2>
<form method="post"><input type="hidden" name="do" value="mail">
<input name="imap_host" placeholder="Serveur IMAP (ex : imap.hostinger.com)" value="<?= $h($c['imap_host'] ?? 'imap.hostinger.com') ?>">
<input name="imap_user" placeholder="Adresse de réception (ex : scan@le76.fr)" value="<?= $h($c['imap_user'] ?? '') ?>" autocomplete="off">
<input type="password" name="imap_pass" placeholder="<?= !empty($c['imap_pass']) ? 'Mot de passe déjà enregistré (laisser vide pour le garder)' : 'Mot de passe de cette boîte mail' ?>" autocomplete="new-password">
<input name="mail_allow" placeholder="Expéditeurs acceptés, séparés par des virgules (facultatif)" value="<?= $h($c['mail_allow'] ?? '') ?>">
<button>Enregistrer</button></form>
</section>

<p><a href="./">← Retour à la page de scan</a></p>
</body></html>
