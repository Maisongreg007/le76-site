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
echo '</main></body></html>';
