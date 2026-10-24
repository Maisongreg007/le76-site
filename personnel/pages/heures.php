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
$vue = ($_GET['v'] ?? $_POST['v'] ?? '') === 'liste' ? 'liste' : '';
$jourF = valid_date($_GET['j'] ?? $_POST['j'] ?? null) ? (string)($_GET['j'] ?? $_POST['j']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $retour = 'admin.php?p=heures&m=' . $ym . ($empId ? '&e=' . $empId : '') . ($vue ? '&v=liste' : '')
        . ($jourF ? '&j=' . $jourF : '') . (valid_etab($_POST['etabf'] ?? null) ? '&etab=' . $_POST['etabf'] : '');

    if ($act === 'save' || $act === 'add') {
        $debut = dt_local_to_sql($_POST['debut'] ?? '');
        $fin = dt_local_to_sql($_POST['fin'] ?? '');
        $etab = (string)($_POST['etab'] ?? '');
        $pe = (int)($_POST['pe'] ?? $empId);
        if ($debut === null || !valid_etab($etab) || !emp_get($pe)) {
            back($retour, '', 'Il manque la personne, une date ou un restaurant.');
        }
        if ($fin !== null && $fin <= $debut) {
            back($retour, '', 'Le départ doit être après l\'arrivée.');
        }
        if ($act === 'save') {
            db()->prepare('UPDATE pointages SET employe_id=?, debut=?, fin=?, etab=?, manuel=1 WHERE id=?')
                ->execute([$pe, $debut, $fin, $etab, (int)$_POST['id']]);
        } else {
            db()->prepare('INSERT INTO pointages (employe_id, etab, debut, fin, manuel) VALUES (?,?,?,?,1)')
                ->execute([$pe, $etab, $debut, $fin]);
        }
        back($retour, 'Enregistré.');
    }
    if ($act === 'delete') {
        db()->prepare('DELETE FROM pointages WHERE id=?')->execute([(int)$_POST['id']]);
        back($retour, 'Pointage supprimé.');
    }
    if ($act === 'purge') {
        if (($_POST['confirm'] ?? '') !== 'EFFACER') {
            back($retour, '', 'Tape EFFACER pour confirmer.');
        }
        $a = $ym . '-01 00:00:00';
        $b = date('Y-m-d H:i:s', strtotime($a . ' +1 month'));
        $st = db()->prepare('DELETE FROM pointages WHERE debut >= ? AND debut < ?');
        $st->execute([$a, $b]);
        back($retour, $st->rowCount() . ' pointages de ' . mois_fr($ym) . ' effacés.');
    }
    redirect($retour);
}

$prevM = date('Y-m', strtotime($ym . '-01 -1 month'));
$nextM = date('Y-m', strtotime($ym . '-01 +1 month'));
$qEtab = $etabF ? '&etab=' . $etabF : '';

page_head('Heures');
admin_nav('heures');
flash_show();

/** Boutons Imprimer / Partager (le partage envoie le fichier Excel via le menu de partage de l'iPad/iPhone). */
function boutons_partage(string $exportUrl, string $nomFichier): string
{
    return '<div class="row no-print"><button type="button" class="small" onclick="window.print()">Imprimer</button>'
        . '<button type="button" class="small primary" data-share="' . h($exportUrl) . '" data-file="' . h($nomFichier) . '">Partager</button></div>';
}

