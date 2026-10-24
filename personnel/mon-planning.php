<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$t = (string)($_GET['t'] ?? '');
$e = null;
if (preg_match('/^[a-f0-9]{24}$/', $t)) {
    $st = db()->prepare('SELECT * FROM employes WHERE token = ? AND actif = 1');
    $st->execute([$t]);
    $e = $st->fetch() ?: null;
}
// Indisponibilités : l'employé coche les jours où il n'est pas disponible
if ($e && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) {
        http_response_code(403);
        exit;
    }
    $j = (string)($_POST['jour'] ?? '');
    if (valid_date($j) && $j >= date('Y-m-d')) {
        if (($_POST['action'] ?? '') === 'off') {
            db()->prepare('INSERT INTO indispos (employe_id, jour, note, created_at) VALUES (?,?,?,?) ON CONFLICT(employe_id, jour) DO UPDATE SET note = excluded.note')
                ->execute([$e['id'], $j, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 80), date('Y-m-d H:i:s')]);
        } else {
            db()->prepare('DELETE FROM indispos WHERE employe_id = ? AND jour = ?')->execute([$e['id'], $j]);
        }
    }
    redirect('mon-planning.php?t=' . $t . '#dispos');
}

page_head('Mon planning', 'manifest-admin.json');
echo '<main class="wrap" style="max-width:560px">';
if (!$e) {
    http_response_code(404);
    echo '<h1>Lien invalide</h1><p class="muted">Ce lien n\'est plus valable. Demande-en un nouveau.</p></main></body></html>';
    exit;
}

$lundi = monday_of(date('Y-m-d'));
$fin = date('Y-m-d', strtotime($lundi . ' +13 days'));
$st = db()->prepare('SELECT * FROM planning WHERE employe_id = ? AND jour BETWEEN ? AND ? ORDER BY jour, debut');
$st->execute([$e['id'], $lundi, $fin]);
$par = [];
foreach ($st->fetchAll() as $r) {
    $par[$r['jour']][] = $r;
}
echo '<h1>Salut ' . h($e['prenom']) . '</h1><p class="muted">Tes horaires des deux prochaines semaines.</p>';
$today = date('Y-m-d');
foreach ([0, 1] as $w) {
    $l = date('Y-m-d', strtotime($lundi . " +$w weeks"));
    $tot = 0;
    $html = '';
    for ($i = 0; $i < 7; $i++) {
        $j = date('Y-m-d', strtotime($l . " +$i days"));
        $liste = $par[$j] ?? [];
        $txt = [];
        foreach ($liste as $s) {
            $tot += shift_minutes($s['debut'], $s['fin']);
            $txt[] = h($s['debut']) . '–' . h($s['fin']) . ' <span class="muted small">' . h(ETABS[$s['etab']]) . '</span>';
        }
        $html .= '<li' . ($j === $today ? ' style="background:var(--accent-soft);margin:0 -10px;padding-left:10px;padding-right:10px;border-radius:10px"' : '') . '><span><b>' . h(ucfirst(JOURS_LONG[$i])) . '</b> ' . (int)date('j', strtotime($j)) . '</span>'
            . '<span>' . ($txt ? implode('<br>', $txt) : '<span class="muted">repos</span>') . '</span></li>';
    }
    echo '<div class="card"><div class="row between"><h3 style="margin:0">' . ($w === 0 ? 'Cette semaine' : 'Semaine prochaine') . '</h3><span class="pill">' . h(fmt_min($tot)) . ' prévues</span></div><ul class="list">' . $html . '</ul></div>';
}
// Mes disponibilités (6 prochaines semaines)
$du = date('Y-m-d');
$au = date('Y-m-d', strtotime('+41 days'));
$ind = indispos_entre($du, $au)[(int)$e['id']] ?? [];
echo '<h2 id="dispos">Mes indisponibilités</h2><div class="card"><p class="muted small" style="margin-top:0">Touche un jour où tu n\'es <b>pas disponible</b> (tu peux ajouter un petit mot). Ton responsable le verra dans le planning.</p><div class="dispo-grid">';
for ($i = 0; $i <= 41; $i++) {
    $j = date('Y-m-d', strtotime($du . " +$i days"));
    $n = (int)date('N', strtotime($j));
    if ($i === 0) for ($k = 1; $k < $n; $k++) echo '<span></span>';
    $off = array_key_exists($j, $ind);
    echo '<form method="post"><input type="hidden" name="jour" value="' . h($j) . '"><input type="hidden" name="action" value="' . ($off ? 'on' : 'off') . '">'
        . ($off ? '' : '<input type="hidden" name="note" value="">')
        . '<button class="dday' . ($off ? ' off' : '') . '" title="' . h($off ? ($ind[$j] ?: 'Pas disponible') . ' — toucher pour annuler' : 'Toucher si pas disponible') . '"' . ($off ? '' : ' data-ask="1"') . '>'
        . '<small>' . h(JOURS[$n - 1]) . '</small><b>' . (int)date('j', strtotime($j)) . '</b>' . ((int)date('j', strtotime($j)) === 1 || $i === 0 ? '<small>' . h(mb_substr(MOIS[(int)date('n', strtotime($j)) - 1], 0, 4)) . '</small>' : '') . '</button></form>';
}
echo '</div><p class="muted small" style="margin-bottom:0"><span class="dday-leg"></span> = pas disponible</p></div>';
?>
<script>
document.querySelectorAll('.dday[data-ask]').forEach(function (b) {
  b.addEventListener('click', function (ev) {
    var n = prompt('Pas disponible ce jour-là. Un petit mot ? (facultatif)', '');
    if (n === null) { ev.preventDefault(); return; }
    b.form.querySelector('input[name=note]').value = n;
  });
});
</script>
<?php
echo '</main></body></html>';
