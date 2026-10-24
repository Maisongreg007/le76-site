<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{24}$/', $t) || !hash_equals(share_token(), $t)) {
    page_head('Planning');
    http_response_code(404);
    echo '<main class="wrap" style="max-width:560px"><h1>Lien invalide</h1><p class="muted">Ce lien n\'est plus valable. Demande le nouveau lien du planning.</p></main></body></html>';
    exit;
}
$etab = valid_etab($_GET['etab'] ?? null) ? (string)$_GET['etab'] : 'le76';
$cette = monday_of(date('Y-m-d'));
$lundi = valid_date($_GET['s'] ?? null) ? monday_of((string)$_GET['s']) : $cette;
// Limite : de la semaine dernière à 6 semaines devant
$min = date('Y-m-d', strtotime($cette . ' -7 days'));
$max = date('Y-m-d', strtotime($cette . ' +42 days'));
if ($lundi < $min) $lundi = $min;
if ($lundi > $max) $lundi = $max;
$fin = date('Y-m-d', strtotime($lundi . ' +6 days'));

$st = db()->prepare('SELECT pl.*, e.prenom, e.nom, e.postes FROM planning pl JOIN employes e ON e.id = pl.employe_id
    WHERE pl.etab = ? AND pl.jour BETWEEN ? AND ? AND e.actif = 1 ORDER BY pl.jour, pl.debut');
$st->execute([$etab, $lundi, $fin]);
$par = [];
foreach ($st->fetchAll() as $r) {
    $par[$r['jour']][] = $r;
}

page_head('Planning ' . ETABS[$etab]);
$u = static fn(string $e, string $s) => 'planning-equipe.php?t=' . $t . '&etab=' . $e . '&s=' . $s;
echo '<main class="wrap" style="max-width:640px">';
echo '<div class="row between" style="margin-bottom:10px"><h1 style="margin:0">Planning</h1><div class="tabs">';
foreach (ETABS as $k => $l) {
    echo '<a href="' . h($u($k, $lundi)) . '"' . ($k === $etab ? ' class="on"' : '') . '>' . h($l) . '</a>';
}
echo '</div></div>';
$prev = date('Y-m-d', strtotime($lundi . ' -7 days'));
$next = date('Y-m-d', strtotime($lundi . ' +7 days'));
echo '<div class="row between" style="margin-bottom:14px">'
    . ($lundi > $min ? '<a class="btn round" href="' . h($u($etab, $prev)) . '">‹</a>' : '<span style="width:44px"></span>')
    . '<b>Du ' . (int)date('j', strtotime($lundi)) . ' ' . MOIS[(int)date('n', strtotime($lundi)) - 1] . ' au ' . (int)date('j', strtotime($fin)) . ' ' . MOIS[(int)date('n', strtotime($fin)) - 1] . '</b>'
    . ($lundi < $max ? '<a class="btn round" href="' . h($u($etab, $next)) . '">›</a>' : '<span style="width:44px"></span>')
    . '</div>';

$today = date('Y-m-d');
for ($i = 0; $i < 7; $i++) {
    $j = date('Y-m-d', strtotime($lundi . " +$i days"));
    $liste = $par[$j] ?? [];
    usort($liste, fn($a, $b) => [poste_rang($a), $a['debut'], $a['prenom']] <=> [poste_rang($b), $b['debut'], $b['prenom']]);
    echo '<div class="card dayblock"' . ($j === $today ? ' style="box-shadow:inset 0 0 0 2px var(--accent)"' : '') . '><h3><span>' . h(ucfirst(JOURS_LONG[$i])) . ' ' . (int)date('j', strtotime($j)) . '</span>'
        . ($j === $today ? '<span class="pill ok">aujourd\'hui</span>' : '') . '</h3>';
    if (!$liste) {
        echo '<p class="muted" style="margin:0">Personne de prévu</p>';
    } else {
        echo '<ul class="list">';
        foreach ($liste as $r) {
            echo '<li><span><b>' . h(trim($r['prenom'] . ' ' . $r['nom'])) . '</b> ' . postes_html($r) . '</span><span class="nowrap">' . h($r['debut']) . '–' . h($r['fin']) . '</span></li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}
echo '<p class="muted small">Planning mis à jour en direct. Une question ? Vois avec ton responsable.</p></main></body></html>';