/** Liste de pointages modifiables (+ tableau propre pour l'impression). */
function liste_pointages(array $liste, bool $avecNom, array $ctx): void
{
    $val = static fn(?string $s) => $s ? str_replace(' ', 'T', substr($s, 0, 16)) : '';
    $emps = $avecNom ? employes(false) : [];
    $cache = [];
    $hidden = csrf_field() . '<input type="hidden" name="m" value="' . h($ctx['m']) . '">'
        . ($ctx['e'] ? '<input type="hidden" name="e" value="' . (int)$ctx['e'] . '">' : '')
        . ($ctx['v'] ? '<input type="hidden" name="v" value="liste">' : '')
        . ($ctx['j'] ? '<input type="hidden" name="j" value="' . h($ctx['j']) . '">' : '')
        . ($ctx['etabf'] ? '<input type="hidden" name="etabf" value="' . h($ctx['etabf']) . '">' : '');

    $print = '<table class="print-only"><thead><tr>' . ($avecNom ? '<th>Nom</th>' : '') . '<th>Date</th><th>Arrivée</th><th>Départ</th><th>Lieu</th><th class="num">Durée</th></tr></thead><tbody>';
    $jourCourant = null;
    echo '<div class="no-print">';
    foreach ($liste as $p) {
        $ouvert = $p['fin'] === null;
        $jour = substr($p['debut'], 0, 10);
        if ($avecNom && $jour !== $jourCourant) {
            $jourCourant = $jour;
            echo '<h3 style="margin:16px 0 4px">' . h(ucfirst(date_fr($jour))) . '</h3>';
        }
        echo '<form method="post" class="prow' . ($ouvert ? ' open' : '') . '">' . $hidden . '<input type="hidden" name="id" value="' . (int)$p['id'] . '">';
        if ($avecNom) {
            echo '<div><label class="small">Personne</label><select name="pe">';
            foreach ($emps as $x) {
                echo '<option value="' . (int)$x['id'] . '"' . ((int)$x['id'] === (int)$p['employe_id'] ? ' selected' : '') . '>' . h(emp_nom($x)) . '</option>';
            }
            echo '</select></div>';
        } else {
            echo '<input type="hidden" name="pe" value="' . (int)$p['employe_id'] . '">';
        }
        echo '<div><label class="small">Arrivée</label><input type="datetime-local" name="debut" value="' . h($val($p['debut'])) . '" required></div>'
            . '<div><label class="small">Départ</label><input type="datetime-local" name="fin" value="' . h($val($p['fin'])) . '"></div>'
            . '<div><label class="small">Lieu</label><select name="etab">';
        foreach (ETABS as $k => $l) {
            echo '<option value="' . h($k) . '"' . ($k === $p['etab'] ? ' selected' : '') . '>' . h($l) . '</option>';
        }
        echo '</select></div><div style="min-width:70px"><label class="small">Durée</label><b>' . ($ouvert ? '<span class="pill warn">en cours</span>' : h(fmt_min(minutes_between($p['debut'], $p['fin'])))) . '</b>'
            . ($p['manuel'] ? ' <span class="pill" title="Modifié à la main">modifié</span>' : '') . '</div>'
            . '<div class="row"><button class="small primary" name="action" value="save">OK</button>'
            . '<button class="small danger" name="action" value="delete" formnovalidate data-confirm="Sûr ? Retouche">Supprimer</button></div></form>';

        $nom = '';
        if ($avecNom) {
            $id = (int)$p['employe_id'];
            $cache[$id] ??= emp_get($id);
            $nom = $cache[$id] ? emp_nom($cache[$id]) : '?';
        }
        $print .= '<tr>' . ($avecNom ? '<td>' . h($nom) . '</td>' : '') . '<td>' . h(date('d/m/Y', strtotime($p['debut']))) . '</td><td>' . h(date('H:i', strtotime($p['debut']))) . '</td><td>'
            . ($ouvert ? 'sans départ' : h(date('H:i', strtotime($p['fin'])))) . '</td><td>' . h(ETABS[$p['etab']]) . '</td><td class="num">'
            . ($ouvert ? '' : h(fmt_min(minutes_between($p['debut'], $p['fin'])))) . '</td></tr>';
    }
    echo '</div>';
    echo $print . '</tbody></table>';

    // Ajout
    $defDate = ($ctx['j'] ?: date('Y-m-d'));
    echo '<h2 class="no-print">Ajouter un pointage</h2><form method="post" class="card prow no-print">' . $hidden . '<input type="hidden" name="action" value="add">';
    if ($avecNom) {
        echo '<div><label class="small">Personne</label><select name="pe" required><option value="">Choisir…</option>';
        foreach (employes() as $x) {
            echo '<option value="' . (int)$x['id'] . '">' . h(emp_nom($x)) . '</option>';
        }
        echo '</select></div>';
    } else {
        echo '<input type="hidden" name="pe" value="' . (int)$ctx['e'] . '">';
    }
    echo '<div><label class="small">Arrivée</label><input type="datetime-local" name="debut" value="' . h($defDate) . 'T18:00" required></div>'
        . '<div><label class="small">Départ</label><input type="datetime-local" name="fin" value="' . h($defDate) . 'T23:00" required></div>'
        . '<div><label class="small">Lieu</label><select name="etab">';
    foreach (ETABS as $k => $l) {
        echo '<option value="' . h($k) . '"' . ($k === ($ctx['etabDef'] ?? 'le76') ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    echo '</select></div><div class="row"><button class="primary">Ajouter</button></div></form>';
}

/* ---------- Tous les pointages (modifiables) ---------- */
if ($vue === 'liste') {
    $a = ($jourF ?: $ym . '-01') . ' 00:00:00';
    $b = $jourF ? date('Y-m-d H:i:s', strtotime($a . ' +1 day')) : date('Y-m-d H:i:s', strtotime($ym . '-01 00:00:00 +1 month'));
    $sql = 'SELECT * FROM pointages WHERE debut >= ? AND debut < ?' . ($etabF ? ' AND etab = ?' : '') . ' ORDER BY debut DESC';
    $st = db()->prepare($sql);
    $st->execute($etabF ? [$a, $b, $etabF] : [$a, $b]);
    $liste = $st->fetchAll();

    $base = 'admin.php?p=heures&v=liste';
    echo '<p class="no-print"><a href="admin.php?p=heures&m=' . h($ym) . $qEtab . '">← Heures de ' . h(mois_fr($ym)) . '</a></p>';
    echo '<div class="row between" style="margin-bottom:12px"><h1 style="margin:0">Tous les pointages</h1><div class="tabs no-print">'
        . '<a href="' . $base . '&m=' . h($ym) . '"' . (!$etabF ? ' class="on"' : '') . '>Tout</a>';
    foreach (ETABS as $k => $l) {
        echo '<a href="' . $base . '&m=' . h($ym) . '&etab=' . h($k) . '"' . ($etabF === $k ? ' class="on"' : '') . '>' . h($l) . '</a>';
    }
    echo '</div></div>';
    echo '<div class="row between no-print" style="margin-bottom:12px"><div class="row">'
        . '<a class="btn round" href="' . $base . '&m=' . h($prevM) . $qEtab . '">‹</a><b style="min-width:130px;text-align:center">' . h(ucfirst(mois_fr($ym))) . '</b>'
        . '<a class="btn round" href="' . $base . '&m=' . h($nextM) . $qEtab . '">›</a>'
        . '<form method="get" class="row"><input type="hidden" name="p" value="heures"><input type="hidden" name="v" value="liste"><input type="hidden" name="m" value="' . h($ym) . '">'
        . ($etabF ? '<input type="hidden" name="etab" value="' . h($etabF) . '">' : '')
        . '<input type="date" name="j" value="' . h((string)$jourF) . '" onchange="this.form.submit()" title="Voir un seul jour">'
        . ($jourF ? '<a class="btn small" href="' . $base . '&m=' . h($ym) . $qEtab . '">Tout le mois</a>' : '') . '</form></div>'
        . boutons_partage('admin.php?p=export&t=detail&m=' . $ym . $qEtab . ($jourF ? '&j=' . $jourF : ''), 'pointages-' . ($jourF ?: $ym) . '.csv') . '</div>';
    echo '<h2 class="print-only">' . h($jourF ? ucfirst(date_fr($jourF)) : ucfirst(mois_fr($ym))) . ($etabF ? ' — ' . h(ETABS[$etabF]) : '') . '</h2>';

    echo '<div class="card">';
    if (!$liste) {
        echo '<p class="muted">Aucun pointage' . ($jourF ? ' ce jour-là' : ' ce mois-ci') . '.</p>';
    }
    liste_pointages($liste, true, ['m' => $ym, 'e' => 0, 'v' => 'liste', 'j' => $jourF, 'etabf' => $etabF, 'etabDef' => $etabF ?: 'le76']);
    echo '</div>';

    echo '<div class="card no-print" style="box-shadow:inset 0 0 0 1.5px var(--err-bg)"><h3>Phase de test</h3><p class="muted small">Efface tous les pointages de ' . h(mois_fr($ym)) . ' (les deux restaurants). L\'équipe, le planning et les réglages ne bougent pas. Impossible à annuler.</p>'
        . '<form method="post" class="row">' . csrf_field()
        . '<input type="hidden" name="action" value="purge"><input type="hidden" name="v" value="liste"><input type="hidden" name="m" value="' . h($ym) . '">'
        . '<input name="confirm" placeholder="Tape EFFACER" autocomplete="off" style="width:160px"><button class="danger" data-confirm="Sûr ? Retouche pour effacer">Effacer les pointages du mois</button></form></div>';
    admin_end();
    exit;
}

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
    $tot = 0;
    foreach ($liste as $p) {
        if ($p['fin'] !== null) {
            $tot += minutes_between($p['debut'], $p['fin']);
        }
    }

    echo '<p class="no-print"><a href="admin.php?p=heures&m=' . h($ym) . $qEtab . '">← Heures de ' . h(mois_fr($ym)) . '</a></p>';
    echo '<div class="row between"><h1 style="margin:0">' . h(emp_nom($emp)) . ' <span class="print-only">— ' . h(mois_fr($ym)) . '</span></h1>'
        . '<div class="row no-print"><a class="btn round" href="admin.php?p=heures&e=' . $empId . '&m=' . h($prevM) . '">‹</a><b>' . h(mois_fr($ym)) . '</b><a class="btn round" href="admin.php?p=heures&e=' . $empId . '&m=' . h($nextM) . '">›</a></div>'
        . boutons_partage('admin.php?p=export&t=detail&m=' . $ym . '&e=' . $empId, 'heures-' . preg_replace('/[^a-z0-9]+/i', '-', emp_nom($emp)) . '-' . $ym . '.csv') . '</div><br>';

    $cmpE = comparer($ym . '-01', date('Y-m-t', strtotime($ym . '-01')), null, $empId);
    $lignes = array_filter($cmpE['shifts'], fn($c) => $c['statut'] !== 'avenir');
    if ($lignes || $cmpE['np']) {
        $hr = static fn(?int $t) => $t ? date('H:i', $t) : '…';
        $lib = ['ok' => ['ok', 'comme prévu'], 'plus' => ['warn', 'plus que prévu'], 'moins' => ['warn', 'moins que prévu'], 'absent' => ['err', 'pas venu·e'], 'attendu' => ['err', 'pas encore arrivé·e'], 'encours' => ['', 'en cours']];
        echo '<h2>Prévu / réel</h2><div class="card scroll"><table><thead><tr><th>Jour</th><th>Prévu</th><th>Réel</th><th class="num">Écart</th><th></th></tr></thead><tbody>';
        foreach ($lignes as $c) {
            $s = $c['shift'];
            [$pc, $pl] = $lib[$c['statut']];
            echo '<tr><td class="nowrap">' . h(ucfirst(date_fr($s['jour']))) . '</td><td class="nowrap">' . h($s['debut'] . '–' . $s['fin']) . '</td>'
                . '<td class="nowrap">' . ($c['debut_reel'] ? h($hr($c['debut_reel']) . '–' . $hr($c['fin_reel'])) : '–') . '</td>'
                . '<td class="num nowrap ' . (in_array($c['statut'], ['plus', 'moins'], true) ? 'ecart-' . $c['statut'] : 'ecart-ok') . '">' . ($c['statut'] === 'encours' ? '' : h(fmt_ecart($c['ecart']))) . '</td>'
                . '<td><span class="pill ' . $pc . '">' . h($pl) . '</span></td></tr>';
        }
        foreach ($cmpE['np'] as $p) {
            echo '<tr><td class="nowrap">' . h(ucfirst(date_fr(substr($p['debut'], 0, 10)))) . '</td><td class="muted">–</td><td class="nowrap">' . h(date('H:i', strtotime($p['debut']))) . '–' . ($p['fin'] ? h(date('H:i', strtotime($p['fin']))) : '…') . '</td>'
                . '<td class="num">' . ($p['fin'] ? h(fmt_ecart(minutes_between($p['debut'], $p['fin']))) : '') . '</td><td><span class="pill">non prévu</span></td></tr>';
        }
        echo '</tbody></table></div><h2>Pointages</h2>';
    }

    echo '<div class="card"><p class="muted small no-print" style="margin-top:0">Corrige une heure puis « OK ». Les lignes en orange sont sans départ.</p>';
    if (!$liste) {
        echo '<p class="muted">Aucun pointage ce mois-ci.</p>';
    }
    liste_pointages($liste, false, ['m' => $ym, 'e' => $empId, 'v' => '', 'j' => null, 'etabf' => null, 'etabDef' => emp_etabs($emp)[0] ?? 'le76']);
    echo '<p style="margin:12px 0 0"><b>Total du mois : ' . h(fmt_min($tot)) . '</b>'
        . ($emp['type'] === 'extra' ? ' · ' . h(eur(round($tot / 60 * (float)$emp['taux'], 2))) : ' · salaire ' . h(eur((float)$emp['salaire']))) . '</p></div>';
    admin_end();
    exit;
}

/* ---------- Synthèse du mois ---------- */
$rows = rapport_mois($ym, $etabF);
// Prévu au planning (créneaux déjà terminés) par personne, pour comparer avec le pointé
$finMois = date('Y-m-t', strtotime($ym . '-01'));
$cmpMois = comparer($ym . '-01', min($finMois, date('Y-m-d')), $etabF);
$prevuPasse = [];
foreach ($cmpMois['shifts'] as $c) {
    if (in_array($c['statut'], ['ok', 'plus', 'moins', 'absent'], true)) {
        $prevuPasse[(int)$c['shift']['employe_id']] = ($prevuPasse[(int)$c['shift']['employe_id']] ?? 0) + $c['prevu'];
    }
}
$totPrevu = array_sum($prevuPasse);
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

echo '<div class="row between no-print" style="margin-bottom:12px"><h1 style="margin:0">Heures</h1><div class="tabs">'
    . '<a href="admin.php?p=heures&m=' . h($ym) . '"' . (!$etabF ? ' class="on"' : '') . '>Tout</a>';
foreach (ETABS as $k => $l) {
    echo '<a href="admin.php?p=heures&m=' . h($ym) . '&etab=' . h($k) . '"' . ($etabF === $k ? ' class="on"' : '') . '>' . h($l) . '</a>';
}
echo '</div></div>';

echo '<div class="row between" style="margin-bottom:12px"><div class="row no-print">'
    . '<a class="btn round" href="admin.php?p=heures&m=' . h($prevM) . $qEtab . '">‹</a><b style="min-width:130px;text-align:center">' . h(ucfirst(mois_fr($ym))) . '</b>'
    . '<a class="btn round" href="admin.php?p=heures&m=' . h($nextM) . $qEtab . '">›</a></div>'
    . '<div class="row no-print"><a class="btn small" href="admin.php?p=heures&v=liste&m=' . h($ym) . $qEtab . '">Modifier les pointages</a>'
    . '<a class="btn small" href="admin.php?p=export&m=' . h($ym) . $qEtab . '&t=resume">Excel</a>'
    . boutons_partage('admin.php?p=export&t=resume&m=' . $ym . $qEtab, 'heures-' . $ym . ($etabF ? '-' . $etabF : '') . '.csv') . '</div></div>'
    . '<h2 class="print-only">Heures — ' . h(ucfirst(mois_fr($ym))) . ($etabF ? ' — ' . h(ETABS[$etabF]) : '') . '</h2>';

echo '<div class="stats">'
    . '<div class="stat"><b>' . h(eur($totExtras + $totFixes)) . '</b><span>Coût net du mois</span></div>'
    . '<div class="stat"><b>' . h(eur($totExtras)) . '</b><span>Extras (à l\'heure)</span></div>'
    . '<div class="stat"><b>' . h(eur($totFixes)) . '</b><span>Fixes (salaires)</span></div>'
    . '<div class="stat"><b>' . h(fmt_min($totMin)) . '</b><span>Heures pointées</span></div></div>';

if (!$rows) {
    echo '<div class="card"><p class="muted">Aucune heure ni salaire ce mois-ci.</p></div>';
} else {
    echo '<div class="card scroll"><table><thead><tr><th>Nom</th><th class="hide-m">Contrat</th><th class="num">Prévu</th><th class="num">Pointé</th><th class="num">Écart</th><th class="num">À payer (net)</th></tr></thead><tbody>';
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
            . '<div class="muted small">' . postes_html($e) . ' ' . h(implode(' · ', $detail)) . '</div></td>'
            . '<td class="hide-m"><span class="pill ' . h($e['type']) . '">' . ($e['type'] === 'fixe' ? 'Fixe' : 'Extra') . '</span></td>'
            . '<td class="num nowrap muted">' . (isset($prevuPasse[(int)$e['id']]) ? h(fmt_min($prevuPasse[(int)$e['id']])) : ($r['minutes'] ? '0h00' : '–')) . '</td>'
            . '<td class="num nowrap">' . h(fmt_min($r['minutes'])) . '</td>'
            . (static function () use ($r, $e, $prevuPasse) {
                $pv = $prevuPasse[(int)$e['id']] ?? null;
                if ($pv === null && $r['minutes'] === 0) return '<td class="num muted">–</td>';
                $pv ??= 0;
                $d = $r['minutes'] - $pv;
                $cl = abs($d) <= tolerance() ? 'ecart-ok' : ($d > 0 ? 'ecart-plus' : 'ecart-moins');
                return '<td class="num nowrap ' . $cl . '">' . h(fmt_ecart($d)) . '</td>';
            })()
            . '<td class="num nowrap"><b>' . h(eur($r['cout'])) . '</b></td></tr>';
    }
    echo '<tr class="total"><td>Total</td><td class="hide-m"></td><td class="num">' . h(fmt_min($totPrevu)) . '</td><td class="num">' . h(fmt_min($totMin)) . '</td><td class="num">' . h(fmt_ecart($totMin - $totPrevu)) . '</td><td class="num">' . h(eur($totExtras + $totFixes)) . '</td></tr>';
    echo '</tbody></table></div>';
    echo '<p class="muted small">Prévu = heures du planning des services terminés ce mois-ci. Écart = pointé − prévu. Extras : heures pointées × taux horaire net. Fixes : salaire mensuel net (heures affichées à titre indicatif). Les heures sont arrondies à la minute.</p>';
}
admin_end();
