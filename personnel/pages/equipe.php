<?php
declare(strict_types=1);

/* ---------- Enregistrement ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($act === 'save') {
        $prenom = trim((string)($_POST['prenom'] ?? ''));
        $nom = trim((string)($_POST['nom'] ?? ''));
        $type = ($_POST['type'] ?? '') === 'fixe' ? 'fixe' : 'extra';
        $etabs = array_values(array_filter((array)($_POST['etabs'] ?? []), 'valid_etab'));
        $postes = array_values(array_filter((array)($_POST['postes'] ?? []), fn($p) => isset(POSTES[$p])));
        $pin = trim((string)($_POST['pin'] ?? ''));
        $taux = (float)str_replace(',', '.', (string)($_POST['taux'] ?? '12'));
        $salaire = (float)str_replace(',', '.', (string)($_POST['salaire'] ?? '1800'));
        $hh = trim((string)($_POST['heures_hebdo'] ?? ''));
        $hh = $hh === '' ? null : (float)str_replace(',', '.', $hh);
        $entree = valid_date($_POST['entree'] ?? null) ? $_POST['entree'] : null;
        $sortie = valid_date($_POST['sortie'] ?? null) ? $_POST['sortie'] : null;

        $retour = 'admin.php?p=equipe&e=' . ($id ?: 'new');
        if ($prenom === '' || $nom === '') back($retour, '', 'Il faut le prénom et le nom.');
        if (!$postes) back($retour, '', 'Choisis au moins un poste.');
        if (!$etabs) back($retour, '', 'Choisis au moins un restaurant.');
        if (!preg_match('/^\d{4}$/', $pin)) back($retour, '', 'Le code doit faire 4 chiffres.');
        $st = db()->prepare('SELECT id FROM employes WHERE pin = ? AND id <> ?');
        $st->execute([$pin, $id]);
        if ($st->fetchColumn()) back($retour, '', 'Ce code est déjà pris par quelqu\'un d\'autre.');

        if ($id) {
            db()->prepare('UPDATE employes SET prenom=?, nom=?, postes=?, type=?, etabs=?, pin=?, taux=?, salaire=?, heures_hebdo=?, entree=?, sortie=? WHERE id=?')
                ->execute([$prenom, $nom, implode(',', $postes), $type, implode(',', $etabs), $pin, $taux, $salaire, $hh, $entree, $sortie, $id]);
        } else {
            db()->prepare('INSERT INTO employes (prenom, nom, postes, type, etabs, pin, taux, salaire, heures_hebdo, entree, sortie, token, actif, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)')
                ->execute([$prenom, $nom, implode(',', $postes), $type, implode(',', $etabs), $pin, $taux, $salaire, $hh, $entree, $sortie, bin2hex(random_bytes(12)), date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
        }
        if ($type === 'fixe' && isset($_POST['h'])) {
            $grille = isset($_POST['h_resto']) ? null : horaires_depuis_post((array)$_POST['h']);
            // Heures convenues = total de ses horaires habituels
            $hebdo = round(horaires_minutes($grille ?? horaires_resto()) / 60, 2);
            db()->prepare('UPDATE employes SET horaires = ?, heures_hebdo = ? WHERE id = ?')->execute([$grille ? json_encode($grille) : null, $hebdo, $id]);
        }
        back('admin.php?p=equipe', $prenom . ' ' . $nom . ' est enregistré(e).');
    }

    if ($act === 'archive' || $act === 'restore') {
        db()->prepare('UPDATE employes SET actif = ? WHERE id = ?')->execute([$act === 'restore' ? 1 : 0, $id]);
        back('admin.php?p=equipe', $act === 'restore' ? 'Remis dans l\'équipe.' : 'Archivé : il ne peut plus pointer, l\'historique reste.');
    }

    if ($act === 'purge_emp') {
        $e = emp_get($id);
        if ($e) {
            db()->prepare('DELETE FROM pointages WHERE employe_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM planning WHERE employe_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM employes WHERE id = ?')->execute([$id]);
        }
        back('admin.php?p=equipe', $e ? emp_nom($e) . ' a été supprimé(e) avec ses pointages et son planning.' : '');
    }

    if ($act === 'newtoken') {
        db()->prepare('UPDATE employes SET token = ? WHERE id = ?')->execute([bin2hex(random_bytes(12)), $id]);
        back('admin.php?p=equipe&e=' . $id, 'Nouveau lien créé (l\'ancien ne marche plus).');
    }
    back('admin.php?p=equipe');
}

/* ---------- Affichage ---------- */
page_head('Équipe');
admin_nav('equipe');
flash_show();

$e = $_GET['e'] ?? null;

