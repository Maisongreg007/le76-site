<?php
declare(strict_types=1);

$etab = valid_etab($_GET['etab'] ?? $_POST['etab'] ?? null) ? (string)($_GET['etab'] ?? $_POST['etab']) : 'le76';
$lundi = valid_date($_GET['s'] ?? $_POST['s'] ?? null) ? monday_of((string)($_GET['s'] ?? $_POST['s'])) : monday_of(date('Y-m-d'));
$retour = 'admin.php?p=planning&etab=' . $etab . '&s=' . $lundi;

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
        if ($id) {
            db()->prepare('UPDATE planning SET debut=?, fin=? WHERE id=?')->execute([$d, $f, $id]);
        } else {
            db()->prepare('INSERT INTO planning (employe_id, etab, jour, debut, fin) VALUES (?,?,?,?,?)')->execute([$emp, $etab, $jour, $d, $f]);
        }
        redirect($retour);
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
            if (!$exists->fetchColumn()) {
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

echo '<div class="row between" style="margin-bottom:12px"><div class="row">'
    . '<a class="btn" href="admin.php?p=planning&etab=' . h($etab) . '&s=' . h($prevS) . '">←</a>'
    . '<b>' . h($titre) . '</b>'
    . '<a class="btn" href="admin.php?p=planning&etab=' . h($etab) . '&s=' . h($nextS) . '">→</a>'
    . '<a class="btn small" href="admin.php?p=planning&etab=' . h($etab) . '">Cette semaine</a></div>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="copy"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '">'
    . '<button class="small">Copier la semaine précédente</button></form></div>';

if (!$emps) {
    echo '<div class="card"><p class="muted">Personne n\'est rattaché à ' . h(ETABS[$etab]) . ' pour l\'instant.</p><a class="btn primary" href="admin.php?p=equipe&e=new">Ajouter quelqu\'un</a></div>';
    admin_end();
    exit;
}

$today = date('Y-m-d');
echo '<div class="card scroll" style="padding:8px"><table class="plan"><thead><tr><th class="emp">&nbsp;</th>';
for ($i = 0; $i < 7; $i++) {
    $j = date('Y-m-d', strtotime($lundi . " +$i days"));
    echo '<th class="' . ($j === $today ? 'today' : '') . '">' . JOURS[$i] . ' ' . (int)date('j', strtotime($j)) . '</th>';
}
echo '<th>Total</th></tr></thead><tbody>';

foreach ($emps as $e) {
    $id = (int)$e['id'];
    $tot = 0;
    echo '<tr><td class="emp">' . h(emp_nom($e)) . '<div style="margin-top:4px">' . postes_html($e) . ' <span class="pill ' . h($e['type']) . '">' . ($e['type'] === 'fixe' ? 'fixe' : 'extra') . '</span></div></td>';
    for ($i = 0; $i < 7; $i++) {
        $j = date('Y-m-d', strtotime($lundi . " +$i days"));
        echo '<td class="day ' . ($j === $today ? 'today' : '') . '">';
        foreach ($shifts[$id][$j] ?? [] as $s) {
            $tot += shift_minutes($s['debut'], $s['fin']);
            echo '<button type="button" class="chip" data-id="' . (int)$s['id'] . '" data-emp="' . $id . '" data-jour="' . h($j) . '" data-debut="' . h($s['debut']) . '" data-fin="' . h($s['fin']) . '" data-nom="' . h(emp_nom($e)) . '">'
                . h($s['debut']) . '–' . h($s['fin']) . '</button>';
        }
        echo '<button type="button" class="chip add" data-id="0" data-emp="' . $id . '" data-jour="' . h($j) . '" data-nom="' . h(emp_nom($e)) . '" aria-label="Ajouter">+</button></td>';
    }
    echo '<td class="tot">' . ($tot ? h(fmt_min($tot)) : '<span class="muted">–</span>') . '</td></tr>';
}
echo '</tbody></table></div>';
echo '<p class="muted small">Touche une case pour ajouter ou modifier un horaire. Une fin avant le début = le service passe minuit.</p>';

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
    . '<button type="button" class="danger" id="d-suppr">Supprimer</button></div></form>'
    . '<form method="post" id="fdel">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '"><input type="hidden" name="id" id="del-id"></form></dialog>';
?>
<script>
(function () {
  const dlg = document.getElementById('dlg');
  const $ = id => document.getElementById(id);
  const JL = <?= json_encode(JOURS_LONG) ?>;
  document.querySelectorAll('.plan .chip').forEach(b => b.addEventListener('click', () => {
    const id = b.dataset.id, d = new Date(b.dataset.jour + 'T12:00:00');
    $('d-id').value = id; $('d-emp').value = b.dataset.emp; $('d-jour').value = b.dataset.jour;
    $('d-titre').textContent = b.dataset.nom + ' — ' + JL[(d.getDay() + 6) % 7] + ' ' + d.getDate() + '/' + (d.getMonth() + 1);
    $('d-debut').value = id !== '0' ? b.dataset.debut : '';
    $('d-fin').value = id !== '0' ? b.dataset.fin : '';
    $('d-suppr').style.display = id !== '0' ? '' : 'none';
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
