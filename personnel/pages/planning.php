<?php
declare(strict_types=1);

$etab = valid_etab($_GET['etab'] ?? $_POST['etab'] ?? null) ? (string)($_GET['etab'] ?? $_POST['etab']) : 'le76';
$lundi = valid_date($_GET['s'] ?? $_POST['s'] ?? null) ? monday_of((string)($_GET['s'] ?? $_POST['s'])) : monday_of(date('Y-m-d'));
$retour = 'admin.php?p=planning&etab=' . $etab . '&s=' . $lundi;

/** Un autre horaire de la même personne recouvre-t-il ce créneau ? (tous restaurants confondus) */
function chevauche_planning(int $emp, string $jour, string $d, string $f, int $saufId = 0): ?array
{
    [$a, $b] = shift_bounds(['jour' => $jour, 'debut' => $d, 'fin' => $f]);
    $st = db()->prepare('SELECT * FROM planning WHERE employe_id = ? AND jour BETWEEN ? AND ? AND id <> ?');
    $st->execute([$emp, date('Y-m-d', strtotime($jour . ' -1 day')), date('Y-m-d', strtotime($jour . ' +1 day')), $saufId]);
    foreach ($st->fetchAll() as $o) {
        [$oa, $ob] = shift_bounds($o);
        if ($a < $ob && $oa < $b) {
            return $o;
        }
    }
    return null;
}

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $emp = (int)($_POST['employe_id'] ?? 0);
        $jour = (string)($_POST['jour'] ?? '');
        $d = (string)($_POST['debut'] ?? '');
        $f = (string)($_POST['fin'] ?? '');
        if (!emp_get($emp) || !valid_date($jour) || !valid_time($d) || !valid_time($f)) {
            back($retour, '', 'Horaire invalide.');
        }
        if ($d === $f) {
            back($retour, '', 'Le début et la fin sont identiques.');
        }
        if ($o = chevauche_planning($emp, $jour, $d, $f, $id)) {
            back($retour, '', 'Impossible : cet horaire recouvre ' . $o['debut'] . '–' . $o['fin'] . ' déjà prévu' . ($o['etab'] !== $etab ? ' au ' . ETABS[$o['etab']] : '') . '. Modifie l\'autre horaire ou choisis d\'autres heures.');
        }
        if ($id) {
            db()->prepare('UPDATE planning SET debut=?, fin=? WHERE id=?')->execute([$d, $f, $id]);
        } else {
            db()->prepare('INSERT INTO planning (employe_id, etab, jour, debut, fin) VALUES (?,?,?,?,?)')->execute([$emp, $etab, $jour, $d, $f]);
        }
        redirect($retour);
    }

    if ($act === 'paste') {
        $emp = (int)($_POST['employe_id'] ?? 0);
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        $e = emp_get($emp);
        if (!$e || !is_array($items)) {
            redirect($retour);
        }
        $exists = db()->prepare('SELECT 1 FROM planning WHERE employe_id=? AND etab=? AND jour=? AND debut=? AND fin=?');
        $ins = db()->prepare('INSERT INTO planning (employe_id, etab, jour, debut, fin) VALUES (?,?,?,?,?)');
        foreach (array_slice($items, 0, 50) as $it) {
            [$j, $d, $f] = array_pad(is_array($it) ? array_values($it) : [], 3, null);
            if (!valid_date($j) || !valid_time($d) || !valid_time($f) || $d === $f) {
                continue;
            }
            $exists->execute([$emp, $etab, $j, $d, $f]);
            if (!$exists->fetchColumn() && !chevauche_planning($emp, $j, $d, $f)) {
                $ins->execute([$emp, $etab, $j, $d, $f]);
            }
        }
        redirect($retour);
    }

    if ($act === 'model_save') {
        $nom = trim((string)($_POST['nom'] ?? ''));
        if ($nom === '') back($retour, '', 'Donne un nom au modèle.');
        $fin = date('Y-m-d', strtotime($lundi . ' +6 days'));
        $st = db()->prepare('SELECT * FROM planning WHERE etab = ? AND jour BETWEEN ? AND ?');
        $st->execute([$etab, $lundi, $fin]);
        $data = [];
        foreach ($st->fetchAll() as $r) {
            $data[] = [(int)round((strtotime($r['jour']) - strtotime($lundi)) / 86400), (int)$r['employe_id'], $r['debut'], $r['fin']];
        }
        if (!$data) back($retour, '', 'Cette semaine est vide, rien à enregistrer.');
        db()->prepare('INSERT INTO modeles (nom, etab, data, created_at) VALUES (?,?,?,?)')->execute([mb_substr($nom, 0, 60), $etab, json_encode($data), date('Y-m-d H:i:s')]);
        back($retour, 'Modèle « ' . $nom . ' » enregistré (' . count($data) . ' horaires).');
    }
    if ($act === 'model_apply' || $act === 'model_delete') {
        $st = db()->prepare('SELECT * FROM modeles WHERE id = ?');
        $st->execute([(int)($_POST['mid'] ?? 0)]);
        $m = $st->fetch();
        if (!$m) redirect($retour);
        if ($act === 'model_delete') {
            db()->prepare('DELETE FROM modeles WHERE id = ?')->execute([$m['id']]);
            back($retour, 'Modèle supprimé.');
        }
        $exists = db()->prepare('SELECT 1 FROM planning WHERE employe_id=? AND etab=? AND jour=? AND debut=? AND fin=?');
        $ins = db()->prepare('INSERT INTO planning (employe_id, etab, jour, debut, fin) VALUES (?,?,?,?,?)');
        $n = 0;
        foreach (json_decode($m['data'], true) ?: [] as [$off, $emp, $d, $f]) {
            $e = emp_get((int)$emp);
            if (!$e || !(int)$e['actif']) continue;
            $j = date('Y-m-d', strtotime($lundi . ' +' . (int)$off . ' days'));
            $exists->execute([$emp, $etab, $j, $d, $f]);
            if (!$exists->fetchColumn() && !chevauche_planning((int)$emp, $j, $d, $f)) { $ins->execute([$emp, $etab, $j, $d, $f]); $n++; }
        }
        back($retour, 'Modèle « ' . $m['nom'] . ' » appliqué : ' . $n . ' horaires ajoutés.');
    }

    if ($act === 'reel') {
        // Ce qui s'est réellement passé sur un service
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM planning WHERE id = ?');
        $st->execute([$id]);
        $sh = $st->fetch();
        if (!$sh) {
            redirect($retour);
        }
        if (mois_cloture(substr($sh['jour'], 0, 7))) {
            back($retour, '', 'Ce mois est clôturé : rouvre-le dans Heures pour modifier.');
        }
        $mode = (string)($_POST['mode'] ?? '');
        $j0 = date('Y-m-d', strtotime($sh['jour'] . ' -1 day'));
        $j1 = date('Y-m-d', strtotime($sh['jour'] . ' +1 day'));
        $c = comparer($j0, $j1, $sh['etab'], (int)$sh['employe_id'])['shifts'][$id] ?? null;
        $ps = $c['punches'] ?? [];
        [$sa, $sb] = shift_bounds($sh);
        $fmt = static fn(int $t) => date('Y-m-d H:i:s', $t);
        $now = time();
        $nom = ($e = emp_get((int)$sh['employe_id'])) ? $e['prenom'] : '';
        $del = db()->prepare('DELETE FROM pointages WHERE id = ?');
        $ins = db()->prepare('INSERT INTO pointages (employe_id, etab, debut, fin, manuel) VALUES (?,?,?,?,1)');
        $upd = db()->prepare('UPDATE pointages SET debut = ?, fin = ?, manuel = 1 WHERE id = ?');
        $setMotif = db()->prepare('UPDATE planning SET motif = ? WHERE id = ?');
        $msg = 'Enregistré.';
        $idsLies = array_map(fn($p) => (int)$p['id'], $ps);
        $conflit = static function (int $a, ?int $b) use ($sh, $idsLies): ?array {
            $st = db()->prepare('SELECT * FROM pointages WHERE employe_id = ? AND debut < ? AND (fin IS NULL OR fin > ?)');
            $st->execute([$sh['employe_id'], date('Y-m-d H:i:s', $b ?? ($a + 60)), date('Y-m-d H:i:s', $a)]);
            foreach ($st->fetchAll() as $p) if (!in_array((int)$p['id'], $idsLies, true)) return $p;
            return null;
        };
        if (in_array($mode, ['prevu', 'arrivee', 'depart'], true) && ($cp = $conflit($sa, $sb))) {
            back($retour, '', 'Impossible : il y a déjà un pointage ' . date('H:i', strtotime($cp['debut'])) . '–' . ($cp['fin'] ? date('H:i', strtotime($cp['fin'])) : '…') . ' qui recouvre cet horaire (deux horaires se chevauchent ?). Corrige-le d\'abord dans Heures.');
        }

        if ($mode === 'prevu') {
            foreach ($ps as $p) $del->execute([$p['id']]);
            $ins->execute([$sh['employe_id'], $sh['etab'], $fmt($sa), $sb <= $now ? $fmt($sb) : null]);
            $setMotif->execute([null, $id]);
            $msg = $nom . ' : présent·e comme prévu (' . $sh['debut'] . '–' . $sh['fin'] . ').';
        } elseif ($mode === 'arrivee') {
            if ($ps) {
                $first = $ps[0];
                foreach ($ps as $p) if ($p['debut'] < $first['debut']) $first = $p;
                $upd->execute([$fmt($sa), $first['fin'], $first['id']]);
            } else {
                $ins->execute([$sh['employe_id'], $sh['etab'], $fmt($sa), $sb <= $now ? $fmt($sb) : null]);
            }
            $msg = $nom . ' : arrivée corrigée à ' . $sh['debut'] . '.';
        } elseif ($mode === 'depart') {
            if ($ps) {
                $last = $ps[0];
                foreach ($ps as $p) if ($p['debut'] > $last['debut']) $last = $p;
                $upd->execute([$last['debut'], $fmt($sb), $last['id']]);
            } else {
                $ins->execute([$sh['employe_id'], $sh['etab'], $fmt($sa), $fmt($sb)]);
            }
            $msg = $nom . ' : départ corrigé à ' . $sh['fin'] . '.';
        } elseif ($mode === 'heures') {
            $a = (string)($_POST['ra'] ?? '');
            $b = (string)($_POST['rb'] ?? '');
            if (!valid_time($a) || ($b !== '' && !valid_time($b))) {
                back($retour, '', 'Heures invalides.');
            }
            // Arrivée le jour prévu (ou le lendemain si service de nuit et heure du matin)
            $ta = strtotime($sh['jour'] . ' ' . $a);
            if ((int)substr($sh['debut'], 0, 2) >= 12 && (int)substr($a, 0, 2) < 6) $ta += 86400;
            $tb = $b !== '' ? strtotime($sh['jour'] . ' ' . $b) : null;
            if ($tb !== null && $tb <= $ta) $tb += 86400;
            foreach ($ps as $p) $del->execute([$p['id']]);
            $ins->execute([$sh['employe_id'], $sh['etab'], $fmt($ta), $tb ? $fmt($tb) : null]);
            $setMotif->execute([null, $id]);
            $msg = $nom . ' : heures réelles ' . $a . '–' . ($b ?: '…') . ' enregistrées.';
        } elseif (isset(MOTIFS[$mode])) {
            if (in_array($mode, ['malade', 'conge', 'absent'], true)) {
                foreach ($ps as $p) $del->execute([$p['id']]);
            }
            $setMotif->execute([$mode, $id]);
            $msg = $nom . ' : ' . mb_strtolower(MOTIFS[$mode]) . '.';
        } elseif ($mode === 'reset') {
            $setMotif->execute([null, $id]);
            $msg = 'Motif effacé.';
        }
        if (isset($_POST['note'])) {
            db()->prepare('UPDATE planning SET note = ? WHERE id = ?')->execute([mb_substr(trim((string)$_POST['note']), 0, 200) ?: null, $id]);
        }
        back($retour, $msg);
    }

    if ($act === 'delete') {
        db()->prepare('DELETE FROM planning WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        redirect($retour);
    }

    if ($act === 'copy') {
        $prev = date('Y-m-d', strtotime($lundi . ' -7 days'));
        $prevFin = date('Y-m-d', strtotime($lundi . ' -1 day'));
        $fin = date('Y-m-d', strtotime($lundi . ' +6 days'));
        $st = db()->prepare('SELECT * FROM planning WHERE etab = ? AND jour BETWEEN ? AND ?');
        $st->execute([$etab, $prev, $prevFin]);
        $n = 0;
        $exists = db()->prepare('SELECT 1 FROM planning WHERE employe_id=? AND etab=? AND jour=? AND debut=? AND fin=?');
        $ins = db()->prepare('INSERT INTO planning (employe_id, etab, jour, debut, fin) VALUES (?,?,?,?,?)');
        foreach ($st->fetchAll() as $r) {
            $nouveau = date('Y-m-d', strtotime($r['jour'] . ' +7 days'));
            $exists->execute([$r['employe_id'], $etab, $nouveau, $r['debut'], $r['fin']]);
            if (!$exists->fetchColumn() && !chevauche_planning((int)$r['employe_id'], $nouveau, $r['debut'], $r['fin'])) {
                $ins->execute([$r['employe_id'], $etab, $nouveau, $r['debut'], $r['fin']]);
                $n++;
            }
        }
        back($retour, $n ? "$n horaires copiés depuis la semaine précédente." : 'Rien à copier (la semaine précédente est vide ou déjà copiée).');
    }
    redirect($retour);
}

