<?php
declare(strict_types=1);

$vue = in_array($_GET['v'] ?? '', ['jour', 'semaine', 'mois'], true) ? (string)$_GET['v'] : 'jour';
$etabF = valid_etab($_GET['etab'] ?? null) ? (string)$_GET['etab'] : null;
$qE = $etabF ? '&etab=' . $etabF : '';

page_head('Coûts');
admin_nav('couts');

$emps = [];
foreach (employes(false) as $e) $emps[(int)$e['id']] = $e;

echo '<div class="row between" style="margin-bottom:12px"><h1 style="margin:0">Coûts du personnel</h1><div class="tabs">'
    . '<a href="admin.php?p=couts&v=' . $vue . '"' . (!$etabF ? ' class="on"' : '') . '>Tout</a>';
foreach (ETABS as $k => $l) echo '<a href="admin.php?p=couts&v=' . $vue . '&etab=' . $k . '"' . ($etabF === $k ? ' class="on"' : '') . '>' . h($l) . '</a>';
echo '</div></div>';

echo '<div class="tabs" style="margin-bottom:14px">';
foreach (['jour' => 'Jour par jour', 'semaine' => 'Par semaine', 'mois' => 'Par mois'] as $k => $l) {
    echo '<a href="admin.php?p=couts&v=' . $k . $qE . '"' . ($vue === $k ? ' class="on"' : '') . '>' . h($l) . '</a>';
}
echo '</div>';

$ligne = static function (string $lib, array $c, string $extra = '') {
    $tot = $c['fixes_eur'] + $c['extras_eur'];
    return '<tr><td>' . $lib . $extra . '</td>'
        . '<td class="num">' . ($c['fixes_min'] + $c['extras_min'] ? h(fmt_min($c['fixes_min'] + $c['extras_min'])) : '<span class="muted">–</span>') . '</td>'
        . '<td class="num">' . ($c['fixes_eur'] ? h(eur($c['fixes_eur'])) : '<span class="muted">–</span>') . '</td>'
        . '<td class="num">' . ($c['extras_eur'] ? h(eur($c['extras_eur'])) : '<span class="muted">–</span>') . '</td>'
        . '<td class="num"><b>' . ($tot ? h(eur($tot)) : '<span class="muted">–</span>') . '</b></td></tr>';
};
$somme = static function (array $jours): array {
    $s = ['fixes_min' => 0, 'fixes_eur' => 0.0, 'extras_min' => 0, 'extras_eur' => 0.0];
    foreach ($jours as $c) foreach ($s as $k => $_) $s[$k] += $c[$k];
    return $s;
};
$thead = '<div class="card scroll"><table><thead><tr><th></th><th class="num">Heures</th><th class="num">Fixes</th><th class="num">Extras</th><th class="num">Total</th></tr></thead><tbody>';

