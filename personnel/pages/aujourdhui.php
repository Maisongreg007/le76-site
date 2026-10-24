<?php
declare(strict_types=1);

$today = date('Y-m-d');
$nowSql = date('Y-m-d H:i:s');
$seuil = date('Y-m-d H:i:s', time() - OUBLI_HEURES * 3600);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'conge_ok' || $act === 'conge_ko') {
        $d = decider_demande((int)($_POST['id'] ?? 0), $act === 'conge_ok');
        back('admin.php?p=aujourdhui', $d ? ($act === 'conge_ok' ? 'Congé accepté : les jours sont bloqués dans le planning.' : 'Demande refusée.') : '');
    }
    redirect('admin.php?p=aujourdhui');
}

$frag = isset($_GET['frag']);
try { verifier_alertes(); } catch (Throwable $t) { }
if ($frag) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
} else {
    page_head("Aujourd'hui");
    admin_nav('aujourdhui');
    flash_show();
    echo '<div class="row between"><div class="titlebar"><h1>' . h(ucfirst(date_fr($today))) . '</h1><span class="clock-sm" id="clk">' . date('H:i') . '</span></div>'
        . '<span class="live-dot" id="live-state"><i></i> En direct</span></div><div id="live">';
}

/* Demandes de congé à valider */
$dem = demandes_en_attente();
if ($dem) {
    echo '<div class="card" style="box-shadow:inset 0 0 0 1.5px var(--warn)"><h3>Demandes de congé <span class="pill warn">' . count($dem) . '</span></h3><ul class="list">';
    foreach ($dem as $d) {
        $nbj = (int)round((strtotime($d['au']) - strtotime($d['du'])) / 86400) + 1;
        $st = db()->prepare('SELECT COUNT(*) FROM planning WHERE employe_id = ? AND jour BETWEEN ? AND ?');
        $st->execute([$d['employe_id'], $d['du'], $d['au']]);
        $nbs = (int)$st->fetchColumn();
        echo '<li><span><b>' . h(trim($d['prenom'] . ' ' . $d['nom'])) . '</b> · ' . h(TYPES_CONGE[$d['type']] ?? '') . '<br><span class="muted small">'
            . h(ucfirst(date_fr($d['du']))) . ($d['au'] !== $d['du'] ? ' → ' . h(date_fr($d['au'])) : '') . ' · ' . $nbj . ' jour' . ($nbj > 1 ? 's' : '')
            . ($nbs ? ' · <b>' . $nbs . ' service(s) prévu(s)</b> sur la période' : '') . ($d['note'] !== '' ? ' · « ' . h($d['note']) . ' »' : '') . '</span></span>'
            . '<span class="row"><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="conge_ko"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><button class="small danger">Refuser</button></form>'
            . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="conge_ok"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><button class="small primary">Accepter</button></form></span></li>';
    }
    echo '</ul></div>';
}

/* À surveiller : écarts entre le planning du jour et les pointages */
$hier = date('Y-m-d', strtotime($today . ' -1 day'));
$cmpJour = comparer($hier, $today);
$alertes = [];
$nowT = time();
$tolM = tolerance();
foreach ($cmpJour['shifts'] as $r) {
    $s = $r['shift'];
    if ($s['jour'] === $hier && $r['sb'] < strtotime($today . ' 04:00:00')) {
        continue; // créneaux d'hier déjà terminés avant la nuit : on ne les rappelle pas
    }
    $e = emp_get((int)$s['employe_id']);
    if (!$e || !(int)$e['actif']) {
        continue;
    }
    $nom = emp_nom($e);
    $prev = $s['debut'] . '–' . $s['fin'];
    $lieu = ETABS[$s['etab']];
    $hr = static fn(?int $t) => $t ? date('H:i', $t) : '';
    switch ($r['statut']) {
        case 'attendu':
            $alertes[] = ['err', $nom, 'pas encore arrivé·e', 'prévu ' . $prev . ' · ' . $lieu . ' · ' . fmt_min((int)round(($nowT - $r['sa']) / 60)) . ' de retard'];
            break;
        case 'absent':
            $alertes[] = ['err', $nom, 'pas venu·e', 'prévu ' . $prev . ' · ' . $lieu];
            break;
        case 'encours':
            if ($nowT > $r['sb'] + $tolM * 60) {
                $alertes[] = ['warn', $nom, 'dépasse son horaire', 'prévu jusqu\'à ' . $s['fin'] . ' · encore là (+' . fmt_min((int)round(($nowT - $r['sb']) / 60)) . ') · ' . $lieu];
            } elseif ($r['retard'] > $tolM) {
                $alertes[] = ['warn', $nom, 'arrivé·e en retard', 'à ' . $hr($r['debut_reel']) . ' au lieu de ' . $s['debut'] . ' (+' . fmt_min($r['retard']) . ') · ' . $lieu];
            }
            break;
        case 'moins':
            $why = [];
            if ($r['retard'] > $tolM) $why[] = 'arrivé·e à ' . $hr($r['debut_reel']) . ' au lieu de ' . $s['debut'];
            if ($r['tot'] > $tolM) $why[] = 'parti·e à ' . $hr($r['fin_reel']) . ' au lieu de ' . $s['fin'];
            $alertes[] = ['warn', $nom, 'a fait moins que prévu (' . fmt_ecart($r['ecart']) . ')', implode(' · ', $why ?: ['prévu ' . $prev]) . ' · ' . $lieu];
            break;
        case 'plus':
            $alertes[] = ['warn', $nom, 'a fait plus que prévu (' . fmt_ecart($r['ecart']) . ')', $hr($r['debut_reel']) . '–' . $hr($r['fin_reel']) . ' au lieu de ' . $prev . ' · ' . $lieu];
            break;
    }
}
foreach ($cmpJour['np'] as $p) {
    if (substr($p['debut'], 0, 10) !== $today) continue;
    $e = emp_get((int)$p['employe_id']);
    $alertes[] = ['info', $e ? emp_nom($e) : '?', 'a pointé sans être au planning', 'depuis ' . date('H:i', strtotime($p['debut'])) . ($p['fin'] ? ' jusqu\'à ' . date('H:i', strtotime($p['fin'])) : '') . ' · ' . ETABS[$p['etab']]];
}
usort($alertes, fn($a, $b) => ['err' => 0, 'warn' => 1, 'info' => 2][$a[0]] <=> ['err' => 0, 'warn' => 1, 'info' => 2][$b[0]]);
if ($alertes) {
    $nbErr = count(array_filter($alertes, fn($a) => $a[0] === 'err'));
    echo '<div class="card' . ($nbErr ? ' alert-card' : '') . '"><div class="row between"><h3 style="margin:0">À surveiller</h3><span class="muted small">tolérance ' . $tolM . ' min</span></div><ul class="list">';
    foreach ($alertes as [$niv, $nom, $quoi, $detail]) {
        $pill = ['err' => 'err', 'warn' => 'warn', 'info' => ''][$niv];
        echo '<li class="alert-item"><span><b>' . h($nom) . '</b> <span class="pill ' . $pill . '">' . h($quoi) . '</span><br><span class="muted why">' . h($detail) . '</span></span></li>';
    }
    echo '</ul></div>';
}

