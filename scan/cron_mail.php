<?php
declare(strict_types=1);
/*
 * Récupère les scans envoyés par l'imprimante (par mail) et les dépose dans « À classer ».
 * À lancer toutes les 5 minutes par une tâche planifiée (cron) Hostinger :
 *   php /chemin/vers/scan/cron_mail.php
 */
require __DIR__ . '/lib.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    if (!setup_key_ok((string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Accès refusé.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function say(string $s): void { echo $s, "\n"; }

$c = cfg();
if (empty($c['imap_user']) || empty($c['imap_pass'])) {
    say('Boîte mail non configurée : rien à faire.');
    exit(0);
}
if (!function_exists('imap_open')) {
    say('Erreur : l\'extension IMAP n\'est pas disponible sur cet hébergement.');
    exit(1);
}
if (!google_ready()) {
    say('Erreur : Google Drive n\'est pas connecté.');
    exit(1);
}

$host = $c['imap_host'] ?: 'imap.hostinger.com';
$mbox = @imap_open('{' . $host . ':993/imap/ssl}INBOX', $c['imap_user'], $c['imap_pass'], 0, 1);
if (!$mbox) {
    say('Erreur de connexion à la boîte mail : ' . imap_last_error());
    exit(1);
}

$allow = array_filter(array_map('strtolower', array_map('trim', explode(',', (string) ($c['mail_allow'] ?? '')))));
$ids = imap_search($mbox, 'UNSEEN') ?: [];
$done = 0;

function collect_parts($struct, string $prefix = ''): array
{
    $out = [];
    foreach (($struct->parts ?? []) as $i => $p) {
        $num = $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1);
        if (!empty($p->parts)) {
            $out = array_merge($out, collect_parts($p, $num));
            continue;
        }
        $name = '';
        foreach (array_merge($p->dparameters ?? [], $p->parameters ?? []) as $x) {
            if (in_array(strtolower($x->attribute), ['filename', 'name'], true)) {
                $name = imap_utf8($x->value);
            }
        }
        if ($name !== '') {
            $out[] = ['num' => $num, 'name' => $name, 'enc' => (int) $p->encoding];
        }
    }
    return $out;
}

foreach ($ids as $n) {
    $head = imap_headerinfo($mbox, $n);
    $from = strtolower(($head->from[0]->mailbox ?? '') . '@' . ($head->from[0]->host ?? ''));
    if ($allow && !in_array($from, $allow, true)) {
        say("Ignoré (expéditeur non autorisé) : $from");
        imap_setflag_full($mbox, (string) $n, '\\Seen');
        continue;
    }

    $struct = imap_fetchstructure($mbox, $n);
    $parts = $struct ? (empty($struct->parts) ? [] : collect_parts($struct)) : [];
    $failed = false;

    foreach ($parts as $p) {
        $ext = strtolower(pathinfo($p['name'], PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? null;
        if (!$mime) {
            continue;
        }
        $raw = imap_fetchbody($mbox, $n, $p['num']);
        $data = match ($p['enc']) { 3 => base64_decode($raw), 4 => quoted_printable_decode($raw), default => $raw };
        if ($data === '' || $data === false) {
            continue;
        }
        $base = clean_label(pathinfo($p['name'], PATHINFO_FILENAME)) ?: 'Scan';
        $name = date('Y-m-d_H-i') . '_' . str_replace(' ', '-', $base) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        try {
            drive_upload_to($data, $name, $mime, fn() => folder_inbox());
            say("Déposé dans « À classer » : $name");
            $done++;
        } catch (Throwable $e) {
            say('Échec : ' . $e->getMessage());
            $failed = true;
        }
    }

    // On ne marque le mail comme lu que si tout est parti, pour réessayer sinon.
    if (!$failed) {
        imap_setflag_full($mbox, (string) $n, '\\Seen');
    }
}

imap_close($mbox);
say($done . ' document(s) récupéré(s).');
