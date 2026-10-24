<?php
/*
 * Données d'exemple pour la démo (le76.fr/demo/personnel). Uniquement des personnes et heures fictives.
 * Tout est calculé par rapport à aujourd'hui, pour que la démo soit toujours « vivante ».
 */
declare(strict_types=1);

function demo_seed(PDO $pdo, bool $reset = false): void
{
    if ($reset) {
        foreach (['employes', 'pointages', 'planning', 'indispos', 'modeles', 'clotures', 'demandes', 'alertes_envoyees', 'tentatives'] as $t) {
            $pdo->exec("DELETE FROM $t");
        }
        $pdo->exec("DELETE FROM settings WHERE k NOT IN ('secret')");
        $pdo->exec("DELETE FROM sqlite_sequence");
    }
    mt_srand((int)date('Ymd'));
    $now = time();
    $today = date('Y-m-d');
    $set = $pdo->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)');
    $set->execute(['presets', "Midi;09:30;14:00\nSoir;18:00;21:00\nSamedi;17:30;01:00\nDimanche midi;11:00;14:00\nDimanche soir;18:00;21:00"]);
    $set->execute(['services_v1', '1']);
    $set->execute(['tolerance', '15']);
    $set->execute(['share_token', bin2hex(random_bytes(12))]);

    // Horaires types
    $m = ['09:30', '14:00'];
    $so = ['18:00', '21:00'];
    $resto = [1 => [], 2 => [$m], 3 => [$m], 4 => [$m, $so], 5 => [$m, $so], 6 => [['17:30', '01:00']], 7 => [['11:00', '14:00'], $so]];
    $set->execute(['horaires_resto', json_encode($resto)]);
    $cuisine = [1 => [], 2 => [['09:00', '14:00']], 3 => [['09:00', '14:00']], 4 => [['09:00', '14:00'], $so], 5 => [['09:00', '14:00'], $so], 6 => [['17:00', '01:00']], 7 => [['10:30', '14:00'], $so]];
    $camping = [1 => [['11:00', '14:00'], ['18:00', '22:00']], 2 => [], 3 => [['11:00', '14:00'], ['18:00', '22:00']], 4 => [['11:00', '14:00'], ['18:00', '22:00']], 5 => [['11:00', '14:00'], ['18:00', '22:00']], 6 => [['11:00', '14:00'], ['18:00', '23:00']], 7 => [['11:00', '14:00']]];

    // Équipe fictive : [prénom, nom, type, etabs, postes, salaire|taux, grille]
    $equipe = [
        ['Julien', 'Martin', 'fixe', 'le76', 'cuisine', 1900, $cuisine],
        ['Sophie', 'Keller', 'fixe', 'le76', 'salle,bar', 1800, $resto],
        ['Camille', 'Weber', 'fixe', 'le76', 'salle,runner', 1700, [1 => [], 2 => [$m], 3 => [], 4 => [$m, $so], 5 => [$m, $so], 6 => [['17:30', '01:00']], 7 => [['11:00', '14:00'], $so]]],
        ['Lucas', 'Muller', 'extra', 'le76', 'plonge', 12, null],
        ['Emma', 'Schmitt', 'extra', 'le76', 'salle,runner', 12.5, null],
        ['Thomas', 'Roth', 'fixe', 'camping', 'cuisine', 1850, $camping],
        ['Marie', 'Lang', 'fixe', 'le76,camping', 'salle,bar', 1750, [1 => [['11:00', '14:00']], 2 => [], 3 => [['11:00', '14:00']], 4 => [$so], 5 => [$so], 6 => [['17:30', '01:00']], 7 => []]],
        ['Léa', 'Fischer', 'extra', 'camping', 'bar', 12, null],
        ['Hugo', 'Klein', 'extra', 'camping', 'runner,salle', 12, null],
    ];
    $pins = ['1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999'];
    $ins = $pdo->prepare('INSERT INTO employes (prenom, nom, postes, type, etabs, pin, taux, salaire, heures_hebdo, horaires, token, actif, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $ids = [];
    foreach ($equipe as $i => [$p, $n, $type, $etabs, $postes, $paie, $grille]) {
        $g = $type === 'fixe' ? ($grille ?? $resto) : null;
        $hebdo = $g ? round(horaires_minutes($g) / 60, 2) : null;
        $ins->execute([$p, $n, $postes, $type, $etabs, $pins[$i], $type === 'extra' ? $paie : 12, $type === 'fixe' ? $paie : 1800, $hebdo, $g && $grille ? json_encode($g) : null, bin2hex(random_bytes(12)), date('Y-m-01 09:00:00', strtotime('-2 months'))]);
        $ids[$p] = (int)$pdo->lastInsertId();
    }

    // Planning : 3 semaines passées + cette semaine + 2 semaines à venir
    $lundi0 = date('Y-m-d', strtotime('monday this week', strtotime('-21 days')));
    $finP = date('Y-m-d', strtotime('monday this week +20 days'));
    $pl = $pdo->prepare('INSERT INTO planning (employe_id, etab, jour, debut, fin, preset, motif, note) VALUES (?,?,?,?,?,?,?,?)');
    $shifts = [];
    $add = static function (int $emp, string $etab, string $j, string $a, string $b, ?string $motif = null, ?string $note = null) use ($pl, $pdo, &$shifts) {
        $pl->execute([$emp, $etab, $j, $a, $b, preset_nom($a, $b), $motif, $note]);
        $shifts[] = ['id' => (int)$pdo->lastInsertId(), 'employe_id' => $emp, 'etab' => $etab, 'jour' => $j, 'debut' => $a, 'fin' => $b, 'motif' => $motif];
    };
    $grilles = [
        'Julien' => $cuisine, 'Sophie' => $resto, 'Camille' => $resto, 'Thomas' => $camping,
    ];
    for ($jj = date('Y-m-d', strtotime($lundi0)); $jj <= $finP; $jj = date('Y-m-d', strtotime($jj . ' +1 day'))) {
        $t = strtotime($jj); // jour par jour, sans se tromper au changement d'heure
        $j = date('Y-m-d', $t);
        $dow = (int)date('N', $t);
        foreach ($grilles as $p => $g) {
            $etab = $p === 'Thomas' ? 'camping' : 'le76';
            if ($p === 'Camille' && $dow === 3) continue; // Camille : repos le mercredi
            foreach ($g[$dow] as [$a, $b]) $add($ids[$p], $etab, $j, $a, $b);
        }
        // Marie : 3 jours au 76, 2 au camping
        if (in_array($dow, [4, 5], true)) $add($ids['Marie'], 'le76', $j, '18:00', '21:00');
        if ($dow === 6) $add($ids['Marie'], 'le76', $j, '17:30', '01:00');
        if (in_array($dow, [1, 3], true)) $add($ids['Marie'], 'camping', $j, '11:00', '14:00');
        // Extras du 76 : plonge et salle les gros services
        if (in_array($dow, [5, 7], true)) $add($ids['Lucas'], 'le76', $j, '18:00', '21:00');
        if ($dow === 6) $add($ids['Lucas'], 'le76', $j, '18:00', '00:30');
        if ($dow === 6) $add($ids['Emma'], 'le76', $j, '18:00', '23:30');
        if ($dow === 7) $add($ids['Emma'], 'le76', $j, '11:00', '14:00');
        // Extras du camping
        if (in_array($dow, [5, 6], true)) $add($ids['Léa'], 'camping', $j, '18:00', '23:00');
        if (in_array($dow, [6, 7], true)) $add($ids['Hugo'], 'camping', $j, '11:00', '14:00');
    }

    // Pointages réalistes pour ce qui est déjà passé (avec quelques écarts pour la démo)
    $pt = $pdo->prepare('INSERT INTO pointages (employe_id, etab, debut, fin, manuel) VALUES (?,?,?,?,?)');
    $motif = $pdo->prepare('UPDATE planning SET motif = ?, note = ? WHERE id = ?');
    $n = 0;
    foreach ($shifts as $s) {
        [$sa, $sb] = shift_bounds($s);
        if ($sa > $now) continue;
        $n++;
        $r = mt_rand(1, 100);
        // Quelques cas particuliers
        if ($n % 37 === 0 && $sb < $now) { $motif->execute(['malade', 'Arrêt de travail envoyé par SMS', $s['id']]); continue; }
        if ($n % 53 === 0 && $sb < $now) continue; // absent sans prévenir
        $arr = $sa + mt_rand(-8, 6) * 60;
        if ($r <= 8) $arr = $sa + mt_rand(18, 35) * 60;          // retard
        $dep = $sb + mt_rand(-5, 10) * 60;
        if ($r > 8 && $r <= 16) $dep = $sb + mt_rand(30, 75) * 60;   // dépassement (gros service)
        if ($r > 16 && $r <= 20) $dep = $sb - mt_rand(30, 60) * 60;  // parti plus tôt
        if ($sb > $now) {
            $pt->execute([$s['employe_id'], $s['etab'], date('Y-m-d H:i:s', min($arr, $now)), null, 0]); // en service maintenant
        } else {
            $pt->execute([$s['employe_id'], $s['etab'], date('Y-m-d H:i:s', $arr), date('Y-m-d H:i:s', $dep), 0]);
        }
    }
    // Un départ oublié il y a 2 jours et un pointage sans être au planning
    $j2 = date('Y-m-d', strtotime('-2 days'));
    $pt->execute([$ids['Hugo'], 'camping', $j2 . ' 18:05:00', null, 0]);
    $j3 = date('Y-m-d', strtotime('-3 days'));
    $pt->execute([$ids['Emma'], 'le76', $j3 . ' 18:00:00', $j3 . ' 21:10:00', 0]);
    // Un retard justifié
    $st = $pdo->prepare("SELECT id FROM planning WHERE employe_id = ? AND jour < ? ORDER BY jour DESC LIMIT 1");
    $st->execute([$ids['Sophie'], $today]);
    if ($sid = $st->fetchColumn()) $motif->execute(['retard_ok', 'Bouchons sur la D468', $sid]);

    // Indisponibilités et demandes de congé
    $ind = $pdo->prepare('INSERT OR IGNORE INTO indispos (employe_id, jour, note, created_at) VALUES (?,?,?,?)');
    $ind->execute([$ids['Lucas'], date('Y-m-d', strtotime('+8 days')), 'Examen', date('Y-m-d H:i:s')]);
    $ind->execute([$ids['Léa'], date('Y-m-d', strtotime('+5 days')), '', date('Y-m-d H:i:s')]);
    $dem = $pdo->prepare('INSERT INTO demandes (employe_id, du, au, type, note, statut, created_at, decided_at) VALUES (?,?,?,?,?,?,?,?)');
    $dem->execute([$ids['Emma'], date('Y-m-d', strtotime('+10 days')), date('Y-m-d', strtotime('+12 days')), 'conge', 'Mariage de ma cousine', 'attente', date('Y-m-d H:i:s', $now - 3600), null]);
    $dem->execute([$ids['Camille'], date('Y-m-d', strtotime('+15 days')), date('Y-m-d', strtotime('+16 days')), 'absence', 'Rendez-vous médical', 'attente', date('Y-m-d H:i:s', $now - 7200), null]);

    // Un modèle de semaine
    $data = [];
    foreach ($shifts as $s) {
        if ($s['etab'] === 'le76' && $s['jour'] >= $lundi0 && $s['jour'] <= date('Y-m-d', strtotime($lundi0 . ' +6 days'))) {
            $data[] = [(int)round((strtotime($s['jour']) - strtotime($lundi0)) / 86400), $s['employe_id'], $s['debut'], $s['fin']];
        }
    }
    $pdo->prepare('INSERT INTO modeles (nom, etab, data, created_at) VALUES (?,?,?,?)')->execute(['Semaine type hiver', 'le76', json_encode($data), date('Y-m-d H:i:s')]);
}