/* ---------- Affichage ---------- */
$fin = date('Y-m-d', strtotime($lundi . ' +6 days'));
$emps = employes(true, $etab);
usort($emps, fn($a, $b) => [poste_rang($a), $a['prenom'], $a['nom']] <=> [poste_rang($b), $b['prenom'], $b['nom']]);

$st = db()->prepare('SELECT * FROM planning WHERE etab = ? AND jour BETWEEN ? AND ? ORDER BY debut');
$st->execute([$etab, $lundi, $fin]);
$shifts = [];
foreach ($st->fetchAll() as $r) {
    $shifts[(int)$r['employe_id']][$r['jour']][] = $r;
}
// Prévu / réel pour la semaine affichée
$cmp = comparer($lundi, $fin, $etab);
$npParCase = [];
foreach ($cmp['np'] as $p) {
    $npParCase[(int)$p['employe_id']][substr($p['debut'], 0, 10)][] = $p;
}

page_head('Planning');
admin_nav('planning');
flash_show();

$prevS = date('Y-m-d', strtotime($lundi . ' -7 days'));
$nextS = date('Y-m-d', strtotime($lundi . ' +7 days'));
$titre = 'Semaine du ' . (int)date('j', strtotime($lundi)) . ' ' . MOIS[(int)date('n', strtotime($lundi)) - 1] . ' au '
    . (int)date('j', strtotime($fin)) . ' ' . MOIS[(int)date('n', strtotime($fin)) - 1];

