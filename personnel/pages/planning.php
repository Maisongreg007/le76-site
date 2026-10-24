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
            if (!$exists->fetchColumn()) {
                $ins->execute([$emp, $etab, $j, $d, $f]);
            }
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

$titreCourt = (int)date('j', strtotime($lundi)) . ' – ' . (int)date('j', strtotime($fin)) . ' ' . MOIS[(int)date('n', strtotime($fin)) - 1];
echo '<div class="toolbar"><div class="weeknav">'
    . '<a class="btn round" aria-label="Semaine précédente" href="admin.php?p=planning&etab=' . h($etab) . '&s=' . h($prevS) . '">‹</a>'
    . '<b><span class="hide-m">' . h($titre) . '</span><span class="show-m">' . h($titreCourt) . '</span></b>'
    . '<a class="btn round" aria-label="Semaine suivante" href="admin.php?p=planning&etab=' . h($etab) . '&s=' . h($nextS) . '">›</a>'
    . ($lundi !== monday_of(date('Y-m-d')) ? '<a class="btn small" href="admin.php?p=planning&etab=' . h($etab) . '">Aujourd\'hui</a>' : '')
    . '</div><div class="row">'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="copy"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '">'
    . '<button class="small">Copier la semaine d\'avant</button></form>'
    . '<button type="button" class="small primary" id="btn-share">Partager</button></div></div>';

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
        echo '<td class="day ' . ($j === $today ? 'today' : '') . '" data-emp="' . $id . '" data-jour="' . h($j) . '">';
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
    . '<div class="row"><button type="button" id="d-copy">Copier</button><button type="button" class="danger" id="d-suppr">Supprimer</button></div></div></form>'
    . '<form method="post" id="fdel">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '"><input type="hidden" name="id" id="del-id"></form></dialog>';
echo '<form method="post" id="fpaste" hidden>' . csrf_field() . '<input type="hidden" name="action" value="paste"><input type="hidden" name="etab" value="' . h($etab) . '"><input type="hidden" name="s" value="' . h($lundi) . '"><input type="hidden" name="employe_id" id="p-emp"><input type="hidden" name="items" id="p-items"></form>';

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
    const l = [...td.querySelectorAll('.chip:not(.add)')].map(c => [0, c.dataset.debut, c.dataset.fin]);
    dlg.close();
    setClip({t: 'jour', l});
  });
  showBar();

  $('btn-share').addEventListener('click', () => $('dshare').showModal());
  $('share-close').addEventListener('click', () => $('dshare').close());
  $('share-print').addEventListener('click', () => { $('dshare').close(); setTimeout(() => window.print(), 150); });
  $('share-copy').addEventListener('click', async () => {
    const t = $('share-link').textContent;
    try { await navigator.clipboard.writeText(t); $('share-copy').textContent = 'Copié !'; }
    catch (e) { const r = document.createRange(); r.selectNodeContents($('share-link')); getSelection().removeAllRanges(); getSelection().addRange(r); }
  });

  document.querySelectorAll('.plan .chip').forEach(b => b.addEventListener('click', () => {
    if (clip) return;
    const id = b.dataset.id, d = new Date(b.dataset.jour + 'T12:00:00');
    $('d-id').value = id; $('d-emp').value = b.dataset.emp; $('d-jour').value = b.dataset.jour;
    $('d-titre').textContent = b.dataset.nom + ' — ' + JL[(d.getDay() + 6) % 7] + ' ' + d.getDate() + '/' + (d.getMonth() + 1);
    $('d-debut').value = id !== '0' ? b.dataset.debut : '';
    $('d-fin').value = id !== '0' ? b.dataset.fin : '';
    $('d-suppr').style.display = id !== '0' ? '' : 'none';
    $('d-copy').style.display = id !== '0' ? '' : 'none';
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
