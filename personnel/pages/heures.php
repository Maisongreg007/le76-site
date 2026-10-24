<?php
declare(strict_types=1);

$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? $_POST['m'] ?? '')) ? (string)($_GET['m'] ?? $_POST['m']) : date('Y-m');
$etabF = valid_etab($_GET['etab'] ?? null) ? (string)$_GET['etab'] : null;
$empId = (int)($_GET['e'] ?? $_POST['e'] ?? 0);

function dt_local_to_sql(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) {
        return null;
    }
    return str_replace('T', ' ', $v) . ':00';
}

/* ---------- Actions sur les pointages ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $retour = 'admin.php?p=heures&m=' . $ym . '&e=' . $empId;

    if ($act === 'save' || $act === 'add') {
        $debut = dt_local_to_sql($_POST['debut'] ?? '');
        $fin = dt_local_to_sql($_POST['fin'] ?? '');
        $etab = (string)($_POST['etab'] ?? '');
        if ($debut === null || !valid_etab($etab) || !emp_get($empId)) {
            back($retour, '', 'Il manque une date ou un restaurant.');
        }
        if ($fin !== null && $fin <= $debut) {
            back($retour, '', 'Le départ doit être après l\'arrivée.');
        }
        if ($act === 'save') {
            db()->prepare('UPDATE pointages SET debut=?, fin=?, etab=?, manuel=1 WHERE id=? AND employe_id=?')
                ->execute([$debut, $fin, $etab, (int)$_POST['id'], $empId]);
        } else {
            db()->prepare('INSERT INTO pointages (employe_id, etab, debut, fin, manuel) VALUES (?,?,?,?,1)')
                ->execute([$empId, $etab, $debut, $fin]);
        }
        back($retour, 'Enregistré.');
    }
    if ($act === 'delete') {
        db()->prepare('DELETE FROM pointages WHERE id=? AND employe_id=?')->execute([(int)$_POST['id'], $empId]);
        back($retour, 'Pointage supprimé.');
    }
    redirect($retour);
}

$prevM = date('Y-m', strtotime($ym . '-01 -1 month'));
$nextM = date('Y-m', strtotime($ym . '-01 +1 month'));
$qEtab = $etabF ? '&etab=' . $etabF : '';

page_head('Heures');
admin_nav('heures');
flash_show();

/* ---------- Détail d'une personne ---------- */
if ($empId) {
    $emp = emp_get($empId);
    if (!$emp) {
        back('admin.php?p=heures&m=' . $ym, '', 'Employé introuvable.');
    }
    $a = $ym . '-01 00:00:00';
    $b = date('Y-m-d H:i:s', strtotime($a . ' +1 month'));
    $st = db()->prepare('SELECT * FROM pointages WHERE employe_id = ? AND debut >= ? AND debut < ? ORDER BY debut DESC');
    $st->execute([$empId, $a, $b]);
    $liste = $st->fetchAll();

    echo '<p><a href="admin.php?p=heures&m=' . h($ym) . $qEtab . '">← Heures de ' . h(mois_fr($ym)) . '</a></p>';
    echo '<div class="row between"><h1 style="margin:0">' . h(emp_nom($emp)) . '</h1><div class="row"><a class="btn" href="admin.php?p=heures&e=' . $empId . '&m=' . h($prevM) . '">←</a><b>' . h(mois_fr($ym)) . '</b><a class="btn" href="admin.php?p=heures&e=' . $empId . '&m=' . h($nextM) . '">→</a></div></div><br>';

    $tot = 0;
    echo '<div class="card"><p class="muted small" style="margin-top:0">Modifie une ligne si quelqu\'un a oublié de pointer. Les lignes en orange sont sans départ.</p>';
    if (!$liste) {
        echo '<p class="muted">Aucun pointage ce mois-ci.</p>';
    }
    foreach ($liste as $p) {
        $ouvert = $p['fin'] === null;
        if (!$ouvert) {
            $tot += minutes_between($p['debut'], $p['fin']);
        }
        $val = static fn(?string $s) => $s ? str_replace(' ', 'T', substr($s, 0, 16)) : '';
        echo '<form method="post" class="row" style="padding:10px 0;border-bottom:1px solid var(--line);' . ($ouvert ? 'background:var(--warn-bg);padding:10px;border-radius:10px;' : '') . '">'
            . csrf_field() . '<input type="hidden" name="id" value="' . (int)$p['id'] . '"><input type="hidden" name="e" value="' . $empId . '"><input type="hidden" name="m" value="' . h($ym) . '">'
            . '<div><label class="small">Arrivée</label><input type="datetime-local" name="debut" value="' . h($val($p['debut'])) . '" required></div>'
            . '<div><label class="small">Départ</label><input type="datetime-local" name="fin" value="' . h($val($p['fin'])) . '"></div>'
            . '<div><label class="small">Lieu</label><select name="etab">';
        foreach (ETABS as $k => $l) {
            echo '<option value="' . h($k) . '"' . ($k === $p['etab'] ? ' selected' : '') . '>' . h($l) . '</option>';
        }
        echo '</select></div><div style="min-width:70px"><label class="small">Durée</label><b>' . ($ouvert ? '<span class="pill warn">en cours</span>' : h(fmt_min(minutes_between($p['debut'], $p['fin'])))) . '</b></div>'
            . '<div class="row" style="margin-top:18px"><button class="small primary" name="action" value="save">OK</button>'
            . '<button class="small danger" name="action" value="delete" onclick="return confirm(\'Supprimer ce pointage ?\')">Supprimer</button></div></form>';
    }
    echo '<p style="margin:12px 0 0"><b>Total du mois : ' . h(fmt_min($tot)) . '</b>'
        . ($emp['type'] === 'extra' ? ' · ' . h(eur(round($tot / 60 * (float)$emp['taux'], 2))) : '') . '</p></div>';

    $defEtab = emp_etabs($emp)[0] ?? 'le76';
    echo '<h2>Ajouter un pointage oublié</h2><form method="post" class="card row">' . csrf_field()
        . '<input type="hidden" name="action" value="add"><input type="hidden" name="e" value="' . $empId . '"><input type="hidden" name="m" value="' . h($ym) . '">'
        . '<div><label class="small">Arrivée</label><input type="datetime-local" name="debut" required></div>'
        . '<div><label class="small">Départ</label><input type="datetime-local" name="fin" required></div>'
        . '<div><label class="small">Lieu</label><select name="etab">';
    foreach (ETABS as $k => $l) {
        echo '<option value="' . h($k) . '"' . ($k === $defEtab ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    echo '</select></div><div style="margin-top:18px"><button class="primary">Ajouter</button></div></form>';
    admin_end();
    exit;
}

/* ---------- Synthèse du mois ---------- */
$rows = rapport_mois($ym, $etabF);
$totMin = 0;
$totExtras = 0.0;
$totFixes = 0.0;
foreach ($rows as $r) {
    $totMin += $r['minutes'];
    if ($r['e']['type'] === 'extra') {
        $totExtras += $r['cout'];
    } else {
        $totFixes += $r['cout'];
    }
}

echo '<div class="row between" style="margin-bottom:12px"><h1 style="margin:0">Heures</h1><div class="tabs">'
    . '<a href="admin.php?p=heures&m=' . h($ym) . '"' . (!$etabF ? ' class="on"' : '') . '>Tout</a>';
foreach (ETABS as $k => $l) {
    echo '<a href="admin.php?p=heures&m=' . h($ym) . '&etab=' . h($k) . '"' . ($etabF === $k ? ' class="on"' : '') . '>' . h($l) . '</a>';
}
echo '</div></div>';

echo '<div class="row between" style="margin-bottom:12px"><div class="row">'
    . '<a class="btn" href="admin.php?p=heures&m=' . h($prevM) . $qEtab . '">←</a><b style="min-width:130px;text-align:center">' . h(ucfirst(mois_fr($ym))) . '</b>'
    . '<a class="btn" href="admin.php?p=heures&m=' . h($nextM) . $qEtab . '">→</a></div>'
    . '<div class="row"><a class="btn small" href="admin.php?p=export&m=' . h($ym) . $qEtab . '&t=resume">Exporter le résumé (Excel)</a>'
    . '<a class="btn small" href="admin.php?p=export&m=' . h($ym) . $qEtab . '&t=detail">Exporter le détail</a></div></div>';

echo '<div class="stats">'
    . '<div class="stat"><b>' . h(eur($totExtras + $totFixes)) . '</b><span>Coût net du mois</span></div>'
    . '<div class="stat"><b>' . h(eur($totExtras)) . '</b><span>Extras (à l\'heure)</span></div>'
    . '<div class="stat"><b>' . h(eur($totFixes)) . '</b><span>Fixes (salaires)</span></div>'
    . '<div class="stat"><b>' . h(fmt_min($totMin)) . '</b><span>Heures pointées</span></div></div>';

if (!$rows) {
    echo '<div class="card"><p class="muted">Aucune heure ni salaire ce mois-ci.</p></div>';
} else {
    echo '<div class="card scroll"><table><thead><tr><th>Nom</th><th>Contrat</th><th class="num">Heures</th><th class="num">À payer (net)</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $e = $r['e'];
        $lien = 'admin.php?p=heures&m=' . h($ym) . '&e=' . (int)$e['id'] . $qEtab;
        $detail = [];
        if ($e['type'] === 'extra') {
            $detail[] = eur((float)$e['taux']) . ' /h';
        } else {
            $detail[] = 'salaire mensuel';
            if ($r['note']) {
                $detail[] = $r['note'];
            }
            if ($r['contrat_min'] !== null && !$etabF) {
                $ecart = $r['minutes'] - $r['contrat_min'];
                $detail[] = 'contrat ≈ ' . fmt_min($r['contrat_min']) . ' (' . ($ecart >= 0 ? '+' : '−') . fmt_min(abs($ecart)) . ')';
            }
        }
        echo '<tr><td><a href="' . $lien . '"><b>' . h(emp_nom($e)) . '</b></a>'
            . (!(int)$e['actif'] ? ' <span class="pill">archivé</span>' : '')
            . ($r['ouverts'] ? ' <a href="' . $lien . '" class="pill warn">' . $r['ouverts'] . ' sans départ</a>' : '')
            . '<div class="muted small">' . h(implode(' · ', $detail)) . '</div></td>'
            . '<td><span class="pill ' . h($e['type']) . '">' . ($e['type'] === 'fixe' ? 'Fixe' : 'Extra') . '</span></td>'
            . '<td class="num nowrap">' . h(fmt_min($r['minutes'])) . '</td>'
            . '<td class="num nowrap"><b>' . h(eur($r['cout'])) . '</b></td></tr>';
    }
    echo '<tr class="total"><td colspan="2">Total</td><td class="num">' . h(fmt_min($totMin)) . '</td><td class="num">' . h(eur($totExtras + $totFixes)) . '</td></tr>';
    echo '</tbody></table></div>';
    echo '<p class="muted small">Extras : heures pointées × taux horaire net. Fixes : salaire mensuel net (heures affichées à titre indicatif). Les heures sont arrondies à la minute.</p>';
}
admin_end();
