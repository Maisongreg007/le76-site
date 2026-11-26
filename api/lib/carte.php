<?php
/**
 * Carte du 76 (appli /carte/) : brouillon partagé, validation, carte publiée, décors.
 * Petit magasin de documents JSON, comme le coût matière.
 * Ce fichier n'est pas accessible depuis le web (api/lib est bloqué par .htaccess).
 */
declare(strict_types=1);

const CA_COLLECTIONS = ['carte', 'sections', 'publie', 'archives'];
const CA_COLLS_RESPONSABLE = ['publie', 'archives']; // seuls les responsables publient

function ca_utilisateur(): ?array
{
    $j = (string) ($_COOKIE[COOKIE] ?? '');
    if (defined('DEMO') && !preg_match('/^[a-f0-9]{64}$/', $j)) {
        return db()->query('SELECT * FROM utilisateurs WHERE admin = 1 AND actif = 1 ORDER BY id LIMIT 1')->fetch() ?: null;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $j)) {
        return null;
    }
    $st = db()->prepare('SELECT u.* FROM sessions s JOIN utilisateurs u ON u.id = s.utilisateur_id WHERE s.jeton = ? AND s.expire > ? AND u.actif = 1');
    $st->execute([hash('sha256', $j), time()]);
    return $st->fetch() ?: null;
}

function ca_dossier(string $sous = ''): string
{
    $d = dossier_donnees() . '/carte' . ($sous !== '' ? '/' . $sous : '');
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

function ca_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . ca_dossier() . '/carte.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS docs (
        coll TEXT NOT NULL, id TEXT NOT NULL, data TEXT, rev INTEGER NOT NULL, maj TEXT NOT NULL,
        PRIMARY KEY (coll, id))");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_docs_rev ON docs(rev)');
    // Première ouverture : on part de la carte d'automne actuelle
    if (!$pdo->query('SELECT COUNT(*) FROM docs')->fetchColumn()) {
        $s = json_decode((string) @file_get_contents(__DIR__ . '/carte-depart.json'), true);
        if (is_array($s)) {
            $e = [['coll' => 'carte', 'id' => 'brouillon', 'data' => $s['meta']]];
            foreach ($s['secs'] as $x) {
                $id = $x['id'];
                unset($x['id']);
                $e[] = ['coll' => 'sections', 'id' => $id, 'data' => $x];
            }
            ca_ecrire_pdo($pdo, $e);
        }
    }
    return $pdo;
}

function ca_rev(?PDO $pdo = null): int
{
    return (int) ($pdo ?? ca_db())->query('SELECT IFNULL(MAX(rev), 0) FROM docs')->fetchColumn();
}

function ca_tout(): array
{
    $docs = array_fill_keys(CA_COLLECTIONS, []);
    foreach (ca_db()->query('SELECT coll, id, data FROM docs WHERE data IS NOT NULL') as $l) {
        $docs[$l['coll']][$l['id']] = json_decode($l['data'], true);
    }
    foreach ($docs as $k => $v) {
        if (!$v) {
            $docs[$k] = new stdClass();
        }
    }
    return [$docs, ca_rev()];
}

function ca_changements(int $depuis): array
{
    $st = ca_db()->prepare('SELECT coll, id, data, rev FROM docs WHERE rev > ? ORDER BY rev LIMIT 2000');
    $st->execute([$depuis]);
    $out = [];
    $rev = $depuis;
    foreach ($st as $l) {
        $out[] = ['coll' => $l['coll'], 'id' => $l['id'], 'data' => $l['data'] === null ? null : json_decode($l['data'], true)];
        $rev = max($rev, (int) $l['rev']);
    }
    return ['rev' => $rev, 'changements' => $out];
}

/** Écritures [{coll, id, data|null}] ; null = suppression. */
function ca_ecrire(array $ecritures, bool $responsable): int
{
    foreach ($ecritures as $e) {
        if (!$responsable && in_array((string) ($e['coll'] ?? ''), CA_COLLS_RESPONSABLE, true)) {
            erreur('Seul un responsable peut publier la carte.', 403);
        }
    }
    return ca_ecrire_pdo(ca_db(), $ecritures);
}

function ca_ecrire_pdo(PDO $pdo, array $ecritures): int
{
    $pdo->beginTransaction();
    try {
        $rev = ca_rev($pdo);
        $st = $pdo->prepare('INSERT INTO docs (coll, id, data, rev, maj) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(coll, id) DO UPDATE SET data = excluded.data, rev = excluded.rev, maj = excluded.maj');
        foreach (array_slice($ecritures, 0, 500) as $e) {
            $coll = (string) ($e['coll'] ?? '');
            $id = (string) ($e['id'] ?? '');
            if (!in_array($coll, CA_COLLECTIONS, true) || !preg_match('/^[A-Za-z0-9_\-.:]{1,80}$/', $id)) {
                continue;
            }
            $data = $e['data'] ?? null;
            $json = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
            if ($json !== null && strlen($json) > 600000) {
                throw new RuntimeException('Document trop volumineux.');
            }
            $st->execute([$coll, $id, $json, ++$rev, maintenant()]);
        }
        $pdo->commit();
        return $rev;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** La carte publiée, lisible par tous (clients compris). */
function ca_publiee(): ?array
{
    $st = ca_db()->prepare("SELECT data FROM docs WHERE coll = 'publie' AND id = 'actuelle' AND data IS NOT NULL");
    $st->execute();
    $d = $st->fetchColumn();
    return $d ? json_decode($d, true) : null;
}

/* Décors envoyés (images) */
function ca_image_enregistrer(string $b64): array
{
    $type = preg_match('/^data:image\/(png|jpeg|webp)/', $b64, $m) ? $m[1] : 'png';
    $bin = base64_decode(preg_replace('/^data:[^,]+,/', '', $b64), true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 8_000_000 || @getimagesizefromstring($bin) === false) {
        erreur("L'image n'a pas pu être enregistrée.");
    }
    $id = bin2hex(random_bytes(12)) . '.' . ($type === 'jpeg' ? 'jpg' : $type);
    file_put_contents(ca_dossier('images') . '/' . $id, $bin);
    return ['id' => $id, 'url' => '/api/carte.php?action=image&id=' . $id];
}

function ca_image_envoyer(string $id): never
{
    if (!preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $id) || !is_file($f = ca_dossier('images') . '/' . $id)) {
        http_response_code(404);
        exit;
    }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[pathinfo($id, PATHINFO_EXTENSION)]);
    header('Cache-Control: public, max-age=31536000');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}