if ($e !== null) {
    $emp = $e === 'new' ? null : emp_get((int)$e);
    if ($e !== 'new' && !$emp) {
        back('admin.php?p=equipe', '', 'Employé introuvable.');
    }
    $v = $emp ?? ['id' => 0, 'prenom' => '', 'nom' => '', 'postes' => '', 'type' => 'extra', 'etabs' => 'le76', 'pin' => pin_libre(), 'taux' => 12, 'salaire' => 1800, 'heures_hebdo' => null, 'entree' => '', 'sortie' => '', 'token' => '', 'actif' => 1];
    $etabsV = explode(',', (string)$v['etabs']);
    $num = static fn($x) => rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.');

    echo '<p><a href="admin.php?p=equipe">← Équipe</a></p><h1>' . ($emp ? h(emp_nom($emp)) : 'Nouvelle personne') . '</h1>';
    echo '<form method="post" class="card" style="max-width:560px">' . csrf_field()
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . (int)$v['id'] . '">';
    echo '<div class="row"><div class="field" style="flex:1;min-width:140px"><label>Prénom</label><input name="prenom" value="' . h($v['prenom']) . '" required style="width:100%"></div>'
        . '<div class="field" style="flex:1;min-width:140px"><label>Nom</label><input name="nom" value="' . h($v['nom']) . '" required style="width:100%"></div></div>';

    $postesV = explode(',', (string)$v['postes']);
    echo '<div class="field"><label>Poste(s)</label>';
    foreach (POSTES as $k => $l) {
        echo '<label class="inline-check"><input type="checkbox" name="postes[]" value="' . h($k) . '" ' . (in_array($k, $postesV, true) ? 'checked' : '') . '> ' . h($l) . '</label>';
    }
    echo '</div>';

    echo '<div class="field"><label>Contrat</label>'
        . '<label class="inline-check"><input type="radio" name="type" value="extra" ' . ($v['type'] === 'extra' ? 'checked' : '') . ' onchange="sw()"> Extra (payé à l\'heure)</label>'
        . '<label class="inline-check"><input type="radio" name="type" value="fixe" ' . ($v['type'] === 'fixe' ? 'checked' : '') . ' onchange="sw()"> Fixe (salaire mensuel)</label></div>';

    echo '<div class="field" id="f-extra"><label>Taux horaire net (€/h)</label><input name="taux" value="' . h($num($v['taux'])) . '" inputmode="decimal" style="width:140px"></div>';
    echo '<div id="f-fixe"><div class="row"><div class="field"><label>Salaire mensuel net (€)</label><input name="salaire" value="' . h($num($v['salaire'])) . '" inputmode="decimal" style="width:160px"></div>'

        . '<div class="row"><div class="field"><label>Date d\'entrée (facultatif)</label><input type="date" name="entree" value="' . h((string)$v['entree']) . '"></div>'
        . '<div class="field"><label>Date de sortie (facultatif)</label><input type="date" name="sortie" value="' . h((string)$v['sortie']) . '"></div></div>'
        . '<p class="muted small" style="margin-top:-4px">Si tu renseignes l\'entrée ou la sortie, le salaire du mois est calculé au prorata des jours présents. Sans date, c\'est le mois complet.</p></div>';

    echo '<div class="field"><label>Travaille à</label>';
    foreach (ETABS as $k => $l) {
        echo '<label class="inline-check"><input type="checkbox" name="etabs[]" value="' . h($k) . '" ' . (in_array($k, $etabsV, true) ? 'checked' : '') . '> ' . h($l) . '</label>';
    }
    echo '</div>';

    if (true) {
        $hEmp = $emp ? emp_horaires($emp) : horaires_resto();
        echo '<div class="field" id="f-horaires"><label>Ses horaires habituels = ses heures convenues</label>'
            . '<p class="muted small" style="margin:0 0 8px 2px">Coche les services qu\'il fait et ajuste ses heures. Le total donne ses heures convenues par semaine. Le bouton « Horaires habituels » du planning s\'en sert.</p>'
            . horaires_grille($hEmp)
            . '<label class="inline-check" style="margin-top:8px"><input type="checkbox" name="h_resto" value="1"' . ($emp && empty($emp['horaires']) ? ' checked' : '') . '> Utiliser simplement les horaires du restaurant</label></div>'
            . '<script>(function(){var w=document.getElementById("f-horaires");w.querySelector("[data-grid]").addEventListener("change",function(){w.querySelector("input[name=h_resto]").checked=false;});})();</script>';
    }

    echo '<div class="field"><label>Code de pointage (4 chiffres)</label><input name="pin" value="' . h($v['pin']) . '" inputmode="numeric" pattern="\d{4}" maxlength="4" required style="width:120px;font-size:1.3rem;letter-spacing:.2em;text-align:center"></div>';
    echo '<div class="row"><button class="primary">Enregistrer</button><a class="btn" href="admin.php?p=equipe">Annuler</a></div></form>';

    if ($emp) {
        $lien = base_url() . 'mon-planning.php?t=' . $emp['token'];
        echo '<div class="card" style="max-width:560px"><h3>Lien de son planning</h3><p class="muted small">À envoyer par SMS ou WhatsApp. Il voit seulement ses horaires, sans rien pouvoir modifier.</p>'
            . '<div class="linkbox">' . h($lien) . '</div>'
            . '<p class="row" style="margin:10px 0 0"><a class="btn small primary" target="_blank" rel="noopener" href="https://wa.me/?text=' . rawurlencode('Salut ' . $emp['prenom'] . ', voici ton planning : ' . $lien) . '">Envoyer sur WhatsApp</a>'
            . '<a class="btn small" href="sms:?&body=' . rawurlencode('Salut ' . $emp['prenom'] . ', voici ton planning : ' . $lien) . '">Envoyer par SMS</a></p>'
            . '<form method="post" style="margin-top:10px">' . csrf_field()
            . '<input type="hidden" name="action" value="newtoken"><input type="hidden" name="id" value="' . (int)$emp['id'] . '"><button class="small" data-confirm="Sûr ? L\'ancien lien ne marchera plus">Créer un nouveau lien</button></form></div>';
        echo '<form method="post" class="row" style="max-width:560px">' . csrf_field()
            . '<input type="hidden" name="action" value="' . ($emp['actif'] ? 'archive' : 'restore') . '"><input type="hidden" name="id" value="' . (int)$emp['id'] . '">'
            . '<button class="danger" data-confirm="Sûr ? Retouche pour archiver">' . ($emp['actif'] ? 'Archiver (a quitté l\'équipe)' : 'Remettre dans l\'équipe') . '</button></form>'
            . '<form method="post" style="max-width:560px;margin-top:10px">' . csrf_field() . '<input type="hidden" name="action" value="purge_emp"><input type="hidden" name="id" value="' . (int)$emp['id'] . '">'
            . '<button class="danger small" data-confirm="Sûr ? Tout sera effacé, retouche">Supprimer définitivement (fiche de test)</button>'
            . '<p class="muted small">Efface la personne avec tous ses pointages et son planning. À utiliser pour les fiches de test ; pour quelqu\'un qui part, préfère « Archiver ».</p></form>';
    }
    echo '<script>function sw(){var f=document.querySelector("input[name=type]:checked").value;document.getElementById("f-extra").style.display=f==="extra"?"":"none";document.getElementById("f-fixe").style.display=f==="fixe"?"":"none";var hz=document.getElementById("f-horaires");if(hz)hz.style.display=f==="fixe"?"":"none"}sw()</script>';
    admin_end();
    exit;
}

