<?php
/**
 * CAMPING OS 76 : point d'entrée unique.
 * POST JSON {a: "action", ...}. Exceptions en GET : ?a=plan (image du plan), ?a=export (sauvegarde).
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

const C_TOUJOURS = ['etat', 'commander'];

try {
    $methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $multipart = str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/');
    if ($methode === 'GET') {
        $q = $_GET;
    } elseif ($multipart) {
        $q = $_POST;
    } else {
        $q = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($q)) {
            throw new Fault('Requête illisible.');
        }
    }
    $a = (string) ($q['a'] ?? '');
    $moi = equipe_utilisateur();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');

    /* ---------- lecture ---------- */
    if ($a === 'plan' && $methode === 'GET') {
        if (!$moi) {
            throw new Fault('Connecte-toi pour voir le plan.', 401);
        }
        $m = rd('config/map');
        $f = $m ? c_dossier() . '/' . basename((string) ($m['file'] ?? '')) : '';
        if (!$m || !is_file($f)) {
            throw new Fault('Aucun plan.', 404);
        }
        header('Content-Type: ' . ($m['type'] ?? 'image/jpeg'));
        header('Cache-Control: private, max-age=86400');
        readfile($f);
        exit;
    }
    if ($a === 'export' && $methode === 'GET') {
        if (!$moi || !$moi['admin']) {
            throw new Fault('Réservé aux responsables.', 403);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="camping-76-sauvegarde-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store');
        echo export_all();
        exit;
    }
    if ($methode !== 'POST') {
        throw new Fault('Méthode non autorisée.', 405);
    }

    if ($a === 'etat') {
        repondre(etat($q, $moi));
    }

    /* ---------- écriture : une transaction par action ---------- */
    $pdo = c_db();
    if ($a === 'effacer' && $moi && $moi['admin'] && ($q['confirm'] ?? '') === 'EFFACER') {
        c_sauvegarde('avant-remise-a-zero'); // copie de sécurité, hors transaction (exigence de SQLite)
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ($a === 'commander') {
            if (!limiter('cmd:' . $ip, 30, 600)) {
                throw new Fault('Trop de commandes d’un coup. Réessayez dans quelques minutes.', 429);
            }
            seed();
            $r = place_order(is_array($q['input'] ?? null) ? $q['input'] : [], (string) ($q['key'] ?? ''));
        } else {
            if (!$moi) {
                throw new Fault('Connecte-toi pour continuer.', 401);
            }
            seed();
            $who = qui($q, $moi);
            $r = action($a, $q, $who);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    repondre(['ok' => true] + (is_array($r) ? $r : []));
} catch (Fault $e) {
    repondre(['ok' => false, 'erreur' => $e->getMessage()], $e->statut);
} catch (Throwable $e) {
    error_log('[camping] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    repondre(['ok' => false, 'erreur' => 'Erreur du serveur. Réessayez.'], 500);
}

/** Le compte CAMPING (poste, livreur, responsable) choisi par la personne connectée. */
function qui(array $q, array $moi): array
{
    $actor = (string) ($q['as'] ?? '');
    $c = comptes();
    if (!isset($c[$actor])) {
        throw new Fault('Choisissez votre compte dans l’espace équipe.', 403);
    }
    if ($c[$actor][1] === 'manager' && !$moi['admin']) {
        throw new Fault('Réservé aux comptes responsables.', 403);
    }
    return ['actor' => $actor, 'role' => $c[$actor][1]];
}

function action(string $a, array $q, array $who): ?array
{
    $s = fn(string $k) => (string) ($q[$k] ?? '');
    switch ($a) {
        case 'valider': validate($s('id'), $s('station'), $who); return null;
        case 'confier': return assign(array_map('strval', is_array($q['ids'] ?? null) ? $q['ids'] : []), $s('driver'), $who);
        case 'presence': presence($who); return null;
        case 'approuver': approve($s('driver'), $who); return null;
        case 'present': mark_present($s('driver'), $who); return null;
        case 'absent': mark_absent($s('driver'), $who); return null;
        case 'equipe': save_staff(is_array($q['staff'] ?? null) ? $q['staff'] : [], $who); return null;
        case 'depart': depart($s('trip'), $who); return null;
        case 'remise': return deliver($s('id'), $who, $q['method'] ?? null);
        case 'annuler': undo_deliver($s('id'), $who); return null;
        case 'retour': return_trip($s('trip'), $who); return null;
        case 'retrait': collect($s('id'), $who, $q['method'] ?? null); return null;
        case 'carte': save_menu($q['items'] ?? null, $who); return null;
        case 'reglages': save_settings($q['settings'] ?? null, $who); return null;
        case 'effacer': reset_tests($q['confirm'] ?? null, $who); return null;
        case 'plan': return enregistrer_plan($who);
    }
    throw new Fault('Action inconnue.', 404);
}

function enregistrer_plan(array $who): array
{
    need($who['role'], 'manager');
    $f = $_FILES['plan'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 8 * 1024 * 1024) {
        throw new Fault('Image PNG ou JPEG, 8 Mo maximum.');
    }
    $info = @getimagesize($f['tmp_name']);
    $types = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
    if (!$info || !isset($types[$info['mime']])) {
        throw new Fault('Image PNG ou JPEG, 8 Mo maximum.');
    }
    $nom = 'plan.' . $types[$info['mime']];
    foreach (glob(c_dossier() . '/plan.*') ?: [] as $vieux) {
        @unlink($vieux);
    }
    if (!move_uploaded_file($f['tmp_name'], c_dossier() . '/' . $nom)) {
        throw new Fault('Enregistrement du plan impossible.', 500);
    }
    $id = substr(sha1_file(c_dossier() . '/' . $nom) ?: c_hex(), 0, 12);
    wr('config/map', ['id' => $id, 'file' => $nom, 'type' => $info['mime'], 't' => c_now()]);
    return ['id' => $id];
}

/** Tout ce que l'écran affiche. Équipe connectée : tout ; client : la carte et sa commande. */
function etat(array $q, ?array $moi): array
{
    $pdo = c_db();
    $base = ['ok' => true, 'me' => $moi, 'installe' => $moi ? true : equipe_installee()];
    if ($moi) {
        // Rattrapage : une tournée entièrement remise mais restée ouverte se ferme.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            seed();
            foreach (liste('trips', "json_extract(data, '$.state') = 'delivering'") as $t) {
                close_if_done($t['id']);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        $rev = c_rev();
        if ((int) ($q['rev'] ?? -1) === $rev) {
            return $base + ['same' => true, 'rev' => $rev];
        }
        $depuis = (new DateTimeImmutable('-2 days', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $orders = liste('orders', "json_extract(data, '$.created') >= ? OR json_extract(data, '$.status') NOT IN ('delivered', 'collected')", [$depuis]);
        $trips = liste('trips', "json_extract(data, '$.created') >= ? OR json_extract(data, '$.state') != 'done'", [$depuis]);
        $map = rd('config/map');
        return $base + [
            'rev' => $rev, 'staff' => staff(), 'menu' => menu(), 'settings' => reglages(),
            'map' => $map ? ['id' => $map['id']] : null,
            'orders' => $orders, 'drivers' => liste('drivers'), 'trips' => $trips,
        ];
    }
    // Client : la carte, les réglages publics et, s'il la suit, sa commande.
    $r = reglages();
    $out = $base + [
        'rev' => null, 'staff' => ['managers' => [], 'drivers' => staff()['drivers']], 'menu' => menu(),
        'settings' => ['open' => (bool) ($r['open'] ?? true), 'spots' => array_map(fn($s) => [$s[0], $s[1]], $r['spots'] ?? [])],
        'map' => null, 'orders' => [], 'drivers' => [], 'trips' => [],
    ];
    $track = strtoupper((string) ($q['track'] ?? ''));
    if (preg_match('/^[A-F0-9]{8}$/D', $track)) {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
        if (limiter('suivi:' . $ip, 240, 600)) {
            $o = rd('orders/' . $track);
            if ($o) {
                $out['orders'] = [$o];
                $t = $o['trip'] ? rd('trips/' . $o['trip']) : null;
                if ($t) {
                    $out['trips'] = [['id' => $t['id'], 'driver' => $t['driver'], 'state' => $t['state'], 'created' => $t['created']]];
                }
            }
        }
    }
    return $out;
}