/* Fil des pointages : derniers mouvements des 24 dernières heures */
$depuis = date('Y-m-d H:i:s', time() - 86400);
$st = db()->prepare('SELECT p.*, e.prenom, e.nom FROM pointages p JOIN employes e ON e.id = p.employe_id WHERE p.debut >= ? OR p.fin >= ? ORDER BY p.id DESC LIMIT 60');
$st->execute([$depuis, $depuis]);
$evts = [];
foreach ($st->fetchAll() as $p) {
    $nom = trim($p['prenom'] . ' ' . $p['nom']);
    if ($p['debut'] >= $depuis) {
        $evts[] = ['t' => $p['debut'], 'k' => 'in', 'nom' => $nom, 'etab' => $p['etab'], 'm' => $p['manuel']];
    }
    if ($p['fin'] !== null && $p['fin'] >= $depuis) {
        $evts[] = ['t' => $p['fin'], 'k' => 'out', 'nom' => $nom, 'etab' => $p['etab'], 'm' => $p['manuel'], 'd' => minutes_between($p['debut'], $p['fin'])];
    }
}
usort($evts, fn($a, $b) => strcmp($b['t'], $a['t']));
$evts = array_slice($evts, 0, 12);
echo '<div class="card"><h3>Derniers pointages</h3>';
if (!$evts) {
    echo '<p class="muted" style="margin:0">Aucun pointage depuis 24 h.</p>';
} else {
    echo '<ul class="list feed">';
    foreach ($evts as $ev) {
        $quand = substr($ev['t'], 0, 10) === $today ? date('H:i', strtotime($ev['t'])) : 'hier ' . date('H:i', strtotime($ev['t']));
        $recent = (time() - strtotime($ev['t'])) < 600;
        echo '<li' . ($recent ? ' class="recent"' : '') . '><span><span class="pill ' . ($ev['k'] === 'in' ? 'ok">Arrivée' : 'err">Départ') . '</span> <b>' . h($ev['nom']) . '</b>'
            . ' <span class="muted small">' . h(ETABS[$ev['etab']]) . ($ev['k'] === 'out' ? ' · ' . h(fmt_min($ev['d'])) : '') . ($ev['m'] ? ' · modifié' : '') . '</span></span>'
            . '<b class="nowrap">' . h($quand) . '</b></li>';
    }
    echo '</ul>';
}
echo '</div>';

/* Alertes : départs oubliés */
$oublies = pointages_oublies();
if ($oublies) {
    echo '<div class="card" style="box-shadow:inset 0 0 0 1.5px var(--err-bg)"><h3>À corriger</h3><p class="muted small">Ces personnes ont pointé leur arrivée mais pas leur départ.</p><ul class="list">';
    foreach ($oublies as $o) {
        $ym = substr($o['debut'], 0, 7);
        echo '<li><span><b>' . h(trim($o['prenom'] . ' ' . $o['nom'])) . '</b> — arrivée le ' . h(date('d/m à H:i', strtotime($o['debut']))) . ' (' . h(ETABS[$o['etab']]) . ')</span>'
            . '<a class="btn small" href="admin.php?p=heures&m=' . h($ym) . '&e=' . (int)$o['employe_id'] . '">Corriger</a></li>';
    }
    echo '</ul></div>';
}

