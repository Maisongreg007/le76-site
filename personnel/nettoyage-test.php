<?php
/*
 * Nettoyage ponctuel de la phase de test (à supprimer après usage).
 * Accessible uniquement connecté en admin, en POST avec le jeton CSRF.
 * - sauvegarde la base
 * - fin des services : midi 14:00, soir 21:00, samedi 01:00 (grilles des fixes + horaires du restaurant)
 * - planning des fixes recalé sur leurs horaires habituels (sur les jours où ils étaient prévus)
 * - pointages passés des fixes recalés sur ce planning ; services en cours démarrés à l'heure prévue
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
if (!is_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
$dry = !empty($_POST['dry']);
$log = [];

$fin = static function (string $debut, int $dow): string {
    if ((int)substr($debut, 0, 2) < 16) return '14:00';
    return $dow === 6 ? '01:00' : '21:00';
};
$normalise = static function (array $h) use ($fin): array {
    $out = [];
    foreach ($h as $d => $slots) {
        $out[$d] = [];
        foreach ($slots as [$a, $b]) $out[$d][] = [$a, $fin($a, (int)$d)];
    }
    return $out;
};

$pdo = db();
if (!$dry) {
    $src = db_file();
    copy($src, DATA_DIR . '/sauvegarde-avant-nettoyage-' . date('Ymd-His') . '.sqlite');
    $log[] = 'Sauvegarde faite.';
}

$pdo->beginTransaction();

// 1. Horaires du restaurant + grilles des fixes
$resto = $normalise(horaires_resto());
if (!$dry) set_setting('horaires_resto', json_encode($resto));
$fixes = array_values(array_filter(employes(false), fn($e) => $e['type'] === 'fixe'));
$grilles = [];
foreach ($fixes as $e) {
    $g = $normalise(emp_horaires($e));
    $grilles[(int)$e['id']] = $g;
    if (!$dry) $pdo->prepare('UPDATE employes SET horaires = ?, heures_hebdo = ? WHERE id = ?')->execute([json_encode($g), round(horaires_minutes($g) / 60, 2), $e['id']]);
    $log[] = emp_nom($e) . ' : convenu ' . fmt_min(horaires_minutes($g)) . '/semaine';
}

// 2. Planning des fixes : chaque jour prévu reprend ses horaires habituels
$now = time();
$nbPl = 0;
foreach ($fixes as $e) {
    $id = (int)$e['id'];
    $st = $pdo->prepare('SELECT jour, etab FROM planning WHERE employe_id = ? GROUP BY jour, etab');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        $slots = $grilles[$id][(int)date('N', strtotime($r['jour']))] ?? [];
        if (!$slots) continue; // jour hors de ses horaires : on laisse tel quel
        if (!$dry) {
            $pdo->prepare('DELETE FROM planning WHERE employe_id = ? AND jour = ? AND etab = ?')->execute([$id, $r['jour'], $r['etab']]);
            foreach ($slots as [$a, $b]) {
                $pdo->prepare('INSERT INTO planning (employe_id, etab, jour, debut, fin, preset) VALUES (?,?,?,?,?,?)')->execute([$id, $r['etab'], $r['jour'], $a, $b, preset_nom($a, $b)]);
            }
        }
        $nbPl++;
    }
}
$log[] = $nbPl . ' journées de planning recalées.';

// 3. Pointages des fixes : services terminés = horaires prévus ; service en cours = arrivée à l'heure prévue
$nbPt = 0;
foreach ($fixes as $e) {
    $id = (int)$e['id'];
    $st = $pdo->prepare('SELECT DISTINCT substr(debut, 1, 10) j FROM pointages WHERE employe_id = ? AND debut >= ? ORDER BY j');
    $st->execute([$id, '2026-10-01 00:00:00']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $q = $pdo->prepare('SELECT * FROM planning WHERE employe_id = ? AND jour = ? ORDER BY debut');
        $q->execute([$id, $j]);
        $shifts = $q->fetchAll();
        if (!$shifts) continue;
        $q = $pdo->prepare('SELECT * FROM pointages WHERE employe_id = ? AND substr(debut, 1, 10) = ?');
        $q->execute([$id, $j]);
        $punches = $q->fetchAll();
        $ouvert = array_values(array_filter($punches, fn($p) => $p['fin'] === null));
        if (!$dry) {
            foreach ($punches as $p) if ($p['fin'] !== null) $pdo->prepare('DELETE FROM pointages WHERE id = ?')->execute([$p['id']]);
        }
        foreach ($shifts as $s) {
            [$sa, $sb] = shift_bounds($s);
            if ($sb <= $now) {
                if (!$dry) $pdo->prepare('INSERT INTO pointages (employe_id, etab, debut, fin, manuel) VALUES (?,?,?,?,1)')->execute([$id, $s['etab'], date('Y-m-d H:i:s', $sa), date('Y-m-d H:i:s', $sb)]);
                $nbPt++;
            } elseif ($sa <= $now && $ouvert) {
                if (!$dry) $pdo->prepare('UPDATE pointages SET debut = ?, etab = ?, manuel = 1 WHERE id = ?')->execute([date('Y-m-d H:i:s', $sa), $s['etab'], $ouvert[0]['id']]);
                array_shift($ouvert);
                $nbPt++;
            }
        }
        // pointages ouverts restants (sans service correspondant) : laissés tels quels
    }
}
$log[] = $nbPt . ' pointages recalés.';

if ($dry) $pdo->rollBack(); else $pdo->commit();
echo json_encode(['ok' => true, 'dry' => $dry, 'log' => $log], JSON_UNESCAPED_UNICODE);