echo '<div class="row between" style="margin-bottom:12px"><h1 style="margin:0">Planning</h1><div class="tabs">';
foreach (ETABS as $k => $l) {
    echo '<a href="admin.php?p=planning&etab=' . h($k) . '&s=' . h($lundi) . '"' . ($k === $etab ? ' class="on"' : '') . '>' . h($l) . '</a>';
}
echo '</div></div>';

$titreCourt = (int)date('j', strtotime($lundi)) . ' – ' . (int)date('j', strtotime($fin)) . ' ' . MOIS[(int)date('n', strtotime($fin)) - 1];
echo '<div class="toolbar"><div class="weeknav">'
    . '<a class="btn round" aria-label="Semaine précédente" href="admin.php?p=planning&etab=' . h($etab) . '&s=' . h($prevS) . '">‹</a>'
    . '<b><span class="hide-m">' . h($titre) . '</span><span class="show-m">' . h($titreCourt) . '</span></b>'
    . '<a class="btn round" aria-label="Semaine suivante" href="admin.php?p=planning&etab=' . h($etab) . '&s=' . h($nextS) . '">›</a>'
    . ($lundi !== monday_of(date('Y-m-d')) ? '<a class="btn small" href="admin.php?p=planning&etab=' . h($etab) . '">Aujourd\'hui</a>' : '')
    . '</div><div class="row">'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="copy"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '">'
    . '<button class="small">Copier la semaine d\'avant</button></form>'
    . '<button type="button" class="small" id="btn-models">Modèles</button>'
    . '<button type="button" class="small primary" id="btn-share">Partager</button></div></div>';