if ($vue === 'jour') {
    $lundi = valid_date($_GET['s'] ?? null) ? monday_of((string)$_GET['s']) : monday_of(date('Y-m-d'));
    $fin = date('Y-m-d', strtotime($lundi . ' +6 days'));
    $jours = couts_jours($lundi, $fin, $etabF);
    $tot = $somme($jours);
    echo '<div class="toolbar"><div class="weeknav"><a class="btn round" href="admin.php?p=couts&v=jour' . $qE . '&s=' . date('Y-m-d', strtotime($lundi . ' -7 days')) . '">‹</a>'
        . '<b>' . (int)date('j', strtotime($lundi)) . ' – ' . (int)date('j', strtotime($fin)) . ' ' . h(MOIS[(int)date('n', strtotime($fin)) - 1]) . '</b>'
        . '<a class="btn round" href="admin.php?p=couts&v=jour' . $qE . '&s=' . date('Y-m-d', strtotime($lundi . ' +7 days')) . '">›</a></div>'
        . '<div class="row"><button type="button" class="small no-print" onclick="window.print()">Imprimer</button></div></div>';
    echo '<div class="stats"><div class="stat"><b>' . h(eur($tot['fixes_eur'] + $tot['extras_eur'])) . '</b><span>Coût de la semaine</span></div>'
        . '<div class="stat"><b>' . h(eur($tot['extras_eur'])) . '</b><span>dont extras (' . h(fmt_min($tot['extras_min'])) . ')</span></div>'
        . '<div class="stat"><b>' . h(fmt_min($tot['fixes_min'] + $tot['extras_min'])) . '</b><span>Heures</span></div></div>';
    echo $thead;
    foreach ($jours as $j => $c) {
        $det = '';
        if ($c['detail']) {
            uasort($c['detail'], fn($a, $b) => $b[1] <=> $a[1]);
            $det = '<details class="det"><summary class="muted small">' . count($c['detail']) . ' personne(s)</summary><ul class="list small">';
            foreach ($c['detail'] as $id => [$m, $eu]) {
                $e = $emps[$id];
                $det .= '<li><span>' . h(emp_nom($e)) . ' <span class="pill ' . h($e['type']) . '">' . $e['type'] . '</span></span><span class="nowrap">' . h(fmt_min($m)) . ' · ' . h(eur($eu)) . '</span></li>';
            }
            $det .= '</ul></details>';
        }
        $lib = '<b>' . h(ucfirst(date_fr($j))) . '</b>' . ($c['source'] === 'prevu' ? ' <span class="pill">prévu</span>' : ($j === date('Y-m-d') ? ' <span class="pill ok">en cours</span>' : ''));
        echo $ligne($lib, $c, $det);
    }
    echo '<tr class="total"><td>Semaine</td><td class="num">' . h(fmt_min($tot['fixes_min'] + $tot['extras_min'])) . '</td><td class="num">' . h(eur($tot['fixes_eur'])) . '</td><td class="num">' . h(eur($tot['extras_eur'])) . '</td><td class="num">' . h(eur($tot['fixes_eur'] + $tot['extras_eur'])) . '</td></tr></tbody></table></div>';
} elseif ($vue === 'semaine') {
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
    $d1 = $ym . '-01';
    $d2 = date('Y-m-t', strtotime($d1));
    $l1 = monday_of($d1);
    $l2 = monday_of($d2);
    $jours = couts_jours($l1, date('Y-m-d', strtotime($l2 . ' +6 days')), $etabF);
    echo '<div class="toolbar"><div class="weeknav"><a class="btn round" href="admin.php?p=couts&v=semaine' . $qE . '&m=' . date('Y-m', strtotime($d1 . ' -1 month')) . '">‹</a><b>' . h(ucfirst(mois_fr($ym))) . '</b>'
        . '<a class="btn round" href="admin.php?p=couts&v=semaine' . $qE . '&m=' . date('Y-m', strtotime($d1 . ' +1 month')) . '">›</a></div></div>';
    echo $thead;
    $all = [];
    for ($l = $l1; $l <= $l2; $l = date('Y-m-d', strtotime($l . ' +7 days'))) {
        $sem = array_filter($jours, fn($j) => $j >= $l && $j <= date('Y-m-d', strtotime($l . ' +6 days')), ARRAY_FILTER_USE_KEY);
        $c = $somme($sem);
        $all[] = $c;
        $prevu = count(array_filter($sem, fn($x) => $x['source'] === 'prevu')) > 0;
        $lib = '<a href="admin.php?p=couts&v=jour' . $qE . '&s=' . $l . '"><b>' . (int)date('j', strtotime($l)) . ' – ' . (int)date('j', strtotime($l . ' +6 days')) . ' ' . h(MOIS[(int)date('n', strtotime($l . ' +6 days')) - 1]) . '</b></a>' . ($prevu ? ' <span class="pill">en partie prévu</span>' : '');
        echo $ligne($lib, $c);
    }
    $t = $somme($all);
    echo '<tr class="total"><td>Total des semaines</td><td class="num">' . h(fmt_min($t['fixes_min'] + $t['extras_min'])) . '</td><td class="num">' . h(eur($t['fixes_eur'])) . '</td><td class="num">' . h(eur($t['extras_eur'])) . '</td><td class="num">' . h(eur($t['fixes_eur'] + $t['extras_eur'])) . '</td></tr></tbody></table></div>';
    echo '<p class="muted small">Les semaines à cheval sur deux mois sont comptées en entier.</p>';
} else {
    // Par mois : salaires réels des fixes (comme dans Heures) + extras pointés
    echo $thead;
    $an = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $rows = rapport_mois($ym, $etabF);
        $c = ['fixes_min' => 0, 'fixes_eur' => 0.0, 'extras_min' => 0, 'extras_eur' => 0.0];
        foreach ($rows as $r) {
            $k = $r['e']['type'] === 'extra' ? 'extras' : 'fixes';
            $c[$k . '_min'] += $r['minutes'];
            $c[$k . '_eur'] += $r['cout'];
        }
        if (!$c['fixes_eur'] && !$c['extras_eur'] && !$c['fixes_min'] && !$c['extras_min']) continue;
        $an[] = $c;
        echo $ligne('<a href="admin.php?p=heures&m=' . $ym . $qE . '"><b>' . h(ucfirst(mois_fr($ym))) . '</b></a>' . ($ym === date('Y-m') ? ' <span class="pill ok">en cours</span>' : ''), $c);
    }
    if (!$an) echo '<tr><td colspan="5" class="muted">Pas encore de données.</td></tr>';
    else {
        $t = $somme($an);
        echo '<tr class="total"><td>12 derniers mois</td><td class="num">' . h(fmt_min($t['fixes_min'] + $t['extras_min'])) . '</td><td class="num">' . h(eur($t['fixes_eur'])) . '</td><td class="num">' . h(eur($t['extras_eur'])) . '</td><td class="num">' . h(eur($t['fixes_eur'] + $t['extras_eur'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

echo '<p class="muted small">'
    . ($vue === 'mois'
        ? 'Par mois : salaires nets réels des fixes (comme dans Heures) + heures d\'extras pointées × leur taux.'
        : 'Extras : heures × taux horaire net. Fixes : heures × leur taux horaire équivalent (salaire mensuel ÷ heures convenues du mois), pour voir ce que coûte chaque service. Jours passés = heures pointées, jours à venir = planning.')
    . '</p>';
admin_end();