/* Par établissement */
echo '<div class="grid two">';
foreach (ETABS as $key => $label) {
    // En service
    $st = db()->prepare('SELECT p.*, e.prenom, e.nom, e.postes FROM pointages p JOIN employes e ON e.id = p.employe_id
        WHERE p.etab = ? AND p.fin IS NULL AND p.debut >= ? ORDER BY p.debut');
    $st->execute([$key, $seuil]);
    $enService = $st->fetchAll();
    $idsEnService = array_map(fn($r) => (int)$r['employe_id'], $enService);

    // Prévus aujourd'hui
    $st = db()->prepare('SELECT pl.*, e.prenom, e.nom, e.postes FROM planning pl JOIN employes e ON e.id = pl.employe_id
        WHERE pl.etab = ? AND pl.jour = ? AND e.actif = 1 ORDER BY pl.debut, e.prenom');
    $st->execute([$key, $today]);
    $prevus = $st->fetchAll();

    // Déjà passés dans la journée
    $st = db()->prepare('SELECT DISTINCT employe_id FROM pointages WHERE etab = ? AND debut >= ?');
    $st->execute([$key, $today . ' 00:00:00']);
    $dejaPointes = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    echo '<div class="card"><div class="row between"><h3 style="margin:0">' . h($label) . '</h3>'
        . '<span class="pill ' . ($enService ? 'ok' : '') . '">' . count($enService) . ' en service</span></div>';

    echo '<h2>En service</h2>';
    if ($enService) {
        echo '<ul class="list">';
        foreach ($enService as $r) {
            echo '<li><span><b>' . h(trim($r['prenom'] . ' ' . $r['nom'])) . '</b> ' . postes_html($r) . '</span><span class="muted">depuis ' . h(date('H:i', strtotime($r['debut'])))
                . ' · ' . h(fmt_min(minutes_between($r['debut'], $nowSql))) . '</span></li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted">Personne pour le moment.</p>';
    }

    echo '<h2>Prévus aujourd\'hui</h2>';
    if ($prevus) {
        echo '<ul class="list">';
        foreach ($prevus as $r) {
            $id = (int)$r['employe_id'];
            $etat = in_array($id, $idsEnService, true) ? '<span class="pill ok">Là</span>'
                : (in_array($id, $dejaPointes, true) ? '<span class="pill">Passé</span>'
                    : (time() > strtotime($today . ' ' . $r['debut']) + tolerance() * 60 ? '<span class="pill err">Pas encore arrivé·e</span>' : ''));
            echo '<li><span><b>' . h(trim($r['prenom'] . ' ' . $r['nom'])) . '</b> ' . postes_html($r) . ' <span class="muted">' . h($r['debut']) . '–' . h($r['fin']) . '</span></span>' . $etat . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted">Rien de planifié.</p>';
    }
    echo '<p class="row" style="margin:12px 0 0"><a class="btn small" href="admin.php?p=planning&etab=' . h($key) . '">Voir le planning</a>'
        . '<a class="btn small" href="admin.php?p=heures&v=liste&m=' . h(substr($today, 0, 7)) . '&j=' . h($today) . '&etab=' . h($key) . '">Modifier les pointages du jour</a></p></div>';
}
echo '</div>';

$nbEquipe = count(employes());
if ($nbEquipe === 0) {
    echo '<div class="card"><h3>On commence</h3><p>Ajoute d\'abord ton équipe, puis active la pointeuse sur l\'iPad.</p>'
        . '<a class="btn primary" href="admin.php?p=equipe&e=new">Ajouter la première personne</a></div>';
}

if ($frag) {
    exit;
}
echo '</div>';
?>
<script>
(function () {
  // Actualise la page toute seule toutes les 10 secondes (pause quand l'écran est caché)
  const box = document.getElementById('live'), state = document.getElementById('live-state');
  let busy = false;
  async function refresh() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const r = await fetch('admin.php?p=aujourdhui&frag=1', {cache: 'no-store'});
      const t = r.ok ? await r.text() : '';
      if (t && !t.includes('name="pw"')) { box.innerHTML = t; state.classList.remove('off'); }
      else state.classList.add('off');
    } catch (e) { state.classList.add('off'); }
    busy = false;
  }
  setInterval(refresh, 10000);
  // Horloge
  const clk = document.getElementById('clk');
  function tick() {
    const d = new Date(), o = {timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false};
    const [hh, mm, ss] = d.toLocaleTimeString('fr-FR', o).split(':');
    clk.innerHTML = hh + ':' + mm + '<span class="sec">' + ss + '</span>';
  }
  tick(); setInterval(tick, 1000);
  document.addEventListener('visibilitychange', refresh);
})();
</script>
<?php
admin_end();