if (!$emps) {
    echo '<div class="card"><p class="muted">Personne n\'est rattaché à ' . h(ETABS[$etab]) . ' pour l\'instant.</p><a class="btn primary" href="admin.php?p=equipe&e=new">Ajouter quelqu\'un</a></div>';
    admin_end();
    exit;
}

$today = date('Y-m-d');
$indispos = indispos_entre($lundi, $fin);
// Coût prévu de la semaine
$hPrevu = 0; $hExtras = 0; $coutExtras = 0.0; $hFixes = 0;
foreach ($emps as $e) {
    $m = 0;
    foreach ($shifts[(int)$e['id']] ?? [] as $liste) foreach ($liste as $sh) $m += shift_minutes($sh['debut'], $sh['fin']);
    $hPrevu += $m;
    if ($e['type'] === 'extra') { $hExtras += $m; $coutExtras += $m / 60 * (float)$e['taux']; } else { $hFixes += $m; }
}
echo '<div class="stats week-stats">'
    . '<div class="stat"><b>' . h(fmt_min($hPrevu)) . '</b><span>Heures prévues</span></div>'
    . '<div class="stat"><b>' . h(eur(round($coutExtras, 2))) . '</b><span>Coût prévu des extras (' . h(fmt_min($hExtras)) . ')</span></div>'
    . '<div class="stat"><b>' . h(fmt_min($hFixes)) . '</b><span>Heures des fixes (salaire mensuel)</span></div></div>';

echo '<div class="card scroll" style="padding:8px"><table class="plan"><thead><tr><th class="emp">&nbsp;</th>';
for ($i = 0; $i < 7; $i++) {
    $j = date('Y-m-d', strtotime($lundi . " +$i days"));
    echo '<th class="' . ($j === $today ? 'today' : '') . '">' . JOURS[$i] . ' ' . (int)date('j', strtotime($j)) . '</th>';
}
echo '<th>Total</th></tr></thead><tbody>';

