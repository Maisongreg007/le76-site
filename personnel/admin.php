<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$p = (string)($_GET['p'] ?? 'aujourdhui');
$pages = ['aujourdhui', 'planning', 'heures', 'equipe', 'reglages', 'export'];
if (!in_array($p, $pages, true)) {
    $p = 'aujourdhui';
}

/* Première utilisation : choisir le mot de passe */
if (!setting('admin_hash')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pw = (string)($_POST['pw'] ?? '');
        if (mb_strlen($pw) < 8) {
            $err = 'Le mot de passe doit faire au moins 8 caractères.';
        } elseif ($pw !== (string)($_POST['pw2'] ?? '')) {
            $err = 'Les deux mots de passe ne sont pas identiques.';
        } else {
            set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
            admin_login();
            redirect('admin.php?p=equipe&ok=' . rawurlencode('Bienvenue ! Commence par ajouter ton équipe.'));
        }
    }
    page_head('Première connexion');
    echo '<main class="wrap" style="max-width:420px"><h1>Bienvenue</h1><div class="card"><p class="muted">Choisis le mot de passe de l\'administration (planning, heures, salaires). Il ne sera demandé qu\'à toi.</p>';
    if (!empty($err)) echo '<div class="flash err">' . h($err) . '</div>';
    echo '<form method="post"><div class="field"><label>Mot de passe</label><input type="password" name="pw" required minlength="8" autocomplete="new-password" style="width:100%"></div>'
        . '<div class="field"><label>Confirme</label><input type="password" name="pw2" required minlength="8" autocomplete="new-password" style="width:100%"></div>'
        . '<button class="primary" style="width:100%">Valider</button></form></div></main></body></html>';
    exit;
}

/* Connexion */
if (!is_admin()) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (too_many('login', 8, 600)) {
            $err = 'Trop d\'essais. Réessaie dans quelques minutes.';
        } elseif (password_verify((string)($_POST['pw'] ?? ''), (string)setting('admin_hash'))) {
            admin_login();
            redirect('admin.php?p=' . $p);
        } else {
            note_fail('login');
            $err = 'Mot de passe incorrect.';
        }
    }
    page_head('Connexion');
    echo '<main class="wrap" style="max-width:420px"><h1>Personnel</h1><div class="card">';
    if ($err) echo '<div class="flash err">' . h($err) . '</div>';
    echo '<form method="post"><div class="field"><label>Mot de passe</label><input type="password" name="pw" required autofocus autocomplete="current-password" style="width:100%"></div>'
        . '<button class="primary" style="width:100%">Entrer</button></form></div></main></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

if (isset($_GET['logout'])) {
    admin_logout();
    redirect('admin.php');
}

require __DIR__ . '/pages/' . $p . '.php';