/* Liste */
echo '<div class="row between"><h1>Équipe</h1><a class="btn primary" href="admin.php?p=equipe&e=new">+ Ajouter</a></div>';
$tous = employes(false);
$actifs = array_filter($tous, fn($x) => (int)$x['actif'] === 1);
usort($actifs, fn($a, $b) => [poste_rang($a), $a['prenom'], $a['nom']] <=> [poste_rang($b), $b['prenom'], $b['nom']]);
$archives = array_filter($tous, fn($x) => (int)$x['actif'] === 0);

if (!$actifs) {
    echo '<div class="card"><p class="muted">Personne pour le moment.</p></div>';
} else {
    echo '<div class="card scroll"><table><thead><tr><th>Nom</th><th>Poste</th><th>Contrat</th><th class="hide-m">Restaurant</th><th class="num">Paie</th><th class="num hide-m">Code</th></tr></thead><tbody>';
    foreach ($actifs as $x) {
        $noms = implode(' + ', array_map(fn($k) => ETABS[$k], emp_etabs($x)));
        echo '<tr onclick="location=\'admin.php?p=equipe&e=' . (int)$x['id'] . '\'" style="cursor:pointer"><td><a href="admin.php?p=equipe&e=' . (int)$x['id'] . '"><b>' . h(emp_nom($x)) . '</b></a></td>'
            . '<td>' . (postes_html($x) ?: '<span class="pill warn">à choisir</span>') . '</td>'
            . '<td><span class="pill ' . h($x['type']) . '">' . ($x['type'] === 'fixe' ? 'Fixe' : 'Extra') . '</span></td>'
            . '<td class="hide-m">' . h($noms) . '</td>'
            . '<td class="num">' . ($x['type'] === 'fixe' ? eur((float)$x['salaire']) . ' /mois' : eur((float)$x['taux']) . ' /h') . '</td>'
            . '<td class="num hide-m"><code>' . h($x['pin']) . '</code></td></tr>';
    }
    echo '</tbody></table></div>';
}

if ($archives) {
    echo '<h2>Archivés</h2><div class="card"><ul class="list">';
    foreach ($archives as $x) {
        echo '<li><span>' . h(emp_nom($x)) . ' <span class="muted small">(' . ($x['type'] === 'fixe' ? 'fixe' : 'extra') . ')</span></span>'
            . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="' . (int)$x['id'] . '"><button class="small">Remettre</button></form></li>';
    }
    echo '</ul></div>';
}
admin_end();
