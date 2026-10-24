<?php
declare(strict_types=1);

$today = date('Y-m-d');
$nowSql = date('Y-m-d H:i:s');
$seuil = date('Y-m-d H:i:s', time() - OUBLI_HEURES * 3600);

page_head("Aujourd'hui");
admin_nav('aujourdhui');
flash_show();

echo '<h1>' . h(ucfirst(date_fr($today))) . '</h1>';

/* Alertes : départs oubliés */
$oublies = pointages_oublies();
if ($oublies) {
    echo '<div class="card" style="border-color:#f0c9c6"><h3>À corriger</h3><p class="muted small">Ces personnes ont pointé leur arrivée mais pas leur départ.</p><ul class="list">';
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

    echo '<h2 style="font-size:.85rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">En service</h2>';
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

    echo '<h2 style="font-size:.85rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Prévus aujourd\'hui</h2>';
    if ($prevus) {
        echo '<ul class="list">';
        foreach ($prevus as $r) {
            $id = (int)$r['employe_id'];
            $etat = in_array($id, $idsEnService, true) ? '<span class="pill ok">Là</span>'
                : (in_array($id, $dejaPointes, true) ? '<span class="pill">Passé</span>'
                    : (date('H:i') > $r['debut'] ? '<span class="pill warn">Pas encore pointé</span>' : ''));
            echo '<li><span><b>' . h(trim($r['prenom'] . ' ' . $r['nom'])) . '</b> ' . postes_html($r) . ' <span class="muted">' . h($r['debut']) . '–' . h($r['fin']) . '</span></span>' . $etat . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted">Rien de planifié.</p>';
    }
    echo '<p style="margin:12px 0 0"><a class="btn small" href="admin.php?p=planning&etab=' . h($key) . '">Voir le planning</a></p></div>';
}
echo '</div>';

$nbEquipe = count(employes());
if ($nbEquipe === 0) {
    echo '<div class="card"><h3>On commence</h3><p>Ajoute d\'abord ton équipe, puis active la pointeuse sur l\'iPad.</p>'
        . '<a class="btn primary" href="admin.php?p=equipe&e=new">Ajouter la première personne</a></div>';
}

admin_end();