foreach ($emps as $e) {
    $id = (int)$e['id'];
    $tot = 0;
    $semaine = [];
    for ($i = 0; $i < 7; $i++) {
        $j = date('Y-m-d', strtotime($lundi . " +$i days"));
        foreach ($shifts[$id][$j] ?? [] as $s) {
            $semaine[] = [$i, $s['debut'], $s['fin']];
        }
    }
    echo '<tr data-emp="' . $id . '"><td class="emp">' . h(emp_nom($e)) . '<div style="margin-top:4px">' . postes_html($e) . ' <span class="pill ' . h($e['type']) . '">' . ($e['type'] === 'fixe' ? 'fixe' : 'extra') . '</span></div>'
        . ($semaine ? '<button type="button" class="copy-week" data-nom="' . h(emp_nom($e)) . '" data-week="' . h(json_encode($semaine)) . '">Copier sa semaine</button>' : '')
        . '</td>';
    for ($i = 0; $i < 7; $i++) {
        $j = date('Y-m-d', strtotime($lundi . " +$i days"));
        $ind = $indispos[$id][$j] ?? null;
        echo '<td class="day ' . ($j === $today ? 'today' : '') . ($ind !== null ? ' indispo' : '') . '" data-emp="' . $id . '" data-jour="' . h($j) . '">';
        if ($ind !== null) {
            echo '<span class="indispo-tag" title="' . h($ind !== '' ? $ind : 'Pas disponible') . '">pas dispo' . ($ind !== '' ? ' · ' . h(mb_strimwidth($ind, 0, 18, '…')) : '') . '</span>';
        }
        foreach ($shifts[$id][$j] ?? [] as $s) {
            $tot += shift_minutes($s['debut'], $s['fin']);
            $res = $cmp['shifts'][(int)$s['id']] ?? null;
            $st = $res['statut'] ?? 'avenir';
            $reel = '';
            if ($res) {
                $hr = static fn(?int $t) => $t ? date('H:i', $t) : '…';
                $reel = match ($st) {
                    'ok' => '✓ ' . $hr($res['debut_reel']) . '–' . $hr($res['fin_reel']),
                    'plus', 'moins' => $hr($res['debut_reel']) . '–' . $hr($res['fin_reel']) . ' · ' . fmt_ecart($res['ecart']),
                    'encours' => 'arrivé·e ' . $hr($res['debut_reel']) . ($res['retard'] > tolerance() ? ' (+' . fmt_min($res['retard']) . ')' : ''),
                    'absent' => $res['motif'] === 'absent' ? 'absent·e sans prévenir' : 'pas venu·e',
                    'attendu' => 'pas encore arrivé·e',
                    'excuse' => mb_strtolower(explode(' /', MOTIFS[$res['motif']] ?? 'excusé')[0]),
                    default => '',
                };
            }
            $etatTxt = $res ? (['avenir' => 'à venir', 'attendu' => 'pas encore arrivé·e', 'absent' => 'absent·e', 'encours' => 'en service', 'ok' => 'fait comme prévu', 'plus' => 'a fait plus', 'moins' => 'a fait moins', 'excuse' => MOTIFS[$res['motif']] ?? ''][$st] ?? '') : '';
            if ($res && $res['motif'] && isset(MOTIFS[$res['motif']]) && $st !== 'excuse') $etatTxt .= ' · ' . MOTIFS[$res['motif']];
            echo '<button type="button" class="chip st-' . $st . '" data-id="' . (int)$s['id'] . '" data-emp="' . $id . '" data-jour="' . h($j) . '" data-debut="' . h($s['debut']) . '" data-fin="' . h($s['fin']) . '" data-nom="' . h(emp_nom($e)) . '"'
                . ' data-started="' . ($res && time() >= $res['sa'] - 3600 ? '1' : '') . '" data-etat="' . h($etatTxt) . '" data-motif="' . h((string)($res['motif'] ?? '')) . '" data-note="' . h((string)($s['note'] ?? '')) . '"'
                . ' data-ra="' . h($res && $res['debut_reel'] ? date('H:i', $res['debut_reel']) : '') . '" data-rb="' . h($res && $res['fin_reel'] ? date('H:i', $res['fin_reel']) : '') . '"'
                . ($s['note'] ? ' title="' . h($s['note']) . '"' : '') . '>'
                . h($s['debut']) . '–' . h($s['fin']) . ($reel !== '' ? '<span class="reel">' . h($reel) . '</span>' : '') . '</button>';
        }
        foreach ($npParCase[$id][$j] ?? [] as $p) {
            echo '<span class="chip st-np" title="Pointé sans être au planning">non prévu<span class="reel">' . h(date('H:i', strtotime($p['debut']))) . '–' . ($p['fin'] ? h(date('H:i', strtotime($p['fin']))) : '…') . '</span></span>';
        }
        echo '<button type="button" class="chip add" data-id="0" data-emp="' . $id . '" data-jour="' . h($j) . '" data-nom="' . h(emp_nom($e)) . '" aria-label="Ajouter">+</button></td>';
    }
    echo '<td class="tot">' . ($tot ? h(fmt_min($tot)) . ($e['type'] === 'extra' ? '<div class="muted small" style="font-weight:500">' . h(eur(round($tot / 60 * (float)$e['taux'], 2))) . '</div>' : '') : '<span class="muted">–</span>') . '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="legend"><span><i style="background:var(--accent)"></i>prévu</span><span><i style="background:var(--ok)"></i>fait comme prévu</span>'
    . '<span><i style="background:var(--warn)"></i>plus ou moins que prévu (au-delà de ' . tolerance() . ' min)</span><span><i style="background:var(--err)"></i>absent / pas encore arrivé</span>'
    . '<span><i style="box-shadow:inset 0 0 0 1.2px var(--muted)"></i>pointé sans être prévu</span></div>';
echo '<p class="muted small">Touche une case pour ajouter ou modifier un horaire. Pour aller vite : touche un horaire puis « Copier », ou « Copier sa semaine » sous un nom, et colle où tu veux. Une fin avant le début = le service passe minuit.</p>';
echo '<div class="pastebar" id="pastebar" hidden><span id="paste-txt"></span><button type="button" class="small" id="paste-stop">Terminé</button></div>';

/* Fenêtre de saisie */
echo '<dialog id="dlg"><form method="post" id="f">' . csrf_field()
    . '<input type="hidden" name="action" value="save"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '">'
    . '<input type="hidden" name="id" id="d-id"><input type="hidden" name="employe_id" id="d-emp"><input type="hidden" name="jour" id="d-jour">'
    . '<h3 id="d-titre"></h3><div class="presets" id="d-presets">';
foreach (presets() as [$n, $a, $b]) {
    echo '<button type="button" data-a="' . h($a) . '" data-b="' . h($b) . '">' . h($n) . ' <span class="muted small">' . h($a) . '–' . h($b) . '</span></button>';
}
echo '</div><div class="row"><div class="field"><label>De</label><input type="time" name="debut" id="d-debut" required></div>'
    . '<div class="field"><label>À</label><input type="time" name="fin" id="d-fin" required></div></div>'
    . '<div class="row between"><div class="row"><button class="primary">Enregistrer</button><button type="button" id="d-annuler">Annuler</button></div>'
    . '<div class="row"><button type="button" id="d-copy">Copier</button><button type="button" class="danger" id="d-suppr" data-confirm="Sûr ?">Supprimer</button></div></div></form>'
    . '<form method="post" id="freel" class="reel-box">' . csrf_field() . '<input type="hidden" name="action" value="reel"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '"><input type="hidden" name="id" id="r-id">'
    . '<div class="reel-head"><b>Ce qui s\'est passé</b><span class="muted small" id="r-etat"></span></div>'
    . '<div class="actgrid">'
    . '<button name="mode" value="prevu" class="act ok"><span>✓</span>Présent·e comme prévu<small>oubli de pointage</small></button>'
    . '<button name="mode" value="arrivee" class="act"><span>→</span>Arrivé·e à l\'heure</button>'
    . '<button name="mode" value="depart" class="act"><span>←</span>Parti·e à l\'heure</button>'
    . '<button name="mode" value="retard_ok" class="act"><span>⏱</span>Retard justifié</button>'
    . '<button name="mode" value="malade" class="act warn"><span>✚</span>Malade</button>'
    . '<button name="mode" value="conge" class="act warn"><span>☀</span>Congé / justifié</button>'
    . '<button name="mode" value="absent" class="act err"><span>✕</span>Absent·e sans prévenir</button>'
    . '<button name="mode" value="reset" class="act ghost" id="r-reset"><span>↺</span>Effacer le motif</button>'
    . '</div>'
    . '<div class="reel-hours"><div><label>Arrivée réelle</label><input type="time" name="ra" id="r-a"></div><div><label>Départ réel</label><input type="time" name="rb" id="r-b"></div>'
    . '<button name="mode" value="heures" class="primary small">Enregistrer ces heures</button></div>'
    . '<div class="field" style="margin:10px 0 0"><label>Note (facultatif)</label><input name="note" id="r-note" maxlength="200" placeholder="ex. prévenu par SMS, échange avec Paul…" style="width:100%"></div>'
    . '</form>'
    . '<form method="post" id="fdel">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '"><input type="hidden" name="id" id="del-id"></form></dialog>';
echo '<form method="post" id="fpaste" hidden>' . csrf_field() . '<input type="hidden" name="action" value="paste"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '"><input type="hidden" name="employe_id" id="p-emp"><input type="hidden" name="items" id="p-items"></form>';

$st = db()->prepare('SELECT * FROM modeles WHERE etab = ? ORDER BY nom COLLATE NOCASE');
$st->execute([$etab]);
$modeles = $st->fetchAll();
$hid = csrf_field() . '<input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '">';
echo '<dialog id="dmodels"><h3>Plannings types · ' . h(ETABS[$etab]) . '</h3>'
    . '<p class="muted small" style="text-align:center;margin-top:-6px">Enregistre une semaine type (été, hiver, soirée à thème…) et applique-la en un clic sur n\'importe quelle semaine.</p>';
if ($modeles) {
    echo '<ul class="list">';
    foreach ($modeles as $m) {
        $nb = count(json_decode($m['data'], true) ?: []);
        echo '<li><span><b>' . h($m['nom']) . '</b> <span class="muted small">' . $nb . ' horaires</span></span><span class="row">'
            . '<form method="post">' . $hid . '<input type="hidden" name="action" value="model_apply"><input type="hidden" name="mid" value="' . (int)$m['id'] . '"><button class="small primary">Appliquer</button></form>'
            . '<form method="post">' . $hid . '<input type="hidden" name="action" value="model_delete"><input type="hidden" name="mid" value="' . (int)$m['id'] . '"><button class="small danger" data-confirm="Sûr ?">Supprimer</button></form>'
            . '</span></li>';
    }
    echo '</ul>';
} else {
    echo '<p class="muted" style="text-align:center">Aucun modèle pour l\'instant.</p>';
}
echo '<form method="post" class="row" style="margin-top:12px">' . $hid . '<input type="hidden" name="action" value="model_save">'
    . '<input name="nom" placeholder="Nom (ex. Semaine été)" maxlength="60" required style="flex:1;min-width:160px"><button class="small">Enregistrer cette semaine</button></form>'
    . '<p style="margin:14px 0 0;text-align:center"><button type="button" id="models-close">Fermer</button></p></dialog>';

$lienEquipe = base_url() . 'planning-equipe.php?t=' . share_token() . '&etab=' . $etab;
$msgEquipe = 'Le planning du ' . ETABS[$etab] . ' : ' . $lienEquipe;
echo '<dialog id="dshare"><h3>Partager le planning</h3>'
    . '<p class="muted small">Un seul lien pour toute l\'équipe : chacun voit le planning de la semaine, jour par jour, sur son téléphone. Il se met à jour tout seul quand tu modifies quelque chose. Personne ne peut rien changer.</p>'
    . '<div class="linkbox" id="share-link">' . h($lienEquipe) . '</div>'
    . '<div class="row" style="margin-top:12px">'
    . '<a class="btn primary" target="_blank" rel="noopener" href="https://wa.me/?text=' . rawurlencode($msgEquipe) . '">Envoyer sur WhatsApp</a>'
    . '<button type="button" id="share-copy">Copier le lien</button>'
    . '<button type="button" id="share-print">Imprimer / PDF</button></div>'
    . '<p class="muted small">Chaque employé a aussi son lien perso (ses horaires seulement), dans sa fiche Équipe.</p>'
    . '<p style="margin:0"><button type="button" id="share-close">Fermer</button></p></dialog>';
?>
<script>
(function () {
  const dlg = document.getElementById('dlg');
  const $ = id => document.getElementById(id);
  const JL = <?= json_encode(JOURS_LONG) ?>;
  const JC = <?= json_encode(JOURS) ?>;
  const LUNDI = <?= json_encode($lundi) ?>;
  // Presse-papiers gardé dans l'adresse (#copie=...) : survit au rechargement et au changement de semaine
  let clip = null;
  try { const m = location.hash.match(/^#copie=(.+)$/); if (m) clip = JSON.parse(decodeURIComponent(m[1])); } catch (e) { clip = null; }
  const fmt = l => l.map(s => s[1] + '–' + s[2]).join(', ');
  function setClip(c) {
    clip = c;
    if (c) history.replaceState(null, '', '#copie=' + encodeURIComponent(JSON.stringify(c)));
    else history.replaceState(null, '', location.pathname + location.search);
    showBar();
  }
  function showBar() {
    const bar = $('pastebar');
    document.body.classList.toggle('pasting', !!clip);
    document.body.classList.toggle('pasting-week', !!clip && clip.t === 'semaine');
    if (!clip) { bar.hidden = true; return; }
    bar.hidden = false;
    $('paste-txt').textContent = clip.t === 'jour'
      ? 'Copié : ' + fmt(clip.l) + ' — touche les cases où le coller'
      : 'Semaine de ' + clip.nom + ' copiée — touche la ligne de la personne où la coller (tu peux changer de semaine)';
    document.querySelectorAll('a[href*="p=planning"]').forEach(a => {
      if (a.href) a.href = a.href.split('#')[0] + location.hash;
    });
  }
  function paste(emp, items) {
    if (!items.length) return;
    $('p-emp').value = emp; $('p-items').value = JSON.stringify(items);
    const fd = new URLSearchParams(new FormData($('fpaste')));
    fetch('admin.php?p=planning', {method: 'POST', body: fd}).then(() => location.reload());
  }
  function addDays(d, n) { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); }
  document.querySelector('.plan').addEventListener('click', e => {
    if (!clip) return;
    const cell = e.target.closest('td.day, tr[data-emp]');
    if (!cell) return;
    e.preventDefault(); e.stopPropagation();
    if (clip.t === 'jour') {
      const td = e.target.closest('td.day'); if (!td) return;
      paste(td.dataset.emp, clip.l.map(s => [td.dataset.jour, s[1], s[2]]));
    } else {
      const tr = e.target.closest('tr[data-emp]'); if (!tr) return;
      paste(tr.dataset.emp, clip.l.map(s => [addDays(LUNDI, s[0]), s[1], s[2]]));
    }
  }, true);
  $('paste-stop').addEventListener('click', () => setClip(null));
  document.querySelectorAll('.copy-week').forEach(b => b.addEventListener('click', e => {
    e.stopPropagation();
    setClip({t: 'semaine', nom: b.dataset.nom, l: JSON.parse(b.dataset.week)});
  }));
  $('d-copy').addEventListener('click', () => {
    const td = document.querySelector('td.day[data-emp="' + $('d-emp').value + '"][data-jour="' + $('d-jour').value + '"]');
    const l = [...td.querySelectorAll('button.chip:not(.add)')].map(c => [0, c.dataset.debut, c.dataset.fin]);
    dlg.close();
    setClip({t: 'jour', l});
  });
  showBar();

  $('btn-share').addEventListener('click', () => $('dshare').showModal());
  $('btn-models').addEventListener('click', () => $('dmodels').showModal());
  $('models-close').addEventListener('click', () => $('dmodels').close());
  $('share-close').addEventListener('click', () => $('dshare').close());
  $('share-print').addEventListener('click', () => { $('dshare').close(); setTimeout(() => window.print(), 150); });
  $('share-copy').addEventListener('click', async () => {
    const t = $('share-link').textContent;
    try { await navigator.clipboard.writeText(t); $('share-copy').textContent = 'Copié !'; }
    catch (e) { const r = document.createRange(); r.selectNodeContents($('share-link')); getSelection().removeAllRanges(); getSelection().addRange(r); }
  });

  document.querySelectorAll('.plan button.chip').forEach(b => b.addEventListener('click', () => {
    if (clip) return;
    const id = b.dataset.id, d = new Date(b.dataset.jour + 'T12:00:00');
    $('d-id').value = id; $('d-emp').value = b.dataset.emp; $('d-jour').value = b.dataset.jour;
    $('d-titre').textContent = b.dataset.nom + ' — ' + JL[(d.getDay() + 6) % 7] + ' ' + d.getDate() + '/' + (d.getMonth() + 1);
    $('d-debut').value = id !== '0' ? b.dataset.debut : '';
    $('d-fin').value = id !== '0' ? b.dataset.fin : '';
    $('d-suppr').style.display = id !== '0' ? '' : 'none';
    $('d-copy').style.display = id !== '0' ? '' : 'none';
    const started = id !== '0' && b.dataset.started === '1';
    $('freel').style.display = started ? '' : 'none';
    if (started) {
      $('r-id').value = id;
      $('r-etat').textContent = b.dataset.etat || '';
      $('r-a').value = b.dataset.ra || b.dataset.debut;
      $('r-b').value = b.dataset.rb || (b.dataset.ra ? '' : b.dataset.fin);
      $('r-note').value = b.dataset.note || '';
      $('r-reset').style.display = b.dataset.motif ? '' : 'none';
      $('freel').querySelectorAll('button[name=mode]').forEach(x => x.classList.toggle('on', x.value === b.dataset.motif));
    }
    $('d-presets').style.display = id !== '0' ? 'none' : '';
    $('del-id').value = id;
    dlg.showModal();
  }));
  $('d-presets').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    $('d-debut').value = b.dataset.a; $('d-fin').value = b.dataset.b;
    $('f').requestSubmit();
  });
  $('d-annuler').addEventListener('click', () => dlg.close());
  $('d-suppr').addEventListener('click', () => $('fdel').submit());
  dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
})();
</script>
<?php
admin_end();
